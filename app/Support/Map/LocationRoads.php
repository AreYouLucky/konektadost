<?php

namespace App\Support\Map;

use App\Support\Caching\ReadCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Selection-scoped road geometry; never loads the national inventory for an unselected map. */
class LocationRoads
{
    public function page(GeographyExplorer $geography, string $level, int $id, int $cursor = 0, int $limit = 2000): array
    {
        $geography->selection($level, $id, false);

        return app(ReadCache::class)->remember('map.roads', compact('level', 'id', 'cursor', 'limit'),
            fn () => $this->loadPage($geography, $level, $id, $cursor, $limit));
    }

    private function loadPage(GeographyExplorer $geography, string $level, int $id, int $cursor, int $limit): array
    {
        $geography->selection($level, $id, false);
        $scope = ['key' => $level.':'.$id, 'level' => $level, 'id' => $id];
        $empty = fn (string $status, string $message) => [
            'type' => 'FeatureCollection', 'features' => [],
            'meta' => ['scope' => $scope, 'status' => $status, 'message' => $message, 'next_cursor' => null, 'omitted_geometries' => 0],
        ];
        if (! Schema::hasColumns('roads', ['id', 'municipality_id', 'geometry'])) {
            return $empty('unavailable', 'Road geometry is not available in the current dataset.');
        }
        $query = DB::table('roads')->where('roads.id', '>', $cursor);
        $hasBarangayLength = false;
        if ($level === 'province') {
            $municipalities = $geography->locations('municipality', $id)['locations'];
            $query->whereIn('roads.municipality_id', array_column($municipalities, 'id'));
        } elseif ($level === 'municipality') {
            $query->where('roads.municipality_id', $id);
        } else {
            if (! Schema::hasColumns('road_barangay', ['road_id', 'barangay_id'])) {
                return $empty('unavailable', 'Barangay road links are not available in the current dataset.');
            }
            // Explicit barangay links are authoritative. A road may cross into a
            // barangay outside the municipality assigned to the whole segment.
            $hasBarangayLength = Schema::hasColumn('road_barangay', 'length_m');
            $links = DB::table('road_barangay')->where('barangay_id', $id)->select('road_id')->groupBy('road_id');
            if ($hasBarangayLength) {
                // Duplicate associations must not duplicate lines or inflate lengths.
                $links->selectRaw('MAX(length_m) AS barangay_length_m');
            }
            $query->joinSub($links, 'selected_barangay_roads', 'selected_barangay_roads.road_id', '=', 'roads.id');
        }

        $columns = Schema::getColumnListing('roads');
        $query->select(array_map(fn ($column) => 'roads.'.$column, array_values(array_intersect($columns, [
            'id', 'road_code', 'name', 'road_class', 'surface_type', 'road_condition', 'width_m', 'length_m', 'municipality_id', 'source', 'reference_date',
        ]))));
        if ($hasBarangayLength) {
            $query->addSelect('selected_barangay_roads.barangay_length_m');
        }
        // The live MySQL column is spatial binary, not a JSON string.
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->selectRaw('ST_AsGeoJSON(roads.geometry, 6) AS road_geometry');
        } else {
            $query->addSelect('roads.geometry as road_geometry');
        }
        $rows = $query->orderBy('roads.id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $features = [];
        foreach ($rows as $row) {
            $geometry = $this->geometry($row->road_geometry);
            if ($geometry === null) {
                continue;
            }
            $features[] = [
                'type' => 'Feature', 'id' => (int) $row->id, 'geometry' => $geometry,
                'properties' => [
                    'id' => (int) $row->id, 'name' => $row->name ?? $row->road_code ?? 'Unnamed road',
                    'road_code' => $row->road_code ?? null, 'road_class' => $row->road_class ?? 'unknown',
                    'surface_type' => $row->surface_type ?? 'unknown', 'road_condition' => $row->road_condition ?? 'unknown',
                    'length_m' => isset($row->length_m) ? (float) $row->length_m : null,
                    'barangay_id' => $level === 'barangay' ? $id : null,
                    'barangay_length_m' => isset($row->barangay_length_m) ? (float) $row->barangay_length_m : null,
                    'width_m' => isset($row->width_m) ? (float) $row->width_m : null,
                    'municipality_id' => (int) $row->municipality_id, 'source' => $row->source ?? null,
                    'reference_date' => $row->reference_date ?? null,
                ],
            ];
        }

        return [
            'type' => 'FeatureCollection', 'features' => $features,
            'meta' => [
                'scope' => $scope, 'status' => $rows->isEmpty() ? 'empty' : 'available',
                'message' => $rows->isEmpty() ? 'No road segments are recorded for this selected location.' : null,
                'next_cursor' => $more ? (int) $rows->last()->id : null,
                'omitted_geometries' => $rows->count() - count($features),
            ],
        ];
    }

    private function geometry(mixed $value): ?array
    {
        $geometry = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($geometry) || ! in_array($geometry['type'] ?? null, ['LineString', 'MultiLineString'], true)) {
            return null;
        }
        $lines = $geometry['type'] === 'LineString' ? [$geometry['coordinates'] ?? []] : ($geometry['coordinates'] ?? []);
        if (! is_array($lines) || $lines === []) {
            return null;
        }
        foreach ($lines as $line) {
            if (! is_array($line) || count($line) < 2) {
                return null;
            }
            foreach ($line as $point) {
                if (! is_array($point) || count($point) < 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])
                    || ! is_finite((float) $point[0]) || ! is_finite((float) $point[1])
                    || abs((float) $point[0]) > 180 || abs((float) $point[1]) > 90) {
                    return null;
                }
            }
        }

        return $geometry;
    }
}
