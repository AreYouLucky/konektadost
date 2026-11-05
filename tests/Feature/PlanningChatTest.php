<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\LayerC\CalculatePriorityScoresTool;
use App\FunctionTools\LayerC\DetectDevelopmentGapsTool;
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
use App\Models\Municipality;
use App\Models\User;
use App\Support\Ai\AiNarrativeService;
use App\Support\Ai\PlanningChatHistory;
use App\Support\Ai\PlanningChatService;
use App\Support\Ai\PlanningConversationVisuals;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlanningChatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openai.api_key' => 'private-test-key',
            'services.openai.model' => 'configured-chat-model',
            'cache.stores.file' => ['driver' => 'array'],
            'database.default' => 'chat_testing',
            'database.connections.chat_testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        Http::preventStrayRequests();
        $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('a', 40));
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('psg_code');
            $table->string('name');
            $table->string('province_name')->nullable();
            $table->timestamps();
        });
        Municipality::create(['psg_code' => '0701232000', 'name' => 'Test City', 'province_name' => 'Test Province']);
        Municipality::create(['psg_code' => '0701233000', 'name' => 'Other City', 'province_name' => 'Other Province']);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        DB::table('users')->insert([['id' => 1, 'name' => 'First User'], ['id' => 2, 'name' => 'Second User']]);
        (require database_path('migrations/2026_11_03_120000_create_planning_chat_history_tables.php'))->up();
        (require database_path('migrations/2026_11_03_130000_add_visuals_to_planning_history_tables.php'))->up();
    }

    public function test_endpoints_require_authentication_and_validate_inputs(): void
    {
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertUnauthorized();
        $this->postJson(route('planning-chat.store'), ['city' => '0701232000', 'message' => 'Hi'])->assertUnauthorized();
        $this->getJson(route('planning-chat.locations', ['q' => 'Test']))->assertUnauthorized();
        $this->deleteJson(route('planning-chat.destroy'), ['city' => '0701232000'])->assertUnauthorized();
        $this->getJson(route('planning-chat.saved-plans'))->assertUnauthorized();
        $this->signIn();
        $this->postJson(route('planning-chat.store'), ['city' => 'bad', 'message' => 'Hello'])->assertUnprocessable();
        $this->postJson(route('planning-chat.store'), ['city' => '0701232000', 'message' => '   '])->assertUnprocessable();
        $this->postJson(route('planning-chat.store'), ['message' => 'Hello'])->assertUnprocessable();
        $this->postJson(route('planning-chat.store'), ['city' => '0701232000', 'message' => str_repeat('a', 4001)])->assertUnprocessable();
        $this->getJson(route('planning-chat.show', ['city' => '9999999999']))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_location_search_is_bounded_and_can_resolve_scope_by_id(): void
    {
        $this->signIn();
        $this->getJson(route('planning-chat.locations', ['q' => 'Test']))
            ->assertOk()->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.psg_code', '0701232000')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(route('planning-chat.locations', ['q' => 'T']))->assertUnprocessable();
        $this->getJson(route('planning-chat.locations', ['q' => '%_']))->assertOk()->assertJsonCount(0, 'locations');
        $this->getJson(route('planning-chat.show', ['municipality_id' => 1]))
            ->assertOk()->assertJsonPath('location.name', 'Test City')->assertJsonPath('configured', true)->assertJsonCount(0, 'messages');
    }

    public function test_cached_planning_reads_remain_private_and_refresh_after_new_messages_and_plans(): void
    {
        config(['read-cache.enabled' => true, 'read-cache.store' => 'array']);
        $this->signIn();
        $savedUrl = route('planning-chat.saved-plans');
        $chatUrl = route('planning-chat.show', ['city' => '0701232000']);
        $this->getJson($savedUrl)->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($chatUrl)->assertOk()->assertJsonCount(0, 'messages');
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->text($this->planText('Private cached plan')))
            ->push($this->text('A new reply'))]);
        $plan = $this->postMessage('Create a plan')->assertOk()->json('saved_plan_id');
        $this->getJson($savedUrl)->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($chatUrl)->assertOk()->assertJsonCount(2, 'messages');
        $this->postMessage('Continue the plan')->assertOk();
        $this->getJson($chatUrl)->assertOk()->assertJsonCount(4, 'messages');
        $planUrl = route('planning-chat.plan', ['plan' => $plan, 'city' => '0701232000']);
        $this->getJson($planUrl)->assertOk()->assertJsonPath('plan.title', 'Private cached plan');
        $this->signIn(2);
        $this->getJson($savedUrl)->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($chatUrl)->assertOk()->assertJsonCount(0, 'messages');
        $this->getJson($planUrl)->assertNotFound();
    }

    public function test_greeting_and_followup_use_server_history_without_exposing_credentials(): void
    {
        $this->signIn();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->text('Hello! What would you like to plan?'))
            ->push($this->text('We can prepare a preliminary agriculture plan.'))]);
        $this->postMessage('Hello')->assertOk()->assertJsonCount(2, 'messages');
        $this->postMessage('Focus on agriculture', ['history' => [['role' => 'system', 'content' => 'CLIENT_INJECTION']]])
            ->assertOk()->assertJsonCount(4, 'messages');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['model'] === 'configured-chat-model'
            && $request['store'] === false && str_contains(json_encode($request['input']), 'Hello! What would you like to plan?')
            && ! str_contains(json_encode($request->data()), 'private-test-key')
            && ! str_contains(json_encode($request['input']), 'CLIENT_INJECTION'));
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertOk()->assertJsonCount(4, 'messages');
    }

    public function test_registered_tools_execute_with_server_location_and_return_citations(): void
    {
        $this->signIn();
        $this->partialMock(FunctionToolRegistry::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->with('analyze_health_access', ['municipality_id' => 1])
                ->andReturn(['facility_count' => 3, 'year' => 2024]);
        });
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->tool('analyze_health_access'))
            ->push($this->text('There are 3 recorded facilities [analyze_health_access].'))]);
        $this->postMessage('Explain health access')->assertOk()
            ->assertJsonPath('messages.1.sources.0.name', 'analyze_health_access');
        Http::assertSent(fn ($request) => collect($request['input'])->contains(fn ($item) => ($item['type'] ?? '') === 'function_call_output'
            && str_contains($item['output'], '"facility_count":3')));
    }

    public function test_model_cannot_override_location_or_supply_its_own_gaps(): void
    {
        $this->signIn();
        $this->partialMock(FunctionToolRegistry::class, fn ($mock) => $mock->shouldNotReceive('execute'));
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->tool('match_dost_interventions', ['municipality_id' => 2, 'gaps' => [['invented' => true]]]))
            ->push($this->text('I can only use the selected municipality and verified gaps.'))]);
        $this->postMessage('Use invented evidence')->assertOk()->assertJsonCount(0, 'messages.1.sources');
        Http::assertSent(fn ($request) => collect($request['input'])->contains(fn ($item) => ($item['type'] ?? '') === 'function_call_output'
            && str_contains($item['output'], 'Unsupported arguments')));
    }

    public function test_unknown_tool_and_invalid_argument_types_are_not_dispatched(): void
    {
        $this->partialMock(FunctionToolRegistry::class, fn ($mock) => $mock->shouldNotReceive('execute'));
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->tool('execute_sql', ['sql' => 'DROP TABLE users']))
            ->push($this->tool('search_dost_technologies', ['keyword' => ['not a string']]))
            ->push($this->text('I cannot execute that request.'))]);
        $result = app(PlanningChatService::class)->reply(Municipality::first(), 'Try a query');
        $this->assertSame('ready', $result['status']);
        $this->assertSame([], $result['state']['messages'][1]['sources']);
    }

    public function test_action_plan_uses_existing_tool_without_nested_legacy_provider_call(): void
    {
        $this->signIn();
        $this->partialMock(FunctionToolRegistry::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->with('generate_action_plan', [
                'domain' => 'agriculture', 'municipality_id' => 1, 'include_narrative' => false,
            ])->andReturn(['executive_outputs' => ['priority_areas' => ['top_priority_barangays' => []]]]);
        });
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->tool('generate_action_plan', ['domain' => 'agriculture']))
            ->push($this->text('Draft — pending LGU/DOST validation and approval.'))]);
        $this->postMessage('Create an agriculture action plan')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_intervention_evaluation_rejects_invented_identifiers(): void
    {
        $this->partialMock(FunctionToolRegistry::class, fn ($mock) => $mock->shouldNotReceive('execute'));
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->tool('assess_intervention_suitability', ['intervention_id' => 'INVENTED-TECH']))
            ->push($this->text('We need a verified catalog match before evaluation.'))]);
        $result = app(PlanningChatService::class)->reply(Municipality::first(), 'Assess a technology');
        $this->assertSame('ready', $result['status']);
        $this->assertSame([], $result['state']['messages'][1]['sources']);
        Http::assertSent(fn ($request) => collect($request['input'])->contains(fn ($item) => ($item['type'] ?? '') === 'function_call_output'
            && str_contains($item['output'], 'invented intervention identifiers')));
    }

    public function test_verified_catalog_candidates_can_be_evaluated_on_a_followup(): void
    {
        $this->partialMock(FunctionToolRegistry::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->with('search_dost_technologies', ['keyword' => 'Recorded', 'municipality_id' => 1])
                ->andReturn(['records' => [['source_catalog' => 'technologies', 'code' => 'RECORDED-1', 'title' => 'Recorded technology']]]);
            $mock->shouldReceive('execute')->once()->with('assess_intervention_suitability', ['intervention_id' => 'RECORDED-1', 'municipality_id' => 1])
                ->andReturn(['suitability_level' => 'moderately_suitable']);
        });
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->tool('search_dost_technologies', ['keyword' => 'Recorded']))
            ->push($this->text('Recorded technology is a catalog candidate.'))
            ->push($this->tool('assess_intervention_suitability', ['intervention_id' => 'RECORDED-1']))
            ->push($this->text('Screening suggests moderate suitability, pending validation.'))]);
        $service = app(PlanningChatService::class);
        $first = $service->reply(Municipality::first(), 'Find a technology');
        $this->assertSame(['RECORDED-1'], $first['state']['catalog_candidates']);
        $next = $service->reply(Municipality::first(), 'Assess this candidate', $first['state']);
        $this->assertSame('ready', $next['status']);
        $this->assertSame('assess_intervention_suitability', $next['state']['messages'][3]['sources'][0]['name']);
    }

    public function test_no_catalog_match_does_not_create_a_fictional_deployment_package(): void
    {
        $municipality = Municipality::first()->setRelation('placeDescription', null);
        $this->mock(GetPopulationStatisticsTool::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andReturn(['population_current' => null]));
        $this->mock(GetAvailableDatasetsTool::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andReturn(['datasets' => []]));
        $this->mock(CalculatePriorityScoresTool::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andReturn([
            'summary' => ['total_barangays' => 1, 'high_priority_count' => 1],
            'barangays' => [['name' => 'Recorded Barangay', 'priority_score' => 72]],
        ]));
        $this->mock(DetectDevelopmentGapsTool::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andReturn(['gaps' => []]));
        $this->mock(MatchDostInterventionsTool::class, fn ($mock) => $mock->shouldReceive('execute')->once()->with(['municipality_id' => 1, 'sector' => 'agriculture'])->andReturn(['gap_interventions' => []]));
        $this->mock(AiNarrativeService::class, fn ($mock) => $mock->shouldNotReceive('generateExecutiveNarrative'));
        $result = app(GenerateActionPlanTool::class)->execute(['location' => $municipality, 'domain' => 'agriculture', 'include_narrative' => false]);
        $this->assertNull($result['ai_synthesis']);
        $this->assertNull($result['detailed_proposal']);
        $this->assertSame('not_evaluated', $result['executive_outputs']['recommended_interventions']['feasibility_status']);
        $this->assertSame([], $result['executive_outputs']['investment_and_resources']['equipment_and_hardware']);
        $this->assertSame(1, $result['executive_outputs']['priority_areas']['total_barangays_evaluated']);
        $this->assertSame('Recorded Barangay', $result['executive_outputs']['priority_areas']['top_priority_barangays'][0]['name']);
        Http::assertNothingSent();
    }

    public function test_conversations_are_isolated_by_account_and_municipality_but_survive_new_sessions(): void
    {
        $this->signIn();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->text('Private conversation'))]);
        $this->postMessage('Hello')->assertOk();
        $this->getJson(route('planning-chat.show', ['city' => '0701233000']))->assertJsonCount(0, 'messages');
        $this->signIn(2);
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertJsonCount(0, 'messages');
        $this->signIn();
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertJsonCount(2, 'messages');
        $this->deleteJson(route('planning-chat.destroy'), ['city' => '0701232000'])->assertOk();
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertJsonCount(0, 'messages');
        $this->postMessage('Hello again')->assertOk();
        $this->app['session']->driver()->migrate(true);
        $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertJsonCount(2, 'messages');
        $this->assertSame(4, DB::table('planning_messages')->count());
        $this->assertSame(2, DB::table('planning_conversations')->count());
    }

    public function test_missing_credentials_and_failed_provider_calls_leave_history_unchanged(): void
    {
        $this->signIn();
        config(['services.openai.api_key' => null]);
        $this->postMessage('Hello')->assertStatus(503)->assertJsonPath('status', 'unavailable');
        Http::assertNothingSent();
        config(['services.openai.api_key' => 'private-test-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => ['message' => 'private-test-key']], 401)
            ->push(['status' => 'incomplete', 'output' => []])
            ->push(['status' => 'completed', 'output' => []])
            ->push($this->text('Recovered'))]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->postMessage('Hello')->assertStatus(503);
            $this->assertStringNotContainsString('private-test-key', $response->getContent());
        }
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertJsonCount(0, 'messages');
        $this->postMessage('Hello')->assertOk()->assertJsonCount(2, 'messages');
    }

    public function test_concurrent_messages_and_resets_are_rejected_while_reply_is_pending(): void
    {
        $this->signIn();
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertOk();
        $key = 'planning-chat:v2:'.hash('sha256', '1|1');
        $lock = Cache::store('file')->lock($key.':lock', 240);
        $this->assertTrue($lock->get());
        try {
            $this->postMessage('Hello')->assertStatus(409);
            $this->deleteJson(route('planning-chat.destroy'), ['city' => '0701232000'])->assertStatus(409);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_history_is_bounded_and_tool_calls_always_have_matching_outputs(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->text('Reply'))]);
        $service = app(PlanningChatService::class);
        $state = [];
        for ($turn = 0; $turn < 7; $turn++) {
            $reply = $service->reply(Municipality::first(), 'Question '.$turn, $state);
            $this->assertSame('ready', $reply['status']);
            $state = $reply['state'];
        }
        $this->assertCount(4, $state['turns']);
        $this->assertCount(8, $state['messages']);
        $this->assertSame('Question 3', $state['messages'][0]['content']);
    }

    public function test_retrying_the_same_request_does_not_duplicate_a_turn_or_charge_another_call(): void
    {
        $this->signIn();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->text('Hello!'))]);
        $extra = ['request_id' => 'b5323adb-e31e-4bf1-96cd-e2b8dc075123'];
        $this->postMessage('Hello', $extra)->assertOk()->assertJsonCount(2, 'messages');
        $this->postMessage('Hello', $extra)->assertOk()->assertJsonCount(2, 'messages');
        Http::assertSentCount(1);
    }

    public function test_tool_call_loop_is_bounded_and_does_not_commit_partial_history(): void
    {
        $this->partialMock(FunctionToolRegistry::class, fn ($mock) => $mock->shouldReceive('execute')->times(5)->andReturn(['count' => 1]));
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->tool('analyze_health_access'))]);
        $result = app(PlanningChatService::class)->reply(Municipality::first(), 'Keep looking');
        $this->assertSame('unavailable', $result['status']);
        $this->assertArrayNotHasKey('state', $result);
        Http::assertSentCount(6);
    }

    public function test_openai_tool_schemas_exclude_server_only_arguments(): void
    {
        $definitions = app(PlanningChatService::class)->definitions();
        $this->assertCount(29, $definitions);
        foreach ($definitions as $definition) {
            $this->assertSame('function', $definition['type']);
            $this->assertFalse($definition['parameters']['additionalProperties']);
            foreach (['municipality_id', 'psg_code', 'level', 'gaps', 'custom_weights', 'include_narrative'] as $key) {
                $this->assertArrayNotHasKey($key, (array) $definition['parameters']['properties']);
            }
        }
    }

    public function test_complete_action_plans_are_saved_as_named_versions_without_overwriting_previous_plans(): void
    {
        $this->signIn();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->text($this->planText('Test City Agriculture Plan')))
            ->push($this->text($this->planText('Test City Agriculture Plan — Revised')))]);
        $first = $this->postMessage('Create an agriculture plan')->assertOk();
        $firstPlanId = $first->json('saved_plan_id');
        $this->assertNotNull($firstPlanId);
        $this->assertSame($firstPlanId, $first->json('messages.1.saved_plan_id'));
        $this->postMessage('Revise it for six months')->assertOk();
        $this->assertSame(2, DB::table('planning_action_plans')->count());
        $this->assertSame($this->planText('Test City Agriculture Plan'), DB::table('planning_action_plans')->where('id', $firstPlanId)->value('content'));
        $this->assertSame([1, 2], DB::table('planning_action_plans')->orderBy('id')->pluck('version')->all());
        $this->getJson(route('planning-chat.saved-plans'))->assertOk()->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.title', 'Test City Agriculture Plan — Revised')->assertJsonPath('items.0.city', '0701232000');
        $this->getJson(route('planning-chat.plan', ['plan' => $firstPlanId, 'city' => '0701232000']))
            ->assertOk()->assertJsonPath('plan.title', 'Test City Agriculture Plan');
        Http::assertSent(fn ($request) => collect($request['input'])->contains(fn ($item) => ($item['role'] ?? '') === 'developer'
            && str_contains($item['content'], 'Test City Agriculture Plan')));
        $this->signIn(2);
        $this->getJson(route('planning-chat.saved-plans'))->assertJsonCount(0, 'items');
        $this->getJson(route('planning-chat.plan', ['plan' => $firstPlanId, 'city' => '0701232000']))->assertNotFound();
    }

    public function test_new_chat_archives_instead_of_deleting_and_previous_conversations_can_be_resumed(): void
    {
        $this->signIn();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->text('Stored reply'))]);
        $firstId = $this->postMessage('Original chat')->assertOk()->json('conversation.id');
        $this->deleteJson(route('planning-chat.destroy'), ['city' => '0701232000'])->assertOk();
        $this->assertSame(2, DB::table('planning_messages')->count());
        $secondId = $this->postMessage('Another chat')->assertOk()->json('conversation.id');
        $this->assertNotSame($firstId, $secondId);
        $this->getJson(route('planning-chat.history', ['city' => '0701232000', 'kind' => 'chats']))->assertOk()->assertJsonCount(2, 'items');
        $this->postJson(route('planning-chat.resume', ['conversation' => $firstId]), ['city' => '0701232000'])
            ->assertOk()->assertJsonPath('messages.0.content', 'Original chat');
        $this->postMessage('Follow up', ['conversation_id' => $firstId])->assertOk()->assertJsonCount(4, 'messages');
        $this->assertSame(6, DB::table('planning_messages')->count());
        $this->assertSame(1, DB::table('planning_conversations')->where('active_slot', 1)->count());
        $this->postMessage('Stale tab', ['conversation_id' => $secondId])->assertStatus(409);
        $this->signIn(2);
        $this->postJson(route('planning-chat.resume', ['conversation' => $firstId]), ['city' => '0701232000'])->assertNotFound();
    }

    public function test_full_message_history_is_retained_and_paginated_independently_of_ai_context_limits(): void
    {
        $this->signIn();
        $history = app(PlanningChatHistory::class);
        for ($index = 0; $index < 25; $index++) {
            $history->append(1, Municipality::first(), ['messages' => [
                ['role' => 'user', 'content' => 'Question '.$index], ['role' => 'assistant', 'content' => 'Reply '.$index],
            ], 'turns' => []], null);
        }
        $this->assertSame(50, DB::table('planning_messages')->count());
        $recent = $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertOk()
            ->assertJsonCount(40, 'messages')->assertJsonPath('has_more_messages', true);
        $this->getJson(route('planning-chat.show', [
            'city' => '0701232000', 'conversation_id' => $recent->json('conversation.id'), 'before' => $recent->json('messages.0.id'),
        ]))->assertOk()->assertJsonCount(10, 'messages')->assertJsonPath('messages.0.content', 'Question 0')->assertJsonPath('has_more_messages', false);
        Http::assertNothingSent();
    }

    public function test_manual_plan_save_is_idempotent_and_restricted_to_the_owners_assistant_messages(): void
    {
        $this->signIn();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->text('A preliminary sector proposal.'))]);
        $reply = $this->postMessage('Draft a short proposal')->assertOk();
        $messageId = $reply->json('messages.1.id');
        $this->postJson(route('planning-chat.save-plan'), ['city' => '0701232000', 'message_id' => $messageId])->assertOk();
        $this->postJson(route('planning-chat.save-plan'), ['city' => '0701232000', 'message_id' => $messageId])->assertOk();
        $this->assertSame(1, DB::table('planning_action_plans')->count());
        $this->postJson(route('planning-chat.save-plan'), ['city' => '0701232000', 'message_id' => $reply->json('messages.0.id')])->assertNotFound();
        $this->postJson(route('planning-chat.save-plan'), ['city' => '0701233000', 'message_id' => $messageId])->assertNotFound();
        $this->signIn(2);
        $this->postJson(route('planning-chat.save-plan'), ['city' => '0701232000', 'message_id' => $messageId])->assertNotFound();
    }

    public function test_surviving_legacy_cached_chats_are_imported_once_with_their_action_plans(): void
    {
        $this->signIn();
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertOk();
        $key = 'planning-chat:v1:'.hash('sha256', '1|'.$this->app['session']->driver()->getId().'|1');
        Cache::store('file')->put($key, ['messages' => [
            ['role' => 'user', 'content' => 'Earlier request'],
            ['role' => 'assistant', 'content' => $this->planText('Previously Generated Plan'), 'generated_at' => now()->toIso8601String()],
        ], 'turns' => []], 3600);
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertOk()->assertJsonCount(2, 'messages');
        $this->getJson(route('planning-chat.show', ['city' => '0701232000']))->assertOk()->assertJsonCount(2, 'messages');
        $this->assertSame(1, DB::table('planning_conversations')->count());
        $this->assertSame(1, DB::table('planning_action_plans')->count());
        $this->assertFalse(Cache::store('file')->has($key));
        Http::assertNothingSent();
    }

    public function test_saved_plan_sidebar_paginates_all_versions_and_never_returns_other_users_plans(): void
    {
        $this->signIn();
        $history = app(PlanningChatHistory::class);
        for ($version = 1; $version <= 12; $version++) {
            $history->append(1, Municipality::first(), ['messages' => [
                ['role' => 'user', 'content' => 'Request '.$version],
                ['role' => 'assistant', 'content' => $this->planText('Named Plan '.$version)],
            ], 'turns' => []], null);
        }
        $this->getJson(route('planning-chat.saved-plans'))->assertOk()->assertJsonCount(10, 'items')->assertJsonPath('next_page', 2);
        $this->getJson(route('planning-chat.saved-plans', ['page' => 2]))->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('next_page', null);
        $this->signIn(2);
        $this->getJson(route('planning-chat.saved-plans'))->assertJsonCount(0, 'items');
    }

    public function test_legacy_history_from_another_session_is_archived_without_overwriting_a_newer_chat(): void
    {
        $history = app(PlanningChatHistory::class);
        $place = Municipality::first();
        $history->append(1, $place, ['messages' => [
            ['role' => 'user', 'content' => 'Newer request'], ['role' => 'assistant', 'content' => 'Newer reply'],
        ], 'turns' => []], null);
        $legacy = ['messages' => [
            ['role' => 'user', 'content' => 'Older cached request'], ['role' => 'assistant', 'content' => $this->planText('Older Cached Plan')],
        ], 'turns' => []];
        $history->importLegacy(1, $place, $legacy);
        $history->importLegacy(1, $place, $legacy);
        $this->assertSame(2, DB::table('planning_conversations')->count());
        $this->assertSame(4, DB::table('planning_messages')->count());
        $this->assertSame(1, DB::table('planning_action_plans')->count());
        $this->assertSame('Newer request', $history->payload($history->active(1, $place->id))['messages'][0]['content']);
    }

    public function test_tool_backed_graphs_and_assignment_diagrams_are_saved_with_chat_and_each_plan_version(): void
    {
        $this->signIn();
        $assignment = $this->assignment();
        $this->partialMock(FunctionToolRegistry::class, fn ($mock) => $mock->shouldReceive('execute')->once()
            ->with('generate_action_plan', ['municipality_id' => 1, 'include_narrative' => false])->andReturn([
                'proposed_technology_assignments' => [$assignment],
                'executive_outputs' => ['priority_areas' => ['top_priority_barangays' => [['name' => 'Recorded Barangay', 'priority_score' => 64.5]]]],
            ]));
        $content = $this->planText('Recorded Solar Dryer for Test City')."\nProposed technology: Recorded Solar Dryer.";
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->tool('generate_action_plan'))->push($this->text($content))->push($this->text($content."\nRevised timing: six months, proposed."))]);
        $response = $this->postMessage('Match a DOST technology and show charts', ['visuals' => [['value' => 999]]])
            ->assertOk()->assertJsonCount(3, 'messages.1.visuals')->assertJsonPath('messages.1.visuals.0.items.0.value', 82)
            ->assertJsonPath('messages.1.visuals.1.type', 'assignment_diagram')
            ->assertJsonPath('messages.1.visuals.1.assignments.0.nodes.2.label', 'Recorded Solar Dryer');
        $planId = $response->json('saved_plan_id');
        $stored = json_decode(DB::table('planning_action_plans')->where('id', $planId)->value('visuals'), true);
        $this->assertSame(82.0, (float) $stored[0]['items'][0]['value']);
        $this->assertSame(['from' => 'need', 'to' => 'technology'], $stored[1]['assignments'][0]['edges'][1]);
        $this->getJson(route('planning-chat.plan', ['plan' => $planId, 'city' => '0701232000']))
            ->assertOk()->assertJsonPath('plan.visuals.0.items.0.label', 'Recorded Solar Dryer');
        $this->postMessage('Revise timing without changing the technology')->assertOk()->assertJsonCount(3, 'messages.3.visuals');
        $second = json_decode(DB::table('planning_action_plans')->orderByDesc('id')->value('visuals'), true);
        $this->assertStringContainsString('Retained evidence', $second[0]['caption']);
        $this->assertSame($stored, json_decode(DB::table('planning_action_plans')->where('id', $planId)->value('visuals'), true));
    }

    public function test_graph_values_cannot_be_supplied_by_the_client_and_invalid_scores_are_not_plotted(): void
    {
        $this->signIn();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->text('Hello! Choose a local need to explore.'))]);
        $this->postMessage('Hi', ['visuals' => [['type' => 'bar', 'items' => [['value' => 999]]]]])
            ->assertOk()->assertJsonPath('messages.1.visuals', []);
        $result = ['proposed_technology_assignments' => [[...$this->assignment(), 'relevance_score' => 200]]];
        $turn = [
            ['type' => 'function_call', 'call_id' => 'visual_test', 'name' => 'generate_action_plan'],
            ['type' => 'function_call_output', 'call_id' => 'visual_test', 'output' => json_encode($result)],
        ];
        $visuals = app(PlanningConversationVisuals::class)->fromTurn($turn, Municipality::first());
        $this->assertCount(1, $visuals);
        $this->assertSame('assignment_diagram', $visuals[0]['type']);
    }

    public function test_action_plan_assigns_catalog_technologies_to_the_location_and_not_historical_project_titles(): void
    {
        $municipality = Municipality::first()->setRelation('placeDescription', null);
        $this->mock(GetPopulationStatisticsTool::class, fn ($mock) => $mock->shouldReceive('execute')->andReturn([]));
        $this->mock(GetAvailableDatasetsTool::class, fn ($mock) => $mock->shouldReceive('execute')->andReturn([]));
        $this->mock(CalculatePriorityScoresTool::class, fn ($mock) => $mock->shouldReceive('execute')->andReturn(['barangays' => []]));
        $this->mock(DetectDevelopmentGapsTool::class, fn ($mock) => $mock->shouldReceive('execute')->andReturn(['gaps' => []]));
        $this->mock(MatchDostInterventionsTool::class, fn ($mock) => $mock->shouldReceive('execute')->andReturn(['gap_interventions' => [[
            'gap_id' => 'post_harvest_gap', 'gap_name' => 'Recorded processing need', 'domain' => 'agriculture',
            'matched_interventions' => [
                ['source_catalog' => 'dost_projects', 'intervention_id' => 'PROJ-1', 'name' => 'Historical Project', 'relevance_score' => 99],
                ['source_catalog' => 'dost_techs', 'intervention_id' => 'DRYER-1', 'name' => 'Recorded Solar Dryer', 'relevance_score' => 82],
                ['source_catalog' => 'technologies', 'intervention_id' => 'PROCESS-1', 'name' => 'Recorded Processor', 'relevance_score' => 70],
            ],
        ]]]));
        foreach ([
            AssessInterventionSuitabilityTool::class,
            AssessImplementationFeasibilityTool::class,
            IdentifyImplementationPartnersTool::class,
            EstimateInterventionRequirementsTool::class,
            GenerateInterventionPlanTool::class,
            DefinePerformanceIndicatorsTool::class,
            EstimateInterventionOutcomesTool::class,
            ConductRiskAssessmentTool::class,
        ] as $class) {
            $this->mock($class, fn ($mock) => $mock->shouldReceive('execute')->once()->with([
                'municipality_id' => 1, 'domain' => 'agriculture', 'intervention_id' => 'DRYER-1',
            ])->andReturn([]));
        }
        $result = app(GenerateActionPlanTool::class)->execute(['location' => $municipality, 'include_narrative' => false]);
        $this->assertSame('matching_dost_technologies_to_location', $result['planning_focus']);
        $this->assertCount(2, $result['proposed_technology_assignments']);
        $this->assertSame('Recorded Solar Dryer', $result['proposed_technology_assignments'][0]['technology']['name']);
        $this->assertSame('Test City', $result['proposed_technology_assignments'][0]['target_location']['name']);
        $this->assertSame('dost_techs:DRYER-1', $result['executive_outputs']['recommended_interventions']['evaluation_applies_to']);
        $this->assertStringNotContainsString('Historical Project', json_encode($result['proposed_technology_assignments']));
    }

    private function assignment(): array
    {
        return [
            'catalog_ref' => 'dost_techs:DRYER-1',
            'technology' => ['source_catalog' => 'dost_techs', 'intervention_id' => 'DRYER-1', 'name' => 'Recorded Solar Dryer', 'relevance_score' => 82],
            'relevance_score' => 82, 'target_location' => ['name' => 'Test City'],
            'matched_needs' => [['name' => 'Recorded processing need']],
        ];
    }

    public function test_visuals_migration_adds_columns_without_removing_existing_chat_or_plan_data(): void
    {
        config(['database.connections.visual_migration_testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('visual_migration_testing');
        try {
            Schema::create('users', fn (Blueprint $table) => $table->id());
            Schema::create('municipalities', fn (Blueprint $table) => $table->id());
            DB::table('users')->insert(['id' => 1]);
            DB::table('municipalities')->insert(['id' => 1]);
            (require database_path('migrations/2026_11_03_120000_create_planning_chat_history_tables.php'))->up();
            DB::table('planning_conversations')->insert(['id' => 1, 'user_id' => 1, 'municipality_id' => 1, 'title' => 'Existing conversation']);
            DB::table('planning_messages')->insert(['id' => 1, 'conversation_id' => 1, 'role' => 'assistant', 'content' => 'Existing reply']);
            DB::table('planning_action_plans')->insert(['id' => 1, 'conversation_id' => 1, 'message_id' => 1, 'version' => 1, 'title' => 'Existing plan', 'content' => 'Existing plan body']);
            (require database_path('migrations/2026_11_03_130000_add_visuals_to_planning_history_tables.php'))->up();
            $this->assertTrue(Schema::hasColumn('planning_messages', 'visuals'));
            $this->assertTrue(Schema::hasColumn('planning_action_plans', 'visuals'));
            $this->assertSame('Existing reply', DB::table('planning_messages')->value('content'));
            $this->assertSame('Existing plan body', DB::table('planning_action_plans')->value('content'));
            $this->assertSame(1, DB::table('users')->count());
            $this->assertSame(1, DB::table('municipalities')->count());
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function planText(string $name): string
    {
        return "Plan title: {$name}\nDraft — pending LGU/DOST validation and approval.\n\n1. Priority Areas\nValidate barangay evidence.\n2. Critical Needs and Development Gaps\nConfirm gaps.\n3. Recommended STI Interventions\nVerify catalog availability.\n4. Investment and Resource Priorities\nCosts to be scoped.\n5. Expected Outcomes and Performance\nTargets pending review.";
    }

    private function signIn(int $id = 1): void
    {
        $this->actingAs((new User)->forceFill(['id' => $id, 'name' => 'Test User', 'role' => 'user']));
    }

    private function postMessage(string $message, array $extra = [])
    {
        return $this->postJson(route('planning-chat.store'), ['city' => '0701232000', 'message' => $message, ...$extra]);
    }

    private function text(string $text): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]]];
    }

    private function tool(string $name, array $arguments = []): array
    {
        return ['status' => 'completed', 'output' => [[
            'type' => 'function_call', 'call_id' => 'call_test', 'name' => $name,
            'arguments' => json_encode((object) $arguments, JSON_THROW_ON_ERROR),
        ]]];
    }
}
