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
 * Layer B (B8) Function Tool: analyze_safety_and_drrm
 *
 * Additional sector analysis supported by `Facility::SECTOR_SAFETY`:
 * Analyzes emergency, police, fire station, and DRRM safety facilities per 10k and 100k population,
 * barangays without safety facilities, and nearest safety facility proximity.
 */
class AnalyzeSafetyAndDrrmTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'analyze_safety_and_drrm';
    }

    public function layer(): string
    {
        return 'B';
    }

    public function description(): string
    {
        return 'Analyze emergency, fire, police, and DRRM safety facilities per 10,000 and per 100,000 population, barangays without safety facilities, affected population, and nearest safety facility distance in km.';
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
     * Analyze safety and DRRM facility access for a resolved location model.
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
        $baseQuery = $datasetsTool->facilityQueryFor($location)->where('sector', 'safety');

        $totalSafety = (clone $baseQuery)->count();
        $fireStations = (clone $baseQuery)->where('facility_type', 'fire_station')->count();
        $policeStations = (clone $baseQuery)->where('facility_type', 'police')->count();

        $byType = (clone $baseQuery)
            ->select(DB::raw("COALESCE(NULLIF(facility_type, ''), 'unclassified') as ftype"), DB::raw('COUNT(*) as total'))
            ->groupBy('ftype')
            ->orderByDesc('total')
            ->pluck('total', 'ftype')
            ->map(fn ($v) => (int) $v)
            ->all();

        $brgyCoverage = $location instanceof Municipality
            ? SpatialMetrics::barangayCoverageForSector($location, 'safety', $population)
            : null;

        $nearestSafetyFacility = $totalSafety === 0
            ? SpatialMetrics::findNearestFacility($location, 'safety', null)
            : null;

        return [
            'status' => 'available',
            'location_level' => LocationResolver::levelOf($location),
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'population_used' => $population,
            'counts' => [
                'total_safety_facilities' => $totalSafety,
                'fire_stations' => $fireStations,
                'police_stations' => $policeStations,
                'by_facility_type' => $byType,
            ],
            'rates_per_10k' => [
                'safety_facilities_per_10k' => SpatialMetrics::ratePer($totalSafety, $population, 10_000),
                'fire_stations_per_10k' => SpatialMetrics::ratePer($fireStations, $population, 10_000),
                'police_stations_per_10k' => SpatialMetrics::ratePer($policeStations, $population, 10_000),
            ],
            'rates_per_100k' => [
                'safety_facilities_per_100k' => SpatialMetrics::ratePer($totalSafety, $population, 100_000),
                'fire_stations_per_100k' => SpatialMetrics::ratePer($fireStations, $population, 100_000),
                'police_stations_per_100k' => SpatialMetrics::ratePer($policeStations, $population, 100_000),
            ],
            'barangay_coverage' => $brgyCoverage,
            'nearest_safety_facility_if_zero' => $nearestSafetyFacility,
        ];
    }
}

