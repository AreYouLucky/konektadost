<?php

namespace App\Support\Assessment;

use App\Support\Caching\ReadCache;
use Illuminate\Database\Eloquent\Model;

/** Memoize within a report and reuse deterministic results across requests when enabled. */
class AssessmentComputationCache
{
    private bool $active = false;

    private array $values = [];

    public function __construct(private readonly ?ReadCache $persistentCache = null) {}

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
        $persistent = fn () => $this->persistentCache === null ? $compute() : $this->persistentCache->remember('assessment.tool.'.$tool,
            ['location' => $location, 'options' => $options], $compute);
        if (! $this->active || $location->getKey() === null) {
            return $location->getKey() === null ? $compute() : $persistent();
        }

        $key = serialize([$tool, $location::class, $location->getConnectionName(), $location->getKey(), $options]);
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $persistent();
        }

        return $this->values[$key];
    }
}
