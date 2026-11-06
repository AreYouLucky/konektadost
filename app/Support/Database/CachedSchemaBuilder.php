<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Builder;

/**
 * Wraps the schema builder behind the `Schema` facade and memoizes table/column lookups.
 *
 * On MySQL/MariaDB every Schema::hasColumn() is an information_schema query (~25 ms). The
 * assessment guards domain-table reads with ~130 of them per report, which cost ~3 s on a
 * cold Quezon City assessment. Everything except the four lookups below is forwarded
 * unchanged; SchemaInspectionCache is cleared on any DDL statement.
 *
 * @mixin Builder
 */
class CachedSchemaBuilder
{
    public function __construct(
        private readonly Builder $builder,
        private readonly SchemaInspectionCache $cache,
    ) {}

    public function hasTable($table): bool
    {
        return $this->cache->remember($this->builder->getConnection(), 'table:'.$table, fn () => $this->builder->hasTable($table));
    }

    /** @return list<string> */
    public function getColumnListing($table): array
    {
        return $this->cache->remember($this->builder->getConnection(), 'columns:'.$table, fn () => $this->builder->getColumnListing($table));
    }

    public function hasColumn($table, $column): bool
    {
        return in_array(strtolower($column), array_map(strtolower(...), $this->getColumnListing($table)), true);
    }

    public function hasColumns($table, array $columns): bool
    {
        $available = array_map(strtolower(...), $this->getColumnListing($table));

        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $available, true)) {
                return false;
            }
        }

        return true;
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->builder->{$method}(...$arguments);
    }
}
