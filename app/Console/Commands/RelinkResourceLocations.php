<?php

namespace App\Console\Commands;

use App\Support\Caching\ReadCache;
use App\Support\Geometry\PointInPolygon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild facility, laboratory and cell-site location links from their own coordinates.
 *
 * The imported municipality_id/barangay_id values follow an older municipality numbering
 * (e.g. every Quezon City facility is stored as Maribojoc), so inventories and assessments
 * counted almost nothing while the map, which places pins by coordinates, showed hundreds.
 *
 * Evidence, strongest first: a cell site's source barangay PSGC; the one municipality
 * polygon that contains the coordinates; otherwise the stored ID translated through the
 * old->new numbering learned from the facilities that were placed by polygon. Barangays
 * come from the containing barangay polygon. Links that cannot be resolved become NULL
 * (unknown) rather than staying wrong. Dry run by default; --apply writes a CSV backup first.
 *
 * Order: migration 2026_11_06_000200 (drops the foreign keys to old_* tables), this command
 * with --apply, then migration 2026_11_06_000300 (foreign keys to the current tables).
 * To undo: roll back 000300, run --restore=<csv>, then roll back 000200.
 */
class RelinkResourceLocations extends Command
{
    protected $signature = 'resources:relink-locations
        {--apply : Write the new links (the default is a dry run that changes nothing)}
        {--restore= : Put back the original links from a CSV written by an earlier run}';

    protected $description = 'Rebuild facility, laboratory and cell-site municipality/barangay links from their coordinates';

    private const TABLES = ['facilities', 'laboratories', 'telecom_sites'];

    /** @var array<int, array{0: array, 1: array}> municipality id => [geometry, bounds] */
    private array $municipalities = [];

    public function handle(): int
    {
        if ($this->option('restore')) {
            return $this->restore((string) $this->option('restore'));
        }

        $this->components->info('Loading municipality polygons…');
        foreach (DB::table('municipalities')->whereNotNull('geometry')->select('id', 'geometry')->cursor() as $row) {
            $geometry = PointInPolygon::decode($row->geometry);
            if ($geometry !== null) {
                $this->municipalities[(int) $row->id] = [$geometry, PointInPolygon::bounds($geometry)];
            }
        }

        $plans = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumns($table, ['municipality_id', 'barangay_id', 'longitude', 'latitude'])) {
                $this->components->info("Locating {$table}…");
                $plans[$table] = $this->locateMunicipalities($table);
            }
        }
        $idMap = $this->learnIdMap($plans['facilities'] ?? []);
        foreach ($plans as &$rows) {
            $this->applyIdMap($rows, $idMap);
            $this->locateBarangays($rows);
        }
        unset($rows);

        $this->report($plans, count($idMap));
        $path = $this->writeCsv($plans);
        $this->components->info("Row-by-row plan and original values: {$path}");

        if (! $this->option('apply')) {
            $this->components->warn('Dry run: no rows were changed. Re-run with --apply to write these links.');

            return self::SUCCESS;
        }
        if ($legacy = $this->legacyForeignKeys()) {
            $this->components->error('These constraints still point at the legacy GADM tables and block the update: '.implode(', ', $legacy)
                .'. Run: php artisan migrate --path=database/migrations/2026_11_06_000200_drop_legacy_resource_location_foreign_keys.php');

            return self::FAILURE;
        }

        DB::transaction(function () use ($plans) {
            foreach ($plans as $table => $rows) {
                $this->writeLinks($table, array_filter($rows, fn ($row) => $this->changed($row)), 'mun', 'brgy');
            }
        });
        app(ReadCache::class)->invalidate('domain');
        $this->components->info("Links updated and read caches cleared. Undo with: php artisan resources:relink-locations --restore=\"{$path}\"");

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function locateMunicipalities(string $table): array
    {
        $hasPsgc = Schema::hasColumn($table, 'barangay_psgc');
        $hasOverseas = Schema::hasColumn($table, 'is_outside_philippines');
        $byPsgc = $hasPsgc ? $this->barangaysByPsgc() : [];
        $columns = ['id', 'municipality_id', 'barangay_id', 'longitude', 'latitude', ...($hasPsgc ? ['barangay_psgc'] : []), ...($hasOverseas ? ['is_outside_philippines'] : [])];

        $rows = [];
        foreach (DB::table($table)->select($columns)->orderBy('id')->cursor() as $record) {
            $row = [
                'id' => (int) $record->id,
                'old_mun' => $record->municipality_id === null ? null : (int) $record->municipality_id,
                'old_brgy' => $record->barangay_id === null ? null : (int) $record->barangay_id,
                'mun' => null, 'brgy' => null, 'point' => $this->point($record->longitude, $record->latitude),
                'candidates' => [], 'evidence' => 'no_coordinates',
            ];
            $code = $hasPsgc ? trim((string) $record->barangay_psgc) : '';

            if ($hasOverseas && $record->is_outside_philippines) {
                // Excluded from every Philippine inventory already; leave untouched.
                [$row['mun'], $row['brgy'], $row['evidence']] = [$row['old_mun'], $row['old_brgy'], 'overseas_unchanged'];
            } elseif ($code !== '' && isset($byPsgc[$code])) {
                [$row['brgy'], $row['mun']] = $byPsgc[$code];
                $row['evidence'] = 'source_barangay_psgc';
            } elseif ($row['point'] !== null) {
                $row['candidates'] = $this->containing($this->municipalities, $row['point']);
                if (count($row['candidates']) === 1) {
                    [$row['mun'], $row['evidence']] = [$row['candidates'][0], 'municipality_polygon'];
                } else {
                    $row['evidence'] = $row['candidates'] === [] ? 'outside_all_polygons' : 'overlapping_polygons';
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Old stored ID -> current ID, only where facilities placed by polygon agree and no two old IDs claim one city. */
    private function learnIdMap(array $facilities): array
    {
        $votes = [];
        foreach ($facilities as $row) {
            if ($row['evidence'] === 'municipality_polygon' && $row['old_mun'] !== null) {
                $votes[$row['old_mun']][$row['mun']] = ($votes[$row['old_mun']][$row['mun']] ?? 0) + 1;
            }
        }
        $map = [];
        foreach ($votes as $old => $targets) {
            arsort($targets);
            if (reset($targets) / array_sum($targets) >= 0.9) {
                $map[$old] = array_key_first($targets);
            }
        }
        $claims = array_count_values($map);

        return array_filter($map, fn ($new) => $claims[$new] === 1);
    }

    private function applyIdMap(array &$rows, array $idMap): void
    {
        foreach ($rows as &$row) {
            $mapped = $row['old_mun'] !== null ? ($idMap[$row['old_mun']] ?? null) : null;
            $eligible = in_array($row['evidence'], ['no_coordinates', 'outside_all_polygons'], true)
                || ($row['evidence'] === 'overlapping_polygons' && in_array($mapped, $row['candidates'], true));
            if ($eligible && $mapped !== null) {
                [$row['mun'], $row['evidence']] = [$mapped, 'translated_old_id'];
            }
        }
    }

    private function locateBarangays(array &$rows): void
    {
        $byMunicipality = [];
        foreach ($rows as $index => $row) {
            if ($row['brgy'] === null && $row['mun'] !== null && $row['point'] !== null && $row['evidence'] !== 'overseas_unchanged') {
                $byMunicipality[$row['mun']][] = $index;
            }
        }
        foreach ($byMunicipality as $municipalityId => $indexes) {
            $barangays = [];
            foreach (DB::table('barangays')->where('municipality_id', $municipalityId)->whereNotNull('geometry')->select('id', 'geometry')->cursor() as $barangay) {
                $geometry = PointInPolygon::decode($barangay->geometry);
                if ($geometry !== null) {
                    $barangays[(int) $barangay->id] = [$geometry, PointInPolygon::bounds($geometry)];
                }
            }
            foreach ($indexes as $index) {
                $hits = $this->containing($barangays, $rows[$index]['point']);
                $rows[$index]['brgy'] = count($hits) === 1 ? $hits[0] : null;
            }
        }
    }

    /** @return list<int> */
    private function containing(array $areas, array $point): array
    {
        [$lng, $lat] = $point;
        $hits = [];
        foreach ($areas as $id => [$geometry, $bounds]) {
            if ($bounds !== null && $lng >= $bounds[0] && $lng <= $bounds[2] && $lat >= $bounds[1] && $lat <= $bounds[3]
                && PointInPolygon::contains($geometry, $lng, $lat)) {
                $hits[] = $id;
            }
        }

        return $hits;
    }

    /** @return array<string, array{0: int, 1: int|null}> barangay PSGC => [barangay id, municipality id] */
    private function barangaysByPsgc(): array
    {
        $map = [];
        foreach (DB::table('barangays')->whereNotNull('psg_code')->select('id', 'municipality_id', 'psg_code')->cursor() as $row) {
            $map[(string) $row->psg_code] = [(int) $row->id, $row->municipality_id === null ? null : (int) $row->municipality_id];
        }

        return $map;
    }

    private function point(mixed $lng, mixed $lat): ?array
    {
        if (! is_numeric($lng) || ! is_numeric($lat)) {
            return null;
        }
        [$lng, $lat] = [(float) $lng, (float) $lat];

        return is_finite($lng) && is_finite($lat) && abs($lng) <= 180 && abs($lat) <= 90 && ($lng !== 0.0 || $lat !== 0.0) ? [$lng, $lat] : null;
    }

    /** @return list<string> */
    private function legacyForeignKeys(): array
    {
        $names = [];
        foreach (self::TABLES as $table) {
            foreach (Schema::hasTable($table) ? Schema::getForeignKeys($table) : [] as $key) {
                if (str_starts_with($key['foreign_table'], 'old_')) {
                    $names[] = "{$table}.{$key['name']}";
                }
            }
        }

        return $names;
    }

    private function changed(array $row): bool
    {
        return $row['mun'] !== $row['old_mun'] || $row['brgy'] !== $row['old_brgy'];
    }

    private function report(array $plans, int $mappedIds): void
    {
        $this->newLine();
        $this->line("Old->current municipality numbering learned from facilities: {$mappedIds} IDs");
        $evidence = [];
        $summary = [];
        foreach ($plans as $table => $rows) {
            $counts = array_count_values(array_column($rows, 'evidence'));
            foreach ($counts as $key => $count) {
                $evidence[$key][$table] = $count;
            }
            $summary[] = [
                $table, count($rows),
                count(array_filter($rows, fn ($row) => ! $this->changed($row))),
                count(array_filter($rows, fn ($row) => $row['mun'] !== $row['old_mun'])),
                count(array_filter($rows, fn ($row) => $row['brgy'] !== $row['old_brgy'])),
                count(array_filter($rows, fn ($row) => $row['mun'] === null)),
            ];
        }
        $this->table(['Table', 'Rows', 'Unchanged', 'Municipality fixed', 'Barangay changed', 'No municipality'], $summary);
        $this->table(['Evidence', ...array_keys($plans)], array_map(
            fn ($key) => [$key, ...array_map(fn ($table) => $evidence[$key][$table] ?? 0, array_keys($plans))],
            array_keys($evidence),
        ));

        $before = array_count_values(array_filter(array_column($plans['facilities'] ?? [], 'old_mun'), 'is_int'));
        $after = array_count_values(array_filter(array_column($plans['facilities'] ?? [], 'mun'), 'is_int'));
        arsort($after);
        $names = DB::table('municipalities')->whereIn('id', array_slice(array_keys($after), 0, 10))->pluck('name', 'id');
        $this->table(['Municipality (most facilities)', 'Facilities now linked', 'After relink'], array_map(
            fn ($id) => [$names[$id] ?? "#{$id}", $before[$id] ?? 0, $after[$id]],
            array_slice(array_keys($after), 0, 10),
        ));
    }

    private function writeCsv(array $plans): string
    {
        $directory = storage_path('app/diagnostics');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.'/resource-relink-'.now()->format('Ymd-His').'.csv';
        $file = fopen($path, 'w');
        fputcsv($file, ['table', 'id', 'old_municipality_id', 'old_barangay_id', 'new_municipality_id', 'new_barangay_id', 'evidence'], ',', '"', '');
        foreach ($plans as $table => $rows) {
            foreach ($rows as $row) {
                fputcsv($file, [$table, $row['id'], $row['old_mun'], $row['old_brgy'], $row['mun'], $row['brgy'], $row['evidence']], ',', '"', '');
            }
        }
        fclose($file);

        return $path;
    }

    private function restore(string $path): int
    {
        if (! is_file($path) || ($file = fopen($path, 'r')) === false) {
            $this->components->error("Backup not found: {$path}");

            return self::FAILURE;
        }
        fgetcsv($file, null, ',', '"', '');
        $byTable = [];
        while (($line = fgetcsv($file, null, ',', '"', '')) !== false) {
            [$table, $id, $oldMun, $oldBrgy] = $line;
            if (in_array($table, self::TABLES, true)) {
                $byTable[$table][] = ['id' => (int) $id, 'old_mun' => $oldMun === '' ? null : (int) $oldMun, 'old_brgy' => $oldBrgy === '' ? null : (int) $oldBrgy];
            }
        }
        fclose($file);
        DB::transaction(function () use ($byTable) {
            foreach ($byTable as $table => $rows) {
                $this->writeLinks($table, $rows, 'old_mun', 'old_brgy');
            }
        });
        app(ReadCache::class)->invalidate('domain');
        $this->components->info('Original links restored from '.$path);

        return self::SUCCESS;
    }

    private function writeLinks(string $table, array $rows, string $municipalityKey, string $barangayKey): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[($row[$municipalityKey] ?? 'null').'|'.($row[$barangayKey] ?? 'null')][] = $row['id'];
        }
        foreach ($groups as $key => $ids) {
            [$municipality, $barangay] = array_map(fn ($value) => $value === 'null' ? null : (int) $value, explode('|', $key));
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table($table)->whereIn('id', $chunk)->update(['municipality_id' => $municipality, 'barangay_id' => $barangay]);
            }
        }
        $this->line(sprintf('  %s: %s rows written', $table, number_format(count($rows))));
    }
}
