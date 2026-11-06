<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Assessment\AssessmentActionPlans;
use App\Support\Assessment\IndicatorCatalog;
use App\Support\Caching\ReadCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only props for the standard user dashboard.
 *
 * Platform figures are recorded counts from the live domain tables (cached with the
 * domain read cache). Planning figures are scoped to the signed-in account. Every
 * table is checked before it is read, so a database without the imported domain
 * tables renders an empty dashboard instead of failing.
 */
class UserDashboardData
{
    /** Display order and labels for normalized DostProject statuses. */
    private const PROJECT_STATUSES = [
        'completed' => 'Completed',
        'ongoing' => 'Ongoing',
        'new' => 'New',
        'continuing' => 'Continuing',
        'awaiting' => 'Awaiting',
        'suspended' => 'Suspended',
        'terminated' => 'Terminated',
        'not_recorded' => 'Not recorded',
    ];

    public function props(User $user): array
    {
        return [
            'platform' => app(ReadCache::class)->remember('dashboard.platform', [], fn () => $this->platform()),
            'planning' => $this->planning($user),
            'dimensions' => array_values(array_filter(IndicatorCatalog::dimensions(), fn (array $d) => $d['supported'])),
        ];
    }

    private function platform(): array
    {
        return [
            'coverage' => [
                'provinces' => $this->count('provinces'),
                'municipalities' => $this->count('municipalities'),
                'barangays' => $this->count('barangays'),
                'facilities' => $this->count('facilities'),
                'laboratories' => $this->count('laboratories'),
                'lab_services' => $this->count('lab_services'),
                'telecom_sites' => $this->count('telecom_sites'),
                'roads' => $this->count('roads'),
            ],
            'facilitiesBySector' => $this->groupCounts('facilities', 'sector'),
            'catalog' => [
                'techs' => $this->rows('dost_techs', ['id', 'code', 'name', 'agency', 'sector', 'status', 'outcome'], 'name'),
                'programs' => $this->rows('technologies', ['id', 'code', 'name', 'agency', 'category', 'active_status', 'target_audience'], 'name'),
            ],
            'projects' => $this->projects(),
        ];
    }

    private function projects(): array
    {
        if (! Schema::hasTable('dost_projects')) {
            return ['total' => null, 'byAgency' => [], 'byStatus' => []];
        }

        $byStatus = array_fill_keys(array_keys(self::PROJECT_STATUSES), 0);
        foreach ($this->groupCounts('dost_projects', 'project_status') as $row) {
            $byStatus[$this->normalizeStatus($row['label'])] += $row['count'];
        }

        return [
            'total' => $this->count('dost_projects'),
            'byAgency' => array_slice($this->groupCounts('dost_projects', 'agency'), 0, 10),
            'byStatus' => array_values(array_filter(array_map(
                fn (string $key) => ['key' => $key, 'label' => self::PROJECT_STATUSES[$key], 'count' => $byStatus[$key]],
                array_keys(self::PROJECT_STATUSES),
            ), fn (array $row) => $row['count'] > 0)),
        ];
    }

    /** The live data spells 1,322 completed rows as "comleted". */
    private function normalizeStatus(?string $status): string
    {
        $lower = strtolower(trim((string) $status));

        return match (true) {
            $lower === '' => 'not_recorded',
            $lower === 'comleted' || str_contains($lower, 'complet') => 'completed',
            array_key_exists($lower, self::PROJECT_STATUSES) => $lower,
            default => 'not_recorded',
        };
    }

    private function planning(User $user): array
    {
        $empty = ['totals' => ['plans' => 0, 'conversations' => 0, 'municipalities' => 0], 'recentPlans' => [], 'recentConversations' => []];
        if (! Schema::hasTable('planning_conversations') || ! Schema::hasTable('planning_action_plans') || ! Schema::hasTable('municipalities')) {
            return $empty;
        }

        $chats = DB::table('planning_conversations')->where('user_id', $user->id);
        $assessmentPlans = app(AssessmentActionPlans::class);
        $chatPlans = DB::table('planning_action_plans as plans')
            ->join('planning_conversations as chats', 'chats.id', '=', 'plans.conversation_id')
            ->join('municipalities as places', 'places.id', '=', 'chats.municipality_id')
            ->where('chats.user_id', $user->id)
            ->orderByDesc('plans.id')->limit(50)
            ->get(['plans.id', 'plans.title', 'plans.version', 'plans.status', 'plans.created_at', 'places.psg_code as city', 'places.name as municipality', 'places.province_name as province'])
            ->map(fn ($row) => ['kind' => 'chat', ...(array) $row])->all();

        return [
            'totals' => [
                'plans' => DB::table('planning_action_plans as plans')
                    ->join('planning_conversations as chats', 'chats.id', '=', 'plans.conversation_id')
                    ->where('chats.user_id', $user->id)->count() + $assessmentPlans->count($user->id),
                'conversations' => (clone $chats)->count(),
                'municipalities' => (clone $chats)->distinct()->count('municipality_id'),
            ],
            // Chat plans and assessment-page plans together, newest first.
            'recentPlans' => collect([...$chatPlans, ...$assessmentPlans->recent($user->id, 50)])
                ->sortByDesc(fn (array $row) => [(string) $row['created_at'], $row['id']])->take(50)->values()->all(),
            'recentConversations' => DB::table('planning_conversations as chats')
                ->join('municipalities as places', 'places.id', '=', 'chats.municipality_id')
                ->where('chats.user_id', $user->id)
                ->orderByDesc('chats.updated_at')->orderByDesc('chats.id')->limit(50)
                ->get(['chats.id', 'chats.title', 'chats.updated_at', 'chats.archived_at', 'places.psg_code as city', 'places.name as municipality', 'places.province_name as province'])
                ->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function count(string $table): ?int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : null;
    }

    /** @return list<array{label: ?string, count: int}> */
    private function groupCounts(string $table, string $column): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)->selectRaw("{$column} as label, count(*) as total")
            ->groupBy($column)->orderByDesc('total')->get()
            ->map(fn ($row) => ['label' => $row->label, 'count' => (int) $row->total])->all();
    }

    private function rows(string $table, array $fields, string $orderBy): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        $columns = array_values(array_intersect($fields, Schema::getColumnListing($table)));

        return DB::table($table)->orderBy($orderBy)->get($columns)
            ->map(fn ($row) => array_replace(array_fill_keys($fields, null), (array) $row))->all();
    }
}
