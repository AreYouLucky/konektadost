<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Support\LocationNameLookup;

/** Layer A (A9) Function Tool: get_barangay_by_name */
class GetBarangayByNameTool extends LocationLookupTool
{
    public function name(): string
    {
        return 'get_barangay_by_name';
    }

    public function description(): string
    {
        return 'Find a Philippine barangay by name and return its name, city or municipality, province, PSGC code, population and area. Use its psg_code with compare_locations. Many barangays share a name, so pass the city or province whenever the user gives one.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Barangay name, e.g. "Poblacion" or "Sta. Teresita".'],
                'city' => ['type' => 'string', 'description' => 'Optional city or municipality the barangay is in.'],
                'province' => ['type' => 'string', 'description' => 'Optional province the barangay is in.'],
            ],
            'required' => ['name'],
        ];
    }

    protected function find(string $name, string $city, string $province): array
    {
        return app(LocationNameLookup::class)->barangay($name, $city, $province);
    }
}
