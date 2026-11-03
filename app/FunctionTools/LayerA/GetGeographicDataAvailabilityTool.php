<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Municipality;
use App\Support\Geometry\PolygonArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer A (A6) Function Tool: get_geographic_data_availability
 *
 * Checks whether boundary geometry, bounding boxes, centroids, and land area
 * data exist for the selected municipality and its constituent barangays,
 * as well as road network geometry availability.
 */
class GetGeographicDataAvailabilityTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'get_geographic_data_availability';
    }

    public function layer(): string
    {
        return 'A';
    }

    public function description(): string
    {
        return 'Check whether boundary geometry, bounds, centroids (center_lat/center_lng), and area_km2 data exist for the municipality and its barangays, plus road network data availability.';
    }

    public function parameters(): array
    {
        return LocationResolver::locationParameterSchema();
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'layer' => $this->layer(),
            'description' => $this->description(),
            'parameters' => $this->parameters(),
        ];
    }

    public function execute(array $args): array
    {
        $location = LocationResolver::resolve($args);

        return $this->forLocation($location);
    }

    /**
     * Audit geographic boundary, centroid, area, and road geometry availability for a location.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        $level = LocationResolver::levelOf($location);
        $psgCode = (string) ($location->psg_code ?? '');

        $hasGeometry = ! empty($location->geometry);
        $hasBounds = ! empty($location->bounds);
        $hasCentroid = $location->center_lat !== null && $location->center_lng !== null;
        $storedAreaKm2 = $location->area_km2 !== null && (float) $location->area_km2 > 0
            ? round((float) $location->area_km2, 4)
            : null;

        $computedGeometryAreaKm2 = null;
        if ($storedAreaKm2 === null && $hasGeometry) {
            $decoded = is_string($location->geometry) ? json_decode($location->geometry, true) : null;
            if (is_array($decoded)) {
                $area = PolygonArea::ofGeometry((object) $decoded);
                if ($area !== null && $area > 0) {
                    $computedGeometryAreaKm2 = round($area, 4);
                }
            }
        }

        $boundaryAudit = [
            'has_geometry' => $hasGeometry,
            'has_bounds' => $hasBounds,
            'has_centroid' => $hasCentroid,
            'center_lat' => $location->center_lat !== null ? (float) $location->center_lat : null,
            'center_lng' => $location->center_lng !== null ? (float) $location->center_lng : null,
            'has_stored_area_km2' => $storedAreaKm2 !== null,
            'stored_area_km2' => $storedAreaKm2,
            'computed_geometry_area_km2' => $computedGeometryAreaKm2,
            'status' => ($hasGeometry && $hasBounds && $hasCentroid && ($storedAreaKm2 !== null || $computedGeometryAreaKm2 !== null))
                ? 'available'
                : (($hasGeometry || $hasCentroid) ? 'partial' : 'unavailable'),
        ];

        $barangayGeoAudit = null;
        if (
            $location instanceof Municipality
            && Schema::hasTable('barangays')
            && Schema::hasColumn('barangays', 'municipality_id')
            && Schema::hasColumn('barangays', 'brgy_name')
        ) {
            $rows = DB::table('barangays')
                ->where('municipality_id', $location->id)
                ->select([
                    'id',
                    'psg_code',
                    'brgy_name',
                    'center_lat',
                    'center_lng',
                    'area_km2',
                    DB::raw('CASE WHEN geometry IS NOT NULL THEN 1 ELSE 0 END as has_geometry'),
                    DB::raw('CASE WHEN bounds IS NOT NULL THEN 1 ELSE 0 END as has_bounds'),
                ])
                ->orderBy('brgy_name')
                ->get();

            $total = $rows->count();
            $withGeom = 0;
            $withBounds = 0;
            $withCentroid = 0;
            $withArea = 0;
            $sumBarangayAreaKm2 = 0.0;
            $missingGeometryBarangays = [];

            foreach ($rows as $row) {
                $g = (bool) $row->has_geometry;
                $b = (bool) $row->has_bounds;
                $c = $row->center_lat !== null && $row->center_lng !== null;
                $a = $row->area_km2 !== null && (float) $row->area_km2 > 0;

                if ($g) {
                    $withGeom++;
                } else {
                    $missingGeometryBarangays[] = [
                        'id' => (int) $row->id,
                        'psg_code' => (string) $row->psg_code,
                        'name' => (string) $row->brgy_name,
                    ];
                }

                if ($b) {
                    $withBounds++;
                }
                if ($c) {
                    $withCentroid++;
                }
                if ($a) {
                    $withArea++;
                    $sumBarangayAreaKm2 += (float) $row->area_km2;
                }
            }

            $barangayGeoAudit = [
                'total_barangays' => $total,
                'with_geometry_count' => $withGeom,
                'with_bounds_count' => $withBounds,
                'with_centroid_count' => $withCentroid,
                'with_area_km2_count' => $withArea,
                'missing_geometry_count' => $total - $withGeom,
                'geometry_coverage_pct' => $total > 0 ? round(($withGeom / $total) * 100, 1) : null,
                'sum_barangay_area_km2' => round($sumBarangayAreaKm2, 4),
                'status' => $total === 0
                    ? 'empty'
                    : ($withGeom === $total ? 'available' : ($withGeom > 0 ? 'partial' : 'unavailable')),
                'missing_geometry_barangays' => $missingGeometryBarangays,
            ];
        }

        // Road Network Availability Check
        $hasRoadsTable = Schema::hasTable('roads');
        $hasRoadBarangayTable = Schema::hasTable('road_barangay');
        $nationalRoadsCount = $hasRoadsTable ? (int) DB::table('roads')->count() : null;
        $localRoadsCount = ($hasRoadsTable && $location instanceof Municipality)
            ? (int) DB::table('roads')->where('municipality_id', $location->id)->count()
            : 0;

        $roadAudit = [
            'roads_table_exists' => $hasRoadsTable,
            'road_barangay_table_exists' => $hasRoadBarangayTable,
            'national_road_records' => $nationalRoadsCount,
            'local_road_records' => $hasRoadsTable ? $localRoadsCount : null,
            'status' => ! $hasRoadsTable
                ? 'unavailable'
                : (($nationalRoadsCount ?? 0) === 0 || $localRoadsCount === 0 ? 'empty' : 'available'),
            'notes' => ! $hasRoadsTable
                ? 'Road network table is not present in the database.'
                : (($nationalRoadsCount ?? 0) === 0
                    ? 'Road network tables (`roads`, `road_barangay`) exist in the database schema but contain 0 records. Do not infer road accessibility from administrative area alone.'
                    : "{$localRoadsCount} road segment(s) recorded locally."),
        ];

        return [
            'location_level' => $level,
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'psg_code' => $psgCode !== '' ? $psgCode : null,
            'location_boundary' => $boundaryAudit,
            'barangay_boundaries' => $barangayGeoAudit,
            'road_network' => $roadAudit,
        ];
    }
}
