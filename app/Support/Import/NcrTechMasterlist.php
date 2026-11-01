<?php

namespace App\Support\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the NCR DOST Deployed Technologies masterlist.
 *
 * Layout: the first rows are a title block, the field names run across a
 * header row partway down, and each subsequent row is one record.
 *
 * The "Technology Catalog" sheet carries its header on row 4 with data from
 * row 5; the "DOST Deployed Technologies" sheet uses the same shape. The
 * header row is therefore a parameter rather than a constant.
 *
 * Same two traps as KonektaWorkbook, which this mirrors:
 *
 *  1. Trailing rows can be padded rather than empty, so the reported highest
 *     row is not the record count.
 *  2. Trailing columns are padded too, so the header scan cannot assume a
 *     fixed width.
 *
 * Cells are addressed by string coordinate throughout, because PhpSpreadsheet
 *'s array form is in [column, row] order and mixing the two transposes the
 * sheet silently.
 */
class NcrTechMasterlist
{
    public function __construct(private readonly string $path) {}

    /**
     * The technology catalogue, keyed by the header of each column.
     *
     * @return list<array<string, string|null>>
     */
    public function catalog(): array
    {
        return $this->records('Technology Catalog');
    }

    /**
     * Read one sheet into a list of associative records.
     *
     * @param  int  $headerRow  The row holding the field names; data starts on the next row.
     * @return list<array<string, string|null>>
     */
    public function records(string $sheetName, int $headerRow = 4): array
    {
        $sheet = $this->sheet($sheetName);

        if ($sheet === null) {
            return [];
        }

        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $headers = $this->headers($sheet, $highestColumn, $headerRow);

        if ($headers === []) {
            return [];
        }

        $records = [];
        $lastRow = $sheet->getHighestDataRow();

        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $record = [];
            $populated = false;

            foreach ($headers as $column => $header) {
                $value = $this->value($sheet, $column, $row);

                $record[$header] = $value;

                if ($value !== null) {
                    $populated = true;
                }
            }

            // Padded or trailing blank rows are skipped rather than imported
            // as empty records.
            if ($populated) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The field names on the header row, keyed by column index.
     *
     * @return array<int, string>
     */
    private function headers(Worksheet $sheet, int $highestColumn, int $headerRow): array
    {
        $headers = [];

        for ($column = 1; $column <= $highestColumn; $column++) {
            $header = $this->value($sheet, $column, $headerRow);

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
