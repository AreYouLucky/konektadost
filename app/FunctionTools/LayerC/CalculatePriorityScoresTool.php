<?php

namespace App\FunctionTools\LayerC;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\Support\LocationResolver;
use App\FunctionTools\Support\SpatialMetrics;
use App\Models\Barangay;
use App\Models\Facility;
use App\Models\Municipality;
use App\Models\TelecomSite;
use App\Support\Assessment\AssessmentComputationCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer C (C3) Function Tool: calculate_priority_scores
 *
 * Configurable, explainable composite priority scoring mechanism (0 to 100):
 * - Normalizes indicators across constituent barangays.
 * - Dynamically redistributes weights if a domain dataset is missing/empty.
 * - Avoids double-counting correlated evidence.
 * - Emits granular component scores, active weight documentation, and scoring version.
 */
class CalculatePriorityScoresTool implements FunctionToolInterface
{
    public const SCORING_VERSION = '1.0.0';

    /**
     * Default component weights (sum = 100.0).
     */
    public const DEFAULT_WEIGHTS = [
        'population_need' => 25.0,
        'health_access_gap' => 20.0,
        'education_access_gap' => 15.0,
        'road_accessibility_gap' => 15.0,
        'digital_connectivity_gap' => 10.0,
        'safety_and_drrm_gap' => 10.0,
        'geographic_isolation' => 5.0,
    ];

    public function name(): string
    {
        return 'calculate_priority_scores';
    }

    public function layer(): string
    {
        return 'C';
    }

    public function description(): string
    {
        return 'Calculate transparent, explainable composite priority scores (0-100) for every barangay in a municipality based on population need, infrastructure deficits, and geographic isolation, with configurable normalized weights.';
    }

    public function parameters(): array
    {
        return [
            ...LocationResolver::locationParameterSchema(),
            'custom_weights' => [
                'type' => 'object',
                'description' => 'Optional override for scoring component weights (keys: population_need, health_access_gap, education_access_gap, road_accessibility_gap, digital_connectivity_gap, safety_and_drrm_gap, geographic_isolation).',
            ],
        ];
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
        $customWeights = isset($args['custom_weights']) && is_array($args['custom_weights']) ? $args['custom_weights'] : null;

        return $this->forLocation($location, $customWeights);
    }

    /**
     * Calculate composite priority scores for a resolved location model.
     *
     * @param  array<string, float|int>|null  $customWeights
     * @return array<string, mixed>
     */
    public function forLocation(Model $location, ?array $customWeights = null): array
    {
        return app(AssessmentComputationCache::class)->remember(
            self::class, $location, ['custom_weights' => $customWeights],
            fn () => $this->computeForLocation($location, $customWeights)
        );
    }

    private function computeForLocation(Model $location, ?array $customWeights): array
    {
        if (! $location instanceof Municipality) {
            return [
                'status' => 'not_applicable',
                'location_level' => LocationResolver::levelOf($location),
                'location_id' => (int) $location->id,
                'location_name' => LocationResolver::nameOf($location),
                'reason' => 'calculate_priority_scores requires a Municipality model.',
                'summary' => null,
                'barangays' => [],
            ];
        }

        $popStats = app(GetPopulationStatisticsTool::class)->forLocation($location, includeBarangayDistribution: false);
        $cityPopulation = $popStats['population_current'] ?? $location->populationFigure?->population;

        $datasetsTool = app(GetAvailableDatasetsTool::class);
        $datasetAudit = $datasetsTool->forLocation($location);
        $hasRoadData = in_array($datasetAudit['datasets']['roads']['status'] ?? null, ['available', 'partial'], true);
        $hasTelecomData = in_array($datasetAudit['datasets']['telecom_sites']['status'] ?? null, ['available', 'partial'], true);

        // 1. Establish and normalize active weights
        $rawWeights = array_merge(self::DEFAULT_WEIGHTS, $customWeights ?? []);

        // Dynamically adjust weights for empty datasets so missing data is never penalized
        $activeWeights = $rawWeights;
        if (! $hasRoadData) {
            $activeWeights['road_accessibility_gap'] = 0.0;
        }
        if (! $hasTelecomData) {
            $activeWeights['digital_connectivity_gap'] = 0.0;
        }

        $activeSum = array_sum($activeWeights);
        $normalizedWeights = [];
        foreach ($activeWeights as $k => $w) {
            $normalizedWeights[$k] = $activeSum > 0 ? round(($w / $activeSum) * 100.0, 2) : 0.0;
        }

        // 2. Preload domain infrastructure data
        $hasFacilities = Schema::hasTable('facilities');
        $hasRoads = Schema::hasTable('road_barangay');
        $hasTelecom = Schema::hasTable('telecom_sites');

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

        $geocodedFacilities = $hasFacilities
            ? Facility::query()
                ->where('municipality_id', $location->id)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->get(['id', 'sector', 'latitude', 'longitude'])
            : collect();

        $barangaysQuery = Barangay::query()
            ->select(['id', 'municipality_id', 'psg_code', 'brgy_name', 'area_km2', 'center_lat', 'center_lng'])
            ->where('municipality_id', $location->id)
            ->with(['populationFigure', 'placeDescription']);

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

        // 3. Compute baseline maximums for normalization
        $rawRows = [];
        foreach ($barangays as $brgy) {
            $pop = $brgy->populationFigure?->population;
            $area = $brgy->area_km2 !== null && $brgy->area_km2 > 0 ? (float) $brgy->area_km2 : null;
            $healthCount = (int) ($brgy->health_facilities_count ?? 0);
            $schoolsCount = (int) ($brgy->schools_count ?? 0);
            $safetyCount = (int) ($brgy->safety_facilities_count ?? 0);
            $psgCode = (string) $brgy->psg_code;
            $telecomCount = ($telecomByBarangayId[(int) $brgy->id] ?? 0) + ($telecomByPsgc[$psgCode] ?? 0);
            $roadLengthM = $roadLengthsByBarangayId[(int) $brgy->id] ?? 0.0;

            $nearestKm = null;
            if ($brgy->center_lat !== null && $brgy->center_lng !== null && $geocodedFacilities->isNotEmpty()) {
                $bLat = (float) $brgy->center_lat;
                $bLng = (float) $brgy->center_lng;
                $minDist = PHP_FLOAT_MAX;
                foreach ($geocodedFacilities as $fac) {
                    $d = SpatialMetrics::haversineKm($bLat, $bLng, (float) $fac->latitude, (float) $fac->longitude);
                    if ($d < $minDist) {
                        $minDist = $d;
                    }
                }
                if ($minDist < PHP_FLOAT_MAX) {
                    $nearestKm = round($minDist, 2);
                }
            }

            $distToCenterKm = ($brgy->center_lat !== null && $brgy->center_lng !== null && $munLat !== null && $munLng !== null)
                ? round(SpatialMetrics::haversineKm((float) $brgy->center_lat, (float) $brgy->center_lng, $munLat, $munLng), 2)
                : null;

            $rawRows[] = [
                'id' => (int) $brgy->id,
                'psg_code' => $psgCode,
                'name' => trim((string) ($brgy->brgy_name ?: $brgy->placeDescription?->name ?: $psgCode)),
                'population' => $pop !== null ? (int) $pop : null,
                'area_km2' => $area !== null ? round($area, 4) : null,
                'density' => ($pop !== null && $area !== null && $area > 0) ? round($pop / $area, 2) : null,
                'urban_rural' => match (strtoupper((string) $brgy->placeDescription?->urban_rural)) {
                    'U' => 'Urban',
                    'R' => 'Rural',
                    default => null,
                },
                'health_facilities_count' => $healthCount,
                'schools_count' => $schoolsCount,
                'safety_facilities_count' => $safetyCount,
                'telecom_sites_count' => $telecomCount,
                'total_facilities_count' => $healthCount + $schoolsCount + $safetyCount + $telecomCount,
                'road_length_meters' => $roadLengthM,
                'nearest_facility_km' => $nearestKm,
                'distance_to_center_km' => $distToCenterKm,
            ];
        }

        $sumPop = array_sum(array_map(fn ($r) => $r['population'] ?? 0, $rawRows));
        $denominatorPop = ($cityPopulation !== null && $cityPopulation > 0) ? $cityPopulation : $sumPop;
        $populatedRows = array_values(array_filter($rawRows, fn ($r) => ($r['population'] ?? 0) > 0));
        $avgBarangayPop = count($populatedRows) > 0 ? $sumPop / count($populatedRows) : 0;
        $maxPop = max([1, ...array_map(fn ($r) => $r['population'] ?? 0, $rawRows)]);
        $maxDensity = max([1.0, ...array_map(fn ($r) => $r['density'] ?? 0.0, $rawRows)]);

        $rows = [];
        $highPriorityCount = 0;
        $underservedBarangaysCount = 0;
        $underservedPopulation = 0;
        $urbanCount = 0;
        $ruralCount = 0;

        foreach ($rawRows as $row) {
            if ($row['urban_rural'] === 'Urban') {
                $urbanCount++;
            } elseif ($row['urban_rural'] === 'Rural') {
                $ruralCount++;
            }

            $pop = $row['population'];
            $share = ($pop !== null && $denominatorPop > 0)
                ? round($pop / $denominatorPop * 100, 2)
                : null;

            // Component 1: Population Need (Relative Population + Density)
            $popWeight = $normalizedWeights['population_need'] ?? 25.0;
            $popNorm = $pop !== null && $maxPop > 0 ? ($pop / $maxPop) : 0.0;
            $densityNorm = $row['density'] !== null && $maxDensity > 0 ? ($row['density'] / $maxDensity) : 0.0;
            $popComponentScore = round(($popNorm * 0.7 + $densityNorm * 0.3) * $popWeight, 2);

            // Component 2: Health Access Gap
            $healthWeight = $normalizedWeights['health_access_gap'] ?? 20.0;
            $healthComponentScore = $row['health_facilities_count'] === 0
                ? $healthWeight
                : ($row['health_facilities_count'] === 1 ? round($healthWeight * 0.3, 2) : 0.0);

            // Component 3: Education Access Gap
            $eduWeight = $normalizedWeights['education_access_gap'] ?? 15.0;
            $eduComponentScore = $row['schools_count'] === 0
                ? $eduWeight
                : ($row['schools_count'] === 1 ? round($eduWeight * 0.3, 2) : 0.0);

            // Component 4: Road Accessibility Gap
            $roadWeight = $normalizedWeights['road_accessibility_gap'] ?? 0.0;
            $roadComponentScore = ($hasRoadData && $roadWeight > 0 && $row['road_length_meters'] <= 0.0)
                ? $roadWeight
                : 0.0;

            // Component 5: Digital Connectivity Gap
            $digitalWeight = $normalizedWeights['digital_connectivity_gap'] ?? 0.0;
            $digitalComponentScore = ($hasTelecomData && $digitalWeight > 0 && $row['telecom_sites_count'] === 0)
                ? $digitalWeight
                : 0.0;

            // Component 6: Safety and DRRM Gap
            $safetyWeight = $normalizedWeights['safety_and_drrm_gap'] ?? 10.0;
            $safetyComponentScore = $row['safety_facilities_count'] === 0 ? $safetyWeight : 0.0;

            // Component 7: Geographic Isolation (Distance to Center / Nearest Facility)
            $isoWeight = $normalizedWeights['geographic_isolation'] ?? 5.0;
            $distKm = $row['nearest_facility_km'] ?? $row['distance_to_center_km'] ?? 0.0;
            $isoComponentScore = round(min($isoWeight, ($distKm / 6.0) * $isoWeight), 2);

            $compositeScore = round(
                $popComponentScore
                + $healthComponentScore
                + $eduComponentScore
                + $roadComponentScore
                + $digitalComponentScore
                + $safetyComponentScore
                + $isoComponentScore,
                1
            );

            // Backward-compatible component scores
            $backwardDeficitScore = round(
                ($row['health_facilities_count'] === 0 ? 12.0 : 0.0)
                + ($row['schools_count'] === 0 ? 10.0 : 0.0)
                + ($row['safety_facilities_count'] === 0 ? 7.0 : 0.0)
                + ($row['telecom_sites_count'] === 0 ? 6.0 : 0.0),
                1
            );
            $backwardIsoScore = $row['nearest_facility_km'] !== null
                ? round(min(15.0, ($row['nearest_facility_km'] / 5.0) * 15.0), 1)
                : ($row['total_facilities_count'] === 0 ? 7.5 : 0.0);

            $tier = match (true) {
                $compositeScore >= 70.0 => 'tier_1_urgent',
                $compositeScore >= 50.0 => 'tier_2_high',
                $compositeScore >= 30.0 => 'tier_3_moderate',
                default => 'tier_4_low',
            };

            $relevantFacilities = $row['total_facilities_count'];
            if ($pop === null) {
                $priority = 'unmeasured';
                $priorityReason = 'No matched population figure.';
            } elseif ($relevantFacilities === 0 && $pop >= $avgBarangayPop) {
                $priority = 'high';
                $priorityReason = 'Above-average population with 0 recorded local facilities.';
                $highPriorityCount++;
                $underservedBarangaysCount++;
                $underservedPopulation += $pop;
            } elseif ($relevantFacilities === 0) {
                $priority = 'moderate';
                $priorityReason = '0 recorded local facilities.';
                $underservedBarangaysCount++;
                $underservedPopulation += $pop;
            } else {
                $priority = 'covered';
                $priorityReason = "{$relevantFacilities} recorded local ".($relevantFacilities === 1 ? 'facility' : 'facilities').'.';
            }

            $rows[] = [
                ...$row,
                'population_share' => $share,
                'priority_level' => $priority,
                'priority_tier' => $tier,
                'priority_reason' => $priorityReason,
                'priority_score' => $pop === null ? null : $compositeScore,
                'composite_score' => $pop === null ? null : $compositeScore,
                'score_components' => [
                    'population_score' => round($popNorm * 35.0, 1),
                    'density_score' => round($densityNorm * 15.0, 1),
                    'deficit_score' => $backwardDeficitScore,
                    'isolation_score' => $backwardIsoScore,
                    'normalized_components' => [
                        'population_need' => $popComponentScore,
                        'health_access_gap' => $healthComponentScore,
                        'education_access_gap' => $eduComponentScore,
                        'road_accessibility_gap' => $roadComponentScore,
                        'digital_connectivity_gap' => $digitalComponentScore,
                        'safety_and_drrm_gap' => $safetyComponentScore,
                        'geographic_isolation' => $isoComponentScore,
                    ],
                ],
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $scoreCmp = ($b['priority_score'] ?? -1) <=> ($a['priority_score'] ?? -1);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return ($b['population'] ?? -1) <=> ($a['population'] ?? -1);
        });

        $mostPopulous = $rows[0] ?? null;
        foreach ($rows as $r) {
            if (($r['population'] ?? 0) === $maxPop) {
                $mostPopulous = $r;
                break;
            }
        }

        $highestDensity = null;
        foreach ($rows as $r) {
            if ($r['density'] !== null && $r['density'] === $maxDensity) {
                $highestDensity = $r;
                break;
            }
        }

        return [
            'scoring_version' => self::SCORING_VERSION,
            'location_level' => 'municipality',
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'methodology_explanation' => 'Composite score (0-100) combining normalized population size, density pressure, multi-sector facility deficits (health, education, road network, telecom, safety), and geographic distance. Inactive or empty municipal datasets are dynamically zeroed and their weights redistributed across available domains.',
            'default_weights' => self::DEFAULT_WEIGHTS,
            'active_weights' => $normalizedWeights,
            'summary' => [
                'total_barangays' => count($rows),
                'total_population' => $sumPop,
                'average_population' => count($populatedRows) > 0 ? (int) round($avgBarangayPop) : null,
                'urban_count' => $urbanCount,
                'rural_count' => $ruralCount,
                'high_priority_count' => $highPriorityCount,
                'underserved_barangays_count' => $underservedBarangaysCount,
                'underserved_population' => $underservedPopulation,
                'underserved_population_share' => $denominatorPop > 0
                    ? round($underservedPopulation / $denominatorPop * 100, 1)
                    : null,
                'most_populous' => $mostPopulous ? [
                    'name' => $mostPopulous['name'],
                    'population' => $mostPopulous['population'],
                    'population_share' => $mostPopulous['population_share'],
                ] : null,
                'highest_density' => $highestDensity ? [
                    'name' => $highestDensity['name'],
                    'density' => $highestDensity['density'],
                    'population' => $highestDensity['population'],
                ] : null,
            ],
            'scoring_weights' => [
                'population_score_max' => 35,
                'density_score_max' => 15,
                'deficit_score_max' => 35,
                'isolation_score_max' => 15,
                'total_max' => 100,
            ],
            'barangays' => $rows,
        ];
    }
}
