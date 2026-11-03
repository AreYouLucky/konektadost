<?php

namespace App\Support\Assessment;

use Illuminate\Support\Facades\DB;

/** Request-scoped reference lookups, isolated by database connection. */
class AssessmentReferenceData
{
    private array $values = [];

    private function key(string $name): string
    {
        $connection = DB::connection();

        return $connection->getName().':'.$connection->getDatabaseName().':'.spl_object_id($connection->getPdo()).':'.$name;
    }

    public function remember(string $name, callable $compute): mixed
    {
        $key = $this->key($name);
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $compute();
        }

        return $this->values[$key];
    }

    public function forget(string $name): void
    {
        unset($this->values[$this->key($name)]);
    }
}
