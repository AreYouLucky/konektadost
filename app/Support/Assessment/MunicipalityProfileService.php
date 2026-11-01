<?php

namespace App\Support\Assessment;

use App\Models\Barangay;
use App\Models\CollectionYear;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use App\Models\PopulationFigure;
use App\Models\Province;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1 & Section 6 Function Tools:
 * - get_municipality_profile
 * - get_population_statistics
 *
 * Computes the geographic, administrative, and multi-year demographic profile
 * (including 2020 -> 2024 population change and growth rate) directly from
 * Province, Municipality, Barangay, PlaceDescription, PopulationFigure, and CollectionYear.
 */
class MunicipalityProfileService
{
    /**
     * Retrieve the full geographic and demographic profile for a Municipality (or Province/Barangay).
     *
     * @return array<string, mixed>
     */
    public function getMunicipalityProfile(Model $location): array
    {
        $level = match (true) {
            $location instanceof Province => 'province',
            $location instanceof Barangay => 'barangay',
            default => 'municipality',
        };

        $psgCode = (string) ($location->psg_code ?? '');
        $placeDescription = $this->resolvePlaceDescription($location, $level, $psgCode);
        $populationStats = $this->getPopulationStatistics($location, $level, $psgCode, $placeDescription);
        $barangayBreakdown = $location instanceof Municipality
            ? $this->getBarangayClassificationCounts($location)
            : null;

        $areaKm2 = $location->area_km2 !== null && (float) $location->area_km2 > 0
            ? round((float) $location->area_km2, 4)
            : null;

        $currentPop = $populationStats['population_current'];
        $density = ($currentPop !== null && $areaKm2 !== null && $areaKm2 > 0)
            ? round($currentPop / $areaKm2, 2)
            : null;

        $urbanRuralRaw = strtoupper(trim((string) ($placeDescription?->urban_rural ?? '')));
        $urbanRuralLabel = match ($urbanRuralRaw) {
            'U' => 'Urban',
            'R' => 'Rural',
            default => null,
        };

        if ($urbanRuralLabel === null && $barangayBreakdown !== null && $barangayBreakdown['total_barangays'] > 0) {
            if ($barangayBreakdown['urban_barangays'] > 0 && $barangayBreakdown['rural_barangays'] === 0) {
                $urbanRuralLabel = 'Urban';
            } elseif ($barangayBreakdown['rural_barangays'] > 0 && $barangayBreakdown['urban_barangays'] === 0) {
                $urbanRuralLabel = 'Rural';
            } elseif ($barangayBreakdown['urban_barangays'] > 0 && $barangayBreakdown['rural_barangays'] > 0) {
                $urbanRuralLabel = sprintf(
                    'Mixed (%d Urban, %d Rural)',
                    $barangayBreakdown['urban_barangays'],
                    $barangayBreakdown['rural_barangays']
                );
            }
        }

        return [
            'id' => $location->id,
            'level' => $level,
            'psg_code' => $psgCode !== '' ? $psgCode : null,
            'name' => $location instanceof Barangay
                ? (string) ($location->brgy_name ?: $placeDescription?->name ?: 'Barangay')
                : (string) $location->name,
            'type' => $location->type ?? ucfirst($level),
            'province_id' => $location instanceof Municipality
                ? $location->province_id
                : ($location instanceof Barangay ? $location->municipality?->province_id : $location->id),
            'province_name' => $location instanceof Municipality
                ? ($location->province_name ?? $location->provinceModel?->name)
                : ($location instanceof Barangay ? $location->municipality?->province_name : $location->name),
            'province_psg_code' => $location instanceof Municipality
                ? $location->provinceModel?->psg_code
                : ($location instanceof Barangay ? $location->municipality?->provinceModel?->psg_code : $location->psg_code),
            'income_class' => $placeDescription?->income_class ?: null,
            'city_class' => $placeDescription?->city_class ?: null,
            'urban_rural' => $urbanRuralLabel,
            'old_name' => $placeDescription?->old_name ?: null,
            'area_km2' => $areaKm2,
            'center_lat' => $location->center_lat !== null ? (float) $location->center_lat : null,
            'center_lng' => $location->center_lng !== null ? (float) $location->center_lng : null,
            'density_per_km2' => $density,
            'barangay_counts' => $barangayBreakdown,
            'population_statistics' => $populationStats,
        ];
    }

    /**
     * Retrieve multi-year population figures, 2020 -> 2024 change, and growth rates.
     *
     * If a Municipality/Province only has barangay-level rows in `population_figures`
     * for the 2020 baseline census year, sums its constituent barangays' 2020 figures
     * and labels the basis transparently.
     *
     * @return array{
     *     active_year: int|null,
     *     population_current: int|null,
     *     baseline_year: int|null,
     *     population_baseline: int|null,
     *     baseline_basis: string|null,
     *     population_change: int|null,
     *     growth_rate_pct: float|null,
     *     annual_growth_rate_pct: float|null,
     *     history: list<array{year: int, population: int, basis: string}>
     * }
     */
    public function getPopulationStatistics(
        Model $location,
        ?string $level = null,
        ?string $psgCode = null,
        ?PlaceDescription $placeDescription = null
    ): array {
        $activeYear = Schema::hasTable('collection_years') ? CollectionYear::activeYear() : null;
        $empty = [
            'active_year' => $activeYear,
            'population_current' => null,
            'baseline_year' => null,
            'population_baseline' => null,
            'baseline_basis' => null,
            'population_change' => null,
            'growth_rate_pct' => null,
            'annual_growth_rate_pct' => null,
            'history' => [],
        ];

        if (! Schema::hasTable('population_figures') || ! Schema::hasTable('place_descriptions')) {
            return $empty;
        }

        $level ??= match (true) {
            $location instanceof Province => 'province',
            $location instanceof Barangay => 'barangay',
            default => 'municipality',
        };
        $psgCode ??= (string) ($location->psg_code ?? '');

        if ($psgCode === '') {
            return $empty;
        }

        $placeDescription ??= $this->resolvePlaceDescription($location, $level, $psgCode);
        $prefix = in_array($level, ['municipality', 'province'], true)
            ? $this->psgcPrefixFor($level, $psgCode)
            : '';

        // Single-pass query: fetch both the direct LGU row and constituent barangay sums by year.
        $rows = DB::table('population_figures')
            ->where(function ($q) use ($psgCode, $prefix) {
                if ($prefix !== '') {
                    $q->where('psg_code', 'like', $prefix.'%');
                } else {
                    $q->where('psg_code', $psgCode);
                }
            })
            ->whereNotNull('population')
            ->select([
                'year',
                DB::raw('SUM(CASE WHEN psg_code = '.DB::getPdo()->quote($psgCode).' THEN population ELSE 0 END) as direct_pop'),
                DB::raw('SUM(CASE WHEN psg_code = '.DB::getPdo()->quote($psgCode).' THEN 1 ELSE 0 END) as direct_count'),
                DB::raw('SUM(CASE WHEN psg_code <> '.DB::getPdo()->quote($psgCode).' THEN population ELSE 0 END) as child_pop'),
                DB::raw('SUM(CASE WHEN psg_code <> '.DB::getPdo()->quote($psgCode).' THEN 1 ELSE 0 END) as child_count'),
            ])
            ->groupBy('year')
            ->orderBy('year')
            ->get();

        $historyMap = [];
        foreach ($rows as $row) {
            $yr = (int) $row->year;
            if ((int) $row->direct_count > 0 && (int) $row->direct_pop > 0) {
                $historyMap[$yr] = [
                    'year' => $yr,
                    'population' => (int) $row->direct_pop,
                    'basis' => 'official_lgu_figure',
                ];
            } elseif (in_array($level, ['municipality', 'province'], true) && (int) $row->child_count > 0 && (int) $row->child_pop > 0) {
                $historyMap[$yr] = [
                    'year' => $yr,
                    'population' => (int) $row->child_pop,
                    'basis' => 'barangay_sum',
                ];
            }
        }

        if ($historyMap === []) {
            return $empty;
        }

        ksort($historyMap);
        $history = array_values($historyMap);

        $currentPop = $activeYear !== null && isset($historyMap[$activeYear])
            ? $historyMap[$activeYear]['population']
            : null;

        if ($currentPop === null && $activeYear === null) {
            $last = end($history);
            if ($last !== false) {
                $activeYear = $last['year'];
                $currentPop = $last['population'];
            }
        }

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

        return [
            'active_year' => $activeYear,
            'population_current' => $currentPop,
            'baseline_year' => $baselineYear,
            'population_baseline' => $baselinePop,
            'baseline_basis' => $baselineBasis,
            'population_change' => $change,
            'growth_rate_pct' => $growthRatePct,
            'annual_growth_rate_pct' => $annualGrowthRatePct,
            'history' => $history,
        ];
    }

    /**
     * Resolve the PlaceDescription row for the given location model using indexed columns.
     */
    private function resolvePlaceDescription(Model $location, string $level, string $psgCode): ?PlaceDescription
    {
        if (! Schema::hasTable('place_descriptions') || $psgCode === '') {
            return null;
        }

        $placeLevels = match ($level) {
            'province' => [PlaceDescription::LEVEL_PROVINCE, PlaceDescription::LEVEL_REGION],
            'barangay' => [PlaceDescription::LEVEL_BARANGAY],
            default => PlaceDescription::LGU_LEVELS,
        };

        return PlaceDescription::query()
            ->where('psg_code', $psgCode)
            ->whereIn('level', $placeLevels)
            ->where('release_id', PlaceDescription::latestReleaseId())
            ->first();
    }

    /**
     * Count total, urban, and rural barangays for a municipality using a single indexed query.
     *
     * @return array{total_barangays: int, urban_barangays: int, rural_barangays: int}
     */
    private function getBarangayClassificationCounts(Municipality $municipality): array
    {
        $psgCode = (string) ($municipality->psg_code ?? '');
        $prefix = $this->psgcPrefixFor('municipality', $psgCode);

        $totalBarangays = Schema::hasTable('barangays')
            ? Barangay::query()->where('municipality_id', $municipality->id)->count()
            : 0;

        if ($prefix === '' || ! Schema::hasTable('place_descriptions')) {
            return [
                'total_barangays' => $totalBarangays,
                'urban_barangays' => 0,
                'rural_barangays' => 0,
            ];
        }

        $counts = PlaceDescription::query()
            ->where('release_id', PlaceDescription::latestReleaseId())
            ->where('level', PlaceDescription::LEVEL_BARANGAY)
            ->where('psg_code', 'like', $prefix.'%')
            ->select('urban_rural', DB::raw('COUNT(*) as total'))
            ->groupBy('urban_rural')
            ->pluck('total', 'urban_rural');

        $urban = (int) ($counts->get('U') ?? $counts->get('u') ?? 0);
        $rural = (int) ($counts->get('R') ?? $counts->get('r') ?? 0);

        if ($totalBarangays === 0) {
            $totalBarangays = $urban + $rural;
        }

        return [
            'total_barangays' => $totalBarangays,
            'urban_barangays' => $urban,
            'rural_barangays' => $rural,
        ];
    }

    private function psgcPrefixFor(string $level, string $psgCode): string
    {
        if ($psgCode === '') {
            return '';
        }

        if ($level === 'province') {
            return str_starts_with($psgCode, '13') ? '13' : substr($psgCode, 0, 5);
        }

        return str_starts_with($psgCode, '13806')
            ? substr($psgCode, 0, 5)
            : substr($psgCode, 0, 7);
    }
}
