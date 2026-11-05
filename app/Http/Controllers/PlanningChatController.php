<?php

namespace App\Http\Controllers;

use App\Models\Municipality;
use App\Support\Ai\PlanningChatHistory;
use App\Support\Ai\PlanningChatService;
use App\Support\Caching\ReadCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class PlanningChatController extends Controller
{
    public function __construct(private readonly PlanningChatHistory $history) {}

    public function locations(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $query = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($data['q']));

        return $this->json(app(ReadCache::class)->remember('planning.locations', ['query' => $query], fn () => ['locations' => Municipality::query()
            ->where(fn ($q) => $q->where('name', 'like', '%'.$query.'%')->orWhere('province_name', 'like', '%'.$query.'%'))
            ->orderBy('name')->limit(10)->get(['id', 'psg_code', 'name', 'province_name'])->toArray()]));
    }

    public function show(Request $request): JsonResponse
    {
        $municipality = $this->municipality($request);
        $data = $request->validate([
            'conversation_id' => ['sometimes', 'integer', 'min:1'],
            'before' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (! isset($data['conversation_id'])) {
            $legacy = Cache::store('file')->get($this->legacyKey($request, $municipality));
            if (! empty($legacy['messages'])) {
                return $this->locked($request, $municipality, function () use ($request, $municipality, $data) {
                    $this->importLegacy($request, $municipality);

                    return $this->response($municipality, $this->history->payload(
                        $this->history->active($request->user()->id, $municipality->id), $data['before'] ?? null
                    ));
                });
            }
        }
        $conversation = isset($data['conversation_id'])
            ? $this->history->find($request->user()->id, $municipality->id, $data['conversation_id'])
            : $this->history->active($request->user()->id, $municipality->id);

        return $this->response($municipality, $this->readHistory($request, 'messages',
            ['municipality' => $municipality->id, 'conversation' => $conversation?->id, 'before' => $data['before'] ?? null],
            fn () => $this->history->payload($conversation, $data['before'] ?? null)));
    }

    public function store(Request $request, PlanningChatService $chat): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
            'request_id' => ['sometimes', 'uuid'],
            'conversation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $municipality = $this->municipality($request);

        return $this->locked($request, $municipality, function () use ($request, $municipality, $data, $chat) {
            $this->importLegacy($request, $municipality);
            $conversation = $this->history->active($request->user()->id, $municipality->id);
            if (isset($data['conversation_id'])) {
                $this->history->find($request->user()->id, $municipality->id, $data['conversation_id']);
                abort_unless($conversation && (int) $conversation->id === (int) $data['conversation_id'], 409, 'The active conversation changed. Refresh the chat before sending.');
            }
            if ($this->history->hasRequest($conversation, $data['request_id'] ?? null)) {
                return $this->response($municipality, ['status' => 'ready', ...$this->history->payload($conversation)]);
            }
            $result = $chat->reply($municipality, trim($data['message']), $this->history->state($conversation));
            if ($result['status'] !== 'ready') {
                return $this->json($result, 503);
            }

            return $this->response($municipality, ['status' => 'ready', ...$this->history->append(
                $request->user()->id, $municipality, $result['state'], $data['request_id'] ?? null
            )]);
        });
    }

    public function destroy(Request $request): JsonResponse
    {
        $municipality = $this->municipality($request);

        return $this->locked($request, $municipality, function () use ($request, $municipality) {
            $this->importLegacy($request, $municipality);
            $this->history->archive($request->user()->id, $municipality->id);

            return $this->response($municipality, $this->history->payload(null));
        });
    }

    public function history(Request $request): JsonResponse
    {
        $municipality = $this->municipality($request);
        $data = $request->validate(['kind' => ['required', Rule::in(['chats', 'plans'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->json($this->readHistory($request, 'listing', ['municipality' => $municipality->id, ...$data],
            fn () => $this->history->listing($request->user()->id, $municipality->id, $data['kind'], $data['page'] ?? 1)));
    }

    public function resume(Request $request, int $conversation): JsonResponse
    {
        $municipality = $this->municipality($request);

        return $this->locked($request, $municipality, fn () => $this->response($municipality,
            $this->history->resume($request->user()->id, $municipality->id, $conversation)
        ));
    }

    public function plan(Request $request, int $plan): JsonResponse
    {
        $municipality = $this->municipality($request);

        return $this->json($this->readHistory($request, 'plan', ['municipality' => $municipality->id, 'plan' => $plan],
            fn () => ['plan' => $this->history->plan($request->user()->id, $municipality->id, $plan)]));
    }

    public function savePlan(Request $request): JsonResponse
    {
        $municipality = $this->municipality($request);
        $data = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);

        return $this->locked($request, $municipality, fn () => $this->json(
            $this->history->saveMessage($request->user()->id, $municipality, $data['message_id'])
        ));
    }

    public function savedPlans(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->json($this->readHistory($request, 'saved-plans', $data,
            fn () => $this->history->allPlans($request->user()->id, $data['page'] ?? 1)));
    }

    private function readHistory(Request $request, string $name, array $parameters, callable $compute): array
    {
        return app(ReadCache::class)->remember('planning.'.$name, ['user' => $request->user()->getAuthIdentifier(), ...$parameters],
            $compute, (int) config('read-cache.planning_ttl', 30), 'planning');
    }

    private function municipality(Request $request): Municipality
    {
        $data = $request->validate([
            'city' => ['nullable', 'required_without:municipality_id', 'string', 'regex:/^\d{10}$/'],
            'municipality_id' => ['nullable', 'required_without:city', 'integer', 'min:1'],
        ]);

        return filled($data['city'] ?? null)
            ? Municipality::query()->where('psg_code', $data['city'])->firstOrFail()
            : Municipality::query()->findOrFail($data['municipality_id']);
    }

    private function legacyKey(Request $request, Municipality $municipality): string
    {
        return 'planning-chat:v1:'.hash('sha256', $request->user()->getAuthIdentifier().'|'.$request->session()->getId().'|'.$municipality->id);
    }

    private function importLegacy(Request $request, Municipality $municipality): void
    {
        $cache = Cache::store('file');
        $key = $this->legacyKey($request, $municipality);
        if ($state = $cache->get($key)) {
            $this->history->importLegacy($request->user()->id, $municipality, $state);
            $cache->forget($key);
        }
    }

    private function locked(Request $request, Municipality $municipality, callable $operation): JsonResponse
    {
        // Stable across logins: histories belong to the account, not the browser session.
        $key = 'planning-chat:v2:'.hash('sha256', $request->user()->id.'|'.$municipality->id);
        $lock = Cache::store('file')->lock($key.':lock', 240);
        if (! $lock->get()) {
            return $this->json(['message' => 'A reply or history update is already in progress. Please wait a moment.'], 409);
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private function response(Municipality $municipality, array $payload): JsonResponse
    {
        return $this->json([...$payload, 'location' => $this->location($municipality), 'configured' => filled(config('services.openai.api_key'))]);
    }

    private function location(Municipality $municipality): array
    {
        return $municipality->only(['id', 'psg_code', 'name', 'province_name']);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
