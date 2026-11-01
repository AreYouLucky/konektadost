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
use App\FunctionTools\LayerC\CalculatePriorityScoresTool;
use App\FunctionTools\LayerC\DetectDevelopmentGapsTool;
use App\FunctionTools\LayerC\IdentifyUnderservedAreasTool;
use App\FunctionTools\LayerD\GetLocalDostProjectsTool;
use App\FunctionTools\LayerD\SearchDostTechnologiesTool;
use App\FunctionTools\LayerE\AssessImplementationFeasibilityTool;
use App\FunctionTools\LayerE\AssessInterventionSuitabilityTool;
use App\FunctionTools\LayerE\EstimateInterventionRequirementsTool;
use App\FunctionTools\LayerE\IdentifyImplementationPartnersTool;
use App\FunctionTools\LayerE\MatchDostInterventionsTool;
use App\FunctionTools\LayerF\ConductRiskAssessmentTool;
use App\FunctionTools\LayerF\DefinePerformanceIndicatorsTool;
use App\FunctionTools\LayerF\EstimateInterventionOutcomesTool;
use App\FunctionTools\LayerF\GenerateActionPlanTool;
use App\FunctionTools\LayerF\GenerateInterventionPlanTool;
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

        // Layer C – Gap Detection and Barangay Prioritization
        DetectDevelopmentGapsTool::class,         // C1: detect_development_gaps
        IdentifyUnderservedAreasTool::class,      // C2: identify_underserved_areas
        CalculatePriorityScoresTool::class,       // C3: calculate_priority_scores

        // Layer D – DOST Catalog and Project Retrieval
        SearchDostTechnologiesTool::class,        // D1: search_dost_technologies
        GetLocalDostProjectsTool::class,          // D2: get_local_dost_projects

        // Layer E – Intervention Matching and Feasibility
        MatchDostInterventionsTool::class,           // E1: match_dost_interventions
        AssessInterventionSuitabilityTool::class,     // E2: assess_intervention_suitability
        AssessImplementationFeasibilityTool::class,   // E3: assess_implementation_feasibility
        IdentifyImplementationPartnersTool::class,    // E4: identify_implementation_partners
        EstimateInterventionRequirementsTool::class,  // E5: estimate_intervention_requirements

        // Layer F – Action Plan and Executive Output Generation
        GenerateInterventionPlanTool::class,          // F1: generate_intervention_plan
        DefinePerformanceIndicatorsTool::class,       // F2: define_performance_indicators
        EstimateInterventionOutcomesTool::class,      // F3: estimate_intervention_outcomes
        ConductRiskAssessmentTool::class,             // F4: conduct_risk_assessment
        GenerateActionPlanTool::class,                // F5: generate_action_plan & 5 Executive Outputs
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

    /**
     * Execute all 3 Layer C tools for a given location model.
     *
     * @return array<string, mixed>
     */
    public function executeLayerC(Model $location): array
    {
        return [
            'detected_gaps' => app(DetectDevelopmentGapsTool::class)->forLocation($location),
            'underserved_areas' => app(IdentifyUnderservedAreasTool::class)->forLocation($location),
            'priority_scores' => app(CalculatePriorityScoresTool::class)->forLocation($location),
        ];
    }

    /**
     * Execute Layer D tools for a given location model and technology search.
     *
     * @param  array<string, mixed>  $searchFilters
     * @return array<string, mixed>
     */
    public function executeLayerD(Model $location, array $searchFilters = []): array
    {
        return [
            'local_projects' => app(GetLocalDostProjectsTool::class)->forLocation($location),
            'technologies' => app(SearchDostTechnologiesTool::class)->execute($searchFilters),
        ];
    }

    /**
     * Execute Layer E tools for a given location model.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function executeLayerE(Model $location, array $options = []): array
    {
        $idArgs = ['municipality_id' => $location->id];

        $matched = app(MatchDostInterventionsTool::class)->execute($idArgs);
        $topIntervention = null;
        if (!empty($matched['gap_interventions'])) {
            foreach ($matched['gap_interventions'] as $gapMatch) {
                if (!empty($gapMatch['matched_interventions'])) {
                    $topIntervention = $gapMatch['matched_interventions'][0];
                    break;
                }
            }
        }

        $interventionArg = $topIntervention ? ['intervention_id' => $topIntervention['intervention_id']] : [];
        $evalArgs = array_merge($idArgs, $interventionArg, $options);

        return [
            'matched_interventions' => $matched,
            'suitability_assessment' => app(AssessInterventionSuitabilityTool::class)->execute($evalArgs),
            'feasibility_assessment' => app(AssessImplementationFeasibilityTool::class)->execute($evalArgs),
            'implementation_partners' => app(IdentifyImplementationPartnersTool::class)->execute($evalArgs),
            'requirements_estimate' => app(EstimateInterventionRequirementsTool::class)->execute($evalArgs),
        ];
    }

    /**
     * Execute Layer F tools for a given location model.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function executeLayerF(Model $location, array $options = []): array
    {
        $idArgs = array_merge(['municipality_id' => $location->id], $options);

        return [
            'intervention_plan' => app(GenerateInterventionPlanTool::class)->execute($idArgs),
            'performance_indicators' => app(DefinePerformanceIndicatorsTool::class)->execute($idArgs),
            'intervention_outcomes' => app(EstimateInterventionOutcomesTool::class)->execute($idArgs),
            'risk_assessment' => app(ConductRiskAssessmentTool::class)->execute($idArgs),
            'action_plan' => app(GenerateActionPlanTool::class)->execute($idArgs),
        ];
    }
}
