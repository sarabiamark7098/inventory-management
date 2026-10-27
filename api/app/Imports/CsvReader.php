<?php

namespace App\Imports;

use RuntimeException;

/**
 * Reads a CSV the way Excel actually writes one.
 *
 * Three things go wrong with a spreadsheet exported on a Windows desktop, and
 * all three are handled here rather than being left to surface as a baffling
 * validation error on the first column:
 *
 * 1. **A byte-order mark.** Excel's "CSV UTF-8" prepends EF BB BF, which would
 *    otherwise make the first header `\u{FEFF}Site Name` and match nothing.
 * 2. **Windows-1252.** Plain "CSV" is saved in the system code page, so a
 *    name like Peñaranda arrives as an invalid UTF-8 byte that MySQL rejects.
 *    Anything that is not valid UTF-8 is converted rather than refused.
 * 3. **A semicolon delimiter**, used by Excel wherever the list separator is
 *    not a comma. The delimiter is sniffed from the header line.
 *
 * Rows are read through `fgetcsv` on a stream, not by splitting on newlines,
 * because a Remarks cell containing a line break is normal in this register
 * and splitting would tear the row in half.
 */
final class CsvReader
{
    /** Generous enough for a province-wide register, low enough to bound memory. */
    public const MAX_ROWS = 5000;

    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * @return array{header: list<string>, rows: list<array{line: int, values: list<string>}>, delimiter: string, truncated: bool}
     */
    public function read(string $contents): array
    {
        $contents = $this->decode($contents);

        if (trim($contents) === '') {
            throw new RuntimeException('That file is empty.');
        }

        $delimiter = $this->sniffDelimiter($contents);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $header = null;
        $rows = [];
        $line = 0;
        $truncated = false;

        while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;

            // fgetcsv reports a blank line as [null].
            if ($values === [null]) {
                continue;
            }

            $values = array_map(fn ($v) => $this->clean((string) ($v ?? '')), $values);

            if ($header === null) {
                $header = $values;

                continue;
            }

            // A row of nothing but separators is Excel padding, not a record.
            if (implode('', $values) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }

            $rows[] = ['line' => $line, 'values' => $values];
        }

        fclose($stream);

        if ($header === null) {
            throw new RuntimeException('That file has no header row.');
        }

        return [
            'header' => $header,
            'rows' => $rows,
            'delimiter' => $delimiter,
            'truncated' => $truncated,
        ];
    }

    /** Strips the BOM and brings the bytes into UTF-8. */
    private function decode(string $contents): string
    {
        foreach (["\xEF\xBB\xBF" => 'UTF-8', "\xFF\xFE" => 'UTF-16LE', "\xFE\xFF" => 'UTF-16BE'] as $bom => $encoding) {
            if (str_starts_with($contents, $bom)) {
                $contents = substr($contents, strlen($bom));

                if ($encoding !== 'UTF-8') {
                    return (string) mb_convert_encoding($contents, 'UTF-8', $encoding);
                }

                return $contents;
            }
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            // Windows-1252 rather than ISO-8859-1: it is what Excel on Windows
            // writes, and it maps the curly quotes and dashes that Latin-1 does not.
            return (string) mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }

    /**
     * Picks the delimiter that splits the header line into the most fields.
     *
     * Counting occurrences would be fooled by a header such as
     * `Keyboard / Mouse Available ( K / M )`; splitting and comparing field
     * counts is not, because the winner has to produce more columns.
     */
    private function sniffDelimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\r\n") ?: '';

        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = count(str_getcsv($firstLine, $delimiter, '"', ''));
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** Trims, and flattens the whitespace that pasted cells carry. */
    private function clean(string $value): string
    {
        $value = str_replace("\u{A0}", ' ', $value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
