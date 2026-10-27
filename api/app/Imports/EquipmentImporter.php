<?php

namespace App\Imports;

use App\Domain\InventoryStatus;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\Office;
use App\Services\BulkContext;
use App\Services\SyncService;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns a CSV into equipment records — or, in a dry run, into a report of what
 * it would turn into.
 *
 * The dry run is not a convenience. This loads hundreds of rows into a
 * register that gets audited, from a spreadsheet maintained by hand across
 * several provinces, so the operator sees every row's verdict before anything
 * is written. `analyse()` and `commit()` run exactly the same validation; the
 * only difference is whether the resulting changes are handed to SyncService.
 *
 * Writes go through SyncService like every other write in the system, so the
 * uniqueness rules, the status vocabulary and the activity log all apply
 * without this class restating any of them. What it adds is everything that
 * only matters in bulk: duplicates *within* one file, asset tags for rows that
 * have none, and office names resolved once rather than per row.
 */
final class EquipmentImporter
{
    /**
     * The fields that make a line a record rather than spreadsheet padding.
     *
     * A sheet maintained in Excel carries hundreds of trailing rows that are
     * not empty — a stray `False` left behind in a Printer column is enough —
     * and reporting 990 of those as failed imports would bury the handful of
     * rows that genuinely need attention.
     */
    private const SUBSTANCE = [
        'assetTag', 'propertyNumber', 'serialNumber', 'monitorSerialNumber',
        'modelName', 'parIssuedTo', 'actualUser', 'dateAcquired',
    ];

    /**
     * Values that mean "nothing recorded" in the source workbook.
     *
     * Stripped only from identifier columns, and that restriction is the whole
     * point: a literal `N/A` in a property number would take the unique index
     * and reject every later row, while `NO` in the "With XDR" column is a
     * real answer that must survive.
     */
    /**
     * How many rows the report lists.
     *
     * A file can be thousands of rows long, and returning every one of them
     * produced eleven megabytes of JSON and a table no browser renders
     * usefully. Rows needing attention come first because they are the ones
     * the operator has to do something about; a short sample of clean rows
     * follows as evidence the file was read as intended. `summary` always
     * counts the whole file regardless.
     *
     * A file with more than five hundred broken rows wants fixing in the
     * spreadsheet, not one row at a time in a browser.
     */
    private const MAX_LISTED_PROBLEMS = 500;

    private const MAX_LISTED_READY = 25;

    /**
     * Rows written per transaction.
     *
     * Large enough that the per-transaction cost disappears into the inserts,
     * small enough that a clash forcing a row-by-row replay re-does a few
     * hundred rows rather than thousands.
     */
    private const WRITE_CHUNK = 500;

    /**
     * Equipment a workstation row can describe besides the workstation.
     *
     * A printer sitting on someone's desk is its own accountable item with its
     * own property number, not a note on the computer next to it, so the
     * importer registers it as one.
     */
    private const DERIVED = [
        'printer' => [
            'label' => 'Printer',
            'prefix' => 'ICT-PRN',
            'category' => CategorySeeder::PRINTERS,
        ],
        'scanner' => [
            'label' => 'Scanner',
            'prefix' => 'ICT-SCN',
            'category' => CategorySeeder::SCANNERS,
        ],
    ];

    private const PLACEHOLDERS = [
        'N/A', 'NA', 'N\\A', 'NONE', 'NO', 'NIL', 'NULL', '-', '--', '---',
        'TBA', 'TBD', 'X', '0', 'TOTAL', 'NO PROPERTY NO', 'NO PROPERTY NO.',
        'NO PROPERTY NUMBER', 'NO SERIAL', 'NO SN', 'NO SERIAL NUMBER',
    ];

    public function __construct(
        private readonly CsvReader $reader,
        private readonly HeaderMatcher $matcher,
        private readonly SyncService $sync,
    ) {}

    /**
     * Validates a file and reports what would happen, writing nothing.
     */
    public function analyse(
        ImportProfile $profile,
        string $contents,
        array $defaults = [],
        array $corrections = [],
    ): array {
        return $this->present(
            $this->examineAll($profile, $contents, $defaults, $corrections),
            $profile,
            withCells: true,
        );
    }

    /**
     * Reads and validates every row, holding nothing back.
     *
     * The full result is what `commit()` writes from and what `present()`
     * trims for the response; it is never returned to a caller as it stands,
     * because at five thousand rows it is far more than a screen can use.
     */
    private function examineAll(
        ImportProfile $profile,
        string $contents,
        array $defaults = [],
        array $corrections = [],
    ): array {
        $file = $this->reader->read($contents);
        $headers = $this->matcher->match($profile, $file['header']);

        $report = [
            'profile' => $profile->name(),
            'label' => $profile->label(),
            'delimiter' => $file['delimiter'],
            'truncated' => $file['truncated'],
            'headers' => [
                'matched' => $headers['matched'],
                'missingRequired' => $headers['missingRequired'],
                'unrecognised' => $headers['unrecognised'],
                'duplicate' => $headers['duplicate'],
            ],
            'rows' => [],
            'summary' => [
                'total' => count($file['rows']),
                'ready' => 0,
                'errors' => 0,
                'duplicates' => 0,
                'blank' => 0,
                'derived' => 0,
            ],
            'committed' => false,
        ];

        /*
         * A column the operator supplied a default for on the import form is
         * not missing — that default exists precisely so a province-wide file
         * need not repeat one division on every one of its rows.
         */
        $missing = array_values(array_filter(
            $headers['missingRequired'],
            fn (string $label) => ! $this->coveredByDefault($profile, $label, $defaults)
                && ! $this->coveredByCorrections($label, $corrections, count($file['rows'])),
        ));
        $report['headers']['missingRequired'] = $missing;

        // A genuinely missing required header is fatal for the whole file:
        // every row would carry the same error, and 900 copies of it tell the
        // operator nothing the header line does not say once.
        if ($missing !== []) {
            $report['fatal'] = 'The file is missing required columns: '
                .implode(', ', $missing)
                .'. Download the template for this type to see the expected headers.';

            return $report;
        }

        $category = Category::find($profile->categoryId());
        if (! $category) {
            $report['fatal'] = "The {$profile->label()} category is not set up yet. "
                .'Run `php artisan db:seed --class=CategorySeeder`, or create it on the Categories screen.';

            return $report;
        }

        $offices = $this->officeIndex();
        $taken = $this->existingIdentifiers(
            $this->candidateIdentifiers($profile, $file['rows'], $headers['byColumn'], $corrections),
        );
        $seen = ['assetTag' => [], 'propertyNumber' => [], 'serial' => []];
        $tagger = $this->assetTagger($profile);

        // Built only if a row actually names one, so an import of tablets
        // never touches the printer or scanner categories.
        $peripheralTaggers = [];
        $peripheralCategories = [];

        foreach ($file['rows'] as $row) {
            $examined = $this->examine(
                $profile, $category, $row, $headers['byColumn'], $defaults, $offices, $taken, $seen, $tagger,
                $corrections[(string) $row['line']] ?? $corrections[$row['line']] ?? [],
            );

            $report['rows'][] = $examined;

            /*
             * A workstation row can name a printer and a scanner, and those
             * are two more pieces of equipment rather than two more facts
             * about this one. Each becomes a record of its own, validated
             * through exactly the same path — the only difference is that its
             * values were inherited rather than read from columns.
             */
            foreach (self::DERIVED as $kind => $meta) {
                $preset = $this->peripheralValues($kind, $examined, $meta['label']);

                if ($preset === null) {
                    continue;
                }

                if (is_string($preset)) {
                    // Named, but short of what a property record needs.
                    $last = count($report['rows']) - 1;
                    $report['rows'][$last]['warnings'][] = $preset;

                    continue;
                }

                $peripheralCategories[$kind] ??= Category::find($meta['category']);

                if (! $peripheralCategories[$kind]) {
                    $last = count($report['rows']) - 1;
                    $report['rows'][$last]['warnings'][] = "The {$meta['label']}s category is not set up yet, so the "
                        .strtolower($meta['label']).' on this row was not registered. Run `php artisan db:seed --class=CategorySeeder`.';

                    continue;
                }

                $peripheralTaggers[$kind] ??= $this->assetTagger($meta['prefix']);

                $report['rows'][] = $this->examine(
                    $profile, $peripheralCategories[$kind], $row, $headers['byColumn'], $defaults,
                    $offices, $taken, $seen, $peripheralTaggers[$kind], [], $preset, $row['line'],
                );
            }
        }

        foreach ($report['rows'] as $row) {
            $key = match ($row['status']) {
                'READY' => 'ready',
                'DUPLICATE' => 'duplicates',
                'BLANK' => 'blank',
                default => 'errors',
            };
            $report['summary'][$key]++;

            if ($row['derivedFrom'] !== null) {
                $report['summary']['derived']++;
            }
        }

        /*
         * The source workbook leaves `PAR / Assigned to` empty on every row and
         * names the holder under `Sub-PAR / Actual User`. Say so once, with the
         * remedy, rather than repeating "PAR Issued To is required" a thousand
         * times and leaving the operator to work out why.
         */
        $report['hint'] = $this->hint($report, $defaults);
        $report['file'] = $file;
        $report['byColumn'] = $headers['byColumn'];

        return $report;
    }

    /**
     * Trims a full report down to what the screen can actually use.
     *
     * At five thousand rows the untrimmed report is eleven megabytes of JSON
     * and a table nothing can render usefully, so only rows the operator can
     * act on are listed: everything needing attention, up to a limit, and a
     * short sample of the clean ones as evidence they were read. The counts in
     * `summary` always describe the whole file.
     *
     * Cells are attached here rather than during examination, so the forty-odd
     * strings that back the correction editor are only built for the handful of
     * rows that offer one.
     */
    private function present(array $report, ImportProfile $profile, bool $withCells): array
    {
        $file = $report['file'] ?? null;
        $byColumn = $report['byColumn'] ?? [];
        unset($report['file'], $report['byColumn']);

        if (isset($report['fatal'])) {
            return $report;
        }

        $problems = [];
        $ready = [];

        foreach ($report['rows'] as $row) {
            if ($row['status'] === 'BLANK') {
                continue;
            }

            $needsAttention = $row['errors'] !== [] || $row['warnings'] !== [];

            if ($needsAttention && count($problems) < self::MAX_LISTED_PROBLEMS) {
                $problems[] = $row;
            } elseif (! $needsAttention && count($ready) < self::MAX_LISTED_READY) {
                $ready[] = $row;
            }
        }

        $listed = [...$problems, ...$ready];
        usort($listed, fn (array $a, array $b) => $a['line'] <=> $b['line']);

        // Records, not lines: one workstation row can register a printer and a
        // scanner alongside the machine itself.
        $records = $report['summary']['total']
            - $report['summary']['blank']
            + $report['summary']['derived'];
        $report['rowsOmitted'] = max(0, $records - count($listed));
        $report['rowLimit'] = self::MAX_LISTED_PROBLEMS;

        if ($withCells && $file) {
            $byLine = [];
            foreach ($file['rows'] as $raw) {
                $byLine[$raw['line']] = $raw['values'];
            }

            foreach ($listed as $i => $row) {
                if ($row['derivedFrom'] !== null) {
                    continue;
                }

                $listed[$i]['cells'] = $this->cells($profile, $byLine[$row['line']] ?? [], $byColumn);
            }
        }

        $report['rows'] = array_values($listed);

        return $report;
    }

    /** The row as the spreadsheet's own cells, for the correction editor. */
    private function cells(ImportProfile $profile, array $values, array $byColumn): array
    {
        $cells = [];

        foreach ($profile->columns() as $column) {
            if (! $column->template) {
                continue;
            }

            $cells[$column->label] = array_key_exists($column->label, $byColumn)
                ? ($values[$byColumn[$column->label]] ?? '')
                : '';
        }

        return $cells;
    }

    /**
     * Validates, then writes the rows that passed.
     *
     * Rows are applied individually rather than in one transaction, so a
     * single bad record cannot discard 900 good ones. Each row's verdict comes
     * back from SyncService, which is the authority — this class's own checks
     * are there to produce a useful preview, not to replace it.
     */
    public function commit(
        ImportProfile $profile,
        string $contents,
        array $defaults = [],
        array $corrections = [],
    ): array {
        $report = $this->examineAll($profile, $contents, $defaults, $corrections);

        if (isset($report['fatal'])) {
            return $this->present($report, $profile, withCells: false);
        }

        $report['committed'] = true;
        $report['summary']['applied'] = 0;
        $report['summary']['rejected'] = 0;

        /*
         * Written a chunk at a time, building each chunk's changes as it goes.
         *
         * Collecting all five thousand first meant holding a second copy of
         * every payload alongside the report. Each chunk's payloads are
         * released as soon as its verdicts are recorded, so the write phase
         * costs the same whether the file has five hundred rows or five
         * thousand.
         *
         * `pushBatch`, not `push`: these rows were validated above, so the
         * batch path can resolve offices and categories once and share a
         * transaction per chunk. `push` re-checks every rule per row, which is
         * right for a single write and ruinous for five thousand.
         */
        $pending = [];
        $index = 0;
        // One set of office and category lookups for the whole import, not one
        // per chunk.
        $context = new BulkContext(preValidated: true);

        $flush = function () use (&$pending, &$report, $context) {
            if ($pending === []) {
                return;
            }

            $verdicts = collect($this->sync->pushBatch($pending, $context)['results'] ?? [])->keyBy('docId');

            foreach ($pending as $change) {
                $verdict = $verdicts->get($change['docId']);
                $applied = ($verdict['status'] ?? null) === 'APPLIED';
                $at = $change['rowIndex'];

                $report['rows'][$at]['status'] = $applied ? 'IMPORTED' : 'ERROR';
                $report['summary'][$applied ? 'applied' : 'rejected']++;

                if (! $applied) {
                    $report['rows'][$at]['errors'][] = $verdict['message'] ?? 'The server refused this row.';
                    $report['summary']['ready']--;
                    $report['summary']['errors']++;
                }
            }

            $pending = [];
        };

        foreach ($report['rows'] as $at => $row) {
            if ($row['status'] !== 'READY') {
                continue;
            }

            $pending[] = [
                'id' => (string) Str::uuid(),
                'table' => 'equipment',
                'action' => 'INSERT',
                'docId' => $row['id'],
                'payload' => $row['payload'],
                'clientTimestamp' => now()->addMilliseconds($index++)->toIso8601ZuluString('millisecond'),
                // Carried along so a verdict can be put back on the right row
                // without keeping a second index of five thousand ids.
                'rowIndex' => $at,
            ];

            if (count($pending) >= self::WRITE_CHUNK) {
                $flush();
            }
        }

        $flush();

        // Correcting a row after it is written is not a thing, so the commit
        // report carries no cells.
        return $this->present($report, $profile, withCells: false);
    }

    /**
     * One actionable sentence about a problem the whole file shares.
     *
     * Only raised when it affects effectively every row, because that is the
     * signature of a column being in the wrong place rather than a few records
     * needing a correction.
     */
    private function hint(array $report, array $defaults): ?string
    {
        $records = array_values(array_filter(
            $report['rows'],
            fn (array $row) => $row['status'] !== 'BLANK',
        ));

        if ($records === []) {
            return null;
        }

        $missingPar = 0;
        $haveActualUser = 0;

        foreach ($records as $row) {
            if (in_array('PAR Issued To is required.', $row['errors'], true)) {
                $missingPar++;
                if (($row['payload']['actualUser'] ?? null) !== null) {
                    $haveActualUser++;
                }
            }
        }

        if ($missingPar >= count($records) && $haveActualUser > 0 && ! ($defaults['parFallback'] ?? false)) {
            return 'PAR Issued To is blank on every row, but '.$haveActualUser.' of them name an Actual User. '
                .'The source workbook keeps the holder in its "Sub-PAR / Actual User" column. Either fill in a '
                .'PAR Issued To column, or tick "Use Actual User when PAR Issued To is blank" and import again.';
        }

        return null;
    }

    /**
     * Whether corrections supply a required column for every row in the file.
     *
     * Without this a file with no Division column could never be fixed on the
     * review screen: the header check would reject it before the corrections
     * were ever looked at.
     */
    private function peripheralValues(string $kind, array $parent, string $label): array|string|null
    {
        $own = $parent['peripherals'][$kind] ?? [];

        $model = $own['modelName'] ?? null;
        $propertyNumber = $own['propertyNumber'] ?? null;
        $serial = $own['serialNumber'] ?? null;

        // Nothing in any of its columns: this row simply has no such kit.
        if ($model === null && $propertyNumber === null && $serial === null) {
            return null;
        }

        /*
         * The older sheets answer the Printer column with YES or TRUE — a tick
         * meaning "there is one" rather than a name. That is not a model, and
         * recording it as one would put "Yes" in the model column of a
         * government property record.
         */
        $named = $model !== null && ! in_array($this->yesNo($model), ['Yes', 'No'], true);

        $missing = match (true) {
            ! $named && $propertyNumber === null => 'a model or a property number',
            ! $named => 'a model — that column holds a yes/no answer rather than a name',
            $propertyNumber === null => 'a property number',
            default => null,
        };

        if ($missing !== null) {
            return "A {$label} is recorded on this row but is missing {$missing}, so it was not registered as its own asset.";
        }

        $from = $parent['inherited'] ?? [];

        return [
            'modelName' => $model,
            'propertyNumber' => $propertyNumber,
            'serialNumber' => $serial,
            'remarks' => $parent['peripherals']['shared']['remarks'] ?? null,
            // Inherited: the same office, officer and purchase as the machine
            // it was delivered with.
            'division' => $from['division'] ?? null,
            'section' => $from['section'] ?? null,
            'province' => $from['province'] ?? null,
            'location' => $from['location'] ?? null,
            'dateAcquired' => $from['dateAcquired'] ?? null,
            'parIssuedTo' => $from['parIssuedTo'] ?? null,
            'subParIssuedTo' => $from['subParIssuedTo'] ?? null,
            'actualUser' => $from['actualUser'] ?? null,
            'position' => $from['position'] ?? null,
            'status' => $from['status'] ?? null,
            'condition' => $from['condition'] ?? null,
            'spec:attachedTo' => $parent['assetTag'] ?? null,
            'spec:program' => $from['spec:program'] ?? null,
            'spec:idNumber' => $from['spec:idNumber'] ?? null,
            'spec:acquisitionType' => $from['spec:acquisitionType'] ?? null,
            'spec:administrationType' => $from['spec:administrationType'] ?? null,
            'spec:warranty' => $own['spec:warranty'] ?? null,
        ];
    }

    /**
     * Whether corrections supply a required column for every row in the file.
     */
    private function coveredByCorrections(string $label, array $corrections, int $rows): bool
    {
        if ($corrections === [] || $rows === 0) {
            return false;
        }

        $supplied = 0;
        foreach ($corrections as $cells) {
            if (is_array($cells) && trim((string) ($cells[$label] ?? '')) !== '') {
                $supplied++;
            }
        }

        return $supplied >= $rows;
    }

    /** Whether a choice made on the import form fills this column. */
    private function coveredByDefault(ImportProfile $profile, string $label, array $defaults): bool
    {
        // The fallback stands in for the PAR column whether that column is
        // present but empty — as it is in the source workbook — or absent
        // altogether, which is the same situation with one fewer column.
        if ($label === 'PAR Issued To' && ($defaults['parFallback'] ?? false)) {
            return true;
        }

        foreach ($profile->columns() as $column) {
            if ($column->label !== $label) {
                continue;
            }

            foreach ($column->targets as $target) {
                if (($defaults[$target] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /* --------------------------------- rows -------------------------------- */

    /**
     * Reads one row into a payload, collecting every problem rather than
     * stopping at the first — an operator fixing a spreadsheet wants the whole
     * list of what is wrong with a line, not one error per re-upload.
     */
    private function examine(
        ImportProfile $profile,
        Category $category,
        array $row,
        array $byColumn,
        array $defaults,
        array $offices,
        array &$taken,
        array &$seen,
        callable $tagger,
        array $corrected = [],
        ?array $preset = null,
        ?int $derivedFrom = null,
    ): array {
        $errors = [];
        $warnings = [];
        $values = $preset ?? [];

        /*
         * Columns the operator corrected on the review screen.
         *
         * A correction replaces the cell, not the verdict: the row is read and
         * validated again from here exactly as it was the first time, so a
         * corrected value still has to satisfy every rule. It also works for a
         * column the file does not have, which is how a row missing a division
         * is fixed without re-exporting the sheet.
         */
        $fix = [];
        $fail = function (string $message, string ...$columns) use (&$errors, &$fix) {
            $errors[] = $message;
            foreach ($columns as $column) {
                $fix[$column] = true;
            }
        };

        $read = function (ImportColumn $column) use ($row, $byColumn, $corrected): ?string {
            if (array_key_exists($column->label, $corrected)) {
                return $this->value($column, (string) $corrected[$column->label]);
            }

            if (! array_key_exists($column->label, $byColumn)) {
                return null;
            }

            $raw = $row['values'][$byColumn[$column->label]] ?? '';

            return $this->value($column, $raw);
        };

        // Dedicated columns first; combined ones then fill only what is still
        // empty, so a file carrying both forms keeps the specific one.
        foreach ($preset === null ? $profile->columns() : [] as $column) {
            if ($column->isCombined()) {
                continue;
            }
            $value = $read($column);
            if ($value !== null) {
                $values[$column->targets[0]] = $value;
            }
        }

        foreach ($preset === null ? $profile->columns() : [] as $column) {
            if (! $column->isCombined()) {
                continue;
            }

            $hasCorrection = array_key_exists($column->label, $corrected);
            if (! $hasCorrection && ! array_key_exists($column->label, $byColumn)) {
                continue;
            }

            $raw = $hasCorrection
                ? (string) $corrected[$column->label]
                : ($row['values'][$byColumn[$column->label]] ?? '');
            $parts = $this->split($raw, count($column->targets));

            if (count($parts) === 1 && $parts[0] !== null) {
                $sole = $column->soleTarget();

                $warnings[] = "\"{$column->label}\" holds one value with no \"/\" separator, so it was read as "
                    .$this->describe($sole).'. Check it, or use the separate columns in the template.';

                if (! array_key_exists($sole, $values)) {
                    $values[$sole] = $parts[0];
                }

                continue;
            }

            foreach ($column->targets as $position => $target) {
                $part = $parts[$position] ?? null;
                if ($part !== null && ! array_key_exists($target, $values)) {
                    $values[$target] = $part;
                }
            }
        }

        // Spreadsheet padding, not a record: nothing identifying it at all.
        $hasSubstance = false;
        foreach (self::SUBSTANCE as $target) {
            if (($values[$target] ?? null) !== null) {
                $hasSubstance = true;
                break;
            }
        }

        if (! $hasSubstance) {
            return [
                'line' => $row['line'],
                'id' => (string) Str::uuid(),
                'status' => 'BLANK',
                'assetTag' => null,
                'assetTagGenerated' => false,
                'derivedFrom' => null,
                'peripherals' => [],
                'inherited' => [],
                'errors' => [],
                'warnings' => [],
                'fix' => [],
                'corrected' => (object) [],
                'payload' => [],
                'office' => ['division' => null, 'section' => null],
            ];
        }

        /* ----------------------------- required ---------------------------- */

        $division = $this->resolveOffice($values['division'] ?? $defaults['division'] ?? null, $offices, false);
        $section = $this->resolveOffice($values['section'] ?? $defaults['section'] ?? null, $offices, true);

        if (($values['division'] ?? $defaults['division'] ?? null) !== null && ! $division) {
            $fail('Division "'.($values['division'] ?? $defaults['division']).'" is not in the office register.', 'Division');
        }
        if (($values['section'] ?? null) !== null && ! $section) {
            $fail('Section "'.$values['section'].'" is not in the office register.', 'Section');
        }
        if ($division === null && $errors === []) {
            $fail('A division is required. Add a Division column, or pick a default on the import form.', 'Division');
        }
        if ($section && $division && $section->division_id !== $division->id) {
            $fail("{$section->name} is not a section of {$division->name}.", 'Section', 'Division');
        }

        $date = null;
        if (isset($values['dateAcquired'])) {
            $date = $this->date($values['dateAcquired']);
            if ($date === null) {
                $fail(
                    "Date Acquired \"{$values['dateAcquired']}\" is not a date I can read unambiguously. Use YYYY-MM-DD.",
                    'Date Acquired',
                );
            }
        } else {
            $fail('Date Acquired is required.', 'Date Acquired');
        }

        /*
         * Opt-in, and deliberately not automatic: the PAR names the officer who
         * is financially accountable for the asset, and the actual user often
         * is not that person. Silently copying one into the other would put the
         * wrong name on an accountability record. The operator asks for it on
         * the import form, and every row it touches says so.
         */
        if ($preset === null
            && ($values['parIssuedTo'] ?? null) === null
            && ($defaults['parFallback'] ?? false)
            && ($values['actualUser'] ?? null) !== null) {
            $values['parIssuedTo'] = $values['actualUser'];
            $warnings[] = 'PAR Issued To was blank, so the Actual User "'.$values['actualUser']
                .'" was recorded as the PAR holder. Correct it if someone else holds the PAR.';
        }

        foreach (['propertyNumber' => 'Property Number', 'modelName' => 'Model', 'parIssuedTo' => 'PAR Issued To'] as $key => $label) {
            if (($values[$key] ?? null) === null) {
                $fail("{$label} is required.", $label);
            }
        }

        if ($category->requires_monitor_serial) {
            $serialColumns = [
                'serialNumber' => ['A system unit serial number', 'Serial Number'],
                'monitorSerialNumber' => ['A monitor serial number', 'Monitor Serial Number'],
            ];

            foreach ($serialColumns as $key => [$label, $column]) {
                if (($values[$key] ?? null) === null) {
                    $fail("{$label} is required for {$category->name}.", $column);
                }
            }
        }

        /* ---------------------------- uniqueness --------------------------- */

        $assetTag = $values['assetTag'] ?? null;
        $generated = false;
        if ($assetTag === null) {
            $assetTag = $tagger();
            $generated = true;
        }

        $status = 'READY';

        $clashes = [
            ['assetTag', $assetTag, 'Asset tag', 'Asset Tag'],
            ['propertyNumber', $values['propertyNumber'] ?? null, 'Property number', 'Property Number'],
            ['serial', $values['serialNumber'] ?? null, 'Serial number', 'Serial Number'],
            ['serial', $values['monitorSerialNumber'] ?? null, 'Monitor serial number', 'Monitor Serial Number'],
        ];

        foreach ($clashes as [$bucket, $value, $label, $column]) {
            if ($value === null) {
                continue;
            }

            $key = mb_strtolower($value);

            if (isset($taken[$bucket][$key])) {
                $fail("{$label} \"{$value}\" is already in the register as {$taken[$bucket][$key]}.", $column);
                $status = 'DUPLICATE';
            } elseif (isset($seen[$bucket][$key])) {
                $fail("{$label} \"{$value}\" is also on line {$seen[$bucket][$key]} of this file.", $column);
                $status = 'DUPLICATE';
            }
        }

        if (($values['serialNumber'] ?? null) !== null
            && ($values['serialNumber'] ?? null) === ($values['monitorSerialNumber'] ?? null)) {
            $fail(
                'The system unit and monitor cannot share a serial number.',
                'Serial Number',
                'Monitor Serial Number',
            );
        }

        if ($errors !== []) {
            $status = $status === 'DUPLICATE' ? 'DUPLICATE' : 'ERROR';
        }

        // Claim the identifiers only for rows that will actually be written,
        // or a rejected row would make a later legitimate one look duplicated.
        if ($status === 'READY') {
            foreach ($clashes as [$bucket, $value]) {
                if ($value !== null) {
                    $seen[$bucket][mb_strtolower($value)] = $row['line'];
                }
            }
        }

        $specifications = [];
        $peripherals = [];

        foreach ($values as $target => $value) {
            if (str_starts_with($target, 'spec:')) {
                $specifications[substr($target, 5)] = $value;

                continue;
            }

            // `printer:…` and `scanner:…` describe other equipment entirely;
            // `peripheral:…` applies to whichever of the two this row names.
            foreach (['printer', 'scanner', 'peripheral'] as $kind) {
                if (str_starts_with($target, $kind.':')) {
                    $bucket = $kind === 'peripheral' ? 'shared' : $kind;
                    $peripherals[$bucket][substr($target, strlen($kind) + 1)] = $value;
                }
            }
        }

        return [
            'line' => $row['line'],
            'id' => (string) Str::uuid(),
            'status' => $status,
            'assetTag' => $assetTag,
            'assetTagGenerated' => $generated,
            'errors' => $errors,
            'warnings' => $warnings,
            // A derived record has no cells of its own to edit: it is fixed
            // by correcting the row it came from, which is the line named here.
            'derivedFrom' => $derivedFrom,
            'peripherals' => $peripherals,
            // What a printer or scanner registered from this row takes with
            // it: the office, the officer and the purchase are shared.
            'inherited' => [
                'division' => $values['division'] ?? null,
                'section' => $values['section'] ?? null,
                'province' => $values['province'] ?? null,
                'location' => $values['location'] ?? null,
                'dateAcquired' => $values['dateAcquired'] ?? null,
                'parIssuedTo' => $values['parIssuedTo'] ?? null,
                'subParIssuedTo' => $values['subParIssuedTo'] ?? null,
                'actualUser' => $values['actualUser'] ?? null,
                'position' => $values['position'] ?? null,
                'status' => $values['status'] ?? null,
                'condition' => $values['condition'] ?? null,
                'spec:program' => $values['spec:program'] ?? null,
                'spec:idNumber' => $values['spec:idNumber'] ?? null,
                'spec:acquisitionType' => $values['spec:acquisitionType'] ?? null,
                'spec:administrationType' => $values['spec:administrationType'] ?? null,
            ],
            'fix' => $derivedFrom === null ? array_keys($fix) : [],
            // `cells` is attached by present(), and only for the rows that are
            // actually listed — building it per row costs forty strings each.
            'corrected' => (object) $corrected,
            'payload' => [
                'assetTag' => $assetTag,
                'propertyNumber' => $values['propertyNumber'] ?? null,
                'dateAcquired' => $date?->toDateString(),
                'serialNumber' => $values['serialNumber'] ?? null,
                'monitorSerialNumber' => $values['monitorSerialNumber'] ?? null,
                'categoryId' => $category->id,
                'modelName' => $values['modelName'] ?? null,
                'brand' => $values['brand'] ?? null,
                'status' => $this->status($values['status'] ?? $defaults['status'] ?? null),
                'condition' => $this->condition($values['condition'] ?? $defaults['condition'] ?? null),
                'location' => $values['location'] ?? null,
                'divisionId' => $division?->id,
                'sectionId' => $section?->id,
                'province' => $values['province'] ?? $defaults['province'] ?? null,
                'parIssuedTo' => $values['parIssuedTo'] ?? null,
                'subParIssuedTo' => $values['subParIssuedTo'] ?? null,
                'actualUser' => $values['actualUser'] ?? null,
                'position' => $values['position'] ?? null,
                'adUsername' => $values['adUsername'] ?? null,
                'anydeskNumber' => $values['anydeskNumber'] ?? null,
                'specifications' => $specifications,
                'remarks' => $values['remarks'] ?? null,
                'updatedAt' => now()->toIso8601ZuluString('millisecond'),
            ],
            'office' => [
                'division' => $division?->name,
                'section' => $section?->name,
            ],
        ];
    }

    /* -------------------------------- values ------------------------------- */

    /** Reads a cell for a column, returning null for "nothing recorded". */
    private function value(ImportColumn $column, string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if ($column->kind === 'identifier' && $this->isPlaceholder($raw)) {
            return null;
        }

        if ($column->kind === 'yesno') {
            return $this->yesNo($raw);
        }

        return $raw;
    }

    /**
     * Settles a yes/no cell on one spelling.
     *
     * The sheets answer these with YES, Yes, Y, TRUE and every combination of
     * case, while the equipment form offers a Yes/No dropdown — a value that
     * does not match an option shows as blank there. Anything the pattern does
     * not recognise is kept verbatim rather than forced into a box it does not
     * fit: the old workbook writes "YES - 8.7" in the XDR column, and losing
     * the agent version would be worse than an untidy value.
     */
    private function yesNo(string $raw): string
    {
        return match (mb_strtoupper(trim($raw))) {
            'YES', 'Y', 'TRUE', '1', 'OO' => 'Yes',
            'NO', 'N', 'FALSE', '0', 'NONE', 'WALA' => 'No',
            default => $raw,
        };
    }

    private function isPlaceholder(string $value): bool
    {
        $value = mb_strtoupper(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return in_array($value, self::PLACEHOLDERS, true);
    }

    /**
     * Splits a combined cell across its targets.
     *
     * Overflow is joined back into the last target rather than dropped: a
     * value with three slashes is unusual, and losing part of a property
     * identifier silently is worse than recording it awkwardly.
     *
     * @return list<string|null>
     */
    private function split(string $raw, int $targets): array
    {
        $raw = trim($raw);
        if ($raw === '' || $this->isPlaceholder($raw)) {
            return [];
        }

        $parts = array_map('trim', explode('/', $raw));

        if (count($parts) > $targets) {
            $tail = array_slice($parts, $targets - 1);
            $parts = array_slice($parts, 0, $targets - 1);
            $parts[] = implode(' / ', $tail);
        }

        return array_map(
            fn (string $p) => ($p === '' || $this->isPlaceholder($p)) ? null : $p,
            $parts
        );
    }

    /**
     * Parses a date, refusing the ones that cannot be read with certainty.
     *
     * `03/04/2026` is the 3rd of April to the person who typed it and the 4th
     * of March to half the world's software. Acquisition dates drive
     * useful-life reporting, so an ambiguous slash date is rejected with an
     * instruction rather than guessed at.
     */
    private function date(string $raw): ?Carbon
    {
        $raw = trim($raw);

        // Excel writes a bare day count when the cell was formatted as a number.
        if (preg_match('/^\d{5}$/', $raw) && (int) $raw >= 20000 && (int) $raw <= 60000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $raw);
        }

        foreach (['Y-m-d', 'Y/m/d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'd-M-Y', 'j F Y', 'M j, Y', 'd.m.Y'] as $format) {
            $parsed = $this->exact($format, $raw);
            if ($parsed) {
                return $parsed;
            }
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $raw, $m)) {
            [, $first, $second] = $m;

            // Only an unambiguous slash date is accepted: one of the two
            // leading numbers has to be too large to be a month.
            if ((int) $first > 12) {
                return $this->exact('d/m/Y', $raw);
            }
            if ((int) $second > 12) {
                return $this->exact('m/d/Y', $raw);
            }

            return null;
        }

        return null;
    }

    private function exact(string $format, string $raw): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('!'.$format, $raw);
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed) {
            return null;
        }

        // getLastErrors() returns false — not an empty array — when the parse
        // was clean, so this cannot be indexed into blindly.
        $errors = Carbon::getLastErrors();

        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }

        return $parsed;
    }

    private function status(?string $raw): string
    {
        $raw = mb_strtoupper(trim((string) $raw));

        return in_array($raw, InventoryStatus::EQUIPMENT_STATUSES, true) ? $raw : 'AVAILABLE';
    }

    /**
     * Maps the register's own words onto the condition vocabulary.
     *
     * "Unserviceable" is a procurement term meaning the unit cannot be used,
     * which is `DAMAGED` here, not merely `POOR`.
     */
    private function condition(?string $raw): string
    {
        $raw = mb_strtoupper(trim((string) $raw));

        if (in_array($raw, InventoryStatus::EQUIPMENT_CONDITIONS, true)) {
            return $raw;
        }

        return match (true) {
            str_contains($raw, 'UNSERVICEABLE'), str_contains($raw, 'DEFECTIVE'),
            str_contains($raw, 'FOR DISPOSAL'), str_contains($raw, 'NOT WORKING') => 'DAMAGED',
            str_contains($raw, 'FOR REPAIR'), str_contains($raw, 'POOR') => 'POOR',
            str_contains($raw, 'SERVICEABLE'), str_contains($raw, 'WORKING') => 'GOOD',
            str_contains($raw, 'BRAND NEW') => 'NEW',
            default => 'GOOD',
        };
    }

    private function describe(string $target): string
    {
        return match ($target) {
            'propertyNumber' => 'the property number',
            'serialNumber' => 'the serial number',
            'spec:monitorPropertyNumber' => 'the monitor property number',
            'monitorSerialNumber' => 'the monitor serial number',
            default => $target,
        };
    }

    /* -------------------------------- lookups ------------------------------ */

    /**
     * The office register, indexed for in-memory lookup.
     *
     * Built once per import rather than queried per row: a thousand-row file
     * would otherwise issue a thousand pairs of queries for the same handful
     * of offices.
     *
     * Names are indexed with and without the register's `FO ` prefix, because
     * a spreadsheet maintained by hand says "Policy and Plans Division" where
     * the register says "FO Policy and Plans Division".
     */
    private function officeIndex(): array
    {
        $index = ['code' => [], 'name' => []];

        foreach (Office::query()->whereNull('deleted_at')->get() as $office) {
            $index['code'][mb_strtolower($office->code)] = $office;

            foreach ([$office->name, preg_replace('/^FO\s*-?\s*/i', '', $office->name)] as $name) {
                $key = $this->flatten((string) $name);
                if ($key !== '' && ! isset($index['name'][$key])) {
                    $index['name'][$key] = $office;
                }
            }
        }

        return $index;
    }

    private function resolveOffice(?string $raw, array $offices, bool $wantSection): ?Office
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        // A default chosen on the form arrives as an id.
        $office = $offices['code'][mb_strtolower(trim($raw))]
            ?? $offices['name'][$this->flatten($raw)]
            ?? null;

        if (! $office) {
            foreach ($offices['code'] as $candidate) {
                if ($candidate->id === $raw) {
                    $office = $candidate;
                    break;
                }
            }
        }

        if (! $office) {
            return null;
        }

        $isSection = $office->division_id !== null;

        return $isSection === $wantSection ? $office : null;
    }

    private function flatten(string $value): string
    {
        $value = mb_strtolower(preg_replace('/^FO\s*-?\s*/i', '', trim($value)) ?? $value);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    /**
     * Identifiers already in the register that this file might collide with.
     *
     * Scoped to the values the file actually carries, rather than reading the
     * whole equipment table: the cost then grows with the import, not with the
     * register, so a field office with sixty thousand assets pays the same as
     * one with sixty. The candidates are gathered from the raw cells in one
     * cheap pass before any row is examined.
     *
     * Soft-deleted rows are included deliberately: MySQL's unique indexes
     * cover them, so an archived asset still owns its property number and a
     * row claiming it would be rejected on insert.
     */
    private function existingIdentifiers(array $candidates): array
    {
        $taken = ['assetTag' => [], 'propertyNumber' => [], 'serial' => []];

        if ($candidates === []) {
            return $taken;
        }

        // Chunked: one IN list of several thousand values is slower to plan
        // than a handful of smaller ones, and some drivers cap the bindings.
        foreach (array_chunk($candidates, 1000) as $chunk) {
            Equipment::query()
                ->select(['asset_tag', 'property_number', 'serial_number', 'monitor_serial_number', 'deleted_at'])
                ->whereIn('asset_tag', $chunk)
                ->orWhereIn('property_number', $chunk)
                ->orWhereIn('serial_number', $chunk)
                ->orWhereIn('monitor_serial_number', $chunk)
                ->cursor()
                ->each(function (Equipment $asset) use (&$taken) {
                    $where = $asset->deleted_at ? "{$asset->asset_tag} (archived)" : $asset->asset_tag;

                    $taken['assetTag'][mb_strtolower($asset->asset_tag)] = $where;

                    if ($asset->property_number) {
                        $taken['propertyNumber'][mb_strtolower($asset->property_number)] = $where;
                    }
                    foreach ([$asset->serial_number, $asset->monitor_serial_number] as $serial) {
                        if ($serial) {
                            $taken['serial'][mb_strtolower($serial)] = $where;
                        }
                    }
                });
        }

        return $taken;
    }

    /**
     * Every value in the file that could be an identifier.
     *
     * Deliberately indiscriminate: it reads the identifier columns without
     * parsing them, splits anything that looks combined, and adds the
     * operator's corrections. A value that turns out not to be an identifier
     * costs one wasted entry in an IN list; a missed one would let a duplicate
     * through to the unique index instead of being reported in the preview.
     *
     * @return list<string>
     */
    private function candidateIdentifiers(ImportProfile $profile, array $rows, array $byColumn, array $corrections): array
    {
        $indexes = [];
        foreach ($profile->columns() as $column) {
            if ($column->kind !== 'identifier' || ! array_key_exists($column->label, $byColumn)) {
                continue;
            }
            $indexes[] = $byColumn[$column->label];
        }

        $candidates = [];

        $add = function (?string $value) use (&$candidates) {
            foreach (explode('/', (string) $value) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $candidates[$part] = true;
                }
            }
        };

        foreach ($rows as $row) {
            foreach ($indexes as $index) {
                $add($row['values'][$index] ?? null);
            }
        }

        foreach ($corrections as $cells) {
            foreach ((array) $cells as $value) {
                $add(is_scalar($value) ? (string) $value : null);
            }
        }

        return array_keys($candidates);
    }

    /**
     * Hands out the next free asset tag for this profile.
     *
     * The register's own workbook has no asset tag column — it keys on property
     * numbers — so rows usually arrive without one, and the system assigns
     * `ICT-DT-0042` style tags continuing from the highest already issued.
     */
    private function assetTagger(ImportProfile|string $profile): callable
    {
        $prefix = is_string($profile) ? $profile : $profile->assetTagPrefix();

        /*
         * The highest suffix, computed in the database rather than by reading
         * every tag into PHP and sorting there — a register with sixty
         * thousand assets should not have to be loaded to answer this.
         *
         * Cast to an integer rather than compared as text: past ICT-DT-9999 a
         * lexicographic maximum picks "ICT-DT-9999" over "ICT-DT-10000" and
         * starts handing out tags that already exist.
         */
        $next = (int) Equipment::query()
            ->where('asset_tag', 'like', $prefix.'-%')
            ->selectRaw('MAX(CAST(SUBSTRING(asset_tag, ?) AS UNSIGNED)) AS highest', [strlen($prefix) + 2])
            ->value('highest');

        return function () use ($prefix, &$next): string {
            $next++;

            return sprintf('%s-%04d', $prefix, $next);
        };
    }
}
