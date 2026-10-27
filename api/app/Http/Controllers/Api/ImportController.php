<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\EquipmentImporter;
use App\Imports\ImportProfile;
use App\Imports\ImportProfileRegistry;
use App\Imports\TemplateWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk registration from a CSV, one profile per kind of ICT equipment.
 *
 * Two steps, always in this order from the UI: `preview` reports what a file
 * would do and writes nothing, then `store` writes it. They run identical
 * validation, so the preview is not an approximation of the result.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly ImportProfileRegistry $registry,
        private readonly EquipmentImporter $importer,
        private readonly TemplateWriter $templates,
    ) {}

    /** The available import types and their column documentation. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (ImportProfile $profile) => $profile->toArray(),
                $this->registry->all(),
            ),
            'maxRows' => \App\Imports\CsvReader::MAX_ROWS,
        ]);
    }

    /**
     * Downloads the sample CSV for a type.
     *
     * `?headersOnly=1` leaves out the example rows, for an operator who would
     * rather start from an empty sheet than delete them.
     */
    public function template(Request $request, string $profile): StreamedResponse|JsonResponse
    {
        $resolved = $this->profile($profile);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $withExamples = ! $request->boolean('headersOnly');
        $csv = $this->templates->write($resolved, $withExamples);
        $filename = $this->templates->filename($resolved, $withExamples);

        return response()->streamDownload(
            fn () => print($csv),
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // The UI reads the filename back off the response.
                'Access-Control-Expose-Headers' => 'Content-Disposition',
            ],
        );
    }

    public function preview(Request $request, string $profile): JsonResponse
    {
        return $this->run($request, $profile, commit: false);
    }

    public function store(Request $request, string $profile): JsonResponse
    {
        return $this->run($request, $profile, commit: true);
    }

    private function run(Request $request, string $profile, bool $commit): JsonResponse
    {
        $resolved = $this->profile($profile);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $validator = Validator::make($request->all(), [
            // The MIME type of a CSV is reported as any of half a dozen things
            // depending on what is installed on the desktop, so the extension
            // and the size are what get checked.
            'file' => ['required', 'file', 'max:8192'],
            'division' => ['nullable', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'condition' => ['nullable', 'string', 'max:50'],
            'parFallback' => ['nullable', 'boolean'],
            // JSON, because it rides along with a multipart file upload.
            'corrections' => ['nullable', 'string', 'max:2000000'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $file = $request->file('file');
            if (! $file) {
                return;
            }

            $extension = mb_strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, ['csv', 'txt'], true)) {
                $validator->errors()->add(
                    'file',
                    'Import takes a CSV file. Save the sheet as "CSV UTF-8 (Comma delimited)" and upload that '
                    .'— an .xlsx holds formatting and formulas this cannot read.'
                );
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $corrections = $this->corrections($request);

        if ($corrections === false) {
            return response()->json([
                'message' => 'The corrections could not be read. Reload the page and preview the file again.',
            ], 422);
        }

        $contents = (string) file_get_contents($request->file('file')->getRealPath());

        $defaults = array_filter([
            'division' => $request->string('division')->trim()->value() ?: null,
            'section' => $request->string('section')->trim()->value() ?: null,
            'province' => $request->string('province')->trim()->value() ?: null,
            'status' => $request->string('status')->trim()->value() ?: null,
            'condition' => $request->string('condition')->trim()->value() ?: null,
            'parFallback' => $request->boolean('parFallback') ?: null,
        ], fn ($value) => $value !== null);

        try {
            $report = $commit
                ? $this->importer->commit($resolved, $contents, $defaults, $corrections)
                : $this->importer->analyse($resolved, $contents, $defaults, $corrections);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($report, isset($report['fatal']) ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * Per-line cell corrections made on the review screen.
     *
     * These replace cells, never verdicts: the file is read and validated again
     * with them in place, so a corrected row has to satisfy exactly the rules
     * the original did. Anything that is not a line number mapped to a set of
     * column/value pairs is dropped rather than trusted.
     *
     * @return array<string, array<string, string>>|false
     */
    private function corrections(Request $request): array|false
    {
        $raw = $request->input('corrections');

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return false;
        }

        $clean = [];

        foreach ($decoded as $line => $cells) {
            if (! is_array($cells) || ! is_numeric($line)) {
                continue;
            }

            foreach ($cells as $column => $value) {
                if (! is_string($column) || is_array($value) || is_object($value)) {
                    continue;
                }

                $clean[(string) (int) $line][$column] = (string) $value;
            }
        }

        return $clean;
    }

    private function profile(string $name): ImportProfile|JsonResponse
    {
        $profile = $this->registry->find($name);

        if (! $profile) {
            return response()->json([
                'message' => "There is no import type called \"{$name}\".",
                'available' => $this->registry->names(),
            ], 404);
        }

        return $profile;
    }
}
