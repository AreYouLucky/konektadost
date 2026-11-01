<?php

namespace App\FunctionTools\LayerB;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\LayerA\GetTelecomInfrastructureTool;
use App\FunctionTools\Support\LocationResolver;
use App\FunctionTools\Support\SpatialMetrics;
use App\Models\Barangay;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Layer B (B4) Function Tool: analyze_digital_connectivity
 *
 * Analyzes telecom site count, per-10k and per-100k rates, barangays with and without
 * telecom sites, 2G/3G/4G/5G technology distribution, operator presence, tower companies,
 * and colocation status.
 */
class AnalyzeDigitalConnectivityTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'analyze_digital_connectivity';
    }

    public function layer(): string
    {
        return 'B';
    }

    public function description(): string
    {
        return 'Analyze telecommunications cell site count, per-10,000 and per-100,000 population rates, 2G/3G/4G/5G radio technology distribution, operators, tower companies, colocation status, and barangays without cell sites.';
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
     * Analyze digital connectivity for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        if (! Schema::hasTable('telecom_sites')) {
            return ['status' => 'unavailable', 'reason' => 'telecom_sites table is not present.'];
        }

        $popStats = app(GetPopulationStatisticsTool::class)->forLocation($location, includeBarangayDistribution: false);
        $population = $popStats['population_current'] ?? $location->populationFigure?->population;

        $telecomInfra = app(GetTelecomInfrastructureTool::class)->forLocation($location, includeSiteRecords: true);
        $summary = $telecomInfra['summary'];
        $totalSites = (int) $summary['total_sites'];

        $brgyCoverage = null;
        if (
            $location instanceof Municipality
            && Schema::hasTable('barangays')
            && Schema::hasColumn('barangays', 'municipality_id')
            && Schema::hasColumn('barangays', 'brgy_name')
        ) {
            $coveredIds = collect($telecomInfra['records'])
                ->pluck('barangay_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->flip();

            $coveredPsgcs = collect($telecomInfra['records'])
                ->pluck('barangay_psgc')
                ->filter()
                ->map(fn ($c) => (string) $c)
                ->flip();

            $barangays = Barangay::query()
                ->where('municipality_id', $location->id)
                ->with('populationFigure')
                ->orderBy('brgy_name')
                ->get(['id', 'psg_code', 'brgy_name']);

            $zeroCount = 0;
            $affectedPop = 0;
            $underservedBarangays = [];

            foreach ($barangays as $brgy) {
                $hasSite = $coveredIds->has((int) $brgy->id) || $coveredPsgcs->has((string) $brgy->psg_code);
                $pop = $brgy->populationFigure?->population !== null ? (int) $brgy->populationFigure->population : null;

                if (! $hasSite) {
                    $zeroCount++;
                    $affectedPop += (int) ($pop ?? 0);
                    $underservedBarangays[] = [
                        'id' => (int) $brgy->id,
                        'psg_code' => (string) $brgy->psg_code,
                        'name' => (string) $brgy->brgy_name,
                        'population' => $pop,
                    ];
                }
            }

            usort($underservedBarangays, fn ($a, $b) => ($b['population'] ?? -1) <=> ($a['population'] ?? -1));

            $totalBgys = $barangays->count();
            $servedBgys = $totalBgys - $zeroCount;

            $brgyCoverage = [
                'total_barangays' => $totalBgys,
                'barangays_with_telecom' => $servedBgys,
                'zero_telecom_barangays' => $zeroCount,
                'coverage_pct' => $totalBgys > 0 ? round(($servedBgys / $totalBgys) * 100, 1) : null,
                'affected_population' => $affectedPop,
                'affected_population_share_pct' => ($population !== null && $population > 0)
                    ? round(($affectedPop / $population) * 100, 2)
                    : null,
                'underserved_barangays' => $underservedBarangays,
            ];
        }

        return [
            'status' => $telecomInfra['dataset_status'] === 'empty' ? 'empty' : 'available',
            'dataset_status' => $telecomInfra['dataset_status'],
            'location_level' => LocationResolver::levelOf($location),
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'population_used' => $population,
            'notes' => $telecomInfra['notes'],
            'counts' => [
                'total_telecom_sites' => $totalSites,
                'colocated_sites' => $summary['colocated_sites'],
                'independent_tower_sites' => $summary['independent_tower_sites'],
                'colocated_share_pct' => $totalSites > 0 ? round(($summary['colocated_sites'] / $totalSites) * 100, 1) : null,
                'sites_with_5g' => $summary['sites_with_5g'],
                'sites_with_4g' => $summary['sites_with_4g'],
                'sites_with_3g' => $summary['sites_with_3g'],
                'sites_with_2g' => $summary['sites_with_2g'],
                'share_5g_pct' => $summary['share_5g_pct'],
                'share_4g_pct' => $summary['share_4g_pct'],
            ],
            'rates_per_10k' => [
                'telecom_sites_per_10k' => SpatialMetrics::ratePer($totalSites, $population, 10_000),
            ],
            'rates_per_100k' => [
                'telecom_sites_per_100k' => SpatialMetrics::ratePer($totalSites, $population, 100_000),
            ],
            'operators' => $telecomInfra['by_operator'],
            'tower_companies' => $telecomInfra['by_tower_company'],
            'technologies' => $telecomInfra['by_technology'],
            'bands' => $telecomInfra['by_band'],
            'barangay_coverage' => $brgyCoverage,
        ];
    }
}

