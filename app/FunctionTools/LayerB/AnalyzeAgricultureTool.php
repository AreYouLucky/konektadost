<?php

namespace App\FunctionTools\LayerB;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\Support\LocationResolver;
use App\Models\AgricultureCategory;
use App\Models\AgricultureType;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer B (B6) Function Tool: analyze_agriculture
 *
 * Analyzes agricultural categories, types, commodity distribution, planted area
 * (sqm and hectares), estimated annual production, estimated revenue, operating cost,
 * net return, and barangay agricultural participation.
 */
class AnalyzeAgricultureTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'analyze_agriculture';
    }

    public function layer(): string
    {
        return 'B';
    }

    public function description(): string
    {
        return 'Analyze agricultural categories (Crops, Livestock & Poultry, Fisheries), commodity distribution, planted area (sqm and hectares), annual production volume, estimated revenue, operating cost, net return in PHP, and barangay participation.';
    }

    public function parameters(): array
    {
        return LocationResolver::locationParameterSchema();
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'layer' => $this->layer(),
            'description' => $this->description(),
            'parameters' => $this->parameters(),
        ];
    }

    public function execute(array $args): array
    {
        $location = LocationResolver::resolve($args);

        return $this->forLocation($location);
    }

    /**
     * Analyze agricultural production and financial estimates for a resolved location.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        if (! Schema::hasTable('agriculture_count_estimates')) {
            return ['status' => 'unavailable', 'reason' => 'agriculture_count_estimates table is not present.'];
        }

        $datasetsTool = app(GetAvailableDatasetsTool::class);
        $query = $datasetsTool->agricultureQueryFor($location);

        $scopeNote = match (true) {
            $location instanceof Barangay => 'barangay',
            $location instanceof Province => 'province',
            default => 'municipality',
        };

        $totalRecords = (clone $query)->count();
        if ($totalRecords === 0) {
            return [
                'status' => 'no_data',
                'location_level' => LocationResolver::levelOf($location),
                'location_id' => (int) $location->id,
                'location_name' => LocationResolver::nameOf($location),
                'scope_basis' => $scopeNote,
                'record_count' => 0,
                'model_year' => null,
                'total_area_sqm' => null,
                'total_area_hectares' => null,
                'categories' => [],
                'types' => [],
                'top_commodities' => [],
                'barangay_participation' => null,
                'financials_php_year' => null,
            ];
        }

        $latestYear = (clone $query)->max('model_year');
        if ($latestYear !== null) {
            $query = (clone $query)->where('model_year', $latestYear);
        }

        $rows = $query->get();

        $categoriesMap = Schema::hasTable('agriculture_categories')
            ? AgricultureCategory::query()->pluck('category_name', 'id')->all()
            : [];
        $categoryCodesMap = Schema::hasTable('agriculture_categories')
            ? AgricultureCategory::query()->pluck('category_code', 'id')->all()
            : [];
        $typesMap = Schema::hasTable('agriculture_types')
            ? AgricultureType::query()->pluck('type_name', 'id')->all()
            : [];

        $byCategory = [];
        foreach ($rows->groupBy('category_id') as $catId => $catRows) {
            $catAreaSqm = round((float) $catRows->whereNotNull('area_sqm')->sum('area_sqm'), 2);
            $byCategory[] = [
                'category_id' => $catId ?: null,
                'category_code' => $categoryCodesMap[$catId] ?? null,
                'category_name' => $categoriesMap[$catId] ?? 'Unclassified',
                'record_count' => $catRows->count(),
                'total_area_sqm' => $catAreaSqm,
                'total_area_hectares' => round($catAreaSqm / 10_000, 2),
                'estimated_revenue_php_year' => round((float) $catRows->whereNotNull('estimated_revenue_php_year')->sum('estimated_revenue_php_year'), 2),
                'estimated_operating_cost_php_year' => round((float) $catRows->whereNotNull('estimated_operating_cost_php_year')->sum('estimated_operating_cost_php_year'), 2),
                'estimated_net_php_year' => round((float) $catRows->whereNotNull('estimated_net_php_year')->sum('estimated_net_php_year'), 2),
            ];
        }

        $byType = [];
        foreach ($rows->groupBy('type_id') as $typeId => $typeRows) {
            $typeAreaSqm = round((float) $typeRows->whereNotNull('area_sqm')->sum('area_sqm'), 2);
            $byType[] = [
                'type_id' => $typeId ?: null,
                'type_name' => $typesMap[$typeId] ?? 'Unclassified',
                'record_count' => $typeRows->count(),
                'total_area_sqm' => $typeAreaSqm,
                'total_area_hectares' => round($typeAreaSqm / 10_000, 2),
                'estimated_revenue_php_year' => round((float) $typeRows->whereNotNull('estimated_revenue_php_year')->sum('estimated_revenue_php_year'), 2),
                'estimated_net_php_year' => round((float) $typeRows->whereNotNull('estimated_net_php_year')->sum('estimated_net_php_year'), 2),
            ];
        }

        $topCommodities = $rows
            ->whereNotNull('commodity_name')
            ->groupBy(fn ($r) => $r->commodity_name.'|'.$r->quantity_unit.'|'.$r->quantity_basis)
            ->map(function ($group) {
                $first = $group->first();
                $areaSqm = round((float) $group->whereNotNull('area_sqm')->sum('area_sqm'), 2);

                return [
                    'commodity_name' => $first->commodity_name,
                    'quantity_unit' => $first->quantity_unit,
                    'quantity_basis' => $first->quantity_basis,
                    'record_count' => $group->count(),
                    'total_estimated_quantity_year' => round((float) $group->whereNotNull('estimated_quantity_year')->sum('estimated_quantity_year'), 2),
                    'total_area_sqm' => $areaSqm,
                    'total_area_hectares' => round($areaSqm / 10_000, 2),
                    'estimated_revenue_php_year' => round((float) $group->whereNotNull('estimated_revenue_php_year')->sum('estimated_revenue_php_year'), 2),
                    'estimated_operating_cost_php_year' => round((float) $group->whereNotNull('estimated_operating_cost_php_year')->sum('estimated_operating_cost_php_year'), 2),
                    'estimated_net_php_year' => round((float) $group->whereNotNull('estimated_net_php_year')->sum('estimated_net_php_year'), 2),
                ];
            })
            ->sortByDesc('total_estimated_quantity_year')
            ->take(15)
            ->values()
            ->all();

        $barangayParticipation = null;
        if (
            $location instanceof Municipality
            && Schema::hasTable('barangays')
            && Schema::hasColumn('barangays', 'municipality_id')
            && Schema::hasColumn('barangays', 'brgy_name')
        ) {
            $bgyMap = Barangay::query()
                ->where('municipality_id', $location->id)
                ->pluck('brgy_name', 'id')
                ->all();

            $byBgy = [];
            foreach ($rows->whereNotNull('barangay_id')->groupBy('barangay_id') as $bId => $bRows) {
                $bAreaSqm = round((float) $bRows->whereNotNull('area_sqm')->sum('area_sqm'), 2);
                $byBgy[] = [
                    'barangay_id' => (int) $bId,
                    'barangay_name' => $bgyMap[(int) $bId] ?? null,
                    'record_count' => $bRows->count(),
                    'distinct_commodities' => $bRows->pluck('commodity_name')->filter()->unique()->count(),
                    'total_area_sqm' => $bAreaSqm,
                    'total_area_hectares' => round($bAreaSqm / 10_000, 2),
                    'estimated_net_php_year' => round((float) $bRows->whereNotNull('estimated_net_php_year')->sum('estimated_net_php_year'), 2),
                ];
            }

            usort($byBgy, fn ($a, $b) => $b['estimated_net_php_year'] <=> $a['estimated_net_php_year']);

            $totalBgys = count($bgyMap);
            $participatingCount = count($byBgy);

            $barangayParticipation = [
                'total_barangays' => $totalBgys,
                'barangays_with_agriculture' => $participatingCount,
                'barangays_without_agriculture' => max(0, $totalBgys - $participatingCount),
                'participation_pct' => $totalBgys > 0 ? round(($participatingCount / $totalBgys) * 100, 1) : null,
                'top_agricultural_barangays' => array_slice($byBgy, 0, 10),
            ];
        }

        $totalAreaSqm = round((float) $rows->whereNotNull('area_sqm')->sum('area_sqm'), 2);
        $revRows = $rows->whereNotNull('estimated_revenue_php_year');
        $costRows = $rows->whereNotNull('estimated_operating_cost_php_year');
        $netRows = $rows->whereNotNull('estimated_net_php_year');

        $totalRev = $revRows->isNotEmpty() ? round((float) $revRows->sum('estimated_revenue_php_year'), 2) : null;
        $totalCost = $costRows->isNotEmpty() ? round((float) $costRows->sum('estimated_operating_cost_php_year'), 2) : null;
        $totalNet = $netRows->isNotEmpty() ? round((float) $netRows->sum('estimated_net_php_year'), 2) : null;
        $netMarginPct = ($totalRev !== null && $totalRev > 0 && $totalNet !== null)
            ? round(($totalNet / $totalRev) * 100, 1)
            : null;

        return [
            'status' => 'available',
            'location_level' => LocationResolver::levelOf($location),
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'scope_basis' => $scopeNote,
            'model_year' => $latestYear !== null ? (int) $latestYear : null,
            'record_count' => $rows->count(),
            'total_area_sqm' => $totalAreaSqm,
            'total_area_hectares' => round($totalAreaSqm / 10_000, 2),
            'categories' => $byCategory,
            'types' => $byType,
            'top_commodities' => $topCommodities,
            'barangay_participation' => $barangayParticipation,
            'financials_php_year' => [
                'estimated_revenue_php' => $totalRev,
                'estimated_operating_cost_php' => $totalCost,
                'estimated_net_php' => $totalNet,
                'net_margin_pct' => $netMarginPct,
            ],
        ];
    }
}
