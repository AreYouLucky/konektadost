<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Support\LocationNameLookup;

/** Layer A (A7) Function Tool: get_city_by_name */
class GetCityByNameTool extends LocationLookupTool
{
    public function name(): string
    {
        return 'get_city_by_name';
    }

    public function description(): string
    {
        return 'Find a Philippine city or municipality by name and return its name, province, PSGC code, population and area. Use its psg_code with compare_locations. Returns a list to choose from when several places share the name.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'City or municipality name, e.g. "Iloilo City" or "Tigbauan".'],
                'province' => ['type' => 'string', 'description' => 'Optional province name to narrow a shared name.'],
            ],
            'required' => ['name'],
        ];
    }

    protected function find(string $name, string $city, string $province): array
    {
        return app(LocationNameLookup::class)->city($name, $province);
    }
}
