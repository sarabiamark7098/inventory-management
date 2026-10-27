<?php

namespace App\Imports;

/**
 * Matches a file's header row to a profile's columns by name.
 *
 * Column order does not matter, extra columns are reported rather than fatal,
 * and a header matches if it equals the column's label or any of its aliases
 * once reduced to letters and digits. That reduction is what lets
 * `PROPERTY NO / SN No.`, `Property No / SN No`, and `property_no_sn_no` all
 * land on the same column without listing every punctuation variant.
 *
 * What it reports is as important as what it matches. A government register
 * import that silently ignored a column the operator believed was being read
 * would put a quietly incomplete record into an audited system, so unmatched
 * and duplicate headers come back for display before anything is written.
 */
final class HeaderMatcher
{
    /**
     * @return array{
     *     byColumn: array<string, int>,
     *     matched: list<array{column: string, header: string, index: int}>,
     *     missingRequired: list<string>,
     *     unrecognised: list<array{header: string, index: int}>,
     *     duplicate: list<array{header: string, index: int, column: string}>
     * }
     */
    public function match(ImportProfile $profile, array $header): array
    {
        $lookup = [];
        foreach ($profile->columns() as $column) {
            foreach ($column->acceptedHeaders() as $accepted) {
                $lookup[$this->normalise($accepted)] = $column->label;
            }
        }

        $byColumn = [];
        $matched = [];
        $unrecognised = [];
        $duplicate = [];

        foreach ($header as $index => $raw) {
            // Trailing empty cells are how Excel pads a sheet, not columns.
            if ($this->normalise($raw) === '') {
                continue;
            }

            $label = $lookup[$this->normalise($raw)] ?? null;

            if ($label === null) {
                $unrecognised[] = ['header' => $raw, 'index' => $index];

                continue;
            }

            // The workbook has two columns that both reduce to "remarks". The
            // first wins and the second is reported, rather than overwriting it.
            if (array_key_exists($label, $byColumn)) {
                $duplicate[] = ['header' => $raw, 'index' => $index, 'column' => $label];

                continue;
            }

            $byColumn[$label] = $index;
            $matched[] = ['column' => $label, 'header' => $raw, 'index' => $index];
        }

        $missingRequired = [];
        foreach ($profile->columns() as $column) {
            if (! $column->required || array_key_exists($column->label, $byColumn)) {
                continue;
            }

            // A required field also counts as present when a combined column
            // supplies it — `PROPERTY NO / SN No.` covers Property Number.
            if ($this->suppliedByCombined($profile, $column, $byColumn)) {
                continue;
            }

            $missingRequired[] = $column->label;
        }

        return [
            'byColumn' => $byColumn,
            'matched' => $matched,
            'missingRequired' => $missingRequired,
            'unrecognised' => $unrecognised,
            'duplicate' => $duplicate,
        ];
    }

    private function suppliedByCombined(ImportProfile $profile, ImportColumn $needed, array $byColumn): bool
    {
        foreach ($profile->columns() as $candidate) {
            if (! $candidate->isCombined() || ! array_key_exists($candidate->label, $byColumn)) {
                continue;
            }

            if (array_intersect($needed->targets, $candidate->targets) !== []) {
                return true;
            }
        }

        return false;
    }

    /** Reduces a header to letters and digits, so spacing and punctuation stop mattering. */
    private function normalise(?string $header): string
    {
        $header = mb_strtolower(trim((string) $header));

        return preg_replace('/[^a-z0-9]+/u', '', $header) ?? '';
    }
}
