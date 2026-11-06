<?php

namespace App\Support\Assessment;

use App\Models\Municipality;
use App\Support\Map\GeographyExplorer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Saved, versioned snapshots of the action plans generated on the location assessment page.
 *
 * The plan itself is built in the browser from the deterministic assessment, so the stored
 * snapshot is exactly what the user saw and downloaded. Location fields are resolved here
 * from the geography tables, never taken from the snapshot. Every query is scoped by user.
 */
class AssessmentActionPlans
{
    public const TABLE = 'assessment_action_plans';

    /** Largest accepted snapshot, in bytes of JSON. A full 10-target plan is roughly 20–40 KB. */
    public const MAX_SNAPSHOT_BYTES = 400_000;

    public function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * @param  array{level?: ?string, location?: ?int, city?: ?string, focus?: ?string, map?: ?string}  $data
     * @return array{level: string, id: int, name: string, psg_code: ?string, province: ?string, focus: string, query: array<string, string|int>}
     */
    public function resolveLocation(array $data, bool $isAdmin): array
    {
        $focus = $data['focus'] ?? 'general';
        if (filled($data['city'] ?? null)) {
            $municipality = Municipality::query()->where('psg_code', $data['city'])->firstOrFail();

            return [
                'level' => 'municipality', 'id' => (int) $municipality->id, 'name' => (string) $municipality->name,
                'psg_code' => $municipality->psg_code, 'province' => $municipality->province_name, 'focus' => $focus,
                'query' => ['city' => $municipality->psg_code, 'focus' => $focus],
            ];
        }

        $path = app(GeographyExplorer::class)->selection($data['level'], (int) $data['location'], false)['path'];
        $place = $path[count($path) - 1];
        $map = ($data['map'] ?? 'user') === 'admin' && $isAdmin ? 'admin' : 'user';

        return [
            'level' => $data['level'], 'id' => (int) $place['id'], 'name' => (string) $place['name'],
            'psg_code' => filled($place['code'] ?? null) ? mb_substr((string) $place['code'], 0, 20) : null,
            'province' => $path[0]['name'] ?? null, 'focus' => $focus,
            'query' => ['level' => $data['level'], 'location' => (int) $place['id'], 'map' => $map, 'focus' => $focus],
        ];
    }

    /**
     * Saves the snapshot as the next version for this user and location. An identical plan
     * (ignoring its generation time) returns the version already saved instead of a duplicate.
     *
     * @return array{plan: array, created: bool}
     *
     * @throws LockTimeoutException
     */
    public function save(int $userId, array $location, array $snapshot): array
    {
        $hash = $this->hash($snapshot);
        $key = 'assessment-plan:'.hash('sha256', $userId.'|'.$location['level'].'|'.$location['id']);

        return Cache::store('file')->lock($key.':lock', 15)->block(10, function () use ($userId, $location, $snapshot, $hash) {
            $scope = fn () => DB::table(self::TABLE)->where('user_id', $userId)
                ->where('level', $location['level'])->where('location_id', $location['id']);
            $existing = $scope()->where('content_hash', $hash)->orderByDesc('version')->first();
            if ($existing) {
                return ['plan' => $this->summary($existing), 'created' => false];
            }
            $id = DB::table(self::TABLE)->insertGetId([
                'user_id' => $userId,
                'level' => $location['level'],
                'location_id' => $location['id'],
                'psg_code' => $location['psg_code'],
                'location_name' => mb_substr($location['name'], 0, 255),
                'province_name' => $location['province'] !== null ? mb_substr($location['province'], 0, 255) : null,
                'focus' => $location['focus'],
                'assessment_query' => $this->encode($location['query']),
                'version' => (int) $scope()->max('version') + 1,
                'title' => mb_substr('Assessment action plan — '.$location['name'], 0, 255),
                'status' => 'draft_pending_human_validation',
                'ai_assisted' => filled($snapshot['plan']['aiGeneratedAt'] ?? null),
                'content_hash' => $hash,
                'snapshot' => $this->encode($snapshot),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['plan' => $this->summary(DB::table(self::TABLE)->where('id', $id)->first()), 'created' => true];
        });
    }

    public function find(int $userId, int $id): array
    {
        $row = DB::table(self::TABLE)->where('user_id', $userId)->where('id', $id)->first();
        abort_unless($row, 404);

        return [...$this->summary($row), 'snapshot' => json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function count(int $userId): int
    {
        return $this->available() ? DB::table(self::TABLE)->where('user_id', $userId)->count() : 0;
    }

    /** Most recent plans in the same row shape the dashboard uses for chat plans. */
    public function recent(int $userId, int $limit): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table(self::TABLE)->where('user_id', $userId)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
            ->get()->map(fn (stdClass $row) => [
                'kind' => 'assessment', 'id' => $row->id, 'title' => $row->title, 'version' => (int) $row->version,
                'status' => $row->status, 'created_at' => $row->created_at, 'city' => $row->psg_code,
                'municipality' => $row->location_name, 'province' => $row->province_name,
            ])->all();
    }

    private function summary(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'kind' => 'assessment',
            'title' => $row->title,
            'version' => (int) $row->version,
            'status' => $row->status,
            'ai_assisted' => (bool) $row->ai_assisted,
            'level' => $row->level,
            'location_id' => (int) $row->location_id,
            'location_name' => $row->location_name,
            'province_name' => $row->province_name,
            'psg_code' => $row->psg_code,
            'created_at' => $row->created_at,
            'assessment_url' => route('location.assess', json_decode($row->assessment_query, true) ?: []),
        ];
    }

    /** Generation time changes on every render, so it is left out of the identity of a plan. */
    private function hash(array $snapshot): string
    {
        unset($snapshot['plan']['generatedAt']);

        return hash('sha256', $this->encode($snapshot));
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }
}
