<?php

namespace App\FunctionTools\LayerB;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetMunicipalityProfileTool;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer B (B5) Function Tool: analyze_road_accessibility
 *
 * Analyzes road network accessibility from the `roads` and `road_barangay` tables.
 * If the `roads` table is empty or unavailable, explicitly reports road network
 * data as unavailable/empty rather than inferring accessibility from administrative area.
 */
class AnalyzeRoadAccessibilityTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'analyze_road_accessibility';
    }

    public function layer(): string
    {
        return 'B';
    }

    public function description(): string
    {
        return 'Analyze road network accessibility (road length by class, surface type, road condition, road density in km/km², and barangays without road access) if road data is available; otherwise explicitly report road dataset unavailability.';
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
     * Analyze road network accessibility for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        $level = LocationResolver::levelOf($location);

        if (! Schema::hasTable('roads')) {
            return [
                'status' => 'unavailable',
                'location_level' => $level,
                'location_id' => (int) $location->id,
                'location_name' => LocationResolver::nameOf($location),
                'reason' => 'The `roads` table is not present in the database.',
                'summary' => [
                    'total_road_segments' => 0,
                    'total_road_length_km' => null,
                    'road_density_km_per_km2' => null,
                ],
                'by_road_class' => [],
                'by_surface_type' => [],
                'by_road_condition' => [],
                'barangay_coverage' => null,
            ];
        }

        $nationalCount = (int) DB::table('roads')->count();
        if ($nationalCount === 0) {
            return [
                'status' => 'empty',
                'location_level' => $level,
                'location_id' => (int) $location->id,
                'location_name' => LocationResolver::nameOf($location),
                'reason' => 'The `roads` and `road_barangay` tables exist in the database schema but currently contain 0 records. Road accessibility cannot be measured until road network data is imported.',
                'summary' => [
                    'total_road_segments' => 0,
                    'total_road_length_km' => null,
                    'road_density_km_per_km2' => null,
                ],
                'by_road_class' => [],
                'by_surface_type' => [],
                'by_road_condition' => [],
                'barangay_coverage' => null,
            ];
        }

        $baseQuery = DB::table('roads');
        if ($location instanceof Municipality) {
            $baseQuery->where('municipality_id', $location->id);
        } elseif ($location instanceof Barangay && Schema::hasTable('road_barangay')) {
            $roadIds = DB::table('road_barangay')->where('barangay_id', $location->id)->pluck('road_id');
            $baseQuery->whereIn('id', $roadIds);
        } elseif ($location instanceof Province) {
            $munIds = Municipality::query()->where('province_id', $location->id)->pluck('id');
            $baseQuery->whereIn('municipality_id', $munIds);
        } else {
            $baseQuery->whereRaw('1 = 0');
        }

        $totalSegments = (clone $baseQuery)->count();
        if ($totalSegments === 0) {
            return [
                'status' => 'empty',
                'location_level' => $level,
                'location_id' => (int) $location->id,
                'location_name' => LocationResolver::nameOf($location),
                'reason' => 'No road segments are recorded for this municipality in the `roads` table.',
                'summary' => [
                    'total_road_segments' => 0,
                    'total_road_length_km' => null,
                    'road_density_km_per_km2' => null,
                ],
                'by_road_class' => [],
                'by_surface_type' => [],
                'by_road_condition' => [],
                'barangay_coverage' => null,
            ];
        }

        $totalLengthMeters = (float) (clone $baseQuery)->whereNotNull('length_m')->sum('length_m');
        $totalLengthKm = round($totalLengthMeters / 1000.0, 2);

        $profile = app(GetMunicipalityProfileTool::class)->forLocation($location, includeBarangayList: false);
        $areaKm2 = $profile['area_km2'] ?? null;
        $roadDensity = ($areaKm2 !== null && $areaKm2 > 0)
            ? round($totalLengthKm / $areaKm2, 3)
            : null;

        $byClass = (clone $baseQuery)
            ->select('road_class', DB::raw('COUNT(*) as segments'), DB::raw('ROUND(COALESCE(SUM(length_m), 0) / 1000, 2) as length_km'))
            ->groupBy('road_class')
            ->get()
            ->map(fn ($r) => [
                'road_class' => $r->road_class,
                'segments' => (int) $r->segments,
                'length_km' => (float) $r->length_km,
            ])
            ->values()
            ->all();

        $bySurface = (clone $baseQuery)
            ->select('surface_type', DB::raw('COUNT(*) as segments'), DB::raw('ROUND(COALESCE(SUM(length_m), 0) / 1000, 2) as length_km'))
            ->groupBy('surface_type')
            ->get()
            ->map(fn ($r) => [
                'surface_type' => $r->surface_type,
                'segments' => (int) $r->segments,
                'length_km' => (float) $r->length_km,
            ])
            ->values()
            ->all();

        $byCondition = (clone $baseQuery)
            ->select('road_condition', DB::raw('COUNT(*) as segments'), DB::raw('ROUND(COALESCE(SUM(length_m), 0) / 1000, 2) as length_km'))
            ->groupBy('road_condition')
            ->get()
            ->map(fn ($r) => [
                'road_condition' => $r->road_condition,
                'segments' => (int) $r->segments,
                'length_km' => (float) $r->length_km,
            ])
            ->values()
            ->all();

        $brgyCoverage = null;
        if (
            $location instanceof Municipality
            && Schema::hasTable('road_barangay')
            && Schema::hasTable('barangays')
            && Schema::hasColumn('barangays', 'municipality_id')
            && Schema::hasColumn('barangays', 'brgy_name')
        ) {
            $bgyIdsWithRoads = DB::table('road_barangay')
                ->join('roads', 'roads.id', '=', 'road_barangay.road_id')
                ->where('roads.municipality_id', $location->id)
                ->distinct('road_barangay.barangay_id')
                ->pluck('road_barangay.barangay_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            $barangays = Barangay::query()
                ->where('municipality_id', $location->id)
                ->orderBy('brgy_name')
                ->get(['id', 'psg_code', 'brgy_name']);

            $withoutRoads = [];
            foreach ($barangays as $b) {
                if (! $bgyIdsWithRoads->has((int) $b->id)) {
                    $withoutRoads[] = [
                        'id' => (int) $b->id,
                        'psg_code' => (string) $b->psg_code,
                        'name' => (string) $b->brgy_name,
                    ];
                }
            }

            $totalBgys = $barangays->count();
            $withRoadsCount = $totalBgys - count($withoutRoads);

            $brgyCoverage = [
                'total_barangays' => $totalBgys,
                'barangays_with_roads' => $withRoadsCount,
                'barangays_without_roads' => count($withoutRoads),
                'coverage_pct' => $totalBgys > 0 ? round(($withRoadsCount / $totalBgys) * 100, 1) : null,
                'unlinked_barangays' => $withoutRoads,
            ];
        }

        return [
            'status' => 'available',
            'location_level' => $level,
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'reason' => null,
            'summary' => [
                'total_road_segments' => $totalSegments,
                'total_road_length_km' => $totalLengthKm,
                'road_density_km_per_km2' => $roadDensity,
            ],
            'by_road_class' => $byClass,
            'by_surface_type' => $bySurface,
            'by_road_condition' => $byCondition,
            'barangay_coverage' => $brgyCoverage,
        ];
    }
}

