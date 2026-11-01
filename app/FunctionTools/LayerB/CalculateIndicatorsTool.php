<?php

namespace App\FunctionTools\LayerB;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetMunicipalityProfileTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\Support\LocationResolver;
use App\FunctionTools\Support\SpatialMetrics;
use App\Support\Assessment\IndicatorResult;
use App\Support\Assessment\LocationAssessor;
use Illuminate\Database\Eloquent\Model;

/**
 * Layer B (B1) Function Tool: calculate_indicators
 *
 * Computes holistic municipal and barangay indicators across all domains:
 * - Population density, population growth (2020 -> 2024), urban/rural distribution
 * - Facility counts and ratios per 10,000 and per 100,000 population
 * - Laboratory availability and service capacity per million
 * - Telecom site density per 10,000 and per 100,000, and 5G share
 * - Road network indicators (if road data exists)
 * - Agricultural area, production, and net income indicators
 */
class CalculateIndicatorsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'calculate_indicators';
    }

    public function layer(): string
    {
        return 'B';
    }

    public function description(): string
    {
        return 'Compute municipal and barangay indicators across all available domains (population density, growth rate, urban/rural distribution, facility ratios per 10k/100k, laboratory capacity per million, telecom density, road network indicators, and agricultural production/income indicators) compared against dynamic national benchmarks.';
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
     * Compute all indicators across all domains for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        $assessor = app(LocationAssessor::class);
        $profile = app(GetMunicipalityProfileTool::class)->forLocation($location, includeBarangayList: false);
        $popStats = app(GetPopulationStatisticsTool::class)->forLocation($location, includeBarangayDistribution: false);
        $datasetsTool = app(GetAvailableDatasetsTool::class);

        $population = $popStats['population_current'] ?? null;
        $areaKm2 = $profile['area_km2'] ?? null;

        // 1. Core catalog indicators compared against dynamic national benchmarks
        $catalogResults = array_map(
            fn (IndicatorResult $r) => $r->toArray(),
            $assessor->assess($location)
        );

        // 2. Demographic & Urban/Rural Indicators
        $bgyCounts = $profile['barangay_counts'] ?? null;
        $totalBgys = (int) ($bgyCounts['total_barangays'] ?? 0);
        $urbanBgys = (int) ($bgyCounts['urban_barangays'] ?? 0);
        $ruralBgys = (int) ($bgyCounts['rural_barangays'] ?? 0);

        $demographicIndicators = [
            'population_current' => $population,
            'population_baseline' => $popStats['population_baseline'] ?? null,
            'population_change' => $popStats['population_change'] ?? null,
            'population_growth_pct' => $popStats['growth_rate_pct'] ?? null,
            'annual_growth_rate_cagr_pct' => $popStats['annual_growth_rate_pct'] ?? null,
            'land_area_km2' => $areaKm2,
            'population_density_per_km2' => $profile['density_per_km2'] ?? null,
            'urban_barangay_share_pct' => $totalBgys > 0 ? round(($urbanBgys / $totalBgys) * 100, 1) : null,
            'rural_barangay_share_pct' => $totalBgys > 0 ? round(($ruralBgys / $totalBgys) * 100, 1) : null,
        ];

        // 3. Facility Ratios per 10,000 and per 100,000 Population
        $facQuery = $datasetsTool->facilityQueryFor($location);
        $healthCount = (clone $facQuery)->where('sector', 'health')->count();
        $hospitalCount = (clone $facQuery)->where('sector', 'health')->where('facility_type', 'hospital')->count();
        $educationCount = (clone $facQuery)->where('sector', 'education')->count();
        $safetyCount = (clone $facQuery)->where('sector', 'safety')->count();
        $telecomCount = $datasetsTool->telecomQueryFor($location)->count();

        $perCapitaRatios = [
            'health_facilities_per_10k' => SpatialMetrics::ratePer($healthCount, $population, 10_000),
            'health_facilities_per_100k' => SpatialMetrics::ratePer($healthCount, $population, 100_000),
            'hospitals_per_10k' => SpatialMetrics::ratePer($hospitalCount, $population, 10_000),
            'hospitals_per_100k' => SpatialMetrics::ratePer($hospitalCount, $population, 100_000),
            'schools_per_10k' => SpatialMetrics::ratePer($educationCount, $population, 10_000),
            'schools_per_100k' => SpatialMetrics::ratePer($educationCount, $population, 100_000),
            'safety_facilities_per_10k' => SpatialMetrics::ratePer($safetyCount, $population, 10_000),
            'safety_facilities_per_100k' => SpatialMetrics::ratePer($safetyCount, $population, 100_000),
            'telecom_sites_per_10k' => SpatialMetrics::ratePer($telecomCount, $population, 10_000),
            'telecom_sites_per_100k' => SpatialMetrics::ratePer($telecomCount, $population, 100_000),
        ];

        // 4. Agriculture Indicators (if data exists)
        $agriSummary = app(AnalyzeAgricultureTool::class)->forLocation($location);
        $agricultureIndicators = [
            'status' => $agriSummary['status'],
            'total_planted_area_sqm' => $agriSummary['total_area_sqm'] ?? null,
            'total_planted_area_hectares' => $agriSummary['total_area_hectares'] ?? null,
            'agricultural_land_share_pct' => ($agriSummary['total_area_sqm'] ?? 0) > 0 && $areaKm2 !== null && $areaKm2 > 0
                ? round((($agriSummary['total_area_sqm'] / 1_000_000) / $areaKm2) * 100, 2)
                : null,
            'estimated_revenue_php_year' => $agriSummary['financials_php_year']['estimated_revenue_php'] ?? null,
            'estimated_operating_cost_php_year' => $agriSummary['financials_php_year']['estimated_operating_cost_php'] ?? null,
            'estimated_net_income_php_year' => $agriSummary['financials_php_year']['estimated_net_php'] ?? null,
            'net_margin_pct' => $agriSummary['financials_php_year']['net_margin_pct'] ?? null,
        ];

        // 5. Road Network Indicators (if road data exists)
        $roadSummary = app(AnalyzeRoadAccessibilityTool::class)->forLocation($location);

        return [
            'location_level' => LocationResolver::levelOf($location),
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'psg_code' => $location->psg_code ?? null,
            'indicators' => $catalogResults,
            'demographic_indicators' => $demographicIndicators,
            'per_capita_ratios' => $perCapitaRatios,
            'agriculture_indicators' => $agricultureIndicators,
            'road_indicators' => [
                'status' => $roadSummary['status'],
                'total_road_length_km' => $roadSummary['summary']['total_road_length_km'] ?? null,
                'road_density_km_per_km2' => $roadSummary['summary']['road_density_km_per_km2'] ?? null,
            ],
        ];
    }
}
