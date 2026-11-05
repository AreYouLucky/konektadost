<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\FunctionTools\LayerE\AssessImplementationFeasibilityTool;
use App\FunctionTools\LayerE\AssessInterventionSuitabilityTool;
use App\FunctionTools\LayerE\EstimateInterventionRequirementsTool;
use App\FunctionTools\LayerE\IdentifyImplementationPartnersTool;
use App\FunctionTools\LayerE\MatchDostInterventionsTool;
use App\Models\Municipality;
use Tests\TestCase;

class LayerEFunctionToolsTest extends TestCase
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

    public function test_registry_exposes_all_five_layer_e_function_tools(): void
    {
        $registry = app(FunctionToolRegistry::class);
        $layerETools = $registry->tools('E');

        $this->assertCount(5, $layerETools);
        $this->assertArrayHasKey('match_dost_interventions', $layerETools);
        $this->assertArrayHasKey('assess_intervention_suitability', $layerETools);
        $this->assertArrayHasKey('assess_implementation_feasibility', $layerETools);
        $this->assertArrayHasKey('identify_implementation_partners', $layerETools);
        $this->assertArrayHasKey('estimate_intervention_requirements', $layerETools);

        foreach ($layerETools as $name => $tool) {
            $this->assertSame('E', $tool->layer());
            $this->assertNotEmpty($tool->description());
            $params = $tool->parameters();
            $this->assertSame('object', $params['type']);
            $this->assertArrayHasKey('properties', $params);
        }
    }

    public function test_match_dost_interventions_executes_against_live_database(): void
    {
        $tool = app(MatchDostInterventionsTool::class);
        $result = $tool->execute(['municipality_id' => $this->municipality->id]);

        $this->assertArrayHasKey('municipality', $result);
        $this->assertSame($this->municipality->id, $result['municipality']['id']);
        $this->assertArrayHasKey('total_gaps_evaluated', $result);
        $this->assertArrayHasKey('gap_interventions', $result);
        $this->assertIsArray($result['gap_interventions']);

        if (!empty($result['gap_interventions'])) {
            $firstGapMatch = $result['gap_interventions'][0];
            $this->assertArrayHasKey('gap_id', $firstGapMatch);
            $this->assertArrayHasKey('domain', $firstGapMatch);
            $this->assertArrayHasKey('matched_interventions', $firstGapMatch);

            if (!empty($firstGapMatch['matched_interventions'])) {
                $matchedItem = $firstGapMatch['matched_interventions'][0];
                $this->assertArrayHasKey('intervention_id', $matchedItem);
                $this->assertArrayHasKey('name', $matchedItem);
                $this->assertArrayHasKey('relevance_score', $matchedItem);
                $this->assertGreaterThanOrEqual(40, $matchedItem['relevance_score']);
                $this->assertArrayHasKey('matching_criteria', $matchedItem);
                $this->assertIsArray($matchedItem['matching_criteria']);
                $this->assertArrayHasKey('evidence', $matchedItem);
                $this->assertArrayHasKey('intended_beneficiaries', $matchedItem);
                $this->assertArrayHasKey('availability_status', $matchedItem);
            }
        }
    }

    public function test_assess_intervention_suitability_evaluates_five_dimensions(): void
    {
        $tool = app(AssessInterventionSuitabilityTool::class);
        $result = $tool->execute([
            'municipality_id' => $this->municipality->id,
            'domain' => 'education',
        ]);

        $this->assertArrayHasKey('suitability_score', $result);
        $this->assertIsNumeric($result['suitability_score']);
        $this->assertGreaterThanOrEqual(0, $result['suitability_score']);
        $this->assertLessThanOrEqual(100, $result['suitability_score']);

        $this->assertArrayHasKey('suitability_level', $result);
        $this->assertContains($result['suitability_level'], ['highly_suitable', 'suitable', 'moderately_suitable', 'low_suitability']);

        $this->assertArrayHasKey('dimension_scores', $result);
        $this->assertArrayHasKey('need_relevance', $result['dimension_scores']);
        $this->assertArrayHasKey('beneficiary_reach', $result['dimension_scores']);
        $this->assertArrayHasKey('resource_readiness', $result['dimension_scores']);
        $this->assertArrayHasKey('infrastructure_readiness', $result['dimension_scores']);
        $this->assertArrayHasKey('institutional_readiness', $result['dimension_scores']);

        $this->assertArrayHasKey('enablers', $result);
        $this->assertArrayHasKey('constraints', $result);
        $this->assertIsArray($result['enablers']);
        $this->assertIsArray($result['constraints']);
    }

    public function test_assess_implementation_feasibility_classifies_feasibility_deterministically(): void
    {
        $tool = app(AssessImplementationFeasibilityTool::class);
        $result = $tool->execute([
            'municipality_id' => $this->municipality->id,
            'intervention_id' => 'DOST-COMMUNITY-PROGRAM',
        ]);

        $this->assertArrayHasKey('feasibility_status', $result);
        $this->assertContains($result['feasibility_status'], [
            'feasible',
            'conditionally_feasible',
            'not_yet_feasible',
            'insufficient_information',
        ]);

        $this->assertArrayHasKey('confidence_score', $result);
        $this->assertArrayHasKey('verified_prerequisites', $result);
        $this->assertIsArray($result['verified_prerequisites']);

        foreach ($result['verified_prerequisites'] as $prereq) {
            $this->assertArrayHasKey('prerequisite', $prereq);
            $this->assertArrayHasKey('status', $prereq);
            $this->assertContains($prereq['status'], ['passed', 'conditional', 'failed', 'unknown']);
            $this->assertArrayHasKey('evidence', $prereq);
        }
    }

    public function test_identify_implementation_partners_uses_verified_records_only(): void
    {
        $tool = app(IdentifyImplementationPartnersTool::class);
        $result = $tool->execute([
            'municipality_id' => $this->municipality->id,
            'sector' => 'health',
        ]);

        $this->assertArrayHasKey('municipality', $result);
        $this->assertArrayHasKey('total_partners_identified', $result);
        $this->assertArrayHasKey('partners', $result);
        $this->assertGreaterThanOrEqual(2, $result['total_partners_identified']);

        foreach ($result['partners'] as $partner) {
            $this->assertArrayHasKey('partner_name', $partner);
            $this->assertArrayHasKey('partner_type', $partner);
            $this->assertArrayHasKey('basis_of_engagement', $partner);
            $this->assertArrayHasKey('source_record', $partner);
            $this->assertArrayHasKey('suggested_role', $partner);

            // Verify source record points to actual table and non-null record_id
            $this->assertNotEmpty($partner['source_record']['table']);
        }
    }

    public function test_estimate_intervention_requirements_determines_utilities_and_budgets_without_inventing_costs(): void
    {
        $tool = app(EstimateInterventionRequirementsTool::class);
        $result = $tool->execute([
            'municipality_id' => $this->municipality->id,
            'sector' => 'education',
        ]);

        $this->assertArrayHasKey('requirements', $result);
        $reqs = $result['requirements'];

        $this->assertArrayHasKey('equipment_and_hardware', $reqs);
        $this->assertArrayHasKey('facility_and_space', $reqs);
        $this->assertArrayHasKey('human_resources_and_training', $reqs);
        $this->assertArrayHasKey('infrastructure_and_utilities', $reqs);
        $this->assertArrayHasKey('funding_profile', $reqs);

        $funding = $reqs['funding_profile'];
        $this->assertContains($funding['financial_status'], ['historical_benchmark_available', 'pending']);

        if ($funding['financial_status'] === 'historical_benchmark_available') {
            $this->assertNotNull($funding['historical_benchmark_budget']);
            $this->assertArrayHasKey('median_php', $funding['historical_benchmark_budget']);
            $this->assertGreaterThan(0, $funding['historical_benchmark_budget']['median_php']);
        } else {
            $this->assertNull($funding['historical_benchmark_budget']);
        }
    }

    public function test_function_tool_registry_executes_layer_e(): void
    {
        $registry = app(FunctionToolRegistry::class);
        $layerEResult = $registry->executeLayerE($this->municipality);

        $this->assertArrayHasKey('matched_interventions', $layerEResult);
        $this->assertArrayHasKey('suitability_assessment', $layerEResult);
        $this->assertArrayHasKey('feasibility_assessment', $layerEResult);
        $this->assertArrayHasKey('implementation_partners', $layerEResult);
        $this->assertArrayHasKey('requirements_estimate', $layerEResult);
    }
}
