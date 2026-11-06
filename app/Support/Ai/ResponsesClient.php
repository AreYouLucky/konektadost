<?php

namespace App\Support\Ai;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The single place that calls a Responses API: the GATES LLM gateway or OpenAI, selected by
 * `services.ai.provider`. Callers build ordinary Responses payloads; this adapts them per provider.
 *
 * GATES contract (gates_llm 0.2.0): POST {base}/v1/responses with a Bearer key, a fresh X-Request-ID
 * per generation (a reused ID is rejected as duplicate_request), a positive max_output_tokens (credit
 * is reserved from it) and a 2 MB body limit. GATES forces store=false and disables stored responses,
 * background jobs and hosted tools, so those options are never sent to it.
 */
class ResponsesClient
{
    public const GATES = 'gates';

    public const OPENAI = 'openai';

    private const OPENAI_URL = 'https://api.openai.com/v1';

    /** Options GATES manages itself or has disabled. */
    private const GATES_MANAGED_OPTIONS = ['store', 'include', 'previous_response_id', 'background', 'service_tier', 'conversation'];

    public function provider(): string
    {
        return config('services.ai.provider') === self::GATES ? self::GATES : self::OPENAI;
    }

    public function isGates(): bool
    {
        return $this->provider() === self::GATES;
    }

    public function configured(): bool
    {
        return $this->isGates()
            ? filled(config('services.gates.api_key')) && $this->gatesBaseUrl() !== null
            : filled(config('services.openai.api_key'));
    }

    public function model(): string
    {
        return (string) ($this->isGates()
            ? config('services.gates.model', 'openai.gpt-6-luna')
            : config('services.openai.model', 'gpt-4.1-mini'));
    }

    /** Per-request timeout in seconds. */
    public function timeout(): int
    {
        return max(10, (int) config($this->isGates() ? 'services.gates.timeout' : 'services.openai.timeout', 60));
    }

    /** Reasoning effort for short structured explanations, or null when it should not be sent. */
    public function explanationReasoningEffort(): ?string
    {
        if ($this->isGates()) {
            return filled(config('services.gates.reasoning_effort')) ? (string) config('services.gates.reasoning_effort') : null;
        }
        $model = $this->model();

        // Default GPT-5 reasoning can exhaust the HTTP budget for explanations of computed results.
        return str_starts_with($model, 'gpt-5') && ! str_contains($model, 'chat') && ! str_contains($model, 'pro') ? 'low' : null;
    }

    public function missingConfigurationMessage(string $feature): string
    {
        return $this->isGates()
            ? $feature . ' is not configured. Add GATES_API_KEY and GATES_BASE_URL on the server.'
            : $feature . ' is not configured. Add OPENAI_API_KEY on the server.';
    }

    /** The gateway origin with any /v1 or /responses suffix removed, or null when it is missing or not a URL. */
    public function gatesBaseUrl(): ?string
    {
        $url = rtrim(trim((string) config('services.gates.base_url')), '/');
        $url = (string) preg_replace('#(/v1)?(/responses)?$#', '', $url);

        return preg_match('#^https?://[^/\s]+#i', $url) ? $url : null;
    }

    /**
     * One bounded generation. Never retried here: a repeated generation could be billed twice.
     *
     * @throws ConnectionException when the provider cannot be reached
     */
    public function create(array $payload, ?int $timeout = null): Response
    {
        if (! is_int($payload['max_output_tokens'] ?? null) || $payload['max_output_tokens'] < 1) {
            throw new InvalidArgumentException('A positive max_output_tokens is required.');
        }
        $payload['model'] ??= $this->model();
        $timeout ??= $this->timeout();

        if (! $this->isGates()) {
            return $this->request('services.openai', $timeout)->post(self::OPENAI_URL . '/responses', ['store' => false, ...$payload]);
        }

        $requestId = bin2hex(random_bytes(16));
        $body = json_encode(
            array_diff_key($payload, array_flip(self::GATES_MANAGED_OPTIONS)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $limit = max(1, (int) config('services.gates.max_request_bytes', 2_000_000));
        if (strlen($body) > $limit) {
            // Same shape as the gateway's own 413, so callers handle both identically.
            return $this->logged($this->syntheticError(413, 'request_too_large', $requestId), $requestId);
        }
        $response = $this->request('services.gates', $timeout)
            ->withHeaders(['X-Request-ID' => $requestId])
            ->withBody($body, 'application/json')
            ->post($this->gatesBaseUrl() . '/v1/responses');

        return $this->logged($response, $requestId);
    }

    /** Model discovery. Free on GATES (no inference credit), so it is safe for configuration checks. */
    public function models(): Response
    {
        if (! $this->isGates()) {
            return $this->request('services.openai', 20)->get(self::OPENAI_URL . '/models/' . rawurlencode($this->model()));
        }
        $requestId = bin2hex(random_bytes(16));

        // A GET is safe to retry, as the SDK does for transport errors and 502/503/504.
        return $this->logged($this->request('services.gates', 20)
            ->withHeaders(['X-Request-ID' => $requestId])
            ->retry(3, 100, fn($exception) => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [502, 503, 504], true)), throw: false)
            ->get($this->gatesBaseUrl() . '/v1/models'), $requestId);
    }

    /** The GATES error code of a failed response (e.g. budget_exhausted), if any. */
    public function errorCode(Response $response): ?string
    {
        $code = $response->json('error.code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * A user-facing explanation for a failed GATES response, or null for OpenAI (whose callers keep
     * their own wording). Never includes provider payloads or credentials.
     */
    public function gatesFailureMessage(Response $response): ?string
    {
        if (! $this->isGates()) {
            return null;
        }
        $code = $this->errorCode($response);

        return match (true) {
            in_array($code, ['authentication_required', 'invalid_api_key'], true), in_array($response->status(), [401, 403], true) => 'The AI gateway rejected the server key. Check GATES_API_KEY and GATES_BASE_URL.',
            $code === 'budget_exhausted' => 'The AI gateway has no credit available for this request. Check the balance on the GATES dashboard.',
            $code === 'model_not_allowed' => 'The configured AI model is not enabled on the gateway. Run php artisan ai:check to list eligible models.',
            $code === 'rate_limit_exceeded', $response->status() === 429 => 'The AI gateway is rate-limiting requests. Please wait a minute and retry.',
            $code === 'request_too_large', $response->status() === 413 => 'This request is too large for the AI gateway. Start a new conversation or ask a narrower question.',
            in_array($code, ['provider_rejected_request', 'capability_not_supported'], true) => 'The AI model rejected a request option. Run php artisan ai:check to verify the model.',
            $response->status() === 404 => 'The AI gateway address is wrong. Set GATES_BASE_URL to the gateway origin, without /v1.',
            default => 'The AI gateway is temporarily unavailable. Please retry.',
        };
    }

    private function request(string $service, int $timeout): PendingRequest
    {
        return Http::withToken((string) config($service . '.api_key'))
            ->withOptions(['verify' => config($service . '.ca_bundle') ?: true])
            ->acceptJson()->connectTimeout(10)->timeout($timeout);
    }

    /** Records only correlation metadata for failed gateway calls: never prompts, outputs or keys. */
    private function logged(Response $response, string $requestId): Response
    {
        if ($response->failed()) {
            Log::warning('AI gateway request failed', [
                'status' => $response->status(),
                'code' => $this->errorCode($response),
                'request_id' => $response->header('X-Request-ID') ?: $requestId,
            ]);
        }

        return $response;
    }

    private function syntheticError(int $status, string $code, string $requestId): Response
    {
        return new Response(new PsrResponse(
            $status,
            ['Content-Type' => 'application/json', 'X-Request-ID' => $requestId],
            json_encode(['error' => ['code' => $code, 'message' => 'The encoded request exceeds the gateway limit.', 'correlation_id' => $requestId]], JSON_THROW_ON_ERROR)
        ));
    }
}
