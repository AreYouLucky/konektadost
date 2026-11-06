<?php

namespace App\Support\Ai;

use App\Models\Municipality;
use App\Support\Assessment\AssessmentActionPlans;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/** Persistence for assistant activity without introducing additional domain models. */
class PlanningChatHistory
{
    public function active(int $userId, int $municipalityId): ?stdClass
    {
        return DB::table('planning_conversations')->where('user_id', $userId)
            ->where('municipality_id', $municipalityId)->where('active_slot', 1)->first();
    }

    public function find(int $userId, int $municipalityId, int $id): stdClass
    {
        $conversation = DB::table('planning_conversations')->where('user_id', $userId)
            ->where('municipality_id', $municipalityId)->where('id', $id)->first();
        abort_unless($conversation, 404);

        return $conversation;
    }

    public function state(?stdClass $conversation): array
    {
        return $conversation?->agent_state ? json_decode($conversation->agent_state, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function payload(?stdClass $conversation, ?int $before = null): array
    {
        if (! $conversation) {
            return ['conversation' => null, 'messages' => [], 'has_more_messages' => false];
        }
        $query = DB::table('planning_messages')->where('conversation_id', $conversation->id);
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $rows = $query->orderByDesc('id')->limit(40)->get()->reverse()->values();
        $planIds = DB::table('planning_action_plans')->whereIn('message_id', $rows->pluck('id'))->pluck('id', 'message_id');
        $messages = $rows->map(fn ($row) => [
            'id' => $row->id, 'role' => $row->role, 'content' => $row->content,
            'sources' => $row->sources ? json_decode($row->sources, true) : [], 'generated_at' => $row->created_at,
            'saved_plan_id' => $planIds->get($row->id),
            'visuals' => $row->visuals ? json_decode($row->visuals, true) : [],
        ])->all();

        return [
            'conversation' => ['id' => $conversation->id, 'title' => $conversation->title, 'archived' => $conversation->active_slot === null],
            'messages' => $messages,
            'has_more_messages' => $rows->isNotEmpty() && DB::table('planning_messages')
                ->where('conversation_id', $conversation->id)->where('id', '<', $rows->first()->id)->exists(),
        ];
    }

    public function hasRequest(?stdClass $conversation, ?string $requestId): bool
    {
        return $conversation && $requestId && DB::table('planning_messages')->where('conversation_id', $conversation->id)
            ->where('request_id', $requestId)->where('role', 'assistant')->exists();
    }

    public function append(int $userId, Municipality $municipality, array $state, ?string $requestId): array
    {
        return DB::transaction(function () use ($userId, $municipality, $state, $requestId) {
            $conversation = $this->active($userId, $municipality->id) ?? $this->create($userId, $municipality);
            $newMessages = array_slice($state['messages'], -2);
            $plan = null;
            foreach ($newMessages as $message) {
                $messageId = $this->insertMessage($conversation->id, $message, $requestId);
                if ($message['role'] === 'assistant' && $this->isActionPlan($message['content'])) {
                    $plan = $this->insertPlan($conversation, $municipality, $messageId, $message['content'], [
                        'tools' => $this->evidence($state), 'based_on_plan_id' => $state['latest_action_plan']['id'] ?? null,
                    ], $message['visuals'] ?? []);
                }
            }
            if ($plan) {
                $state['latest_action_plan'] = ['id' => $plan->id, 'content' => $plan->content, 'version' => $plan->version, 'saved_at' => $plan->created_at, 'visuals' => json_decode($plan->visuals ?? '[]', true)];
            }
            DB::table('planning_conversations')->where('id', $conversation->id)->update([
                'agent_state' => $this->encode($state), 'updated_at' => now(),
                'title' => $conversation->title === 'New planning conversation' ? mb_substr($newMessages[0]['content'], 0, 100) : $conversation->title,
            ]);

            return [...$this->payload($this->find($userId, $municipality->id, $conversation->id)), 'saved_plan_id' => $plan?->id];
        });
    }

    /** Preserve the surviving cache history from the earlier session-based version, if any. */
    public function importLegacy(int $userId, Municipality $municipality, array $state): void
    {
        if (empty($state['messages'])) {
            return;
        }
        $state['legacy_import_hash'] = hash('sha256', $this->encode($state['messages']));
        if (DB::table('planning_conversations')->where('user_id', $userId)->where('municipality_id', $municipality->id)
            ->where('agent_state->legacy_import_hash', $state['legacy_import_hash'])->exists()) {
            return;
        }
        DB::transaction(function () use ($userId, $municipality, $state) {
            // A legacy chat from another browser session must not overwrite or be discarded by a newer chat.
            $conversation = $this->create($userId, $municipality, ! DB::table('planning_conversations')
                ->where('user_id', $userId)->where('municipality_id', $municipality->id)->exists());
            foreach ($state['messages'] as $message) {
                $id = $this->insertMessage($conversation->id, $message, null);
                if ($message['role'] === 'assistant' && $this->isActionPlan($message['content'])) {
                    $plan = $this->insertPlan($conversation, $municipality, $id, $message['content'], [], $message['visuals'] ?? []);
                    $state['latest_action_plan'] = ['id' => $plan->id, 'content' => $plan->content, 'version' => $plan->version, 'saved_at' => $plan->created_at, 'visuals' => json_decode($plan->visuals ?? '[]', true)];
                }
            }
            DB::table('planning_conversations')->where('id', $conversation->id)->update([
                'agent_state' => $this->encode($state), 'title' => mb_substr($state['messages'][0]['content'], 0, 100), 'updated_at' => now(),
            ]);
        });
    }

    public function archive(int $userId, int $municipalityId): void
    {
        DB::table('planning_conversations')->where('user_id', $userId)->where('municipality_id', $municipalityId)
            ->where('active_slot', 1)->update(['active_slot' => null, 'archived_at' => now(), 'updated_at' => now()]);
    }

    public function resume(int $userId, int $municipalityId, int $id): array
    {
        return DB::transaction(function () use ($userId, $municipalityId, $id) {
            $this->find($userId, $municipalityId, $id);
            $this->archive($userId, $municipalityId);
            DB::table('planning_conversations')->where('id', $id)->update(['active_slot' => 1, 'archived_at' => null, 'updated_at' => now()]);

            return $this->payload($this->find($userId, $municipalityId, $id));
        });
    }

    public function listing(int $userId, int $municipalityId, string $kind, int $page): array
    {
        $query = $kind === 'plans'
            ? DB::table('planning_action_plans as plans')->join('planning_conversations as chats', 'chats.id', '=', 'plans.conversation_id')
                ->select(['plans.id', 'plans.conversation_id', 'plans.title', 'plans.version', 'plans.status', 'plans.created_at'])
                ->orderByDesc('plans.id')
            : DB::table('planning_conversations as chats')->select(['chats.id', 'chats.title', 'chats.updated_at', 'chats.archived_at'])->orderByDesc('chats.updated_at')->orderByDesc('chats.id');
        $rows = $query->where('chats.user_id', $userId)->where('chats.municipality_id', $municipalityId)
            ->offset(($page - 1) * 10)->limit(11)->get();

        return ['items' => $rows->take(10)->values()->all(), 'next_page' => $rows->count() > 10 ? $page + 1 : null];
    }

    public function plan(int $userId, int $municipalityId, int $id): array
    {
        $plan = DB::table('planning_action_plans as plans')->join('planning_conversations as chats', 'chats.id', '=', 'plans.conversation_id')
            ->where('chats.user_id', $userId)->where('chats.municipality_id', $municipalityId)->where('plans.id', $id)->select('plans.*')->first();
        abort_unless($plan, 404);

        return [...(array) $plan, 'visuals' => $plan->visuals ? json_decode($plan->visuals, true) : []];
    }

    /** The account's chat plans and assessment-page plans, newest first. `kind` tells the client how to open each. */
    public function allPlans(int $userId, int $page): array
    {
        $chat = DB::table('planning_action_plans as plans')
            ->join('planning_conversations as chats', 'chats.id', '=', 'plans.conversation_id')
            ->join('municipalities as places', 'places.id', '=', 'chats.municipality_id')
            ->where('chats.user_id', $userId)
            ->selectRaw("'chat' as kind, plans.id, plans.title, plans.version, plans.created_at, places.psg_code as city, places.name as municipality");
        $query = Schema::hasTable(AssessmentActionPlans::TABLE)
            ? $chat->unionAll(DB::table(AssessmentActionPlans::TABLE)->where('user_id', $userId)
                ->selectRaw("'assessment' as kind, id, title, version, created_at, psg_code as city, location_name as municipality"))
                ->orderByDesc('created_at')->orderByDesc('id')
            : $chat->orderByDesc('plans.id');
        $rows = $query->offset(($page - 1) * 10)->limit(11)->get();

        return ['items' => $rows->take(10)->values()->all(), 'next_page' => $rows->count() > 10 ? $page + 1 : null];
    }

    public function saveMessage(int $userId, Municipality $municipality, int $messageId): array
    {
        return DB::transaction(function () use ($userId, $municipality, $messageId) {
            $message = DB::table('planning_messages as messages')->join('planning_conversations as chats', 'chats.id', '=', 'messages.conversation_id')
                ->where('chats.user_id', $userId)->where('chats.municipality_id', $municipality->id)
                ->where('messages.id', $messageId)->where('messages.role', 'assistant')->select('messages.*')->first();
            abort_unless($message, 404);
            $conversation = $this->find($userId, $municipality->id, $message->conversation_id);
            // Do not attach evidence from a newer turn to a manually saved older message.
            $plan = $this->insertPlan($conversation, $municipality, $messageId, $message->content, [], $message->visuals ? json_decode($message->visuals, true) : []);

            return ['saved_plan_id' => $plan->id, 'version' => $plan->version];
        });
    }

    public function isActionPlan(string $content): bool
    {
        foreach (['Priority Areas', 'Critical Needs', 'Recommended STI Interventions', 'Investment and Resource', 'Expected Outcomes'] as $heading) {
            if (! preg_match('/(?:^|\n)\s*(?:[#* ]*)[1-5][.)]\s*'.preg_quote($heading, '/').'/iu', $content)) {
                return false;
            }
        }

        return true;
    }

    private function create(int $userId, Municipality $municipality, bool $active = true): stdClass
    {
        $id = DB::table('planning_conversations')->insertGetId([
            'user_id' => $userId, 'municipality_id' => $municipality->id, 'title' => 'New planning conversation',
            'active_slot' => $active ? 1 : null, 'archived_at' => $active ? null : now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->find($userId, $municipality->id, $id);
    }

    private function insertMessage(int $conversationId, array $message, ?string $requestId): int
    {
        return DB::table('planning_messages')->insertGetId([
            'conversation_id' => $conversationId, 'role' => $message['role'], 'content' => $message['content'],
            'sources' => $this->encode($message['sources'] ?? []), 'request_id' => $requestId,
            'visuals' => $this->encode($message['visuals'] ?? []),
            'created_at' => isset($message['generated_at']) ? Carbon::parse($message['generated_at'])->utc()->toDateTimeString() : now(), 'updated_at' => now(),
        ]);
    }

    private function insertPlan(stdClass $conversation, Municipality $municipality, int $messageId, string $content, array $evidence, array $visuals = []): stdClass
    {
        $existing = DB::table('planning_action_plans')->where('message_id', $messageId)->first();
        if ($existing) {
            return $existing;
        }
        $version = (int) DB::table('planning_action_plans')->where('conversation_id', $conversation->id)->max('version') + 1;
        $id = DB::table('planning_action_plans')->insertGetId([
            'conversation_id' => $conversation->id, 'message_id' => $messageId, 'version' => $version,
            'title' => $this->planTitle($content, $municipality), 'status' => 'draft_pending_human_validation',
            'content' => $content, 'evidence_snapshot' => $this->encode($evidence), 'model' => app(ResponsesClient::class)->model(),
            'visuals' => $this->encode($visuals),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('planning_action_plans')->where('id', $id)->first();
    }

    private function evidence(array $state): array
    {
        $latest = array_slice($state['turns'] ?? [], -1)[0] ?? [];
        $calls = [];
        $evidence = [];
        foreach ($latest as $item) {
            if (($item['type'] ?? '') === 'function_call') {
                $calls[$item['call_id']] = $item['name'];
            } elseif (($item['type'] ?? '') === 'function_call_output' && isset($calls[$item['call_id']])) {
                $evidence[] = ['tool' => $calls[$item['call_id']], 'result' => json_decode($item['output'], true)];
            }
        }

        return $evidence;
    }

    private function planTitle(string $content, Municipality $municipality): string
    {
        if (preg_match('/^\s*(?:Plan title|Action plan title):\s*(.+)$/imu', $content, $match)) {
            return mb_substr(trim($match[1], " \t\n\r\0\x0B*#"), 0, 230);
        }

        return mb_substr('Action plan — '.$municipality->name, 0, 230);
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
