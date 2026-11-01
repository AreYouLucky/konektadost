<?php

namespace App\FunctionTools\LayerF;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerC\CalculatePriorityScoresTool;
use App\FunctionTools\LayerC\DetectDevelopmentGapsTool;
use App\FunctionTools\LayerE\AssessImplementationFeasibilityTool;
use App\FunctionTools\LayerE\AssessInterventionSuitabilityTool;
use App\FunctionTools\LayerE\EstimateInterventionRequirementsTool;
use App\FunctionTools\LayerE\IdentifyImplementationPartnersTool;
use App\FunctionTools\LayerE\MatchDostInterventionsTool;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Municipality;
use App\Support\Ai\AiNarrativeService;

/**
 * Layer F (F5) Function Tool: generate_action_plan
 *
 * Consolidates all deterministic assessment results from Layers A through F into
 * the comprehensive Action Plan and the Five Executive Outputs:
 * 1. Priority Areas
 * 2. Critical Needs and Development Gaps
 * 3. Recommended DOST Interventions
 * 4. Investment and Resource Priorities
 * 5. Expected Outcomes and Performance
 *
 * Includes an API-ready AI narrative synthesis with seamless deterministic offline fallback.
 */
class GenerateActionPlanTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'generate_action_plan';
    }

    public function layer(): string
    {
        return 'F';
    }

    public function description(): string
    {
        return 'Generate the comprehensive DOST Action Plan and Five Primary Executive Outputs (Priority Areas, Critical Needs, Recommended Interventions, Resource Priorities, and Performance Outcomes) with API-ready AI narrative generation.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'municipality_id' => [
                    'type' => 'integer',
                    'description' => 'Target municipality numeric ID.',
                ],
                'psg_code' => [
                    'type' => 'string',
                    'description' => 'Target municipality 10-digit PSGC code.',
                ],
                'domain' => [
                    'type' => 'string',
                    'description' => 'Optional domain focus.',
                ],
            ],
            'required' => [],
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
        $municipality = LocationResolver::resolve($args);
        if ($municipality === null) {
            return [
                'error' => 'Could not resolve municipality. Provide municipality_id or psg_code.',
                'action_plan' => null,
            ];
        }

        $idArgs = ['municipality_id' => $municipality->id];

        // 1. Gather Layer C & E Data
        $priorityResult = app(CalculatePriorityScoresTool::class)->execute($idArgs);
        $gapsResult = app(DetectDevelopmentGapsTool::class)->execute($idArgs);
        $matchesResult = app(MatchDostInterventionsTool::class)->execute($idArgs);

        // Find primary candidate intervention
        $primaryIntervention = null;
        if (!empty($matchesResult['gap_interventions'])) {
            foreach ($matchesResult['gap_interventions'] as $gi) {
                if (!empty($gi['matched_interventions'])) {
                    $primaryIntervention = $gi['matched_interventions'][0];
                    break;
                }
            }
        }

        $evalArgs = array_merge($idArgs, $primaryIntervention ? ['intervention_id' => $primaryIntervention['intervention_id']] : []);

        // 2. Gather Layer E Assessments
        $suitability = app(AssessInterventionSuitabilityTool::class)->execute($evalArgs);
        $feasibility = app(AssessImplementationFeasibilityTool::class)->execute($evalArgs);
        $partners = app(IdentifyImplementationPartnersTool::class)->execute($evalArgs);
        $requirements = app(EstimateInterventionRequirementsTool::class)->execute($evalArgs);

        // 3. Gather Layer F Planning Components
        $planProposal = app(GenerateInterventionPlanTool::class)->execute($evalArgs);
        $kpis = app(DefinePerformanceIndicatorsTool::class)->execute($evalArgs);
        $outcomes = app(EstimateInterventionOutcomesTool::class)->execute($evalArgs);
        $risks = app(ConductRiskAssessmentTool::class)->execute($evalArgs);

        // 4. Construct the Five Primary Executive Outputs
        $executiveOutputs = [
            // Executive Output 1: Priority Areas
            'priority_areas' => [
                'title' => 'Executive Output 1: Priority Areas for STI Intervention',
                'description' => 'Geographically ranked barangays and underserved clusters requiring urgent developmental attention.',
                'total_barangays_evaluated' => $priorityResult['total_barangays'] ?? 0,
                'high_priority_count' => count(array_filter($priorityResult['priority_scores'] ?? [], fn ($b) => ($b['composite_priority_score'] ?? 0) >= 60)),
                'top_priority_barangays' => array_slice($priorityResult['priority_scores'] ?? [], 0, 5),
            ],

            // Executive Output 2: Critical Needs and Development Gaps
            'critical_needs' => [
                'title' => 'Executive Output 2: Critical Needs and Development Gaps',
                'description' => 'Deterministically identified shortfalls across health, education, digital telecom, road infrastructure, agriculture, and safety/DRRM.',
                'total_gaps_detected' => $gapsResult['total_gaps_detected'] ?? 0,
                'critical_severity_count' => $gapsResult['critical_severity_count'] ?? 0,
                'identified_gaps' => $gapsResult['gaps'] ?? [],
            ],

            // Executive Output 3: Recommended STI Interventions
            'recommended_interventions' => [
                'title' => 'Executive Output 3: Recommended DOST STI Interventions',
                'description' => 'Verified catalog technologies and programs matched to local development gaps with suitability and feasibility ratings.',
                'total_interventions_matched' => $matchesResult['total_interventions_matched'] ?? 0,
                'primary_recommended_package' => $primaryIntervention,
                'interventions' => $primaryIntervention ? [$primaryIntervention] : [],
                'suitability_rating' => $suitability['suitability_level'] ?? 'suitable',
                'feasibility_status' => $feasibility['feasibility_status'] ?? 'feasible',
            ],

            // Executive Output 4: Investment and Resource Priorities
            'investment_and_resources' => [
                'title' => 'Executive Output 4: Investment and Resource Priorities',
                'description' => 'Empirical equipment, utilities, physical space, personnel, and historical grant funding requirements.',
                'equipment_and_hardware' => $requirements['requirements']['equipment_and_hardware'] ?? [],
                'facility_and_space' => $requirements['requirements']['facility_and_space'] ?? [],
                'utilities_and_infrastructure' => $requirements['requirements']['infrastructure_and_utilities'] ?? [],
                'human_resources' => $requirements['requirements']['human_resources_and_training'] ?? [],
                'funding_benchmark' => $requirements['requirements']['funding_profile'] ?? [],
                'institutional_partners' => $partners['partners'] ?? [],
            ],

            // Executive Output 5: Expected Outcomes and Performance
            'expected_outcomes_and_performance' => [
                'title' => 'Executive Output 5: Expected Outcomes and Performance Indicators',
                'description' => 'Quantitative KPIs, targets, monitoring frequencies, and projected socioeconomic outcomes.',
                'kpi_indicators' => $kpis['performance_indicators'] ?? [],
                'projected_outcomes' => $outcomes['estimated_outcomes'] ?? [],
                'monitoring_framework' => $kpis['monitoring_framework'] ?? [],
                'risk_management_profile' => $risks['overall_risk_profile'] ?? 'manageable_with_controls',
            ],
        ];

        // 5. Generate AI Executive Narrative (API-ready with deterministic fallback)
        $aiService = app(AiNarrativeService::class);
        $narrativeResult = $aiService->generateExecutiveNarrative([
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'income_class' => $municipality->placeDescription?->income_class ?? 'Unclassified',
                'population' => $municipality->populationFigure?->population ?? 25000,
            ],
            'executive_outputs' => $executiveOutputs,
        ]);

        return [
            'action_plan_id' => 'ACTION-PLAN-' . $municipality->id . '-' . date('Ymd'),
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'psg_code' => $municipality->psg_code,
                'income_class' => $municipality->placeDescription?->income_class ?? 'Unclassified',
            ],
            'status' => 'proposal_pending_human_validation_and_approval',
            'generated_at' => now()->toIso8601String(),
            'ai_synthesis' => $narrativeResult,
            'executive_outputs' => $executiveOutputs,
            'detailed_proposal' => $planProposal,
            'risk_matrix' => $risks['risk_assessment'] ?? [],
        ];
    }
}

