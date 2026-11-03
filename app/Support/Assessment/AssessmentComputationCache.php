<?php

namespace App\Support\Assessment;

use Illuminate\Database\Eloquent\Model;

/** Reuse deterministic tool results only while one report is being computed. */
class AssessmentComputationCache
{
    private bool $active = false;

    private array $values = [];

    public function within(callable $compute): array
    {
        if ($this->active) {
            return $compute();
        }

        $this->active = true;
        $this->values = [];

        try {
            return $compute();
        } finally {
            $this->active = false;
            $this->values = [];
        }
    }

    public function remember(string $tool, Model $location, array $options, callable $compute): array
    {
        if (! $this->active || $location->getKey() === null) {
            return $compute();
        }

        $key = serialize([$tool, $location::class, $location->getConnectionName(), $location->getKey(), $options]);
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $compute();
        }

        return $this->values[$key];
    }
}
