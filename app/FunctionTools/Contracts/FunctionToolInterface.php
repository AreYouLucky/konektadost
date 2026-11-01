<?php

namespace App\FunctionTools\Contracts;

interface FunctionToolInterface
{
    /**
     * Unique snake_case tool identifier (e.g., "get_municipality_profile").
     */
    public function name(): string;

    /**
     * Layer identifier ("A", "B", "C", "D", "E", or "F").
     */
    public function layer(): string;

    /**
     * Human- and AI-readable description of what this deterministic tool computes or retrieves.
     */
    public function description(): string;

    /**
     * JSON Schema for the input parameters accepted by execute().
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Full tool definition formatted for function-calling registries.
     *
     * @return array{name: string, layer: string, description: string, parameters: array<string, mixed>}
     */
    public function definition(): array;

    /**
     * Execute the deterministic tool with the given arguments.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args): array;
}

