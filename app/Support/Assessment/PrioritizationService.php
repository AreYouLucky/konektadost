<?php

namespace App\Support\Assessment;

use App\Models\Barangay;
use App\Models\CollectionYear;
use App\Models\Facility;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use App\Models\TelecomSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Layer C Function Tools:
 * - detect_development_gaps
 * - calculate_priority_scores
 * - identify_underserved_areas
 *
 * Pure mathematical gap detection and barangay composite priority scoring
 * (0 to 100) based on population share, density pressure, facility deficits,
 * and Haversine distance to the nearest recorded facility.
 */
class PrioritizationService
{
    public function __construct(
        private readonly LocationAssessor $assessor,
        private readonly MunicipalityProfileService $profileService,
    ) {}

    /**
     * Tool: detect_development_gaps
     *
     * Mathematically detects indicator shortfalls and service pressures from
     * LocationAssessor measurements against dynamic benchmarks, and computes
     * deterministic severity scores (0-100).
     *
     * @return array{
     *     total_measured: int,
     *     total_gaps_detected: int,
     *     high_confidence_gaps: int,
     *     provisional_gaps: int,
     *     gaps: list<array<string, mixed>>
     * }
     */
    public function detectDevelopmentGaps(Model $location, ?string $dimension = null): array
    {
        $results = $this->assessor->assess($location, $dimension);
        $popStats = $this->profileService->getPopulationStatistics($location);
        $population = $popStats['population_current'] ?? $location->populationFigure?->population;

        $measuredCount = 0;
        $gaps = [];

        foreach ($results as $result) {
            if ($result->status !== IndicatorResult::STATUS_NO_DATA && $result->value !== null) {
                $measuredCount++;
            }

            if (! $result->isShortfall()) {
                continue;
            }

            $gapPct = $result->gap !== null ? abs($result->gap) : 0.0;
            $confidenceMultiplier = $result->confidence === IndicatorResult::CONFIDENCE_HIGH ? 1.0 : 0.65;

            // Normalize relative deviation into a 0-100 severity score
            $rawMagnitude = min(100.0, $gapPct);
            $severityScore = round($rawMagnitude * $confidenceMultiplier, 1);

            $severityLevel = match (true) {
                $severityScore >= 75.0 => 'critical',
                $severityScore >= 50.0 => 'high',
                $severityScore >= 25.0 => 'moderate',
                default => 'low',
            };

            $gaps[] = [
                'indicator_key' => $result->indicator->key,
                'label' => $result->indicator->label,
                'dimension' => $result->indicator->dimension,
                'gap_type' => $result->indicator->higherIsBetter ? 'service_shortfall' : 'population_pressure',
                'measured_value' => $result->value,
                'benchmark_value' => $result->benchmark,
                'unit' => $result->indicator->unit,
                'gap_pct' => $result->gap !== null ? round($result->gap, 2) : null,
                'confidence' => $result->confidence,
                'severity_score' => $severityScore,
                'severity_level' => $severityLevel,
                'affected_population' => $population,
                'source' => $result->indicator->source,
                'reason' => $result->reason,
            ];
        }

        usort($gaps, fn (array $a, array $b) => ($b['severity_score'] <=> $a['severity_score'])
            ?: strcmp($a['label'], $b['label']));

        $highConfidenceGaps = count(array_filter($gaps, fn ($g) => $g['confidence'] === IndicatorResult::CONFIDENCE_HIGH));

        return [
            'total_measured' => $measuredCount,
            'total_gaps_detected' => count($gaps),
            'high_confidence_gaps' => $highConfidenceGaps,
            'provisional_gaps' => count($gaps) - $highConfidenceGaps,
            'gaps' => $gaps,
        ];
    }

    /**
     * Tool: calculate_priority_scores / identify_underserved_areas
     *
     * Computes a transparent composite priority score (0-100) for every barangay
     * in a municipality:
     * - population_score (0-35): Barangay population relative to largest barangay
     * - density_score (0-15): Barangay density relative to max barangay density
     * - deficit_score (0-35): Missing facility sectors (health: 12, education: 10, safety: 7, telecom: 6)
     * - isolation_score (0-15): Distance to nearest recorded facility in the municipality (up to 5 km+)
     *
     * @return array{
     *     summary: array<string, mixed>,
     *     scoring_weights: array<string, int>,
     *     barangays: list<array<string, mixed>>
     * }
     */
    public function calculatePriorityScores(Municipality $municipality, string $focus = 'general'): array
    {
        if (! Schema::hasTable('barangays') || ! Schema::hasColumn('barangays', 'municipality_id')) {
            return [
                'summary' => [
                    'total_barangays' => 0,
                    'total_population' => 0,
                    'average_population' => null,
                    'urban_count' => 0,
                    'rural_count' => 0,
                    'high_priority_count' => 0,
                    'underserved_barangays_count' => 0,
                    'underserved_population' => 0,
                    'underserved_population_share' => null,
                    'most_populous' => null,
                    'highest_density' => null,
                ],
                'scoring_weights' => [
                    'population' => 35,
                    'density' => 15,
                    'facility_deficit' => 35,
                    'isolation_distance' => 15,
                ],
                'barangays' => [],
            ];
        }

        $popStats = $this->profileService->getPopulationStatistics($municipality);
        $cityPopulation = $popStats['population_current'] ?? $municipality->populationFigure?->population;

        $hasFacilities = Schema::hasTable('facilities');
        $hasTelecomSites = Schema::hasTable('telecom_sites');
        $prefix = str_starts_with((string) $municipality->psg_code, '13806')
            ? substr((string) $municipality->psg_code, 0, 5)
            : substr((string) $municipality->psg_code, 0, 7);

        $telecomByBarangayId = [];
        $telecomByPsgc = [];

        if ($hasTelecomSites) {
            $telecomRows = TelecomSite::query()
                ->select(['id', 'barangay_id', 'barangay_psgc'])
                ->where(function ($q) use ($municipality, $prefix) {
                    $q->where('municipality_id', $municipality->id);
                    if ($prefix !== '') {
                        $q->orWhere('barangay_psgc', 'like', $prefix.'%');
                    }
                })
                ->get();

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

        // Geocoded facilities inside the municipality for nearest-facility distance calculation
        $geocodedFacilities = $hasFacilities
            ? Facility::query()
                ->where('municipality_id', $municipality->id)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->get(['id', 'sector', 'latitude', 'longitude'])
            : collect();

        $barangayQuery = Barangay::query()
            ->select(['id', 'municipality_id', 'psg_code', 'brgy_name', 'area_km2', 'center_lat', 'center_lng'])
            ->where('municipality_id', $municipality->id)
            ->with(['populationFigure', 'placeDescription']);

        if ($hasFacilities) {
            $barangayQuery->withCount([
                'facilities as health_facilities_count' => fn ($q) => $q->where('sector', 'health'),
                'facilities as schools_count' => fn ($q) => $q->where('sector', 'education'),
                'facilities as safety_facilities_count' => fn ($q) => $q->where('sector', 'safety'),
            ]);
        }

        $barangays = $barangayQuery->get();

        if ($barangays->isEmpty() && $prefix !== '') {
            $fallbackQuery = Barangay::query()
                ->select(['id', 'municipality_id', 'psg_code', 'brgy_name', 'area_km2', 'center_lat', 'center_lng'])
                ->where('psg_code', 'like', $prefix.'%')
                ->with(['populationFigure', 'placeDescription']);

            if ($hasFacilities) {
                $fallbackQuery->withCount([
                    'facilities as health_facilities_count' => fn ($q) => $q->where('sector', 'health'),
                    'facilities as schools_count' => fn ($q) => $q->where('sector', 'education'),
                    'facilities as safety_facilities_count' => fn ($q) => $q->where('sector', 'safety'),
                ]);
            }

            $barangays = $fallbackQuery->get();
        }

        $rawRows = [];

        if ($barangays->isNotEmpty()) {
            foreach ($barangays as $brgy) {
                $pop = $brgy->populationFigure?->population;
                $area = $brgy->area_km2 !== null && $brgy->area_km2 > 0 ? (float) $brgy->area_km2 : null;
                $healthCount = (int) ($brgy->health_facilities_count ?? 0);
                $schoolsCount = (int) ($brgy->schools_count ?? 0);
                $safetyCount = (int) ($brgy->safety_facilities_count ?? 0);
                $psgCode = (string) $brgy->psg_code;
                $telecomCount = ($telecomByBarangayId[(int) $brgy->id] ?? 0) + ($telecomByPsgc[$psgCode] ?? 0);

                $nearestKm = null;
                if ($brgy->center_lat !== null && $brgy->center_lng !== null && $geocodedFacilities->isNotEmpty()) {
                    $bLat = (float) $brgy->center_lat;
                    $bLng = (float) $brgy->center_lng;
                    $minDist = PHP_FLOAT_MAX;
                    foreach ($geocodedFacilities as $fac) {
                        $d = SectorAnalysisService::haversineKm($bLat, $bLng, (float) $fac->latitude, (float) $fac->longitude);
                        if ($d < $minDist) {
                            $minDist = $d;
                        }
                    }
                    if ($minDist < PHP_FLOAT_MAX) {
                        $nearestKm = round($minDist, 2);
                    }
                }

                $rawRows[] = [
                    'psg_code' => $psgCode,
                    'name' => trim((string) ($brgy->brgy_name ?: $brgy->placeDescription?->name ?: $brgy->psg_code)),
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
                    'nearest_facility_km' => $nearestKm,
                ];
            }
        } elseif ($prefix !== '' && Schema::hasTable('place_descriptions') && Schema::hasTable('population_figures')) {
            $activeYear = CollectionYear::activeYear();
            $latestReleaseId = PlaceDescription::latestReleaseId();

            $placeQuery = PlaceDescription::query()
                ->where('place_descriptions.level', PlaceDescription::LEVEL_BARANGAY)
                ->where('place_descriptions.psg_code', 'like', $prefix.'%')
                ->leftJoin('population_figures', function ($join) use ($activeYear) {
                    $join->on('population_figures.place_description_id', '=', 'place_descriptions.id')
                        ->where('population_figures.year', $activeYear);
                })
                ->select([
                    'place_descriptions.psg_code',
                    'place_descriptions.name',
                    'place_descriptions.urban_rural',
                    'population_figures.population',
                ]);

            if ($latestReleaseId > 0) {
                $placeQuery->where('place_descriptions.release_id', $latestReleaseId);
            }

            foreach ($placeQuery->get() as $fig) {
                $psgCode = (string) $fig->psg_code;
                $telecomCount = $telecomByPsgc[$psgCode] ?? 0;

                $rawRows[] = [
                    'psg_code' => $psgCode,
                    'name' => trim((string) $fig->name),
                    'population' => $fig->population !== null ? (int) $fig->population : null,
                    'area_km2' => null,
                    'density' => null,
                    'urban_rural' => match (strtoupper((string) $fig->urban_rural)) {
                        'U' => 'Urban',
                        'R' => 'Rural',
                        default => null,
                    },
                    'health_facilities_count' => 0,
                    'schools_count' => 0,
                    'safety_facilities_count' => 0,
                    'telecom_sites_count' => $telecomCount,
                    'total_facilities_count' => $telecomCount,
                    'nearest_facility_km' => null,
                ];
            }
        }

        $sumPop = array_sum(array_map(fn ($r) => $r['population'] ?? 0, $rawRows));
        $denominatorPop = ($cityPopulation !== null && $cityPopulation > 0) ? $cityPopulation : $sumPop;

        $populatedRows = array_values(array_filter($rawRows, fn ($r) => ($r['population'] ?? 0) > 0));
        $avgBarangayPop = count($populatedRows) > 0 ? $sumPop / count($populatedRows) : 0;
        $maxPop = max(1, ...array_map(fn ($r) => $r['population'] ?? 0, $rawRows));
        $maxDensity = max(1.0, ...array_map(fn ($r) => $r['density'] ?? 0.0, $rawRows));

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

            $relevantFacilities = match ($focus) {
                IndicatorCatalog::DIMENSION_HEALTH => $row['health_facilities_count'],
                IndicatorCatalog::DIMENSION_EDUCATION => $row['schools_count'],
                IndicatorCatalog::DIMENSION_CONNECTIVITY => $row['telecom_sites_count'],
                IndicatorCatalog::DIMENSION_DRR_SAFETY => $row['safety_facilities_count'],
                default => $row['total_facilities_count'],
            };

            // Component 1: Population Score (0 - 35)
            $popScore = $pop !== null && $maxPop > 0 ? round(($pop / $maxPop) * 35, 1) : 0.0;

            // Component 2: Density Score (0 - 15)
            $densityScore = $row['density'] !== null && $maxDensity > 0
                ? round(($row['density'] / $maxDensity) * 15, 1)
                : 0.0;

            // Component 3: Infrastructure Deficit Score (0 - 35)
            $deficitScore = 0.0;
            if ($row['health_facilities_count'] === 0) {
                $deficitScore += 12.0;
            }
            if ($row['schools_count'] === 0) {
                $deficitScore += 10.0;
            }
            if ($row['safety_facilities_count'] === 0) {
                $deficitScore += 7.0;
            }
            if ($row['telecom_sites_count'] === 0) {
                $deficitScore += 6.0;
            }

            // Component 4: Isolation / Distance Score (0 - 15, maxed at 5 km)
            $isolationScore = $row['nearest_facility_km'] !== null
                ? round(min(15.0, ($row['nearest_facility_km'] / 5.0) * 15.0), 1)
                : ($relevantFacilities === 0 ? 7.5 : 0.0);

            $totalPriorityScore = $pop === null
                ? null
                : round($popScore + $densityScore + $deficitScore + $isolationScore, 1);

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
                'priority_reason' => $priorityReason,
                'priority_score' => $totalPriorityScore,
                'score_components' => [
                    'population_score' => $popScore,
                    'density_score' => $densityScore,
                    'deficit_score' => $deficitScore,
                    'isolation_score' => $isolationScore,
                ],
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $popCmp = ($b['population'] ?? -1) <=> ($a['population'] ?? -1);

            return $popCmp !== 0 ? $popCmp : strcasecmp($a['name'], $b['name']);
        });

        $mostPopulous = $rows[0] ?? null;
        if ($mostPopulous !== null && $mostPopulous['population'] === null) {
            $mostPopulous = null;
        }

        $byDensity = array_values(array_filter($rows, fn ($r) => $r['density'] !== null));
        usort($byDensity, fn ($a, $b) => ($b['density'] ?? 0) <=> ($a['density'] ?? 0));
        $highestDensity = $byDensity[0] ?? null;

        return [
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

