<?php

namespace App\Console\Commands;

use App\Support\Ai\ResponsesClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Verifies the AI provider configuration. Model discovery is free on GATES; --generate and --tools
 * each make tiny paid generations, so they only run when asked for.
 */
class AiCheck extends Command
{
    protected $signature = 'ai:check
        {--generate : Also run one tiny paid text generation}
        {--tools : Also run a tiny paid tool round trip, shaped like a GeoAgent turn}';

    protected $description = 'Check the AI provider (GATES or OpenAI): URL, key and model. Discovery is free; --generate/--tools spend a little credit.';

    public function handle(ResponsesClient $client): int
    {
        $this->components->twoColumnDetail('Provider', $client->provider());
        if ($client->isGates()) {
            $this->components->twoColumnDetail('Gateway', $client->gatesBaseUrl() ?? '<fg=red>GATES_BASE_URL missing or invalid</>');
        }
        $this->components->twoColumnDetail('Model', $client->model());
        if (! $client->configured()) {
            $this->components->error($client->missingConfigurationMessage('The AI provider'));

            return self::FAILURE;
        }

        try {
            if (! $this->discover($client)) {
                return self::FAILURE;
            }
            if ($this->option('generate') && ! $this->generate($client)) {
                return self::FAILURE;
            }
            if ($this->option('tools') && ! $this->toolRoundTrip($client)) {
                return self::FAILURE;
            }
        } catch (ConnectionException) {
            $this->components->error('The provider could not be reached. Check the URL, network and CA bundle (GATES_CA_BUNDLE).');

            return self::FAILURE;
        }

        if (! $this->option('generate') && ! $this->option('tools')) {
            $this->components->info('Configuration verified without spending credit. Add --generate or --tools to test a real generation.');
        }

        return self::SUCCESS;
    }

    private function discover(ResponsesClient $client): bool
    {
        $response = $client->models();
        if (! $response->successful()) {
            return $this->failed($client, $response, 'Model discovery');
        }
        if (! $client->isGates()) {
            $this->components->info('OpenAI accepted the key and model.');

            return true;
        }
        $models = collect($response->json('data', []));
        $this->components->twoColumnDetail('Eligible models', $models->pluck('id')->implode(', ') ?: '(none)');
        $model = $models->firstWhere('id', $client->model());
        if (! $model) {
            $this->components->error('GATES_MODEL is not in the eligible list above. Set it to one of those IDs.');

            return false;
        }
        $this->components->twoColumnDetail('Capabilities', json_encode($model['capabilities'] ?? null));
        $this->components->twoColumnDetail('Limits', json_encode($model['limits'] ?? null));
        $this->components->info('Gateway, key and model verified (no credit used).');

        return true;
    }

    private function generate(ResponsesClient $client): bool
    {
        $response = $client->create(['input' => 'Reply with the single word OK.', 'max_output_tokens' => 128]);
        if (! $response->successful()) {
            return $this->failed($client, $response, 'Generation');
        }
        $this->components->twoColumnDetail('Status', (string) $response->json('status'));
        $this->components->twoColumnDetail('Reply', $this->text($response) ?: '(no text)');
        $this->components->twoColumnDetail('Usage', json_encode($response->json('usage')));
        $this->components->twoColumnDetail('Request ID', (string) ($response->header('X-Request-ID') ?: '-'));

        return $response->json('status') === 'completed';
    }

    /** Same request shape as GeoAgent: tools, tool_choice, and the model's own output items sent back. */
    private function toolRoundTrip(ResponsesClient $client): bool
    {
        $tools = [[
            'type' => 'function', 'name' => 'get_check_value', 'description' => 'Returns the configuration check value.',
            'strict' => false, 'parameters' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false, 'required' => []],
        ]];
        $input = [['role' => 'user', 'content' => 'Call get_check_value, then reply with only the number it returns.']];
        $request = fn (array $input) => $client->create([
            'input' => $input, 'tools' => $tools, 'tool_choice' => 'auto', 'parallel_tool_calls' => false,
            'max_output_tokens' => 300, 'include' => ['reasoning.encrypted_content'],
        ]);

        $first = $request($input);
        if (! $first->successful()) {
            return $this->failed($client, $first, 'Tool call');
        }
        $call = collect($first->json('output', []))->firstWhere('type', 'function_call');
        if (! $call) {
            $this->components->error('The model answered without calling the tool; GeoAgent needs tool calls.');

            return false;
        }
        $second = $request([...$input, ...$first->json('output', []), [
            'type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => json_encode(['value' => 42]),
        ]]);
        if (! $second->successful()) {
            return $this->failed($client, $second, 'Tool result follow-up');
        }
        $reply = $this->text($second);
        $this->components->twoColumnDetail('Tool reply', $reply ?: '(no text)');
        if (! str_contains($reply, '42')) {
            $this->components->error('The tool result did not reach the final answer.');

            return false;
        }
        $this->components->info('Tool round trip works, so GeoAgent can call the assessment tools.');

        return true;
    }

    private function failed(ResponsesClient $client, Response $response, string $step): bool
    {
        $this->components->error($step.' failed: HTTP '.$response->status().' '.($client->errorCode($response) ?? ''));
        if ($message = $client->gatesFailureMessage($response)) {
            $this->line('  '.$message);
        }
        $this->line('  Request ID: '.($response->header('X-Request-ID') ?: '-'));

        return false;
    }

    private function text(Response $response): string
    {
        $texts = [];
        foreach ($response->json('output', []) as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    $texts[] = $content['text'];
                }
            }
        }

        return trim(implode("\n", $texts));
    }
}
