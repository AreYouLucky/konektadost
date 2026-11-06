<?php

namespace App\Support\Caching;

use App\Support\Assessment\AssessmentReferenceData;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cache computed data, leaving authentication, validation and response/session headers live. */
class ReadCache
{
    private const DOMAIN_TABLES = [
        'provinces', 'municipalities', 'barangays', 'place_descriptions', 'population_figures',
        'collection_years', 'facilities', 'laboratories', 'lab_services', 'telecom_sites',
        'agriculture_categories', 'agriculture_types', 'agriculture_count_estimates',
        'dost_projects', 'dost_techs', 'technologies', 'roads', 'road_barangay',
    ];

    private const PLANNING_TABLES = ['planning_conversations', 'planning_messages', 'planning_action_plans', 'assessment_action_plans'];

    public function remember(string $name, array $parameters, callable $compute, ?int $ttl = null, string $group = 'domain'): mixed
    {
        $ttl ??= (int) config('read-cache.ttl', 300);
        $connection = DB::connection();
        // Uncommitted reads must never escape into a shared cache.
        if (! config('read-cache.enabled') || $ttl <= 0 || $connection->transactionLevel() > 0) {
            return $compute();
        }

        $store = Cache::store(config('read-cache.store', 'file'));
        $namespace = $this->namespace($connection, $group);
        $revisionKey = $namespace.':revision';
        $store->add($revisionKey, (string) Str::uuid(), now()->addYears(10));
        $revision = $store->get($revisionKey);
        $key = $namespace.':'.$revision.':'.hash('sha256', serialize([$name, $this->normalize($parameters)]));
        // An envelope also caches legitimate null/empty results; exceptions are never stored.
        $entry = $store->get($key);
        if (is_array($entry) && array_key_exists('value', $entry)) {
            return $entry['value'];
        }
        $value = $compute();
        if (! is_array($value) || ! isset($value['error'])) {
            $store->put($key, ['value' => $value], $ttl);
        }

        return $value;
    }

    public function invalidate(string $group = 'domain', ?Connection $connection = null): void
    {
        $connection ??= DB::connection();
        Cache::store(config('read-cache.store', 'file'))
            ->forever($this->namespace($connection, $group).':revision', (string) Str::uuid());
        if ($group === 'domain') {
            app(AssessmentReferenceData::class)->clear();
        }
    }

    /** Includes query-builder/bulk imports, which do not dispatch Eloquent model events. */
    public function invalidateForWrite(QueryExecuted $event): void
    {
        if (! config('read-cache.enabled') || ! preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop|create|rename|load)\b/i', $event->sql)) {
            return;
        }
        $sql = str_replace(['`', '"', '[', ']'], '', $event->sql);
        foreach (['domain' => self::DOMAIN_TABLES, 'planning' => self::PLANNING_TABLES] as $group => $tables) {
            $names = array_map(fn ($table) => preg_quote($event->connection->getTablePrefix().$table, '/'), $tables);
            if (! preg_match('/(?<![a-z0-9_])(?:'.implode('|', $names).')(?![a-z0-9_])/i', $sql)) {
                continue;
            }
            if ($event->connection->transactionLevel() > 0) {
                $event->connection->afterCommit(fn () => $this->invalidate($group, $event->connection));
            } else {
                $this->invalidate($group, $event->connection);
            }
        }
    }

    private function namespace(Connection $connection, string $group): string
    {
        $identity = [$connection->getName(), $connection->getDriverName(), $connection->getConfig('host'),
            $connection->getConfig('port'), $connection->getDatabaseName(), $connection->getTablePrefix()];
        if ($connection->getDatabaseName() === ':memory:') {
            $identity[] = spl_object_id($connection->getPdo());
        }

        return 'read-cache:'.config('read-cache.version', 'v1').':'.hash('sha256', serialize($identity)).':'.$group;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return ['model' => $value::class, 'connection' => $value->getConnectionName(), 'attributes' => $this->normalize($value->getAttributes())];
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => $this->normalize($item), $value);
        }

        return $value;
    }
}
