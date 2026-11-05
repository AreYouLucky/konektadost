<?php

namespace App\Support\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the KonektaDOST workbook.
 *
 * Layout: field names run ACROSS row 1 and each subsequent row is one record,
 * starting at row 2.
 *
 * Two traps this class exists to handle:
 *
 *  1. Trailing rows are padded, not empty data. Evidence Source reports a
 *     highest data row of 254 but holds 25 records, so the row count cannot be
 *     used as a record count.
 *  2. Trailing columns are padded too, so the header scan cannot assume a
 *     fixed width.
 *
 * Cells are addressed by string coordinate throughout. PhpSpreadsheet also
 * accepts an array, but in [column, row] order rather than [row, column];
 * mixing the two transposes the sheet silently.
 */
class KonektaWorkbook
{
    public function __construct(private readonly string $path) {}

    /**
     * Read one sheet into a list of associative records, keyed by the row 1
     * header of each column.
     *
     * Blank cells are normalised to null so callers can test presence rather
     * than compare empty strings.
     *
     * @return list<array<string, string|null>>
     */
    public function records(string $sheetName): array
    {
        $sheet = $this->sheet($sheetName);

        if ($sheet === null) {
            return [];
        }

        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $headers = $this->headers($sheet, $highestColumn);

        if ($headers === []) {
            return [];
        }

        $records = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $record = [];
            $populated = false;

            foreach ($headers as $column => $header) {
                $value = $this->value($sheet, $column, $row);

                $record[$header] = $value;

                if ($value !== null) {
                    $populated = true;
                }
            }

            // Padded rows are skipped rather than imported as blank records.
            if ($populated) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The field names in row 1, keyed by column index.
     *
     * @return array<int, string>
     */
    private function headers(Worksheet $sheet, int $highestColumn): array
    {
        $headers = [];

        for ($column = 1; $column <= $highestColumn; $column++) {
            $header = $this->value($sheet, $column, 1);

            if ($header !== null) {
                $headers[$column] = $header;
            }
        }

        return $headers;
    }

    private function sheet(string $name): ?Worksheet
    {
        if (! is_readable($this->path)) {
            return null;
        }

        return IOFactory::load($this->path)->getSheetByName($name);
    }

    private function value(Worksheet $sheet, int $column, int $row): ?string
    {
        $value = trim($sheet->getCell(
            Coordinate::stringFromColumnIndex($column).$row
        )->getFormattedValue());

        return $value === '' ? null : $value;
    }
}
