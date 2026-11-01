<?php

namespace App\Support\Map;

use App\Models\AgricultureCategory;
use App\Models\AgricultureCountEstimate;
use App\Models\AgricultureType;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/** Read-only summaries of modeled records; never interpreted as measured production. */
class ProvinceAgriculture
{
    public function summary(int $provinceId, ?GeographicScope $scope = null): array
    {
        $scope ??= GeographicScope::province(Province::query()->findOrFail($provinceId));
        $result = [
            'province_id' => $provinceId,
            'scope' => $scope->toArray(),
            'status' => 'unavailable',
            'model_year' => null,
            'record_count' => 0,
            'municipality_count' => 0,
            'data_source_types' => [],
            'source_tables' => [],
            'categories' => [],
            'commodity_count' => 0,
            'commodities' => [],
            'municipalities' => [],
            'financials' => null,
            'areas' => [],
        ];

        foreach (['agriculture_count_estimates', 'agriculture_categories', 'agriculture_types'] as $table) {
            if (! Schema::hasTable($table)) {
                return $result;
            }
        }

        $query = $this->query($provinceId, $scope);
        $year = (clone $query)->max('model_year');
        $query->where('model_year', $year);
        $count = (clone $query)->count();
        $result['status'] = $count > 0 ? 'available' : 'no_data';
        $result['model_year'] = $year === null ? null : (int) $year;
        $result['record_count'] = $count;
        if ($count === 0) {
            return $result;
        }

        $financialSql = $this->financialSql();
        $result['financials'] = $this->financials((clone $query)->selectRaw($financialSql)->first());
        $result['areas'] = (clone $query)->select('area_kind')->selectRaw('SUM(area_sqm) AS area, COUNT(area_sqm) AS reported_count')
            ->groupBy('area_kind')->orderBy('area_kind')->get()->map(fn ($row) => [
                'kind' => $row->area_kind,
                'area_sqm' => $row->area === null ? null : (string) $row->area,
                'reported_count' => (int) $row->reported_count,
            ])->all();

        $result['municipality_count'] = (clone $query)->distinct()->count('municipality_id');
        $result['data_source_types'] = (clone $query)->whereNotNull('data_source_type')->distinct()->orderBy('data_source_type')->pluck('data_source_type')->all();
        $result['source_tables'] = (clone $query)->whereNotNull('source_table')->distinct()->orderBy('source_table')->pluck('source_table')->all();
        $result['commodity_count'] = (clone $query)->whereNotNull('commodity_name')->where('commodity_name', '<>', '')->distinct()->count('commodity_name');
        $result['municipalities'] = Municipality::query()->whereIn('id', (clone $query)->select('municipality_id')->distinct())
            ->orderBy('name')->get(['id', 'name'])->map(fn ($row) => ['id' => (int) $row->id, 'name' => $this->displayName($row->name)])->all();

        // Keep annual quantities separate by type, unit, and basis; SQL preserves decimal precision.
        $groups = (clone $query)
            ->select(['category_id', 'agriculture_type_id', 'quantity_unit', 'quantity_basis'])
            ->selectRaw('COUNT(*) AS record_count, SUM(estimated_quantity_year) AS quantity')
            ->groupBy('category_id', 'agriculture_type_id', 'quantity_unit', 'quantity_basis')
            ->orderBy('category_id')->orderBy('agriculture_type_id')->orderBy('quantity_unit')->orderBy('quantity_basis')
            ->get();
        $categories = AgricultureCategory::query()->whereIn('id', $groups->pluck('category_id'))->get()->keyBy('id');
        $types = AgricultureType::query()->whereIn('id', $groups->pluck('agriculture_type_id'))->get()->keyBy('id');
        $summaries = [];

        foreach ($groups as $group) {
            $categoryId = $group->category_id;
            $typeId = $group->agriculture_type_id;
            $category = $categories->get($categoryId);
            $type = $types->get($typeId);
            $summaries[$categoryId] ??= [
                'id' => $categoryId,
                'code' => $category?->category_code,
                'name' => $category?->category_name ?? 'Unclassified agriculture',
                'description' => $category?->description,
                'record_count' => 0,
                'types' => [],
            ];
            $summaries[$categoryId]['record_count'] += (int) $group->record_count;
            $summaries[$categoryId]['types'][$typeId] ??= [
                'id' => $typeId,
                'name' => $type?->type_name ?? 'Unclassified type',
                'record_count' => 0,
                'quantities' => [],
            ];
            $summary = &$summaries[$categoryId]['types'][$typeId];
            $summary['record_count'] += (int) $group->record_count;
            $summary['quantities'][] = [
                'unit' => $group->quantity_unit,
                'basis' => $group->quantity_basis,
                'annual_quantity' => $group->quantity === null ? null : (string) $group->quantity,
            ];
            unset($summary);
        }

        foreach ($summaries as &$category) {
            $category['types'] = array_values($category['types']);
        }
        unset($category);
        $result['categories'] = collect($summaries)->sortBy('name')->values()->all();

        $result['commodities'] = (clone $query)->select(['category_id', 'agriculture_type_id', 'commodity_name', 'commodity_variant', 'quantity_unit', 'quantity_basis'])
            ->selectRaw('COUNT(*) AS record_count, SUM(estimated_quantity_year) AS annual_quantity, SUM(estimated_marketable_quantity_year) AS marketable_quantity, SUM(estimated_sold_quantity_year) AS sold_quantity')
            ->selectRaw($financialSql)
            ->selectRaw('MIN(estimated_unit_price_php) AS min_unit_price, MAX(estimated_unit_price_php) AS max_unit_price, COUNT(estimated_unit_price_php) AS unit_price_count')
            ->groupBy('category_id', 'agriculture_type_id', 'commodity_name', 'commodity_variant', 'quantity_unit', 'quantity_basis')
            ->orderBy('commodity_name')->orderBy('commodity_variant')->orderBy('category_id')->orderBy('agriculture_type_id')->orderBy('quantity_unit')->orderBy('quantity_basis')
            ->get()->map(fn ($row) => [
                'name' => $row->commodity_name,
                'variant' => $row->commodity_variant,
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category_name' => $categories->get($row->category_id)?->category_name ?? 'Unclassified agriculture',
                'category_code' => $categories->get($row->category_id)?->category_code,
                'type_id' => $row->agriculture_type_id === null ? null : (int) $row->agriculture_type_id,
                'type_name' => $types->get($row->agriculture_type_id)?->type_name ?? 'Unclassified type',
                'unit' => $row->quantity_unit,
                'basis' => $row->quantity_basis,
                'record_count' => (int) $row->record_count,
                'annual_quantity' => $row->annual_quantity === null ? null : (string) $row->annual_quantity,
                'marketable_quantity' => $row->marketable_quantity === null ? null : (string) $row->marketable_quantity,
                'sold_quantity' => $row->sold_quantity === null ? null : (string) $row->sold_quantity,
                'financials' => $this->financials($row),
                'unit_price' => [
                    'min' => $row->min_unit_price === null ? null : (string) $row->min_unit_price,
                    'max' => $row->max_unit_price === null ? null : (string) $row->max_unit_price,
                    'reported_count' => (int) $row->unit_price_count,
                ],
            ])->all();

        return $result;
    }

    private function financialSql(): string
    {
        return collect(['revenue', 'operating_cost', 'net'])->map(fn ($key) => "SUM(estimated_{$key}_php_year) AS {$key}, COUNT(estimated_{$key}_php_year) AS {$key}_count"
        )->implode(', ');
    }

    private function financials(object $row): array
    {
        $result = [];
        foreach (['revenue', 'operating_cost', 'net'] as $key) {
            $result[$key] = ['total' => $row->{$key} === null ? null : (string) $row->{$key}, 'reported_count' => (int) $row->{$key.'_count'}];
        }

        return $result;
    }

    public function records(int $provinceId, array $filters, ?GeographicScope $scope = null): array
    {
        $scope ??= GeographicScope::province(Province::query()->findOrFail($provinceId));
        $empty = ['province_id' => $provinceId, 'scope' => $scope->toArray(), 'status' => 'unavailable', 'model_year' => null, 'data' => [],
            'pagination' => ['page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]];
        foreach (['agriculture_count_estimates', 'agriculture_categories', 'agriculture_types'] as $table) {
            if (! Schema::hasTable($table)) {
                return $empty;
            }
        }
        $query = $this->query($provinceId, $scope);
        $year = (clone $query)->max('model_year');
        $query->where('model_year', $year);
        foreach (['category' => 'category_id', 'type' => 'agriculture_type_id', 'municipality' => 'municipality_id', 'commodity' => 'commodity_name'] as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        if (! empty($filters['q'])) {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['q']))).'%';
            $query->where(function (Builder $query) use ($needle) {
                foreach (['commodity_name', 'commodity_variant', 'record_code', 'production_method', 'activity_type'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$needle]);
                }
            });
        }
        $page = $query->with(['category:id,category_code,category_name', 'agricultureType:id,type_name', 'municipality:id,name'])
            ->orderBy('commodity_name')->orderBy('id')->paginate($filters['per_page'] ?? 15, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'province_id' => $provinceId,
            'scope' => $scope->toArray(),
            'status' => 'available',
            'model_year' => $year === null ? null : (int) $year,
            'data' => $page->getCollection()->map(fn ($record) => [
                ...$record->attributesToArray(),
                'category_name' => $record->category?->category_name ?? 'Unclassified agriculture',
                'category_code' => $record->category?->category_code,
                'type_name' => $record->agricultureType?->type_name ?? 'Unclassified type',
                'municipality_name' => $record->municipality === null ? null : $this->displayName($record->municipality->name),
            ])->all(),
            'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    private function query(int $provinceId, GeographicScope $scope): Builder
    {
        $query = AgricultureCountEstimate::query()->where('province_id', $provinceId);
        if ($scope->municipalityId !== null) {
            $query->where(fn (Builder $query) => $query->where('municipality_id', $scope->municipalityId)
                ->orWhere(fn (Builder $query) => $query->whereNull('municipality_id')->whereIn('barangay_id', $scope->barangayIds())));
        }
        if ($scope->barangayId !== null) {
            $query->where('barangay_id', $scope->barangayId);
        }

        return $query;
    }

    private function displayName(string $name): string
    {
        return preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', trim($name));
    }
}
