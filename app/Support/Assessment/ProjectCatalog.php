<?php

namespace App\Support\Assessment;

use App\Models\DostTech;
use App\Support\Caching\ReadCache;
use Illuminate\Support\Facades\Schema;

/** Reference options for transparent screening, not verified local projects. */
class ProjectCatalog
{
    public function summary(): array
    {
        return app(ReadCache::class)->remember('catalog.summary', [], fn () => $this->buildSummary());
    }

    private function buildSummary(): array
    {
        $result = [
            'status' => 'unavailable',
            'source' => 'DOST technology reference catalog',
            'coverage' => 'NCR reference catalog. Applicability, eligibility, availability and local deployment must be confirmed with DOST.',
            'records' => [],
        ];
        if (! Schema::hasColumns('dost_techs', ['id', 'code', 'name'])) {
            return $result;
        }
        $fields = ['id', 'code', 'name', 'agency', 'sector', 'mechanism', 'status', 'outcome', 'deployment_clusters'];
        $columns = array_values(array_intersect($fields, Schema::getColumnListing('dost_techs')));
        $result['records'] = DostTech::query()->select($columns)->orderBy('name')->get()
            ->map(fn ($row) => array_replace(array_fill_keys($fields, null), $row->only($columns)))->all();
        $result['status'] = 'available';

        return $result;
    }
}
