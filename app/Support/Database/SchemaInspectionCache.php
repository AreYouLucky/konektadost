<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;

/** Request-scoped memo of schema lookups, isolated per connection and cleared by any DDL. */
class SchemaInspectionCache
{
    private array $values = [];

    public function remember(Connection $connection, string $key, callable $compute): mixed
    {
        $key = $connection->getName().':'.spl_object_id($connection->getPdo()).':'.$key;
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $compute();
        }

        return $this->values[$key];
    }

    public function flushOnSchemaChange(QueryExecuted $event): void
    {
        if ($this->values !== [] && preg_match('/^\s*(create|alter|drop|rename|truncate)\b/i', $event->sql)) {
            $this->values = [];
        }
    }
}
