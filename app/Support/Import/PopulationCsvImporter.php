<?php

namespace App\Support\Import;

use App\Models\Barangay;
use App\Models\DataVintage;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use App\Models\PopulationFigure;
use Illuminate\Support\Facades\DB;

/**
 * Validates and applies a population CSV import.
 *
 * A two-step flow: `preview()` reports what would change without writing
 * anything, and `commit()` applies it. Validation failures are returned rather
 * than thrown, so the operator sees every problem at once instead of fixing
 * them one reload at a time.
 *
 * Expected columns (case-insensitive, order-independent):
 *   area_level, area_ref, population, census_year
 * Optional: area_name, province, municipality, psgc10, psgc_corr,
 *           figure_basis, match_status, city_class, income_class,
 *           urban_rural, source, source_url
 */
class PopulationCsvImporter
{
    /** Number of rows a full national file is expected to contain. */
    private const EXPECTED_MUNICIPALITIES = 1628;

    private const EXPECTED_BARANGAYS = 41929;

    /**
     * @return array<string, array<int, string>> Normalised header => line numbers.
     */
    private array $errors = [];

    /** @var list<array<string, mixed>> Parsed rows. */
    private array $rows = [];

    private string $filename = '';

    private string $vintage = '';

    private int $censusYear = 0;

    /**
     * Parse and validate without writing.
     *
     * @return array<string, mixed>
     */
    public function preview(string $path, string $originalName): array
    {
        $this->reset();

        $this->filename = $originalName;

        if (! is_readable($path)) {
            $this->errors['_file'][] = 'The uploaded file could not be read.';

            return $this->result();
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->errors['_file'][] = 'The uploaded file could not be opened.';

            return $this->result();
        }

        $header = fgetcsv($handle);

        if ($header === false || $header === null) {
            fclose($handle);
            $this->errors['_file'][] = 'The file is empty.';

            return $this->result();
        }

        $map = $this->mapHeader($header);
        $line = 1;

        while (($record = fgetcsv($handle)) !== false) {
            $line++;

            if ($record === [null] || $record === []) {
                continue;
            }

            $this->rows[] = $this->hydrate($record, $map, $line);
        }

        fclose($handle);

        $this->validate();

        return $this->result();
    }

    /**
     * Apply a validated file.
     *
     * @return array<string, mixed>
     */
    public function commit(string $path, string $vintage, ?int $userId = null, bool $makeCurrent = true): array
    {
        $result = $this->preview($path, $this->filename ?: basename($path));

        $result['vintage'] = $vintage !== '' ? $vintage : $this->vintage;

        if (! $result['valid']) {
            return $result;
        }

        $vintageKey = $result['vintage'];

        DB::transaction(function () use ($vintageKey, $userId, $makeCurrent) {
            $record = DataVintage::updateOrCreate(
                ['vintage' => $vintageKey],
                [
                    'label' => $this->labelFor($vintageKey),
                    'source' => $this->rows[0]['source'] ?? 'psa',
                    'census_year' => $this->censusYear ?: null,
                    'imported_at' => now(),
                    'imported_by' => $userId,
                ]
            );

            $inserted = 0;
            $updated = 0;

            foreach ($this->rows as $row) {
                $existing = PopulationFigure::query()
                    ->where('area_level', $row['area_level'])
                    ->where('area_ref', $row['area_ref'])
                    ->where('vintage', $vintageKey)
                    ->first();

                if ($existing) {
                    // Never overwrite a corrected figure with the raw file
                    // value; the correction is the deliberate choice.
                    $existing->fill([
                        'population' => $row['population'],
                        'match_status' => $row['match_status'],
                        'area_name' => $row['area_name'],
                        'census_year' => $row['census_year'],
                    ]);
                    $existing->save();

                    $updated++;

                    continue;
                }

                $new = new PopulationFigure;
                $new->fill($row);
                $new->vintage = $vintageKey;
                $new->save();

                $inserted++;
            }

            if ($makeCurrent) {
                $record->markCurrent();
            }

            $this->inserted = $inserted;
            $this->updated = $updated;
        });

        $result['inserted'] = $this->inserted;
        $result['updated'] = $this->updated;
        $result['committed'] = true;

        return $result;
    }

    private int $inserted = 0;

    private int $updated = 0;

    /**
     * Map the file header to canonical column names.
     *
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $map = [];

        foreach ($header as $index => $name) {
            $key = str(trim((string) $name))->lower()->replace([' ', '-'], '_')->toString();

            if ($key !== '') {
                $map[$key] = $index;
            }
        }

        foreach (['area_level', 'area_ref', 'population'] as $required) {
            if (! array_key_exists($required, $map)) {
                $this->errors['_header'][] = "Missing required column: {$required}.";
            }
        }

        return $map;
    }

    /**
     * Turn one CSV record into a row, validating as we go.
     *
     * @param  array<int, string|null>  $record
     * @param  array<string, int>  $map
     * @return array<string, mixed>
     */
    private function hydrate(array $record, array $map, int $line): array
    {
        $get = function (string $key) use ($record, $map): string {
            return isset($map[$key]) ? trim((string) ($record[$map[$key]] ?? '')) : '';
        };

        $level = $get('area_level');

        if (! in_array($level, [PlaceDescription::LEVEL_MUNICIPALITY, PlaceDescription::LEVEL_BARANGAY], true)) {
            $this->errors['area_level'][] = "Line {$line}: area_level must be 'municipality' or 'barangay'.";
            $level = $level === '' ? PlaceDescription::LEVEL_MUNICIPALITY : $level;
        }

        $areaRef = $get('area_ref');

        if ($areaRef === '') {
            $this->errors['area_ref'][] = "Line {$line}: area_ref is required.";
        } else {
            $this->assertKnownArea($level, $areaRef, $line);
        }

        $populationRaw = $get('population');

        if ($populationRaw === '') {
            $this->errors['population'][] = "Line {$line}: population is required.";
            $population = null;
        } elseif (! is_numeric($populationRaw) || (int) $populationRaw < 0) {
            $this->errors['population'][] = "Line {$line}: population must be a non-negative integer.";
            $population = null;
        } else {
            $population = (int) $populationRaw;
        }

        $censusYear = $get('census_year');

        if ($censusYear !== '' && ! is_numeric($censusYear)) {
            $this->errors['census_year'][] = "Line {$line}: census_year must be a number.";
            $censusYear = '';
        }

        if ($this->vintage === '' && $get('vintage') !== '') {
            $this->vintage = $get('vintage');
        }

        if ($this->censusYear === 0 && is_numeric($censusYear)) {
            $this->censusYear = (int) $censusYear;
        }

        return [
            'area_level' => $level,
            'area_ref' => $areaRef,
            'population' => $population,
            'census_year' => is_numeric($censusYear) ? (int) $censusYear : null,
            'area_name' => $get('area_name') ?: null,
            'province' => $get('province') ?: null,
            'municipality' => $get('municipality') ?: null,
            'psgc10' => $get('psgc10') ?: null,
            'psgc_corr' => $get('psgc_corr') ?: null,
            'figure_basis' => $get('figure_basis') ?: null,
            'match_status' => $get('match_status') ?: ($population === null ? PlaceDescription::MATCH_UNMATCHED_NAME : PlaceDescription::MATCH_MATCHED),
            'city_class' => $get('city_class') ?: null,
            'income_class' => $get('income_class') ?: null,
            'urban_rural' => $get('urban_rural') ?: null,
            'source' => $get('source') ?: 'psa',
            'source_url' => $get('source_url') ?: null,
            'vintage' => $get('vintage') ?: $this->vintage,
        ];
    }

    /**
     * Confirm the GADM id exists at the declared level.
     *
     * A typo'd area_ref would otherwise create a figure for nowhere.
     */
    private function assertKnownArea(string $level, string $areaRef, int $line): void
    {
        $exists = $level === PlaceDescription::LEVEL_MUNICIPALITY
            ? Municipality::query()->where('gadm_id', $areaRef)->exists()
            : Barangay::query()->where('gadm_id', $areaRef)->exists();

        if (! $exists) {
            $this->errors['area_ref'][] = "Line {$line}: no {$level} found with GADM id {$areaRef}.";
        }
    }

    /**
     * Whole-file checks that only make sense once every row is parsed.
     */
    private function validate(): void
    {
        $seen = [];
        $duplicates = [];

        foreach ($this->rows as $row) {
            $key = $row['area_level'].'|'.$row['area_ref'];

            if ($row['area_ref'] === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $duplicates[$key] = ($duplicates[$key] ?? 1) + 1;
            }

            $seen[$key] = true;
        }

        foreach ($duplicates as $key => $count) {
            $this->errors['duplicate'][] = "{$key} appears {$count} times. Each area may appear once per file.";
        }

        if ($this->vintage === '') {
            $this->errors['vintage'][] = 'No vintage found. Supply one, or include a vintage column.';
        }

        // Volume warnings, not errors: a provincial extract is a legitimate
        // import even though it is nowhere near a national file.
        $municipalities = count(array_filter($this->rows, fn ($r) => $r['area_level'] === PlaceDescription::LEVEL_MUNICIPALITY));
        $barangays = count($this->rows) - $municipalities;

        if ($municipalities > 0 && $municipalities < self::EXPECTED_MUNICIPALITIES) {
            $this->warnings[] = "File contains {$municipalities} municipality rows; a national file has ".self::EXPECTED_MUNICIPALITIES.'.';
        }

        if ($barangays > 0 && $barangays < self::EXPECTED_BARANGAYS) {
            $this->warnings[] = "File contains {$barangays} barangay rows; a national file has ".self::EXPECTED_BARANGAYS.'.';
        }
    }

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @return array<string, mixed>
     */
    private function result(): array
    {
        $total = count($this->rows);

        return [
            'valid' => $this->errors === [],
            'filename' => $this->filename,
            'vintage' => $this->vintage,
            'census_year' => $this->censusYear ?: null,
            'rows_total' => $total,
            'rows_municipality' => count(array_filter($this->rows, fn ($r) => $r['area_level'] === PlaceDescription::LEVEL_MUNICIPALITY)),
            'rows_barangay' => count(array_filter($this->rows, fn ($r) => $r['area_level'] === PlaceDescription::LEVEL_BARANGAY)),
            'rows_with_population' => count(array_filter($this->rows, fn ($r) => $r['population'] !== null)),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'committed' => false,
        ];
    }

    private function reset(): void
    {
        $this->errors = [];
        $this->rows = [];
        $this->warnings = [];
        $this->vintage = '';
        $this->censusYear = 0;
        $this->inserted = 0;
        $this->updated = 0;
    }

    private function labelFor(string $vintage): string
    {
        return $this->censusYear > 0
            ? "{$this->censusYear} Census Population and Housing (POPCEN)"
            : $vintage;
    }
}
