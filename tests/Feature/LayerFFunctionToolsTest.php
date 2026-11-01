<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\FunctionTools\LayerF\ConductRiskAssessmentTool;
use App\FunctionTools\LayerF\DefinePerformanceIndicatorsTool;
use App\FunctionTools\LayerF\EstimateInterventionOutcomesTool;
use App\FunctionTools\LayerF\GenerateActionPlanTool;
use App\FunctionTools\LayerF\GenerateInterventionPlanTool;
use App\Models\Municipality;
use App\Support\Ai\AiNarrativeService;
use Tests\TestCase;

class LayerFFunctionToolsTest extends TestCase
{
    protected Municipality $municipality;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('DB_TEST_LIVE_DATABASE', 'konektadost'),
            'cache.default' => 'array',
        ]);

        $mun = Municipality::where('name', 'like', '%Tagbilaran%')->first()
            ?? Municipality::where('name', 'like', '%Tubigon%')->first()
            ?? Municipality::where('province_id', 15)->first()
            ?? Municipality::first();

        $this->assertNotNull($mun, 'At least one municipality must exist in live database.');
        $this->municipality = $mun;
    }

    public function test_registry_exposes_all_five_layer_f_function_tools(): void
    {
        $registry = app(FunctionToolRegistry::class);
        $layerFTools = $registry->tools('F');

        $this->assertCount(5, $layerFTools);
        $this->assertArrayHasKey('generate_intervention_plan', $layerFTools);
        $this->assertArrayHasKey('define_performance_indicators', $layerFTools);
        $this->assertArrayHasKey('estimate_intervention_outcomes', $layerFTools);
        $this->assertArrayHasKey('conduct_risk_assessment', $layerFTools);
        $this->assertArrayHasKey('generate_action_plan', $layerFTools);

        foreach ($layerFTools as $name => $tool) {
            $this->assertSame('F', $tool->layer());
            $this->assertNotEmpty($tool->description());
            $params = $tool->parameters();
            $this->assertSame('object', $params['type']);
            $this->assertArrayHasKey('properties', $params);
            $def = $tool->definition();
            $this->assertSame($name, $def['name']);
        }
    }

    public function test_generate_intervention_plan_produces_phased_proposal_with_disclaimer(): void
    {
        $tool = app(GenerateInterventionPlanTool::class);
        $result = $tool->execute(['municipality_id' => $this->municipality->id]);

        $this->assertArrayHasKey('plan_title', $result);
        $this->assertArrayHasKey('plan_status', $result);
        $this->assertSame('proposal_pending_human_validation_and_approval', $result['plan_status']);
        $this->assertArrayHasKey('formal_disclaimer', $result);
        $this->assertStringContainsString('proposal', strtolower($result['formal_disclaimer']));

        $this->assertArrayHasKey('target_municipality', $result);
        $this->assertArrayHasKey('priority_beneficiary_barangays', $result);
        $this->assertArrayHasKey('implementation_activities', $result);
        $this->assertNotEmpty($result['implementation_activities']);
        $this->assertArrayHasKey('verification_requirements', $result);
    }

    public function test_define_performance_indicators_computes_empirical_targets(): void
    {
        $tool = app(DefinePerformanceIndicatorsTool::class);
        $result = $tool->execute([
            'municipality_id' => $this->municipality->id,
            'domain' => 'education',
        ]);

        $this->assertArrayHasKey('performance_indicators', $result);
        $this->assertNotEmpty($result['performance_indicators']);

        foreach ($result['performance_indicators'] as $kpi) {
            $this->assertArrayHasKey('indicator_name', $kpi);
            $this->assertArrayHasKey('category', $kpi);
            $this->assertArrayHasKey('baseline', $kpi);
            $this->assertArrayHasKey('target', $kpi);
            $this->assertGreaterThan(0, $kpi['target']);
            $this->assertArrayHasKey('unit', $kpi);
            $this->assertArrayHasKey('reporting_frequency', $kpi);
            $this->assertArrayHasKey('data_source', $kpi);
            $this->assertNull($kpi['actual_accomplishment']);
        }
    }

    public function test_estimate_intervention_outcomes_distinguishes_projections(): void
    {
        $tool = app(EstimateInterventionOutcomesTool::class);
        $result = $tool->execute(['municipality_id' => $this->municipality->id]);

        $this->assertArrayHasKey('formal_notice', $result);
        $this->assertArrayHasKey('estimated_outcomes', $result);
        $this->assertNotEmpty($result['estimated_outcomes']);

        foreach ($result['estimated_outcomes'] as $outcome) {
            $this->assertArrayHasKey('outcome_title', $outcome);
            $this->assertArrayHasKey('baseline', $outcome);
            $this->assertArrayHasKey('estimated_target', $outcome);
            $this->assertArrayHasKey('estimation_method', $outcome);
            $this->assertArrayHasKey('assumptions', $outcome);
            $this->assertArrayHasKey('confidence_score', $outcome);
            $this->assertArrayHasKey('limitations', $outcome);
            $this->assertSame('projected_estimate_not_actual_accomplishment', $outcome['status']);
        }
    }

    public function test_conduct_risk_assessment_evaluates_four_risk_dimensions(): void
    {
        $tool = app(ConductRiskAssessmentTool::class);
        $result = $tool->execute(['municipality_id' => $this->municipality->id]);

        $this->assertArrayHasKey('total_risks_identified', $result);
        $this->assertArrayHasKey('risk_assessment', $result);
        $this->assertGreaterThanOrEqual(3, $result['total_risks_identified']);

        foreach ($result['risk_assessment'] as $risk) {
            $this->assertArrayHasKey('risk_id', $risk);
            $this->assertArrayHasKey('category', $risk);
            $this->assertArrayHasKey('risk_title', $risk);
            $this->assertArrayHasKey('likelihood', $risk);
            $this->assertArrayHasKey('impact', $risk);
            $this->assertArrayHasKey('mitigation_strategy', $risk);
            $this->assertArrayHasKey('contingency_measure', $risk);
        }
    }

    public function test_generate_action_plan_synthesizes_all_five_executive_outputs_without_api_key(): void
    {
        $tool = app(GenerateActionPlanTool::class);
        $result = $tool->execute(['municipality_id' => $this->municipality->id]);

        $this->assertArrayHasKey('action_plan_id', $result);
        $this->assertArrayHasKey('municipality', $result);
        $this->assertArrayHasKey('executive_outputs', $result);

        $exec = $result['executive_outputs'];

        // Output 1: Priority Areas
        $this->assertArrayHasKey('priority_areas', $exec);
        $this->assertArrayHasKey('top_priority_barangays', $exec['priority_areas']);

        // Output 2: Critical Needs
        $this->assertArrayHasKey('critical_needs', $exec);
        $this->assertArrayHasKey('identified_gaps', $exec['critical_needs']);

        // Output 3: Recommended Interventions
        $this->assertArrayHasKey('recommended_interventions', $exec);
        $this->assertArrayHasKey('interventions', $exec['recommended_interventions']);

        // Output 4: Investment and Resources
        $this->assertArrayHasKey('investment_and_resources', $exec);
        $this->assertArrayHasKey('equipment_and_hardware', $exec['investment_and_resources']);
        $this->assertArrayHasKey('funding_benchmark', $exec['investment_and_resources']);

        // Output 5: Expected Outcomes and Performance
        $this->assertArrayHasKey('expected_outcomes_and_performance', $exec);
        $this->assertArrayHasKey('kpi_indicators', $exec['expected_outcomes_and_performance']);

        // AI Synthesis (Deterministic offline mode without API key)
        $this->assertArrayHasKey('ai_synthesis', $result);
        $this->assertSame('deterministic_synthesis_engine', $result['ai_synthesis']['mode']);
        $this->assertSame('offline_api_key_ready', $result['ai_synthesis']['api_status']);
        $this->assertNotEmpty($result['ai_synthesis']['narrative']);
        $this->assertNotEmpty($result['ai_synthesis']['strategic_insights']);
    }

    public function test_ai_narrative_service_functions_without_api_key(): void
    {
        $aiService = app(AiNarrativeService::class);
        $this->assertFalse($aiService->hasApiKey());

        $result = $aiService->generateExecutiveNarrative([
            'municipality' => ['name' => 'Bohol City', 'province' => 'Bohol'],
        ]);

        $this->assertSame('deterministic_synthesis_engine', $result['mode']);
        $this->assertStringContainsString('Bohol City', $result['narrative']);
        $this->assertIsArray($result['strategic_insights']);
    }

    public function test_function_tool_registry_executes_layer_f(): void
    {
        $registry = app(FunctionToolRegistry::class);
        $layerFResult = $registry->executeLayerF($this->municipality);

        $this->assertArrayHasKey('intervention_plan', $layerFResult);
        $this->assertArrayHasKey('performance_indicators', $layerFResult);
        $this->assertArrayHasKey('intervention_outcomes', $layerFResult);
        $this->assertArrayHasKey('risk_assessment', $layerFResult);
        $this->assertArrayHasKey('action_plan', $layerFResult);
    }
}

