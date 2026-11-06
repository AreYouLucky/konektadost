<?php

namespace Tests\Feature;

use App\Support\Ai\ResponsesClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/** Provider selection and the GATES gateway contract. No network: every request is faked. */
class AiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.provider' => 'gates',
            'services.gates.api_key' => 'gates_test_key',
            'services.gates.base_url' => 'https://gates.test',
            'services.gates.model' => 'openai.gpt-6-luna',
            'services.gates.max_request_bytes' => 2_000_000,
        ]);
        Http::preventStrayRequests();
    }

    public function test_the_base_url_is_reduced_to_the_gateway_origin(): void
    {
        $client = app(ResponsesClient::class);
        foreach (['https://gates.test', 'https://gates.test/', 'https://gates.test/v1', 'https://gates.test/v1/responses'] as $url) {
            config(['services.gates.base_url' => $url]);
            $this->assertSame('https://gates.test', $client->gatesBaseUrl(), $url);
        }
        config(['services.gates.base_url' => 'not a url']);
        $this->assertNull($client->gatesBaseUrl());
        $this->assertFalse($client->configured());
    }

    public function test_openai_remains_the_fallback_provider(): void
    {
        config(['services.ai.provider' => 'openai', 'services.openai.api_key' => 'sk-test', 'services.openai.model' => 'gpt-4.1-mini']);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => []])]);
        app(ResponsesClient::class)->create(['input' => 'Hi', 'max_output_tokens' => 10, 'include' => ['reasoning.encrypted_content']]);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['store'] === false && $request['model'] === 'gpt-4.1-mini' && $request['include'] === ['reasoning.encrypted_content']
            && ! $request->hasHeader('X-Request-ID'));
    }

    public function test_oversized_requests_are_refused_before_any_network_call(): void
    {
        config(['services.gates.max_request_bytes' => 1_000]);
        Http::fake();
        $response = app(ResponsesClient::class)->create(['input' => str_repeat('a', 2_000), 'max_output_tokens' => 10]);
        $this->assertSame(413, $response->status());
        $this->assertSame('request_too_large', app(ResponsesClient::class)->errorCode($response));
        $this->assertStringContainsString('too large', app(ResponsesClient::class)->gatesFailureMessage($response));
        Http::assertNothingSent();
    }

    public function test_a_generation_needs_a_positive_output_ceiling(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ResponsesClient::class)->create(['input' => 'Hi']);
    }

    public function test_ai_check_verifies_the_gateway_with_free_model_discovery_only(): void
    {
        Http::fake(['gates.test/v1/models' => Http::response(['object' => 'list', 'data' => [
            ['id' => 'openai.gpt-6-luna', 'capabilities' => ['tools' => true], 'limits' => ['max_request_bytes' => 2_000_000]],
        ]])]);
        $this->artisan('ai:check')->expectsOutputToContain('no credit used')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://gates.test/v1/models'
            && $request->hasHeader('Authorization', 'Bearer gates_test_key'));
    }

    public function test_ai_check_reports_a_model_the_gateway_does_not_offer(): void
    {
        Http::fake(['gates.test/v1/models' => Http::response(['data' => [['id' => 'openai.other-model']]])]);
        $this->artisan('ai:check')->expectsOutputToContain('GATES_MODEL is not in the eligible list')->assertFailed();
    }

    public function test_ai_check_tool_round_trip_sends_the_tool_result_back(): void
    {
        Http::fake([
            'gates.test/v1/models' => Http::response(['data' => [['id' => 'openai.gpt-6-luna']]]),
            'gates.test/v1/responses' => Http::sequence()
                ->push(['status' => 'completed', 'output' => [['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'get_check_value', 'arguments' => '{}']]])
                ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '42']]]]]),
        ]);
        $this->artisan('ai:check --tools')->expectsOutputToContain('Tool round trip works')->assertSuccessful();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/responses')
            && collect($request['input'] ?? [])->contains(fn ($item) => ($item['type'] ?? '') === 'function_call_output' && $item['call_id'] === 'call_1'));
    }
}
