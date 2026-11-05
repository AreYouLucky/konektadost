<?php

namespace App\Support\Map;

use App\Models\Province;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** A verified selection within the province named in a protected endpoint. */
final readonly class GeographicScope
{
    public function __construct(
        public int $provinceId,
        public string $level,
        public int $id,
        public string $name,
        public ?int $municipalityId,
        public ?int $barangayId,
        public array $path,
    ) {}

    public static function fromRequest(Request $request, Province $province, GeographyExplorer $geography): self
    {
        $data = $request->validate([
            'level' => ['sometimes', Rule::in(['province', 'municipality', 'barangay'])],
            'location' => [Rule::requiredIf(fn () => $request->input('level', 'province') !== 'province'), 'nullable', 'integer', 'min:1'],
        ]);
        $level = $data['level'] ?? 'province';
        $id = (int) ($data['location'] ?? $province->id);
        $path = $geography->selection($level, $id, false)['path'];
        abort_unless((int) $path[0]['id'] === (int) $province->id, 404, 'The selected location does not belong to this province.');
        $municipality = collect($path)->firstWhere('level', 'municipality');

        return new self((int) $province->id, $level, $id, $path[array_key_last($path)]['name'],
            $municipality === null ? null : (int) $municipality['id'], $level === 'barangay' ? $id : null,
            array_map(fn ($location) => array_intersect_key($location, array_flip(['id', 'level', 'name'])), $path));
    }

    public static function province(Province $province): self
    {
        $name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', trim($province->name));

        return new self((int) $province->id, 'province', (int) $province->id, $name, null, null,
            [['id' => (int) $province->id, 'level' => 'province', 'name' => $name]]);
    }

    public function toArray(): array
    {
        return ['key' => $this->level.':'.$this->id, 'level' => $this->level, 'id' => $this->id, 'name' => $this->name, 'path' => $this->path];
    }

    public function barangayIds(): Builder
    {
        $query = DB::table('barangays')->select('id');
        if ($this->barangayId !== null) {
            return $query->where('id', $this->barangayId);
        }
        if ($this->municipalityId !== null && Schema::hasColumn('barangays', 'municipality_id')) {
            return $query->where('municipality_id', $this->municipalityId);
        }
        if ($this->municipalityId !== null && Schema::hasColumn('barangays', 'municipality_gadm_id') && Schema::hasColumn('municipalities', 'gadm_id')) {
            return $query->whereIn('municipality_gadm_id', DB::table('municipalities')->select('gadm_id')->where('id', $this->municipalityId));
        }

        return $query->whereRaw('1 = 0');
    }
}
