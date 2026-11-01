<?php

namespace App\FunctionTools\LayerB;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetLocalAssetsTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\Support\LocationResolver;
use App\FunctionTools\Support\SpatialMetrics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Layer B (B7) Function Tool: analyze_sti_laboratory_capacity
 *
 * Analyzes accredited laboratory count, laboratory service offerings (`LabServices`),
 * free vs. paid service counts, fee ranges, testing capability domains,
 * and nearest accredited laboratory proximity when local count is zero.
 */
class AnalyzeStiLaboratoryCapacityTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'analyze_sti_laboratory_capacity';
    }

    public function layer(): string
    {
        return 'B';
    }

    public function description(): string
    {
        return 'Analyze DOST/OneLab accredited laboratory count, distinct testing/calibration services (service_name, description, fee), free vs. paid service ratios, testing capability categories, and nearest laboratory distance in km.';
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
     * Analyze STI and laboratory capacity for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        if (! Schema::hasTable('laboratories')) {
            return ['status' => 'unavailable', 'reason' => 'laboratories table is not present.'];
        }

        $popStats = app(GetPopulationStatisticsTool::class)->forLocation($location, includeBarangayDistribution: false);
        $population = $popStats['population_current'] ?? $location->populationFigure?->population;

        $datasetsTool = app(GetAvailableDatasetsTool::class);
        $hasServices = Schema::hasTable('lab_services');
        $labQuery = $datasetsTool->laboratoryQueryFor($location);
        if ($hasServices) {
            $labQuery->with('tests');
        }

        $labs = $labQuery->get();
        $localLabCount = $labs->count();

        $distinctServices = collect();
        $freeServicesCount = 0;
        $paidServicesCount = 0;
        $paidFees = [];
        $capabilityDomains = [
            'microbiological_and_food_safety' => 0,
            'chemical_and_physicochemical' => 0,
            'water_and_environmental' => 0,
            'calibration_and_metrology' => 0,
            'mechanical_and_materials' => 0,
            'other_testing_services' => 0,
        ];

        foreach ($labs as $lab) {
            if (! $hasServices) {
                continue;
            }
            foreach ($lab->tests as $svc) {
                $name = (string) ($svc->service_name ?? $svc->test_name ?? 'Service');
                $desc = $svc->description ?? $svc->method ?? null;
                $fee = $svc->fee !== null ? (float) $svc->fee : null;

                $distinctServices->push([
                    'service_name' => $name,
                    'description' => $desc,
                    'reference' => $svc->reference ?? null,
                    'fee' => $fee,
                ]);

                if ($fee === null || $fee <= 0) {
                    $freeServicesCount++;
                } else {
                    $paidServicesCount++;
                    $paidFees[] = $fee;
                }

                $haystack = strtolower($name.' '.($desc ?? ''));
                if (str_contains($haystack, 'microb') || str_contains($haystack, 'coliform') || str_contains($haystack, 'salmonella') || str_contains($haystack, 'food')) {
                    $capabilityDomains['microbiological_and_food_safety']++;
                } elseif (str_contains($haystack, 'calibrat') || str_contains($haystack, 'thermometer') || str_contains($haystack, 'balance') || str_contains($haystack, 'pressure')) {
                    $capabilityDomains['calibration_and_metrology']++;
                } elseif (str_contains($haystack, 'water') || str_contains($haystack, 'wastewater') || str_contains($haystack, 'effluent')) {
                    $capabilityDomains['water_and_environmental']++;
                } elseif (str_contains($haystack, 'tensile') || str_contains($haystack, 'compress') || str_contains($haystack, 'metal') || str_contains($haystack, 'steel')) {
                    $capabilityDomains['mechanical_and_materials']++;
                } elseif (str_contains($haystack, 'chem') || str_contains($haystack, 'moisture') || str_contains($haystack, 'protein') || str_contains($haystack, 'ash') || str_contains($haystack, 'ph')) {
                    $capabilityDomains['chemical_and_physicochemical']++;
                } else {
                    $capabilityDomains['other_testing_services']++;
                }
            }
        }

        $uniqueServices = $distinctServices
            ->unique(fn ($s) => $s['service_name'].'|'.$s['description'])
            ->values();
        $uniqueServicesCount = $uniqueServices->count();

        $nearestLab = $localLabCount === 0
            ? SpatialMetrics::findNearestLaboratory($location)
            : null;

        $assets = app(GetLocalAssetsTool::class)->forLocation($location, includeFacilityRecords: false);
        $unmappedCityMatches = $assets['laboratories']['unmapped_city_text_matches'] ?? [];

        return [
            'status' => 'available',
            'location_level' => LocationResolver::levelOf($location),
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'population_used' => $population,
            'counts' => [
                'local_laboratories' => $localLabCount,
                'unmapped_city_text_match_laboratories' => count($unmappedCityMatches),
                'distinct_services_offered' => $uniqueServicesCount,
                'free_service_entries' => $freeServicesCount,
                'paid_service_entries' => $paidServicesCount,
            ],
            'fee_summary_php' => [
                'min_fee_php' => count($paidFees) > 0 ? round(min($paidFees), 2) : null,
                'max_fee_php' => count($paidFees) > 0 ? round(max($paidFees), 2) : null,
                'avg_fee_php' => count($paidFees) > 0 ? round(array_sum($paidFees) / count($paidFees), 2) : null,
            ],
            'rates_per_million' => [
                'laboratories_per_million' => SpatialMetrics::ratePer($localLabCount, $population, 1_000_000),
                'laboratory_tests_per_million' => SpatialMetrics::ratePer($uniqueServicesCount, $population, 1_000_000),
            ],
            'capability_domains' => $capabilityDomains,
            'services_sample' => $uniqueServices->take(20)->all(),
            'unmapped_city_text_matches' => $unmappedCityMatches,
            'nearest_accredited_laboratory_if_zero' => $nearestLab,
        ];
    }
}

