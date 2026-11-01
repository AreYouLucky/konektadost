<?php

namespace App\FunctionTools\LayerB;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\Support\LocationResolver;
use App\FunctionTools\Support\SpatialMetrics;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Layer B (B3) Function Tool: analyze_education_access
 *
 * Analyzes school counts by level (elementary, secondary, tertiary, unclassified),
 * schools per 10,000 and per 100,000 population, barangays without schools,
 * and nearest school proximity.
 */
class AnalyzeEducationAccessTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'analyze_education_access';
    }

    public function layer(): string
    {
        return 'B';
    }

    public function description(): string
    {
        return 'Analyze education facility counts by level (elementary, secondary, tertiary, unclassified), schools per 10,000 and per 100,000 population, barangays with and without schools, affected population, and nearest school proximity in km.';
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
     * Analyze education access for a resolved location model.
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
        $baseQuery = $datasetsTool->facilityQueryFor($location)->where('sector', 'education');

        $totalSchools = (clone $baseQuery)->count();
        $elementary = (clone $baseQuery)->where('level', 'elementary')->count();
        $secondary = (clone $baseQuery)->whereIn('level', ['secondary', 'high_school', 'senior_high'])->count();
        $tertiary = (clone $baseQuery)->whereIn('level', ['tertiary', 'college', 'university'])->count();
        $unclassified = (clone $baseQuery)->where(function ($q) {
            $q->whereNull('level')->orWhere('level', '')->orWhere('level', 'unknown');
        })->count();

        $brgyCoverage = $location instanceof Municipality
            ? SpatialMetrics::barangayCoverageForSector($location, 'education', $population)
            : null;

        // Backward-compatible alias key `zero_school_barangays` alongside `zero_facility_barangays`
        if ($brgyCoverage !== null) {
            $brgyCoverage['zero_school_barangays'] = $brgyCoverage['zero_facility_barangays'];
        }

        $nearestSchool = $totalSchools === 0
            ? SpatialMetrics::findNearestFacility($location, 'education', null)
            : null;

        return [
            'status' => 'available',
            'location_level' => LocationResolver::levelOf($location),
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'population_used' => $population,
            'counts' => [
                'total_schools' => $totalSchools,
                'elementary_schools' => $elementary,
                'secondary_schools' => $secondary,
                'tertiary_institutions' => $tertiary,
                'unclassified_level_schools' => $unclassified,
                'unclassified_share_pct' => $totalSchools > 0 ? round(($unclassified / $totalSchools) * 100, 1) : 0.0,
            ],
            'rates_per_10k' => [
                'schools_per_10k' => SpatialMetrics::ratePer($totalSchools, $population, 10_000),
                'elementary_schools_per_10k' => SpatialMetrics::ratePer($elementary, $population, 10_000),
                'secondary_schools_per_10k' => SpatialMetrics::ratePer($secondary, $population, 10_000),
            ],
            'rates_per_100k' => [
                'schools_per_100k' => SpatialMetrics::ratePer($totalSchools, $population, 100_000),
                'elementary_schools_per_100k' => SpatialMetrics::ratePer($elementary, $population, 100_000),
                'secondary_schools_per_100k' => SpatialMetrics::ratePer($secondary, $population, 100_000),
            ],
            'barangay_coverage' => $brgyCoverage,
            'nearest_school_if_zero' => $nearestSchool,
        ];
    }
}
