<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use App\Models\Province;
use App\Support\Assessment\AssessmentComputationCache;
use App\Support\Geometry\PolygonArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer A (A1) Function Tool: get_municipality_profile
 *
 * Retrieves the complete administrative, geographic, and classification profile
 * of a Municipality (or Barangay/Province) including region/province metadata,
 * coordinates, bounds, land area, PlaceDescription classifications, and barangay roster.
 */
class GetMunicipalityProfileTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'get_municipality_profile';
    }

    public function layer(): string
    {
        return 'A';
    }

    public function description(): string
    {
        return 'Retrieve the administrative and geographic profile of a municipality (PSGC code, province, region, coordinates, bounds, land area in km², income class, city class, urban/rural classification, and constituent barangay list).';
    }

    public function parameters(): array
    {
        $schema = LocationResolver::locationParameterSchema();
        $schema['properties']['include_barangay_list'] = [
            'type' => 'boolean',
            'description' => 'Whether to include the full list of constituent barangays (default: true).',
        ];

        return $schema;
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
        $includeBarangayList = (bool) ($args['include_barangay_list'] ?? true);

        return $this->forLocation($location, $includeBarangayList);
    }

    /**
     * Compute the full profile for an already-resolved Eloquent model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location, bool $includeBarangayList = true): array
    {
        return app(AssessmentComputationCache::class)->remember(
            self::class, $location, ['include_barangay_list' => $includeBarangayList],
            fn () => $this->computeForLocation($location, $includeBarangayList)
        );
    }

    private function computeForLocation(Model $location, bool $includeBarangayList): array
    {
        $level = LocationResolver::levelOf($location);
        $psgCode = (string) ($location->psg_code ?? '');
        $placeDescription = LocationResolver::resolvePlaceDescription($level, $psgCode);
        $region = LocationResolver::resolveRegion($psgCode);

        $populationStats = app(GetPopulationStatisticsTool::class)->forLocation(
            $location,
            includeBarangayDistribution: false
        );

        $barangayData = $location instanceof Municipality
            ? $this->buildBarangayOverview($location, $includeBarangayList)
            : null;

        $barangayCounts = $barangayData !== null
            ? $barangayData['counts']
            : null;

        $rawAreaKm2 = $location->area_km2 !== null && (float) $location->area_km2 > 0
            ? round((float) $location->area_km2, 4)
            : null;

        $areaBasis = $rawAreaKm2 !== null ? 'stored_area_km2' : 'unavailable';
        $effectiveAreaKm2 = $rawAreaKm2;

        if ($effectiveAreaKm2 === null && $barangayData !== null && ($barangayData['sum_barangay_area_km2'] ?? 0) > 0) {
            $effectiveAreaKm2 = round((float) $barangayData['sum_barangay_area_km2'], 4);
            $areaBasis = 'barangay_area_sum';
        } elseif ($effectiveAreaKm2 === null && ! empty($location->geometry)) {
            $decoded = is_string($location->geometry) ? json_decode($location->geometry, true) : null;
            if (is_array($decoded)) {
                $computed = PolygonArea::ofGeometry((object) $decoded);
                if ($computed !== null && $computed > 0) {
                    $effectiveAreaKm2 = round($computed, 4);
                    $areaBasis = 'computed_from_geometry';
                }
            }
        }

        $currentPop = $populationStats['population_current'] ?? null;
        $density = ($currentPop !== null && $effectiveAreaKm2 !== null && $effectiveAreaKm2 > 0)
            ? round($currentPop / $effectiveAreaKm2, 2)
            : null;

        $urbanRuralRaw = strtoupper(trim((string) ($placeDescription?->urban_rural ?? '')));
        $urbanRuralLabel = match ($urbanRuralRaw) {
            'U' => 'Urban',
            'R' => 'Rural',
            default => null,
        };

        if ($urbanRuralLabel === null && $barangayCounts !== null && $barangayCounts['total_barangays'] > 0) {
            if ($barangayCounts['urban_barangays'] > 0 && $barangayCounts['rural_barangays'] === 0) {
                $urbanRuralLabel = 'Urban';
            } elseif ($barangayCounts['rural_barangays'] > 0 && $barangayCounts['urban_barangays'] === 0) {
                $urbanRuralLabel = 'Rural';
            } elseif ($barangayCounts['urban_barangays'] > 0 && $barangayCounts['rural_barangays'] > 0) {
                $urbanRuralLabel = sprintf(
                    'Mixed (%d Urban, %d Rural)',
                    $barangayCounts['urban_barangays'],
                    $barangayCounts['rural_barangays']
                );
            }
        }

        $bounds = null;
        if (! empty($location->bounds)) {
            $decodedBounds = is_string($location->bounds) ? json_decode($location->bounds, true) : $location->bounds;
            if (is_array($decodedBounds)) {
                $bounds = $decodedBounds;
            }
        }

        return [
            'id' => (int) $location->id,
            'level' => $level,
            'psg_code' => $psgCode !== '' ? $psgCode : null,
            'name' => LocationResolver::nameOf($location),
            'type' => $location->type ?? ucfirst($level),
            'province_id' => $location instanceof Municipality
                ? $location->province_id
                : ($location instanceof Barangay ? $location->municipality?->province_id : $location->id),
            'province_name' => $location instanceof Municipality
                ? trim((string) ($location->province_name ?? $location->provinceModel?->name ?? '')) ?: null
                : ($location instanceof Barangay
                    ? trim((string) ($location->municipality?->province_name ?? '')) ?: null
                    : trim((string) $location->name)),
            'province_psg_code' => $location instanceof Municipality
                ? $location->provinceModel?->psg_code
                : ($location instanceof Barangay ? $location->municipality?->provinceModel?->psg_code : $location->psg_code),
            'region_psg_code' => $region['region_psg_code'],
            'region_name' => $region['region_name'],
            'income_class' => $placeDescription?->income_class ?: null,
            'city_class' => $placeDescription?->city_class ?: null,
            'urban_rural' => $urbanRuralLabel,
            'old_name' => $placeDescription?->old_name ?: null,
            'area_km2' => $effectiveAreaKm2,
            'area_basis' => $areaBasis,
            'center_lat' => $location->center_lat !== null ? (float) $location->center_lat : null,
            'center_lng' => $location->center_lng !== null ? (float) $location->center_lng : null,
            'bounds' => $bounds,
            'has_geometry' => ! empty($location->geometry),
            'density_per_km2' => $density,
            'barangay_counts' => $barangayCounts,
            'population_statistics' => $populationStats,
            'barangays' => $includeBarangayList && $barangayData !== null ? $barangayData['list'] : null,
        ];
    }

    /**
     * Build barangay classification counts, total area sum, and optional barangay roster.
     *
     * @return array{
     *     counts: array{total_barangays: int, urban_barangays: int, rural_barangays: int, unclassified_barangays: int},
     *     sum_barangay_area_km2: float,
     *     list: list<array<string, mixed>>
     * }
     */
    private function buildBarangayOverview(Municipality $municipality, bool $includeList): array
    {
        if (! Schema::hasTable('barangays') || ! Schema::hasColumn('barangays', 'municipality_id')) {
            return [
                'counts' => [
                    'total_barangays' => 0,
                    'urban_barangays' => 0,
                    'rural_barangays' => 0,
                    'unclassified_barangays' => 0,
                ],
                'sum_barangay_area_km2' => 0.0,
                'list' => [],
            ];
        }

        $psgCode = (string) ($municipality->psg_code ?? '');
        $prefix = LocationResolver::psgcPrefixFor('municipality', $psgCode);

        $urbanRuralByPsgc = [];
        if ($prefix !== '' && Schema::hasColumns('place_descriptions', ['psg_code', 'urban_rural'])) {
            $urbanRuralByPsgc = PlaceDescription::query()
                ->where('release_id', PlaceDescription::latestReleaseId())
                ->where('level', PlaceDescription::LEVEL_BARANGAY)
                ->where('psg_code', 'like', $prefix.'%')
                ->pluck('urban_rural', 'psg_code')
                ->all();
        }

        $rows = DB::table('barangays')
            ->where('municipality_id', $municipality->id)
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

        $urban = 0;
        $rural = 0;
        $unclassified = 0;
        $sumArea = 0.0;
        $list = [];

        foreach ($rows as $row) {
            $bgyPsgc = (string) ($row->psg_code ?? '');
            $urRaw = strtoupper(trim((string) ($urbanRuralByPsgc[$bgyPsgc] ?? '')));
            $urLabel = match ($urRaw) {
                'U' => 'Urban',
                'R' => 'Rural',
                default => null,
            };

            if ($urLabel === 'Urban') {
                $urban++;
            } elseif ($urLabel === 'Rural') {
                $rural++;
            } else {
                $unclassified++;
            }

            $areaVal = $row->area_km2 !== null && (float) $row->area_km2 > 0
                ? round((float) $row->area_km2, 4)
                : null;
            if ($areaVal !== null) {
                $sumArea += $areaVal;
            }

            if ($includeList) {
                $list[] = [
                    'id' => (int) $row->id,
                    'psg_code' => $bgyPsgc !== '' ? $bgyPsgc : null,
                    'name' => (string) $row->brgy_name,
                    'urban_rural' => $urLabel,
                    'area_km2' => $areaVal,
                    'center_lat' => $row->center_lat !== null ? (float) $row->center_lat : null,
                    'center_lng' => $row->center_lng !== null ? (float) $row->center_lng : null,
                    'has_geometry' => (bool) $row->has_geometry,
                    'has_bounds' => (bool) $row->has_bounds,
                ];
            }
        }

        return [
            'counts' => [
                'total_barangays' => $rows->count(),
                'urban_barangays' => $urban,
                'rural_barangays' => $rural,
                'unclassified_barangays' => $unclassified,
            ],
            'sum_barangay_area_km2' => round($sumArea, 4),
            'list' => $list,
        ];
    }
}
