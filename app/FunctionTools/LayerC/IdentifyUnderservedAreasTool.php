<?php

namespace App\FunctionTools\LayerC;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\Support\LocationResolver;
use App\FunctionTools\Support\SpatialMetrics;
use App\Models\AgricultureCountEstimate;
use App\Models\Barangay;
use App\Models\Facility;
use App\Models\Municipality;
use App\Models\TelecomSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer C (C2) Function Tool: identify_underserved_areas
 *
 * Evaluates every barangay in a municipality across all sectors:
 * - Health access (health facilities presence & nearest distance)
 * - Education access (schools presence & nearest distance)
 * - Road accessibility (road length in meters from road_barangay)
 * - Digital connectivity (cell site presence when telecom dataset exists)
 * - Safety & DRRM facilities presence
 * - Agricultural participation
 * - Geographic isolation from municipal center
 *
 * Quantifies overlapping development deficits without double-counting evidence.
 */
class IdentifyUnderservedAreasTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'identify_underserved_areas';
    }

    public function layer(): string
    {
        return 'C';
    }

    public function description(): string
    {
        return 'Identify barangays with verified multi-sector development needs (health, education, road accessibility, digital connectivity, safety, agriculture) and quantify overlapping deficits without double-counting.';
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
     * Identify underserved barangays for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        if (! $location instanceof Municipality) {
            return [
                'status' => 'not_applicable',
                'location_level' => LocationResolver::levelOf($location),
                'location_id' => (int) $location->id,
                'location_name' => LocationResolver::nameOf($location),
                'reason' => 'identify_underserved_areas assesses constituent barangays within a Municipality.',
                'summary' => null,
                'underserved_barangays' => [],
            ];
        }

        $popStats = app(GetPopulationStatisticsTool::class)->forLocation($location, includeBarangayDistribution: false);
        $munPopulation = $popStats['population_current'] ?? $location->populationFigure?->population;

        $datasetsTool = app(GetAvailableDatasetsTool::class);
        $datasetAudit = $datasetsTool->forLocation($location);
        $hasRoadData = in_array($datasetAudit['datasets']['roads']['status'] ?? null, ['available', 'partial'], true);
        $hasTelecomData = in_array($datasetAudit['datasets']['telecom_sites']['status'] ?? null, ['available', 'partial'], true);
        $hasAgriData = in_array($datasetAudit['datasets']['agriculture_count_estimates']['status'] ?? null, ['available', 'partial'], true);

        $hasFacilities = Schema::hasTable('facilities');
        $hasRoads = Schema::hasTable('road_barangay');
        $hasTelecom = Schema::hasTable('telecom_sites');
        $hasAgri = Schema::hasTable('agriculture_count_estimates');

        // Preload telecom site counts per barangay
        $prefix = str_starts_with((string) $location->psg_code, '13806')
            ? substr((string) $location->psg_code, 0, 5)
            : substr((string) $location->psg_code, 0, 7);

        $telecomByBarangayId = [];
        $telecomByPsgc = [];
        if ($hasTelecom) {
            $telecomRows = $datasetsTool->telecomQueryFor($location)->get();

            foreach ($telecomRows as $site) {
                if ($site->barangay_id !== null) {
                    $bid = (int) $site->barangay_id;
                    $telecomByBarangayId[$bid] = ($telecomByBarangayId[$bid] ?? 0) + 1;
                } elseif (! empty($site->barangay_psgc)) {
                    $psgc = (string) $site->barangay_psgc;
                    $telecomByPsgc[$psgc] = ($telecomByPsgc[$psgc] ?? 0) + 1;
                }
            }
        }

        // Preload road lengths per barangay
        $roadLengthsByBarangayId = [];
        if ($hasRoads) {
            $roadRows = DB::table('road_barangay')
                ->whereIn('barangay_id', function ($q) use ($location) {
                    $q->select('id')->from('barangays')->where('municipality_id', $location->id);
                })
                ->select('barangay_id', DB::raw('SUM(length_m) as total_len_m'))
                ->groupBy('barangay_id')
                ->get();

            foreach ($roadRows as $rr) {
                $roadLengthsByBarangayId[(int) $rr->barangay_id] = round((float) $rr->total_len_m, 2);
            }
        }

        // Preload agriculture participation per barangay
        $agriByBarangayId = [];
        if ($hasAgri) {
            $agriRows = AgricultureCountEstimate::query()
                ->where('municipality_id', $location->id)
                ->whereNotNull('barangay_id')
                ->select('barangay_id', DB::raw('COUNT(*) as records_cnt'), DB::raw('SUM(area_sqm) as total_sqm'))
                ->groupBy('barangay_id')
                ->get();

            foreach ($agriRows as $ar) {
                $agriByBarangayId[(int) $ar->barangay_id] = [
                    'records' => (int) $ar->records_cnt,
                    'area_sqm' => round((float) $ar->total_sqm, 2),
                ];
            }
        }

        // Preload geocoded facilities for distance measurements
        $facilities = $hasFacilities
            ? Facility::query()
                ->where('municipality_id', $location->id)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->get(['id', 'sector', 'latitude', 'longitude'])
            : collect();

        $barangaysQuery = Barangay::query()
            ->where('municipality_id', $location->id)
            ->with(['populationFigure', 'placeDescription'])
            ->orderBy('brgy_name');

        if ($hasFacilities) {
            $barangaysQuery->withCount([
                'facilities as health_facilities_count' => fn ($q) => $q->where('sector', 'health'),
                'facilities as schools_count' => fn ($q) => $q->where('sector', 'education'),
                'facilities as safety_facilities_count' => fn ($q) => $q->where('sector', 'safety'),
            ]);
        }

        $barangays = $barangaysQuery->get();

        $munLat = $location->center_lat !== null ? (float) $location->center_lat : null;
        $munLng = $location->center_lng !== null ? (float) $location->center_lng : null;

        $analyzedBarangays = [];
        $totalUnderservedPop = 0;
        $unmetDomainCounts = [
            'health' => 0,
            'education' => 0,
            'safety' => 0,
            'road_network' => 0,
            'telecom' => 0,
        ];

        foreach ($barangays as $brgy) {
            $bId = (int) $brgy->id;
            $pop = $brgy->populationFigure?->population !== null ? (int) $brgy->populationFigure->population : null;
            $psgCode = (string) $brgy->psg_code;

            $healthCount = (int) ($brgy->health_facilities_count ?? 0);
            $schoolsCount = (int) ($brgy->schools_count ?? 0);
            $safetyCount = (int) ($brgy->safety_facilities_count ?? 0);
            $telecomCount = ($telecomByBarangayId[$bId] ?? 0) + ($telecomByPsgc[$psgCode] ?? 0);
            $roadLengthM = $roadLengthsByBarangayId[$bId] ?? 0.0;
            $agriData = $agriByBarangayId[$bId] ?? null;

            $bLat = $brgy->center_lat !== null ? (float) $brgy->center_lat : null;
            $bLng = $brgy->center_lng !== null ? (float) $brgy->center_lng : null;

            // Distance to municipal center
            $distToCenterKm = ($bLat !== null && $bLng !== null && $munLat !== null && $munLng !== null)
                ? round(SpatialMetrics::haversineKm($bLat, $bLng, $munLat, $munLng), 2)
                : null;

            // Distance to nearest geocoded facility
            $distToNearestFacilityKm = null;
            if ($bLat !== null && $bLng !== null && $facilities->isNotEmpty()) {
                $minD = PHP_FLOAT_MAX;
                foreach ($facilities as $fac) {
                    $d = SpatialMetrics::haversineKm($bLat, $bLng, (float) $fac->latitude, (float) $fac->longitude);
                    if ($d < $minD) {
                        $minD = $d;
                    }
                }
                if ($minD < PHP_FLOAT_MAX) {
                    $distToNearestFacilityKm = round($minD, 2);
                }
            }

            // Unmet development domains
            $unmetDomains = [];
            if ($healthCount === 0) {
                $unmetDomains[] = 'health';
                $unmetDomainCounts['health']++;
            }
            if ($schoolsCount === 0) {
                $unmetDomains[] = 'education';
                $unmetDomainCounts['education']++;
            }
            if ($safetyCount === 0) {
                $unmetDomains[] = 'safety';
                $unmetDomainCounts['safety']++;
            }
            if ($hasRoadData && $roadLengthM <= 0.0) {
                $unmetDomains[] = 'road_network';
                $unmetDomainCounts['road_network']++;
            }
            if ($hasTelecomData && $telecomCount === 0) {
                $unmetDomains[] = 'telecom';
                $unmetDomainCounts['telecom']++;
            }

            $unmetCount = count($unmetDomains);
            $isUnderserved = $unmetCount >= 2 || ($healthCount === 0 && $schoolsCount === 0);

            if ($isUnderserved && $pop !== null) {
                $totalUnderservedPop += $pop;
            }

            $needLevel = match (true) {
                $unmetCount >= 4 => 'critical',
                $unmetCount >= 3 => 'high',
                $unmetCount >= 2 => 'moderate',
                $unmetCount === 1 => 'low',
                default => 'well_served',
            };

            $analyzedBarangays[] = [
                'barangay_id' => $bId,
                'psg_code' => $psgCode,
                'name' => (string) ($brgy->brgy_name ?: $brgy->placeDescription?->name ?: $psgCode),
                'population' => $pop,
                'area_km2' => $brgy->area_km2 !== null ? round((float) $brgy->area_km2, 4) : null,
                'urban_rural' => match (strtoupper((string) $brgy->placeDescription?->urban_rural)) {
                    'U' => 'Urban',
                    'R' => 'Rural',
                    default => null,
                },
                'need_level' => $needLevel,
                'is_underserved' => $isUnderserved,
                'unmet_domains_count' => $unmetCount,
                'unmet_domains' => $unmetDomains,
                'access_status' => [
                    'has_health_facility' => $healthCount > 0,
                    'health_facilities_count' => $healthCount,
                    'has_school' => $schoolsCount > 0,
                    'schools_count' => $schoolsCount,
                    'has_safety_facility' => $safetyCount > 0,
                    'safety_facilities_count' => $safetyCount,
                    'has_road_network_records' => $roadLengthM > 0,
                    'road_length_meters' => $roadLengthM,
                    'has_telecom_site' => $telecomCount > 0,
                    'telecom_sites_count' => $telecomCount,
                    'has_agriculture_estimates' => $agriData !== null,
                    'agriculture_area_sqm' => $agriData['area_sqm'] ?? null,
                ],
                'spatial_metrics' => [
                    'distance_to_municipal_center_km' => $distToCenterKm,
                    'distance_to_nearest_facility_km' => $distToNearestFacilityKm,
                ],
            ];
        }

        // Sort: most unmet domains first, then highest population
        usort($analyzedBarangays, function ($a, $b) {
            $dCmp = $b['unmet_domains_count'] <=> $a['unmet_domains_count'];
            if ($dCmp !== 0) {
                return $dCmp;
            }

            return ($b['population'] ?? -1) <=> ($a['population'] ?? -1);
        });

        $totalBgys = count($analyzedBarangays);
        $underservedList = array_values(array_filter($analyzedBarangays, fn ($b) => $b['is_underserved']));
        $underservedCount = count($underservedList);

        return [
            'status' => 'available',
            'location_level' => 'municipality',
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'summary' => [
                'total_barangays' => $totalBgys,
                'underserved_barangays_count' => $underservedCount,
                'underserved_population' => $totalUnderservedPop,
                'underserved_population_share_pct' => ($munPopulation !== null && $munPopulation > 0)
                    ? round(($totalUnderservedPop / $munPopulation) * 100, 1)
                    : null,
                'by_need_level' => [
                    'critical' => count(array_filter($analyzedBarangays, fn ($b) => $b['need_level'] === 'critical')),
                    'high' => count(array_filter($analyzedBarangays, fn ($b) => $b['need_level'] === 'high')),
                    'moderate' => count(array_filter($analyzedBarangays, fn ($b) => $b['need_level'] === 'moderate')),
                    'low' => count(array_filter($analyzedBarangays, fn ($b) => $b['need_level'] === 'low')),
                    'well_served' => count(array_filter($analyzedBarangays, fn ($b) => $b['need_level'] === 'well_served')),
                ],
                'barangays_without_facilities_by_sector' => [
                    'health' => $unmetDomainCounts['health'],
                    'education' => $unmetDomainCounts['education'],
                    'safety' => $unmetDomainCounts['safety'],
                    'road_network' => $hasRoadData ? $unmetDomainCounts['road_network'] : 'dataset_empty',
                    'telecom' => $hasTelecomData ? $unmetDomainCounts['telecom'] : 'dataset_empty',
                ],
            ],
            'underserved_barangays' => $underservedList,
            'all_barangays' => $analyzedBarangays,
        ];
    }
}

