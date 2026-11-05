<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Barangay;
use App\Models\CollectionYear;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use App\Support\Assessment\AssessmentComputationCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer A (A2) Function Tool: get_population_statistics
 *
 * Retrieves multi-year PSA census population statistics (2024 vs 2020),
 * population change, growth rate %, annualized CAGR, population density,
 * barangay-level distribution, concentration metrics, and missing data flags.
 */
class GetPopulationStatisticsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'get_population_statistics';
    }

    public function layer(): string
    {
        return 'A';
    }

    public function description(): string
    {
        return 'Retrieve multi-year population figures (2024 vs 2020), absolute change, percentage growth, annualized growth rate (CAGR), population density, barangay-level population distribution, concentration metrics, and missing population record flags.';
    }

    public function parameters(): array
    {
        $schema = LocationResolver::locationParameterSchema();
        $schema['properties']['include_barangay_distribution'] = [
            'type' => 'boolean',
            'description' => 'Whether to include the per-barangay population distribution and concentration metrics (default: true).',
        ];

        return $schema;
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
        $includeBarangayDistribution = (bool) ($args['include_barangay_distribution'] ?? true);

        return $this->forLocation($location, $includeBarangayDistribution);
    }

    /**
     * Compute population statistics for an already-resolved Eloquent model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location, bool $includeBarangayDistribution = true): array
    {
        return app(AssessmentComputationCache::class)->remember(
            self::class, $location, ['include_barangay_distribution' => $includeBarangayDistribution],
            fn () => $this->computeForLocation($location, $includeBarangayDistribution)
        );
    }

    private function computeForLocation(Model $location, bool $includeBarangayDistribution): array
    {
        $level = LocationResolver::levelOf($location);
        $psgCode = (string) ($location->psg_code ?? '');
        $activeYear = Schema::hasTable('collection_years') ? CollectionYear::activeYear() : null;

        $areaKm2 = $location->area_km2 !== null && (float) $location->area_km2 > 0
            ? round((float) $location->area_km2, 4)
            : null;

        $empty = [
            'active_year' => $activeYear,
            'population_current' => null,
            'current_basis' => null,
            'baseline_year' => null,
            'population_baseline' => null,
            'baseline_basis' => null,
            'population_change' => null,
            'growth_rate_pct' => null,
            'annual_growth_rate_pct' => null,
            'area_km2' => $areaKm2,
            'density_per_km2' => null,
            'history' => [],
        ];

        if (! Schema::hasTable('population_figures') || ! Schema::hasTable('place_descriptions') || $psgCode === '') {
            return $empty;
        }

        $historyMap = [];
        $levels = match ($level) {
            'barangay' => [PlaceDescription::LEVEL_BARANGAY],
            'province' => [PlaceDescription::LEVEL_PROVINCE, PlaceDescription::LEVEL_REGION],
            default => PlaceDescription::LGU_LEVELS,
        };
        // A PSGC can occur in multiple releases. Never add duplicate releases or other levels together.
        $rows = $this->populationRows([$psgCode], $levels);
        foreach ($rows as $row) {
            $year = (int) $row->year;
            if (isset($historyMap[$year]) || ($year === $activeYear && (int) $row->release_id !== PlaceDescription::latestReleaseId())) {
                continue;
            }
            $historyMap[$year] = ['year' => $year, 'population' => (int) $row->population,
                'basis' => 'official_lgu_figure', 'records_counted' => 1];
        }

        // Historic barangay sums are a labelled baseline only, and require every linked barangay.
        // Missing current LGU population must remain unknown in every assessment tool.
        if ($level !== 'barangay' && $activeYear !== null) {
            $codes = $this->barangayCodes($location);
            if ($codes !== []) {
                $children = $this->populationRows($codes, [PlaceDescription::LEVEL_BARANGAY])
                    ->unique(fn ($row) => $row->psg_code.':'.$row->year)->groupBy('year');
                foreach ($children as $year => $figures) {
                    if ((int) $year < $activeYear && ! isset($historyMap[$year]) && $figures->count() === count($codes)) {
                        $historyMap[$year] = ['year' => (int) $year, 'population' => (int) $figures->sum('population'),
                            'basis' => 'barangay_sum', 'records_counted' => $figures->count()];
                    }
                }
            }
        }

        ksort($historyMap);
        $history = array_values($historyMap);

        $currentPop = $activeYear !== null && isset($historyMap[$activeYear])
            ? $historyMap[$activeYear]['population']
            : null;
        $currentBasis = $activeYear !== null && isset($historyMap[$activeYear])
            ? $historyMap[$activeYear]['basis']
            : null;

        $baselineYear = null;
        $baselinePop = null;
        $baselineBasis = null;
        foreach ($history as $point) {
            if ($activeYear !== null && $point['year'] < $activeYear && $point['population'] > 0) {
                $baselineYear = $point['year'];
                $baselinePop = $point['population'];
                $baselineBasis = $point['basis'];
            }
        }

        $change = null;
        $growthRatePct = null;
        $annualGrowthRatePct = null;

        if ($currentPop !== null && $baselinePop !== null && $baselinePop > 0 && $activeYear !== null && $baselineYear !== null) {
            $change = $currentPop - $baselinePop;
            $growthRatePct = round(($change / $baselinePop) * 100, 2);
            $yearsElapsed = $activeYear - $baselineYear;
            if ($yearsElapsed > 0) {
                $cagr = (pow($currentPop / $baselinePop, 1 / $yearsElapsed) - 1) * 100;
                $annualGrowthRatePct = round($cagr, 2);
            }
        }

        $density = ($currentPop !== null && $areaKm2 !== null && $areaKm2 > 0)
            ? round($currentPop / $areaKm2, 2)
            : null;

        $result = [
            'active_year' => $activeYear,
            'population_current' => $currentPop,
            'current_basis' => $currentBasis,
            'baseline_year' => $baselineYear,
            'population_baseline' => $baselinePop,
            'baseline_basis' => $baselineBasis,
            'population_change' => $change,
            'growth_rate_pct' => $growthRatePct,
            'annual_growth_rate_pct' => $annualGrowthRatePct,
            'area_km2' => $areaKm2,
            'density_per_km2' => $density,
            'history' => $history,
        ];

        if ($includeBarangayDistribution && $location instanceof Municipality) {
            $distribution = $this->buildBarangayDistribution($location, $activeYear, $baselineYear, $currentPop);
            $result['concentration'] = $distribution['concentration'];
            $result['completeness'] = $distribution['completeness'];
            $result['barangay_distribution'] = $distribution['barangays'];
        }

        return $result;
    }

    private function populationRows(array $codes, array $levels): \Illuminate\Support\Collection
    {
        return DB::table('population_figures as pf')
            ->join('place_descriptions as pd', 'pd.id', '=', 'pf.place_description_id')
            ->whereColumn('pd.psg_code', 'pf.psg_code')->whereIn('pf.psg_code', $codes)
            ->whereIn('pd.level', $levels)->whereNotNull('pf.population')
            ->orderByDesc('pd.release_id')->orderByDesc('pf.id')
            ->get(['pf.psg_code', 'pf.year', 'pf.population', 'pd.release_id']);
    }

    private function barangayCodes(Model $location): array
    {
        if (! Schema::hasColumns('barangays', ['municipality_id', 'psg_code'])) {
            return [];
        }
        $query = Barangay::query();
        if ($location instanceof Municipality) {
            $query->where('municipality_id', $location->id);
        } else {
            $query->whereIn('municipality_id', Municipality::query()->select('id')->where('province_id', $location->id));
        }

        return $query->whereNotNull('psg_code')->distinct()->pluck('psg_code')->all();
    }

    /**
     * Build barangay-level population distribution, concentration metrics, and completeness audit.
     *
     * @return array{
     *     concentration: array<string, mixed>,
     *     completeness: array<string, mixed>,
     *     barangays: list<array<string, mixed>>
     * }
     */
    private function buildBarangayDistribution(
        Municipality $municipality,
        ?int $activeYear,
        ?int $baselineYear,
        ?int $municipalityPop
    ): array {
        if (! Schema::hasTable('barangays') || ! Schema::hasColumn('barangays', 'municipality_id')) {
            return [
                'concentration' => [],
                'completeness' => [
                    'total_barangays' => 0,
                    'with_current_population' => 0,
                    'missing_current_population' => 0,
                    'with_baseline_population' => 0,
                    'missing_baseline_population' => 0,
                ],
                'barangays' => [],
            ];
        }

        $psgCode = (string) ($municipality->psg_code ?? '');
        $codes = $this->barangayCodes($municipality);

        $popByPsgcAndYear = [];
        if ($codes !== []) {
            $years = array_values(array_filter([$activeYear, $baselineYear]));
            if ($years !== []) {
                $popRows = $this->populationRows($codes, [PlaceDescription::LEVEL_BARANGAY])
                    ->filter(fn ($row) => in_array((int) $row->year, $years, true)
                        && ((int) $row->year !== $activeYear || (int) $row->release_id === PlaceDescription::latestReleaseId()))
                    ->unique(fn ($row) => $row->psg_code.':'.$row->year);

                foreach ($popRows as $pr) {
                    $popByPsgcAndYear[(string) $pr->psg_code][(int) $pr->year] = (int) $pr->population;
                }
            }
        }

        $urbanRuralByPsgc = [];
        if ($codes !== [] && Schema::hasTable('place_descriptions')) {
            $urbanRuralByPsgc = PlaceDescription::query()
                ->where('release_id', PlaceDescription::latestReleaseId())
                ->where('level', PlaceDescription::LEVEL_BARANGAY)
                ->whereIn('psg_code', $codes)
                ->pluck('urban_rural', 'psg_code')
                ->all();
        }

        $barangayRows = DB::table('barangays')
            ->where('municipality_id', $municipality->id)
            ->select(['id', 'psg_code', 'brgy_name', 'area_km2'])
            ->orderBy('brgy_name')
            ->get();

        $barangays = [];
        $missingCurrentList = [];
        $missingBaselineList = [];
        $currentPopValues = [];
        $mostPopulous = null;
        $highestDensity = null;

        foreach ($barangayRows as $row) {
            $code = (string) ($row->psg_code ?? '');
            $curPop = ($activeYear !== null && isset($popByPsgcAndYear[$code][$activeYear]))
                ? $popByPsgcAndYear[$code][$activeYear]
                : null;
            $basePop = ($baselineYear !== null && isset($popByPsgcAndYear[$code][$baselineYear]))
                ? $popByPsgcAndYear[$code][$baselineYear]
                : null;

            if ($curPop === null) {
                $missingCurrentList[] = ['psg_code' => $code, 'name' => (string) $row->brgy_name];
            } else {
                $currentPopValues[] = $curPop;
            }

            if ($baselineYear !== null && $basePop === null) {
                $missingBaselineList[] = ['psg_code' => $code, 'name' => (string) $row->brgy_name];
            }

            $change = ($curPop !== null && $basePop !== null) ? ($curPop - $basePop) : null;
            $growthPct = ($change !== null && $basePop !== null && $basePop > 0)
                ? round(($change / $basePop) * 100, 2)
                : null;

            $sharePct = ($curPop !== null && $municipalityPop !== null && $municipalityPop > 0)
                ? round(($curPop / $municipalityPop) * 100, 2)
                : null;

            $bgyArea = $row->area_km2 !== null && (float) $row->area_km2 > 0
                ? round((float) $row->area_km2, 4)
                : null;
            $bgyDensity = ($curPop !== null && $bgyArea !== null && $bgyArea > 0)
                ? round($curPop / $bgyArea, 2)
                : null;

            $urRaw = strtoupper(trim((string) ($urbanRuralByPsgc[$code] ?? '')));
            $urLabel = match ($urRaw) {
                'U' => 'Urban',
                'R' => 'Rural',
                default => null,
            };

            $item = [
                'id' => (int) $row->id,
                'psg_code' => $code,
                'name' => (string) $row->brgy_name,
                'urban_rural' => $urLabel,
                'population_current' => $curPop,
                'population_baseline' => $basePop,
                'population_change' => $change,
                'growth_rate_pct' => $growthPct,
                'population_share_pct' => $sharePct,
                'area_km2' => $bgyArea,
                'density_per_km2' => $bgyDensity,
            ];

            if ($curPop !== null && ($mostPopulous === null || $curPop > $mostPopulous['population'])) {
                $mostPopulous = [
                    'psg_code' => $code,
                    'name' => (string) $row->brgy_name,
                    'population' => $curPop,
                    'population_share_pct' => $sharePct,
                ];
            }

            if ($bgyDensity !== null && ($highestDensity === null || $bgyDensity > $highestDensity['density_per_km2'])) {
                $highestDensity = [
                    'psg_code' => $code,
                    'name' => (string) $row->brgy_name,
                    'density_per_km2' => $bgyDensity,
                    'population' => $curPop,
                ];
            }

            $barangays[] = $item;
        }

        usort($barangays, fn ($a, $b) => ($b['population_current'] ?? -1) <=> ($a['population_current'] ?? -1));

        $avgPop = count($currentPopValues) > 0
            ? (int) round(array_sum($currentPopValues) / count($currentPopValues))
            : null;

        $medianPop = null;
        if (count($currentPopValues) > 0) {
            sort($currentPopValues);
            $mid = intdiv(count($currentPopValues), 2);
            $medianPop = count($currentPopValues) % 2 === 0
                ? (int) round(($currentPopValues[$mid - 1] + $currentPopValues[$mid]) / 2)
                : $currentPopValues[$mid];
        }

        $top3Pop = array_sum(array_map(
            fn ($b) => (int) ($b['population_current'] ?? 0),
            array_slice($barangays, 0, 3)
        ));
        $top3SharePct = ($municipalityPop !== null && $municipalityPop > 0 && $top3Pop > 0)
            ? round(($top3Pop / $municipalityPop) * 100, 2)
            : null;

        return [
            'concentration' => [
                'average_barangay_population' => $avgPop,
                'median_barangay_population' => $medianPop,
                'top_3_barangays_population_share_pct' => $top3SharePct,
                'most_populous_barangay' => $mostPopulous,
                'highest_density_barangay' => $highestDensity,
            ],
            'completeness' => [
                'total_barangays' => $barangayRows->count(),
                'with_current_population' => $barangayRows->count() - count($missingCurrentList),
                'missing_current_population' => count($missingCurrentList),
                'missing_current_barangays' => $missingCurrentList,
                'with_baseline_population' => $baselineYear !== null ? ($barangayRows->count() - count($missingBaselineList)) : 0,
                'missing_baseline_population' => $baselineYear !== null ? count($missingBaselineList) : $barangayRows->count(),
                'missing_baseline_barangays' => array_slice($missingBaselineList, 0, 25),
            ],
            'barangays' => $barangays,
        ];
    }
}
