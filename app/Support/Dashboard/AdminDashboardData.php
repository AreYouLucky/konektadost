<?php

namespace App\Support\Dashboard;

use App\Models\CollectionYear;
use App\Models\User;
use App\Support\Caching\ReadCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin overview: how the platform is used (accounts, GeoAgent conversations, action plans)
 * and the state of the reference data behind every assessment. Missing tables report null.
 */
class AdminDashboardData
{
    private const DAYS = 30;

    public function props(): array
    {
        $since = now()->subDays(self::DAYS);

        return [
            'generatedAt' => now()->toIso8601String(),
            'days' => self::DAYS,
            'users' => $this->users($since),
            'planning' => app(ReadCache::class)->remember('admin.dashboard.planning', ['day' => now()->toDateString()],
                fn () => $this->planning($since), (int) config('read-cache.planning_ttl', 30), 'planning'),
            'data' => app(ReadCache::class)->remember('admin.dashboard.data', [], fn () => $this->data()),
        ];
    }

    private function users(Carbon $since): array
    {
        $roles = DB::table('users')->select('role', DB::raw('count(*) as total'))->groupBy('role')->pluck('total', 'role');

        return [
            'total' => (int) $roles->sum(),
            'byRole' => [
                ['role' => User::ROLE_ADMIN, 'label' => 'Administrators', 'count' => (int) ($roles[User::ROLE_ADMIN] ?? 0)],
                ['role' => User::ROLE_TECHNICIAN, 'label' => 'Technicians', 'count' => (int) ($roles[User::ROLE_TECHNICIAN] ?? 0)],
                ['role' => User::ROLE_USER, 'label' => 'Users', 'count' => (int) ($roles[User::ROLE_USER] ?? 0)],
            ],
            'new' => DB::table('users')->where('created_at', '>=', $since)->count(),
            'active' => Schema::hasColumn('users', 'last_login') ? DB::table('users')->where('last_login', '>=', $since->toDateString())->count() : null,
        ];
    }

    private function planning(Carbon $since): ?array
    {
        if (! Schema::hasTable('planning_conversations') || ! Schema::hasTable('planning_messages') || ! Schema::hasTable('planning_action_plans')) {
            return null;
        }

        $questions = DB::table('planning_messages')->where('role', 'user');
        $start = now()->subDays(self::DAYS - 1)->startOfDay();
        $perDay = fn ($query) => $query->where('created_at', '>=', $start)
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('count(*) as total'))->groupBy('day')->pluck('total', 'day');
        $dailyQuestions = $perDay(DB::table('planning_messages')->where('role', 'user'));
        $dailyPlans = $perDay(DB::table('planning_action_plans'));
        $daily = [];
        for ($day = $start->copy(); $day->lte(now()); $day->addDay()) {
            $key = $day->toDateString();
            $daily[] = ['date' => $key, 'questions' => (int) ($dailyQuestions[$key] ?? 0), 'plans' => (int) ($dailyPlans[$key] ?? 0)];
        }

        $locations = DB::table('planning_conversations as c')
            ->join('municipalities as m', 'm.id', '=', 'c.municipality_id')
            ->leftJoin('planning_action_plans as p', 'p.conversation_id', '=', 'c.id')
            ->select('m.name', 'm.province_name', DB::raw('count(distinct c.id) as conversations'), DB::raw('count(p.id) as plans'))
            ->groupBy('m.id', 'm.name', 'm.province_name')
            ->orderByDesc('conversations')->orderByDesc('plans')->limit(5)->get()
            ->map(fn ($row) => ['name' => $row->name, 'province' => $row->province_name, 'conversations' => (int) $row->conversations, 'plans' => (int) $row->plans]);

        $recent = DB::table('planning_action_plans as p')
            ->join('planning_conversations as c', 'c.id', '=', 'p.conversation_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('municipalities as m', 'm.id', '=', 'c.municipality_id')
            ->orderByDesc('p.created_at')->limit(6)
            ->get(['p.id', 'p.title', 'p.version', 'p.created_at', 'u.name as user', 'm.name as location', 'm.province_name as province'])
            ->map(fn ($row) => [
                'id' => (int) $row->id, 'title' => $row->title, 'version' => (int) $row->version, 'createdAt' => $row->created_at,
                'user' => $row->user, 'location' => $row->location, 'province' => $row->province,
            ]);

        return [
            'conversations' => DB::table('planning_conversations')->count(),
            'questions' => (clone $questions)->count(),
            'questionsRecent' => (clone $questions)->where('created_at', '>=', $since)->count(),
            'plans' => DB::table('planning_action_plans')->count(),
            'plansRecent' => DB::table('planning_action_plans')->where('created_at', '>=', $since)->count(),
            'activeUsers' => DB::table('planning_conversations')->where('updated_at', '>=', $since)->distinct()->count('user_id'),
            'daily' => $daily,
            'topLocations' => $locations->all(),
            'recentPlans' => $recent->all(),
        ];
    }

    private function data(): array
    {
        $count = fn (string $table, ?callable $scope = null) => Schema::hasTable($table)
            ? ($scope ? $scope(DB::table($table))->count() : DB::table($table)->count()) : null;
        $sector = fn (string $name) => $count('facilities', fn ($q) => $q->where('sector', $name));

        $datasets = [
            ['key' => 'health-facilities', 'label' => 'Health facilities', 'group' => 'Services', 'count' => $sector('health')],
            ['key' => 'education-facilities', 'label' => 'Schools', 'group' => 'Services', 'count' => $sector('education')],
            ['key' => 'safety-facilities', 'label' => 'Safety & DRRM facilities', 'group' => 'Services', 'count' => $sector('safety')],
            ['key' => 'laboratories', 'label' => 'Laboratories', 'group' => 'STI resources', 'count' => $count('laboratories')],
            ['key' => 'lab-services', 'label' => 'Laboratory services', 'group' => 'STI resources', 'count' => $count('lab_services')],
            ['key' => 'cell-sites', 'label' => 'Cell sites', 'group' => 'STI resources', 'count' => $count('telecom_sites')],
            ['key' => 'dost-technologies', 'label' => 'DOST technologies', 'group' => 'Catalog', 'count' => $count('dost_techs')],
            ['key' => 'sti-programs', 'label' => 'STI programs', 'group' => 'Catalog', 'count' => $count('technologies')],
            ['key' => 'dost-projects', 'label' => 'DOST projects', 'group' => 'Catalog', 'count' => $count('dost_projects')],
            ['key' => 'agriculture', 'label' => 'Agriculture estimates', 'group' => 'Livelihood', 'count' => $count('agriculture_count_estimates')],
            ['key' => null, 'label' => 'Road segments', 'group' => 'Infrastructure', 'count' => $count('roads')],
        ];

        $geography = [
            'provinces' => $count('provinces'),
            'municipalities' => $count('municipalities'),
            'barangays' => $count('barangays'),
        ];

        $year = CollectionYear::activeYear();
        $quality = array_values(array_filter([
            $this->share('Facilities linked to a city / municipality', 'facilities', fn ($q) => $q->whereNotNull('municipality_id'),
                'Counted by assessments only when linked.'),
            $this->share('Facilities linked to a barangay', 'facilities', fn ($q) => $q->whereNotNull('barangay_id'),
                'Needed for barangay priority ranking.'),
            $this->share('Cities / municipalities with a boundary', 'municipalities', fn ($q) => $q->whereNotNull('geometry'),
                'Used for maps, land area and placing records by coordinates.'),
            $this->share('Barangays with a boundary', 'barangays', fn ($q) => $q->whereNotNull('geometry')),
            $year !== null && Schema::hasTable('population_figures')
                ? $this->share("Barangays with {$year} population", 'barangays',
                    fn ($q) => $q->whereIn('psg_code', DB::table('population_figures')->where('year', $year)->whereNotNull('population')->select('psg_code')),
                    'Missing figures are shown as unavailable, never as zero.')
                : null,
            $this->share('Laboratories with coordinates', 'laboratories', fn ($q) => $q->whereNotNull('latitude')->whereNotNull('longitude')),
        ]));

        $manual = array_sum(array_filter([
            $count('facilities', fn ($q) => $q->where('source', 'Admin data entry')),
            $count('laboratories', fn ($q) => $q->where('source', 'Admin data entry')),
            $count('lab_services', fn ($q) => $q->where('source', 'Admin data entry')),
            Schema::hasColumn('agriculture_count_estimates', 'data_source_type')
                ? $count('agriculture_count_estimates', fn ($q) => $q->where('data_source_type', 'MANUAL_ENTRY')) : null,
        ]));

        return ['datasets' => $datasets, 'geography' => $geography, 'quality' => $quality, 'manualEntries' => $manual, 'populationYear' => $year];
    }

    /** @return array{label: string, value: int, total: int, note: string|null}|null */
    private function share(string $label, string $table, callable $scope, ?string $note = null): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $total = DB::table($table)->count();

        return ['label' => $label, 'value' => $total ? $scope(DB::table($table))->count() : 0, 'total' => $total, 'note' => $note];
    }
}
