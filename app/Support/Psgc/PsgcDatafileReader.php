<?php

namespace App\Support\Psgc;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

/**
 * Reads the PSA PSGC publication datafile.
 *
 * The datafile stores one geographic unit per row with attributes in columns,
 * and it carries things that are not units at all: a footnote sits at the
 * bottom of the same data block, and two rows have no geographic level. Those
 * are rejected on their own merits rather than by row number, because the file
 * is republished quarterly and the positions move.
 *
 * Columns are located by header text rather than by index, so PSA can reorder
 * or insert a column without silently shifting every value into the wrong
 * field.
 */
class PsgcDatafileReader
{
    private const SHEET = 'PSGC';

    private const HEADER_ROW = 1;

    /** The attributes we read, and the header text that identifies each. */
    private const COLUMNS = [
        'psgc10' => '10-digit psgc',
        'name' => 'name',
        'correspondence_code' => 'correspondence code',
        'level' => 'geographic level',
        'old_name' => 'old names',
        'city_class' => 'city class',
        'income_class' => 'income classification',
        'urban_rural' => 'urban / rural',
        'population_2024' => '2024 population',
        'status' => 'status',
    ];

    /**
     * @return array{
     *     label: string,
     *     publication_date: string|null,
     *     units: list<PsgcUnitData>,
     *     rejected: array<string, int>,
     *     rows_read: int
     * }
     */
    public function read(string $path, string $label): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("PSGC datafile not readable: {$path}");
        }

        $spreadsheet = (new Xlsx)->setReadDataOnly(true)->load($path);

        if ($spreadsheet->getSheetByName(self::SHEET) === null) {
            throw new RuntimeException('PSGC datafile has no "'.self::SHEET.'" sheet.');
        }

        $sheet = $spreadsheet->getSheetByName(self::SHEET);
        $lastColumn = $sheet->getHighestColumn();
        // field => column letter, resolved once from the header row.
        $columns = $this->locateColumns($sheet, $lastColumn);

        $units = [];
        $rejected = [];
        $rowsRead = 0;

        foreach ($sheet->getRowIterator(self::HEADER_ROW + 1) as $row) {
            $rowsRead++;

            $cells = $this->rowValues($row, $lastColumn);

            $values = [];

            foreach ($columns as $field => $index) {
                $values[$field] = $this->text($cells[$index] ?? null);
            }

            $psgc10 = (string) ($values['psgc10'] ?? '');

            // Trailing formatting rows can be entirely empty.
            if ($psgc10 === '' && ($values['name'] ?? '') === '') {
                $rowsRead--;

                continue;
            }

            $reason = $this->rejectReason($psgc10, $values);

            if ($reason !== null) {
                $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;

                continue;
            }

            $units[] = new PsgcUnitData(
                psgc10: $psgc10,
                name: (string) $values['name'],
                correspondenceCode: $values['correspondence_code'] !== ''
                    ? str_pad((string) $values['correspondence_code'], 9, '0', STR_PAD_LEFT)
                    : null,
                level: (string) $values['level'],
                oldName: $values['old_name'] !== '' ? (string) $values['old_name'] : null,
                cityClass: $values['city_class'] !== '' ? (string) $values['city_class'] : null,
                // Kept verbatim: "-" and the "2nd*" style markers are PSA
                // distinctions, not formatting noise.
                incomeClass: $values['income_class'] !== '' ? (string) $values['income_class'] : null,
                urbanRural: $values['urban_rural'] !== '' ? (string) $values['urban_rural'] : null,
                population2024: $this->population($values['population_2024'] ?? ''),
                status: $values['status'] !== '' ? (string) $values['status'] : null,
            );
        }

        $publicationDate = $this->publicationDate($spreadsheet);

        $spreadsheet->disconnectWorksheets();

        return [
            'label' => $label,
            'publication_date' => $publicationDate,
            'units' => $units,
            'rejected' => $rejected,
            'rows_read' => $rowsRead,
        ];
    }

    /**
     * The publication date from the Metadata sheet, when present.
     */
    private function publicationDate(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): ?string
    {
        if (! in_array('Metadata', $spreadsheet->getSheetNames(), true)) {
            return null;
        }

        $sheet = $spreadsheet->getSheetByName('Metadata');
        $lastColumn = $sheet->getHighestColumn();

        foreach ($sheet->getRowIterator(1, 40) as $row) {
            $cells = $this->rowValues($row, $lastColumn);
            $label = $this->text($cells['A'] ?? null);
            $value = $this->text($cells['B'] ?? null);

            if (stripos($label, 'publication date') !== false && $value !== '') {
                $timestamp = strtotime($value);

                return $timestamp === false ? null : date('Y-m-d', $timestamp);
            }
        }

        return null;
    }

    /**
     * Map each attribute to its column letter by header text.
     *
     * @return array<string, string>
     */
    private function locateColumns(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $lastColumn,
    ): array {
        $highest = Coordinate::columnIndexFromString($lastColumn);

        // Read through the row iterator rather than getCell(): the sheet is
        // loaded in data-only mode, where cells are streamed and not hydrated.
        $header = $sheet->getRowIterator(self::HEADER_ROW, self::HEADER_ROW)->current();

        $byHeader = [];

        foreach ($this->rowValues($header, $lastColumn) as $index => $value) {
            $text = $this->normaliseHeader((string) $value);

            if ($text !== '') {
                $byHeader[$text] = $index;
            }
        }

        $columns = [];
        $missing = [];

        foreach (self::COLUMNS as $field => $needle) {
            $found = null;

            foreach ($byHeader as $label => $index) {
                // Headers carry footnotes such as "(based on 2024 CPH)", so the
                // match is on the leading text rather than the whole header.
                if ($label === $needle || str_starts_with($label, $needle)) {
                    $found = $index;

                    break;
                }
            }

            if ($found === null) {
                $missing[] = $needle;
            } else {
                $columns[$field] = $found;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'PSGC datafile is missing expected column(s): '.implode(', ', $missing)
            );
        }

        return $columns;
    }

    /**
     * One row as column letter => raw value.
     *
     * The sheet is loaded in data-only mode, where the cell collection is not
     * hydrated, so cells have to be streamed through the row's cell iterator.
     *
     * @return array<string, mixed>
     */
    private function rowValues(
        \PhpOffice\PhpSpreadsheet\Worksheet\Row $row,
        string $lastColumn,
    ): array {
        $values = [];

        foreach ($row->getCellIterator('A', $lastColumn, false) as $cell) {
            $values[$cell->getColumn()] = $cell->getValue();
        }

        return $values;
    }

    /**
     * Why a row is not a geographic unit, or null when it is one.
     */
    private function rejectReason(string $psgc10, array $values): ?string
    {
        if ($psgc10 === '' || ! preg_match('/^\d{10}$/', $psgc10)) {
            // Catches the footnote that PSA leaves in the data block.
            return 'not_a_psgc_code';
        }

        $name = trim((string) ($values['name'] ?? ''));

        if ($name === '') {
            return 'missing_name';
        }

        $level = trim((string) ($values['level'] ?? ''));

        if ($level === '') {
            // "City of Isabela (Not a Province)" and "Special Geographic
            // Area" carry a valid code but no level, so they cannot be placed
            // in the hierarchy.
            return 'no_geographic_level';
        }

        if (! in_array($level, PsgcUnitData::LEVELS, true)) {
            return 'unknown_geographic_level';
        }

        return null;
    }

    /**
     * Population is published as text, and is "#N/A" for a few units.
     */
    private function population(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Flatten a header so "Income\nClassification (DOF DO No. 074.2024)"
     * becomes "income classification (dof do no. 074.2024)".
     */
    private function normaliseHeader(string $header): string
    {
        $header = str_replace(["\r", "\n", "\t"], ' ', $header);
        $header = preg_replace('/\s+/u', ' ', $header) ?? $header;

        return mb_strtolower(trim($header));
    }

    /**
     * PSA publishes numbers as text and blanks as empty strings or nulls.
     */
    private function text(mixed $value): string
    {
        return $value === null ? '' : trim((string) $value);
    }
}
