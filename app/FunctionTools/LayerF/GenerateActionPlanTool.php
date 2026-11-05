<?php

namespace App\FunctionTools\LayerF;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\LayerC\CalculatePriorityScoresTool;
use App\FunctionTools\LayerC\DetectDevelopmentGapsTool;
use App\FunctionTools\LayerE\AssessImplementationFeasibilityTool;
use App\FunctionTools\LayerE\AssessInterventionSuitabilityTool;
use App\FunctionTools\LayerE\EstimateInterventionRequirementsTool;
use App\FunctionTools\LayerE\IdentifyImplementationPartnersTool;
use App\FunctionTools\LayerE\MatchDostInterventionsTool;
use App\FunctionTools\Support\LocationResolver;
use App\Support\Ai\AiNarrativeService;
use Illuminate\Database\Eloquent\Model;

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
        return 'Match recorded DOST technologies/programs to a selected location and generate a proposed technology-assignment/deployment action plan. Returns named catalog technologies, target municipality, supporting local needs, match scores and deployment validation requirements within the five executive outputs. Historical projects are context, not deployable assignments.';
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
        $populationContext = app(GetPopulationStatisticsTool::class)->execute([...$idArgs, 'include_barangay_distribution' => false]);
        $datasetAudit = app(GetAvailableDatasetsTool::class)->execute($idArgs);

        // 1. Gather Layer C & E Data
        $priorityResult = app(CalculatePriorityScoresTool::class)->execute($idArgs);
        $rankedBarangays = $priorityResult['barangays'] ?? $priorityResult['priority_scores'] ?? [];
        $gapsResult = app(DetectDevelopmentGapsTool::class)->execute($idArgs);
        $domain = $args['domain'] ?? null;
        $matchesResult = app(MatchDostInterventionsTool::class)->execute($domain ? [...$idArgs, 'sector' => $domain] : $idArgs);

        $assignments = $this->technologyAssignments($matchesResult, $municipality);
        $primaryIntervention = $assignments[0]['technology'] ?? null;

        $evalArgs = array_merge($idArgs, ['domain' => $domain ?? ($assignments[0]['matched_needs'][0]['domain'] ?? null)], $primaryIntervention ? ['intervention_id' => $primaryIntervention['intervention_id']] : []);

        // 2. Gather Layer E Assessments
        $suitability = $primaryIntervention ? app(AssessInterventionSuitabilityTool::class)->execute($evalArgs) : [];
        $feasibility = $primaryIntervention ? app(AssessImplementationFeasibilityTool::class)->execute($evalArgs) : [];
        $partners = $primaryIntervention ? app(IdentifyImplementationPartnersTool::class)->execute($evalArgs) : [];
        $requirements = $primaryIntervention ? app(EstimateInterventionRequirementsTool::class)->execute($evalArgs) : [];

        // 3. Gather Layer F Planning Components
        $planProposal = $primaryIntervention ? app(GenerateInterventionPlanTool::class)->execute($evalArgs) : null;
        $kpis = $primaryIntervention ? app(DefinePerformanceIndicatorsTool::class)->execute($evalArgs) : [];
        $outcomes = $primaryIntervention ? app(EstimateInterventionOutcomesTool::class)->execute($evalArgs) : [];
        $risks = $primaryIntervention ? app(ConductRiskAssessmentTool::class)->execute($evalArgs) : [];

        // 4. Construct the Five Primary Executive Outputs
        $executiveOutputs = [
            // Executive Output 1: Priority Areas
            'priority_areas' => [
                'title' => 'Executive Output 1: Priority Areas for STI Intervention',
                'description' => 'Selected municipality for technology assignment, with general barangay screening priorities. A composite rank alone does not establish suitability for a particular technology.',
                'total_barangays_evaluated' => $priorityResult['summary']['total_barangays'] ?? $priorityResult['total_barangays'] ?? count($rankedBarangays),
                'high_priority_count' => $priorityResult['summary']['high_priority_count'] ?? 0,
                'top_priority_barangays' => array_slice($rankedBarangays, 0, 5),
                'methodology' => $priorityResult['methodology_explanation'] ?? null,
                'active_weights' => $priorityResult['active_weights'] ?? [],
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
                'description' => 'Named DOST catalog technologies/programs proposed for the selected location, with evidence explaining each match. These are proposed assignments, not approved deployments.',
                'total_interventions_matched' => $matchesResult['total_interventions_matched'] ?? 0,
                'total_technology_assignment_candidates' => count($assignments),
                'primary_recommended_package' => $primaryIntervention,
                'interventions' => array_column($assignments, 'technology'),
                'proposed_technology_assignments' => $assignments,
                'evaluation_applies_to' => $primaryIntervention ? ($assignments[0]['catalog_ref'] ?? null) : null,
                'suitability_rating' => $primaryIntervention ? ($suitability['suitability_level'] ?? 'unknown') : 'not_evaluated',
                'feasibility_status' => $primaryIntervention ? ($feasibility['feasibility_status'] ?? 'unknown') : 'not_evaluated',
                'validation_note' => $primaryIntervention
                    ? 'Catalog match and screening estimates only; availability, costs, partners and readiness require local validation.'
                    : 'No verified catalog match. Validate local needs and consult DOST before defining deployment resources or targets.',
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
                'risk_management_profile' => $risks['overall_risk_profile'] ?? 'not_evaluated',
            ],
        ];

        // 5. Generate AI Executive Narrative (API-ready with deterministic fallback)
        $aiService = app(AiNarrativeService::class);
        $narrativeResult = ($args['include_narrative'] ?? true) ? $aiService->generateExecutiveNarrative([
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'income_class' => $municipality->placeDescription?->income_class ?? 'Unclassified',
                'population' => $municipality->populationFigure?->population,
            ],
            'executive_outputs' => $executiveOutputs,
        ]) : null;

        return [
            'action_plan_id' => 'ACTION-PLAN-'.$municipality->id.'-'.date('Ymd'),
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'psg_code' => $municipality->psg_code,
                'income_class' => $municipality->placeDescription?->income_class ?? 'Unclassified',
            ],
            'status' => 'proposal_pending_human_validation_and_approval',
            'planning_focus' => 'matching_dost_technologies_to_location',
            'proposed_technology_assignments' => $assignments,
            'generated_at' => now()->toIso8601String(),
            'evidence_context' => ['population_statistics' => $populationContext, 'dataset_audit' => $datasetAudit],
            'ai_synthesis' => $narrativeResult,
            'executive_outputs' => $executiveOutputs,
            'detailed_proposal' => $planProposal,
            'risk_matrix' => $risks['risk_assessment'] ?? [],
        ];
    }

    private function technologyAssignments(array $matches, Model $municipality): array
    {
        $assignments = [];
        foreach ($matches['gap_interventions'] ?? [] as $gap) {
            foreach ($gap['matched_interventions'] ?? [] as $technology) {
                $source = $technology['source_catalog'] ?? '';
                // Historical project titles are not technology packages that can be assigned.
                if (! in_array($source, ['dost_techs', 'technologies'], true)
                    || empty($technology['intervention_id']) || empty($technology['name'])
                    || in_array($technology['availability_status'] ?? '', ['unavailable', 'research_stage', 'historical_benchmark'], true)) {
                    continue;
                }
                $ref = $source.':'.$technology['intervention_id'];
                $assignments[$ref] ??= [
                    'catalog_ref' => $ref,
                    'technology' => $technology,
                    'target_location' => $municipality->only(['id', 'name', 'province_name', 'psg_code']),
                    'matched_needs' => [],
                    'relevance_score' => $technology['relevance_score'] ?? null,
                    'assignment_status' => 'proposed_pending_dost_lgu_validation',
                    'deployment_site' => 'Municipality-level proposal; exact barangay/host site requires technology-specific validation.',
                    'availability_validation_required' => true,
                ];
                $assignments[$ref]['matched_needs'][] = [
                    'gap_id' => $gap['gap_id'] ?? null, 'name' => $gap['gap_name'] ?? null,
                    'domain' => $gap['domain'] ?? null, 'severity' => $gap['severity'] ?? null,
                    'relevance_score' => $technology['relevance_score'] ?? null,
                    'matching_criteria' => $technology['matching_criteria'] ?? [],
                ];
                if (is_numeric($technology['relevance_score'] ?? null)
                    && $technology['relevance_score'] > ($assignments[$ref]['relevance_score'] ?? -1)) {
                    $assignments[$ref]['relevance_score'] = $technology['relevance_score'];
                }
            }
        }
        $ranked = array_values($assignments);
        usort($ranked, fn ($a, $b) => ($b['relevance_score'] ?? -1) <=> ($a['relevance_score'] ?? -1));

        return $ranked;
    }
}
