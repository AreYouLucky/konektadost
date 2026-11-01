<?php

namespace App\FunctionTools;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetGeographicDataAvailabilityTool;
use App\FunctionTools\LayerA\GetLocalAssetsTool;
use App\FunctionTools\LayerA\GetMunicipalityProfileTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\LayerA\GetTelecomInfrastructureTool;
use App\FunctionTools\LayerB\AnalyzeAgricultureTool;
use App\FunctionTools\LayerB\AnalyzeDigitalConnectivityTool;
use App\FunctionTools\LayerB\AnalyzeEducationAccessTool;
use App\FunctionTools\LayerB\AnalyzeHealthAccessTool;
use App\FunctionTools\LayerB\AnalyzeRoadAccessibilityTool;
use App\FunctionTools\LayerB\AnalyzeSafetyAndDrrmTool;
use App\FunctionTools\LayerB\AnalyzeStiLaboratoryCapacityTool;
use App\FunctionTools\LayerB\CalculateIndicatorsTool;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Central Registry & Dispatcher for KonektaDOST Function Tools.
 *
 * Organized strictly by layer:
 * - Layer A (A1–A6): Municipality and Data Retrieval
 * - Layer B (B1–B8): Domain Indicator and Sector Analysis
 */
class FunctionToolRegistry
{
    /**
     * Registered Function Tool classes.
     *
     * @var list<class-string<FunctionToolInterface>>
     */
    private const TOOL_CLASSES = [
        // Layer A – Municipality and Data Retrieval
        GetMunicipalityProfileTool::class,        // A1: get_municipality_profile
        GetPopulationStatisticsTool::class,       // A2: get_population_statistics
        GetAvailableDatasetsTool::class,          // A3: get_available_datasets
        GetLocalAssetsTool::class,                // A4: get_local_assets
        GetTelecomInfrastructureTool::class,      // A5: get_telecom_infrastructure
        GetGeographicDataAvailabilityTool::class, // A6: get_geographic_data_availability

        // Layer B – Domain Indicator and Sector Analysis
        CalculateIndicatorsTool::class,           // B1: calculate_indicators
        AnalyzeHealthAccessTool::class,           // B2: analyze_health_access
        AnalyzeEducationAccessTool::class,        // B3: analyze_education_access
        AnalyzeDigitalConnectivityTool::class,    // B4: analyze_digital_connectivity
        AnalyzeRoadAccessibilityTool::class,      // B5: analyze_road_accessibility
        AnalyzeAgricultureTool::class,            // B6: analyze_agriculture
        AnalyzeStiLaboratoryCapacityTool::class,  // B7: analyze_sti_laboratory_capacity
        AnalyzeSafetyAndDrrmTool::class,          // B8: analyze_safety_and_drrm
    ];

    /**
     * Return instantiated FunctionToolInterface objects keyed by tool name.
     *
     * @return array<string, FunctionToolInterface>
     */
    public function tools(?string $layer = null): array
    {
        $map = [];
        $filterLayer = $layer !== null ? strtoupper(trim($layer)) : null;

        foreach (self::TOOL_CLASSES as $class) {
            /** @var FunctionToolInterface $tool */
            $tool = app($class);

            if ($filterLayer !== null && strtoupper($tool->layer()) !== $filterLayer) {
                continue;
            }

            $map[$tool->name()] = $tool;
        }

        return $map;
    }

    /**
     * Return tool schema definitions (optionally filtered by layer).
     *
     * @return list<array{name: string, layer: string, description: string, parameters: array<string, mixed>}>
     */
    public function definitions(?string $layer = null): array
    {
        return array_values(array_map(
            fn (FunctionToolInterface $tool) => $tool->definition(),
            $this->tools($layer)
        ));
    }

    /**
     * Execute a registered Function Tool by name.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $args = []): array
    {
        $tools = $this->tools();

        if (! isset($tools[$toolName])) {
            throw new InvalidArgumentException("Unknown Function Tool: {$toolName}");
        }

        return $tools[$toolName]->execute($args);
    }

    /**
     * Execute all 6 Layer A tools for a given location model.
     *
     * @return array<string, mixed>
     */
    public function executeLayerA(Model $location): array
    {
        return [
            'municipality_profile' => app(GetMunicipalityProfileTool::class)->forLocation($location, includeBarangayList: false),
            'population_statistics' => app(GetPopulationStatisticsTool::class)->forLocation($location, includeBarangayDistribution: true),
            'available_datasets' => app(GetAvailableDatasetsTool::class)->forLocation($location),
            'local_assets' => app(GetLocalAssetsTool::class)->forLocation($location, includeFacilityRecords: false),
            'telecom_infrastructure' => app(GetTelecomInfrastructureTool::class)->forLocation($location, includeSiteRecords: false),
            'geographic_data_availability' => app(GetGeographicDataAvailabilityTool::class)->forLocation($location),
        ];
    }

    /**
     * Execute all 8 Layer B tools for a given location model.
     *
     * @return array<string, mixed>
     */
    public function executeLayerB(Model $location): array
    {
        return [
            'indicators' => app(CalculateIndicatorsTool::class)->forLocation($location),
            'health_access' => app(AnalyzeHealthAccessTool::class)->forLocation($location),
            'education_access' => app(AnalyzeEducationAccessTool::class)->forLocation($location),
            'digital_connectivity' => app(AnalyzeDigitalConnectivityTool::class)->forLocation($location),
            'road_accessibility' => app(AnalyzeRoadAccessibilityTool::class)->forLocation($location),
            'agriculture' => app(AnalyzeAgricultureTool::class)->forLocation($location),
            'sti_laboratory_capacity' => app(AnalyzeStiLaboratoryCapacityTool::class)->forLocation($location),
            'safety_and_drrm' => app(AnalyzeSafetyAndDrrmTool::class)->forLocation($location),
        ];
    }
}
