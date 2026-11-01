<?php

namespace App\Support\Map;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Read-only map access to both the imported GADM schema and the newer PSGC schema. */
class GeographyExplorer
{
    private array $columns = [];

    private array $records = [];

    public function locations(string $level, ?int $parentId = null, bool $geometry = false): array
    {
        $query = $this->query($level);

        if ($level !== 'province') {
            $parentLevel = $level === 'municipality' ? 'province' : 'municipality';
            $parent = $this->record($parentLevel, $parentId);
            $this->forParent($query, $level, $parent);
        }

        $rows = $query->orderBy($this->nameColumn($level))->get($this->selectColumns($level, $geometry));

        return [
            'locations' => $rows->map(fn (Model $row) => $this->summary($level, $row))->values()->all(),
            'boundaries' => [
                'type' => 'FeatureCollection',
                'features' => $geometry
                    ? $rows->map(fn (Model $row) => $this->feature($level, $row))->filter()->values()->all()
                    : [],
            ],
        ];
    }

    public function selection(string $level, int $id, bool $geometry = true): array
    {
        $row = $this->record($level, $id, $geometry);

        return [
            'path' => $this->path($level, $row),
            'boundary' => $geometry ? $this->feature($level, $row) : null,
        ];
    }

    public function search(string $term): array
    {
        // Bound parameters plus escaped LIKE wildcards; whitespace-insensitive for GADM names.
        $needle = '%'.addcslashes(mb_strtolower(preg_replace('/\s+/u', '', $term)), '\\%_').'%';
        $results = [];

        foreach (['province', 'municipality', 'barangay'] as $level) {
            $nameColumn = $this->nameColumn($level);
            $rows = $this->query($level)
                ->whereRaw("LOWER(REPLACE({$nameColumn}, ' ', '')) LIKE ?", [$needle])
                ->orderBy($nameColumn)
                ->limit(6)
                ->get($this->selectColumns($level));

            foreach ($rows as $row) {
                $path = $this->path($level, $row, false);
                if ($path === []) {
                    continue;
                }
                $results[] = [...$this->summary($level, $row), 'path' => $path];
            }
        }

        return $results;
    }

    private function query(string $level): Builder
    {
        return match ($level) {
            'province' => Province::query(),
            'municipality' => Municipality::query(),
            'barangay' => Barangay::query(),
            default => abort(422, 'Unknown geographic level.'),
        };
    }

    private function columns(string $level): array
    {
        return $this->columns[$level] ??= Schema::getColumnListing($this->query($level)->getModel()->getTable());
    }

    private function nameColumn(string $level): string
    {
        return $level === 'barangay' && in_array('brgy_name', $this->columns($level), true) ? 'brgy_name' : 'name';
    }

    private function selectColumns(string $level, bool $geometry = false): array
    {
        return array_values(array_intersect($this->columns($level), [
            'id', 'name', 'brgy_name', 'psg_code', 'psgc_code', 'gadm_id',
            'province_id', 'province_gadm_id', 'municipality_id', 'municipality_gadm_id',
            'center_lat', 'center_lng', 'bounds', ...($geometry ? ['geometry'] : []),
        ]));
    }

    private function record(string $level, ?int $id, bool $geometry = false): Model
    {
        abort_if($id === null, 422, 'A parent location is required.');
        $key = $level.':'.$id.':'.(int) $geometry;

        return $this->records[$key] ??= $this->query($level)->findOrFail($id, $this->selectColumns($level, $geometry));
    }

    private function forParent(Builder $query, string $level, Model $parent): void
    {
        $foreignKey = $level === 'municipality' ? 'province_id' : 'municipality_id';
        $gadmKey = $level === 'municipality' ? 'province_gadm_id' : 'municipality_gadm_id';

        if (in_array($foreignKey, $this->columns($level), true)) {
            // Real numeric parent links take precedence, including NCR and independent cities.
            $query->where($foreignKey, $parent->id);
        } elseif (in_array($gadmKey, $this->columns($level), true) && $parent->getAttribute('gadm_id')) {
            $query->where($gadmKey, $parent->getAttribute('gadm_id'));
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    private function parent(string $level, Model $row): ?Model
    {
        $parentLevel = $level === 'barangay' ? 'municipality' : 'province';
        $foreignKey = $level === 'barangay' ? 'municipality_id' : 'province_id';
        $gadmKey = $level === 'barangay' ? 'municipality_gadm_id' : 'province_gadm_id';

        if ($row->getAttribute($foreignKey) !== null) {
            return $this->query($parentLevel)->find($row->getAttribute($foreignKey), $this->selectColumns($parentLevel));
        }
        if ($row->getAttribute($gadmKey)) {
            return $this->query($parentLevel)->where('gadm_id', $row->getAttribute($gadmKey))->first($this->selectColumns($parentLevel));
        }

        return null;
    }

    private function path(string $level, Model $row, bool $required = true): array
    {
        $path = [$this->summary($level, $row)];
        while ($level !== 'province') {
            $row = $this->parent($level, $row);
            if ($row === null) {
                abort_if($required, 422, 'This location has no verified parent link in the geographic dataset.');

                return [];
            }
            $level = $level === 'barangay' ? 'municipality' : 'province';
            array_unshift($path, $this->summary($level, $row));
        }

        return $path;
    }

    private function summary(string $level, Model $row): array
    {
        $name = trim((string) $row->getAttribute($this->nameColumn($level)));
        $name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name);
        $name = preg_replace('/(?<=\p{Ll})(del|de|of) (?=\p{Lu})/u', ' $1 ', $name);
        $center = $this->position([$row->getAttribute('center_lng'), $row->getAttribute('center_lat')]);

        return [
            'id' => (int) $row->id,
            'key' => $level.':'.$row->id,
            'level' => $level,
            'name' => $name ?: 'Unnamed location',
            'code' => $row->getAttribute('psg_code') ?? $row->getAttribute('psgc_code') ?? $row->getAttribute('gadm_id'),
            'center' => $center,
            'bounds' => $this->bounds($row->getAttribute('bounds')),
        ];
    }

    private function position(mixed $point): ?array
    {
        if (! is_array($point) || count($point) < 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
            return null;
        }
        [$lng, $lat] = [(float) $point[0], (float) $point[1]];

        return is_finite($lng) && is_finite($lat) && abs($lng) <= 180 && abs($lat) <= 90 ? [$lng, $lat] : null;
    }

    private function bounds(mixed $value): ?array
    {
        $value = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($value) || count($value) !== 4 || count(array_filter($value, 'is_numeric')) !== 4) {
            return null;
        }
        // Imported bounds are [south, north, west, east]. Accept conventional W/S/E/N too.
        $box = abs((float) $value[0]) <= 90 && (float) $value[2] > 90
            ? [$value[2], $value[0], $value[3], $value[1]] : array_values($value);
        $sw = $this->position([$box[0], $box[1]]);
        $ne = $this->position([$box[2], $box[3]]);

        return $sw && $ne && $sw[0] < $ne[0] && $sw[1] < $ne[1] ? [...$sw, ...$ne] : null;
    }

    private function feature(string $level, Model $row): ?array
    {
        $geometry = $row->getAttribute('geometry');
        $geometry = is_string($geometry) ? json_decode($geometry, true) : $geometry;
        if (($geometry['type'] ?? null) === 'Feature') {
            $geometry = $geometry['geometry'] ?? null;
        }
        if (! is_array($geometry) || ! in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
            return null;
        }

        $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates'] ?? []] : ($geometry['coordinates'] ?? []);
        if (! is_array($polygons) || $polygons === []) {
            return null;
        }
        foreach ($polygons as $polygon) {
            if (! is_array($polygon) || $polygon === []) {
                return null;
            }
            foreach ($polygon as $ring) {
                if (! is_array($ring) || count($ring) < 4 || $ring[0] !== $ring[count($ring) - 1]) {
                    return null;
                }
                foreach ($ring as $point) {
                    if ($this->position($point) === null) {
                        return null;
                    }
                }
            }
        }

        return [
            'type' => 'Feature',
            'id' => $level.':'.$row->id,
            'properties' => $this->summary($level, $row),
            'geometry' => $geometry,
        ];
    }
}
