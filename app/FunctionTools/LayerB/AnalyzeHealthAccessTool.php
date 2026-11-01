<?php

namespace App\FunctionTools\LayerB;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\Support\LocationResolver;
use App\FunctionTools\Support\SpatialMetrics;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer B (B2) Function Tool: analyze_health_access
 *
 * Analyzes health facility counts, health facilities per 10,000 and per 100,000 population,
 * barangays with and without health facilities, spatial distribution, and nearest hospital/clinic proximity.
 */
class AnalyzeHealthAccessTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'analyze_health_access';
    }

    public function layer(): string
    {
        return 'B';
    }

    public function description(): string
    {
        return 'Analyze health facility counts by type (hospitals, clinics, pharmacies, birthing centers), ratios per 10,000 and per 100,000 population, barangays with and without health facilities, affected population, and nearest hospital distance in km.';
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
     * Analyze health access for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        if (! Schema::hasTable('facilities')) {
            return ['status' => 'unavailable', 'reason' => 'facilities table is not present.'];
        }

        $popStats = app(GetPopulationStatisticsTool::class)->forLocation($location, includeBarangayDistribution: false);
        $population = $popStats['population_current'] ?? $location->populationFigure?->population;

        $datasetsTool = app(GetAvailableDatasetsTool::class);
        $baseQuery = $datasetsTool->facilityQueryFor($location)->where('sector', 'health');

        $totalHealth = (clone $baseQuery)->count();
        $hospitals = (clone $baseQuery)->where('facility_type', 'hospital')->count();
        $pharmacies = (clone $baseQuery)->where('facility_type', 'pharmacy')->count();
        $birthingCentres = (clone $baseQuery)->where('facility_type', 'birthing_centre')->count();
        $clinicsAndPosts = max(0, $totalHealth - $hospitals - $pharmacies - $birthingCentres);

        $byType = (clone $baseQuery)
            ->select(DB::raw("COALESCE(NULLIF(facility_type, ''), 'unclassified') as ftype"), DB::raw('COUNT(*) as total'))
            ->groupBy('ftype')
            ->orderByDesc('total')
            ->pluck('total', 'ftype')
            ->map(fn ($v) => (int) $v)
            ->all();

        $brgyCoverage = $location instanceof Municipality
            ? SpatialMetrics::barangayCoverageForSector($location, 'health', $population)
            : null;

        $nearestHospital = SpatialMetrics::findNearestFacility($location, 'health', 'hospital');
        $nearestHealthFacility = $totalHealth === 0
            ? SpatialMetrics::findNearestFacility($location, 'health', null)
            : null;

        return [
            'status' => 'available',
            'location_level' => LocationResolver::levelOf($location),
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'population_used' => $population,
            'counts' => [
                'total_health_facilities' => $totalHealth,
                'hospitals' => $hospitals,
                'pharmacies' => $pharmacies,
                'birthing_centres' => $birthingCentres,
                'clinics_and_centers' => $clinicsAndPosts,
                'by_facility_type' => $byType,
            ],
            'rates_per_10k' => [
                'health_facilities_per_10k' => SpatialMetrics::ratePer($totalHealth, $population, 10_000),
                'hospitals_per_10k' => SpatialMetrics::ratePer($hospitals, $population, 10_000),
                'pharmacies_per_10k' => SpatialMetrics::ratePer($pharmacies, $population, 10_000),
            ],
            'rates_per_100k' => [
                'health_facilities_per_100k' => SpatialMetrics::ratePer($totalHealth, $population, 100_000),
                'hospitals_per_100k' => SpatialMetrics::ratePer($hospitals, $population, 100_000),
                'pharmacies_per_100k' => SpatialMetrics::ratePer($pharmacies, $population, 100_000),
            ],
            'barangay_coverage' => $brgyCoverage,
            'nearest_hospital' => $nearestHospital,
            'nearest_health_facility_if_zero' => $nearestHealthFacility,
        ];
    }
}

