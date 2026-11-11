<?php

namespace App\Imports;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads the first sheet of an uploaded CSV or Excel file into a header row and
 * data rows, as strings (dates as YYYY-MM-DD).
 *
 * CSVs come in many shapes: Excel's "CSV" is Windows-1252, not UTF-8; some
 * regions use semicolons. Both are detected rather than asked about.
 */
final class SpreadsheetFile
{
    /**
     * @param  list<string>  $headers
     * @param  list<array{line: int, cells: list<string>}>  $rows  line = the row number as the person sees it in their spreadsheet
     */
    private function __construct(
        public readonly array $headers,
        public readonly array $rows,
    ) {}

    public static function read(UploadedFile $file, int $maxRows): self
    {
        $path = $file->getRealPath();
        $isExcel = in_array(strtolower($file->getClientOriginalExtension()), ['xlsx'], true);

        $reader = $isExcel ? new XlsxReader : new CsvReader(self::csvOptions($path));
        $reader->open($path);

        $headers = null;
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    $cells = array_map(self::cellToString(...), $row->toArray());
                    if ($headers === null) {
                        if (implode('', $cells) === '') {
                            continue; // blank lines above the header
                        }
                        $headers = array_map(fn ($h) => trim($h), $cells);

                        continue;
                    }
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    if (count($rows) >= $maxRows) {
                        throw ValidationException::withMessages(['file' => 'This file has more than '.number_format($maxRows).' machines. Split it into smaller files and import them one after another.']);
                    }
                    $rows[] = ['line' => $index, 'cells' => $cells];
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        if ($headers === null) {
            throw ValidationException::withMessages(['file' => 'This file is empty.']);
        }

        return new self($headers, $rows);
    }

    /** UTF-8 (with or without a BOM) or Excel's Windows-1252; comma, semicolon or tab. */
    private static function csvOptions(string $path): CsvOptions
    {
        $sample = (string) file_get_contents($path, false, null, 0, 64 * 1024);
        $options = new CsvOptions;

        if (! mb_check_encoding($sample, 'UTF-8')) {
            $options->ENCODING = 'Windows-1252';
        }

        // The delimiter that splits the header line into the most columns.
        $firstLine = strtok(ltrim($sample, "\xEF\xBB\xBF"), "\r\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $options->FIELD_DELIMITER = (string) array_key_first($counts);

        return $options;
    }

    private static function cellToString(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value => (string) (int) $value, // 24001.0 -> "24001"
            is_bool($value) => $value ? 'yes' : 'no',
            default => trim((string) $value),
        };
    }
}
