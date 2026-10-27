<?php

namespace App\Imports;

/**
 * Writes the sample CSV an operator starts from.
 *
 * Only columns marked `template: true` are offered. The combined
 * `PROPERTY NO / SN No.` form is accepted on import but never suggested,
 * because its single-value case is ambiguous — the template's separate
 * Property Number and Serial Number columns are not.
 *
 * The file opens in Excel with a BOM and CRLF line endings. Without the BOM,
 * Excel reads a UTF-8 CSV in the system code page and mangles every accented
 * name in it; it is the difference between the template working on the
 * operator's desktop and appearing to be corrupt.
 */
final class TemplateWriter
{
    /** Sample values are prefixed so an accidentally imported example is obvious and findable. */
    public function write(ImportProfile $profile, bool $withExamples = true): string
    {
        $columns = array_values(array_filter(
            $profile->columns(),
            fn (ImportColumn $column) => $column->template,
        ));

        $rows = [array_map(fn (ImportColumn $c) => $c->label, $columns)];

        if ($withExamples) {
            $rows[] = array_map(fn (ImportColumn $c) => (string) $c->example, $columns);
            $rows[] = $this->secondExample($profile, $columns);
        }

        $stream = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        // Excel needs CRLF, and the BOM to read it as UTF-8.
        return "\xEF\xBB\xBF".str_replace("\n", "\r\n", str_replace("\r\n", "\n", $csv));
    }

    public function filename(ImportProfile $profile, bool $withExamples): string
    {
        $suffix = $withExamples ? 'sample' : 'template';

        return "ict-inventory-{$profile->name()}-{$suffix}.csv";
    }

    /**
     * A second row that differs from the first, so the sample shows a filled-in
     * record next to a sparse one and the optional columns read as optional.
     */
    private function secondExample(ImportProfile $profile, array $columns): array
    {
        $overrides = [
            'Property Number' => 'SAMPLE-FO11-CO1-26-0002',
            'Serial Number' => 'SAMPLE-SN-0002',
            'Monitor Serial Number' => 'SAMPLE-MON-0002',
            'Date Acquired' => '2025-09-30',
            'Brand' => 'Dell',
            'Model' => 'Latitude 3540',
            'PAR Issued To' => 'Ana R. Cruz',
            'Actual User' => '',
            'Position' => 'Administrative Assistant II',
            'Condition' => 'Serviceable',
            'Province' => 'Davao del Sur',
            'Location' => 'ICT Office',
            'Remarks' => 'Replaced keyboard, March 2026',
            'Host Name' => '11-ICT-WS004',
            'AD User' => 'arcruz',
        ];

        return array_map(
            fn (ImportColumn $c) => $overrides[$c->label] ?? (string) $c->example,
            $columns,
        );
    }
}
