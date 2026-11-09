<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Support\LocationNameLookup;

/** Layer A (A8) Function Tool: get_province_by_name */
class GetProvinceByNameTool extends LocationLookupTool
{
    public function name(): string
    {
        return 'get_province_by_name';
    }

    public function description(): string
    {
        return 'Find a Philippine province by name, including Metro Manila/NCR, and return its name, PSGC code, population and area. Use its psg_code with compare_locations.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Province name, e.g. "Ilocos Norte" or "Metro Manila".'],
            ],
            'required' => ['name'],
        ];
    }

    protected function find(string $name, string $city, string $province): array
    {
        return app(LocationNameLookup::class)->province($name);
    }
}
