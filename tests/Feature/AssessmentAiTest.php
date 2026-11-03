<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use App\Support\Assessment\AssessmentAiService;
use App\Support\Assessment\MunicipalityAssessmentData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentAiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openai.api_key' => 'test-server-secret',
            'services.openai.model' => 'configured-model',
            'cache.stores.file' => ['driver' => 'array'],
            'database.default' => 'ai_testing',
            'database.connections.ai_testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        Http::preventStrayRequests();
    }

    public function test_structured_analysis_uses_server_evidence_and_caches_unchanged_results(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]);
        $service = app(AssessmentAiService::class);
        $result = $service->analyze($this->report());
        $this->assertSame('ready', $result['status']);
        $this->assertCount(8, $result['analysis']['sectors']);
        $this->assertSame($result, $service->analyze($this->report()));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['model'] === 'configured-model'
            && $request['store'] === false && $request['text']['format']['strict'] === true
            && str_contains($request['input'], 'health_facilities_per_100k')
            && ! str_contains($request['input'], 'test-server-secret'));
        $changed = $this->report();
        $changed['results'][0]['value'] = 5;
        $service->analyze($changed);
        Http::assertSentCount(2);
    }

    public function test_missing_key_does_not_make_a_provider_request(): void
    {
        config(['services.openai.api_key' => null]);
        $this->assertSame('unavailable', app(AssessmentAiService::class)->analyze($this->report())['status']);
        Http::assertNothingSent();
    }

    public function test_conflicting_inventory_totals_are_removed_from_ai_sector_evidence(): void
    {
        $report = $this->report();
        $report['functionToolsBundle']['sector_analysis']['health'] = ['total_facilities' => 1000];
        $report['inventoryIntegrity'] = ['has_conflicts' => true, 'inventories' => [[
            'dataset' => 'facilities', 'has_conflicts' => true, 'reason' => 'Foreign location links need verification.',
        ]]];
        $evidence = app(AssessmentAiService::class)->evidence($report);
        $this->assertSame(['status' => 'unavailable', 'reason' => 'Foreign location links need verification.'], $evidence['sector_analysis']['health']);
        $this->assertTrue($evidence['inventory_integrity']['has_conflicts']);
    }

    public function test_provider_errors_do_not_expose_secrets_and_can_be_retried(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => ['message' => 'test-server-secret']], 429)->push($this->response())]);
        $service = app(AssessmentAiService::class);
        $failed = $service->analyze($this->report());
        $this->assertSame('unavailable', $failed['status']);
        $this->assertStringNotContainsString('test-server-secret', json_encode($failed));
        $this->assertSame('ready', $service->analyze($this->report())['status']);
    }

    public function test_incomplete_refused_malformed_and_invented_catalog_responses_are_rejected(): void
    {
        $invented = $this->analysis();
        $invented['sectors'][0]['intervention_refs'] = ['invented:program'];
        $crossSector = $this->analysis();
        $crossSector['sectors'][0]['intervention_refs'] = ['dost_techs:HEALTH-1'];
        $duplicate = $this->analysis();
        $duplicate['sectors'][0]['sector'] = 'health';
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(['status' => 'incomplete', 'output' => []])
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Refused']]]]])
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'invalid']]]]])
            ->push($this->response($invented))->push($this->response($crossSector))->push($this->response($duplicate))]);
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->assertSame('unavailable', app(AssessmentAiService::class)->analyze($this->report())['status']);
        }
    }

    public function test_endpoint_requires_authentication_and_valid_location_input(): void
    {
        $this->postJson(route('location.assess.ai'), ['city' => '0701232000'])->assertUnauthorized();
        $this->actingAs((new User)->forceFill(['id' => 1, 'name' => 'Test', 'role' => 'user']));
        $this->postJson(route('location.assess.ai'), ['city' => 'bad'])->assertUnprocessable();
        $this->postJson(route('location.assess.ai'), ['city' => '0701232000', 'focus' => 'invented'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_endpoint_ignores_client_metrics_and_uses_recomputed_report(): void
    {
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('psg_code');
            $table->string('name');
            $table->timestamps();
        });
        $municipality = Municipality::create(['psg_code' => '0701232000', 'name' => 'Test City']);
        $report = $this->report();
        $this->partialMock(MunicipalityAssessmentData::class, function ($mock) use ($report, $municipality) {
            $mock->shouldReceive('props')->once()->withArgs(fn ($model, $focus) => $model->id === $municipality->id && $focus === 'general')->andReturn($report);
        });
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]);
        $this->actingAs((new User)->forceFill(['id' => 1, 'name' => 'Test', 'role' => 'user']));
        $this->postJson(route('location.assess.ai'), ['city' => '0701232000', 'results' => [['value' => 999999]]])
            ->assertOk()->assertJsonPath('status', 'ready')->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($request) => ! str_contains($request['input'], '999999'));
    }

    private function report(): array
    {
        return [
            'location' => ['name' => 'Test City', 'psg_code' => '0701232000'], 'focus' => 'general',
            'results' => [['key' => 'health_facilities_per_100k', 'value' => 0, 'confidence' => 'low']],
            'layerEResult' => ['matched_interventions' => ['gap_interventions' => [[
                'domain' => 'health', 'gap_id' => 'health_facilities_per_100k', 'matched_interventions' => [[
                    'source_catalog' => 'dost_techs', 'intervention_id' => 'HEALTH-1', 'name' => 'Recorded health technology',
                ]],
            ]]]],
        ];
    }

    private function analysis(): array
    {
        return ['summary' => 'Recorded evidence needs local validation.', 'sectors' => array_map(fn ($sector) => [
            'sector' => $sector, 'gap_explanation' => 'No high-confidence shortfall established.',
            'intervention_analysis' => 'Validate catalog availability.', 'next_action' => 'Confirm local records.',
            'indicator_keys' => [], 'intervention_refs' => [],
        ], AssessmentAiService::SECTORS)];
    }

    private function response(?array $analysis = null): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => json_encode($analysis ?? $this->analysis()),
        ]]]]];
    }
}
