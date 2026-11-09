<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Contracts\FunctionToolInterface;

/** Shared shape of the Layer A tools that find a location by name. Deterministic; no AI. */
abstract class LocationLookupTool implements FunctionToolInterface
{
    public function layer(): string
    {
        return 'A';
    }

    public function definition(): array
    {
        return ['name' => $this->name(), 'layer' => $this->layer(), 'description' => $this->description(), 'parameters' => $this->parameters()];
    }

    public function execute(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'Ask the user for the name of the location.'];
        }

        return $this->find($name, trim((string) ($args['city'] ?? '')), trim((string) ($args['province'] ?? '')));
    }

    /** @return array<string, mixed> */
    abstract protected function find(string $name, string $city, string $province): array;
}
