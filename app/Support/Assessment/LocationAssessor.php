<?php

namespace App\Support\Assessment;

use App\Models\Barangay;
use App\Models\CollectionYear;
use App\Models\PlaceDescription;
use App\Models\TelecomSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Measures the indicators in the catalog for a single location.
 *
 * Every value returned is computed here from source data, never inferred. An
 * indicator with no underlying data is reported as unavailable rather than
 * defaulted, because a fabricated zero reads as "measured, and there is none"
 * when the truth is "we do not know".
 */
class LocationAssessor
{
    private const PER_100K = 100_000;

    private const PER_MILLION = 1_000_000;

    /**
     * Request-level memoized national reference rates computed directly from source tables.
     *
     * @var array<string, float|null>|null
     */
    private static ?array $computedBenchmarks = null;

    /**
     * Assess a location against every indicator for a dimension.
     *
     * @param  Model  $location  A Municipality or Barangay.
     * @return list<IndicatorResult>
     */
    public function assess(Model $location, ?string $dimension = null): array
    {
        $context = $this->buildContext($location);

        $results = [];

        foreach (IndicatorCatalog::forDimension($dimension) as $indicator) {
            $results[] = $this->measure($indicator, $context);
        }

        return $results;
    }

    /**
     * Use the same formulas and confidence rules for a verified map inventory.
     *
     * @param  array<string, int|float|null>  $counts
     * @param  array<string, string>  $unavailable  Reasons keyed by indicator.
     * @return list<IndicatorResult>
     */
    public function assessSnapshot(?int $population, ?float $areaKm2, array $counts, array $unavailable = []): array
    {
        $context = ['population' => $population, 'area_km2' => $areaKm2, 'counts' => $counts];

        return array_map(function (Indicator $indicator) use ($context, $unavailable) {
            if (isset($unavailable[$indicator->key])) {
                return new IndicatorResult(indicator: $indicator, status: IndicatorResult::STATUS_NO_DATA,
                    reason: $unavailable[$indicator->key]);
            }

            return $this->measure($indicator, $context);
        }, array_values(IndicatorCatalog::all()));
    }

    /**
     * The facts about a location that the indicators are computed from.
     *
     * @return array{population: int|null, area_km2: float|null, counts: array<string, int>, location: Model}
     */
    private function buildContext(Model $location): array
    {
        $population = $this->populationFor($location);

        return [
            'location' => $location,
            'population' => $population,
            'area_km2' => $location->area_km2,
            'counts' => $this->countsFor($location, $population),
        ];
    }

    /**
     * Population for the location, from `population_figures` for the active
     * collection year.
     *
     * Reached through the `populationFigure` relation, which joins on
     * psg_code and filters by the level held on `place_descriptions`. A null
     * means PSA published no figure for that place and year, which is the
     * only "no data" case; treating it as zero would understate every rate.
     */
    private function populationFor(Model $location): ?int
    {
        return $location->populationFigure?->population;
    }

    /**
     * Raw counts backing each indicator.
     *
     * @return array<string, int>
     */
    private function countsFor(Model $location, ?int $population): array
    {
        $facilities = $location->facilities();
        $laboratories = $location->laboratories();

        if ($population === null) {
            return [];
        }

        [$telecomTotal, $telecom5gShare] = $this->telecomMetricsFor($location);

        return [
            'health_facilities_per_100k' => (clone $facilities)->ofSector('health')->count(),
            'hospitals_per_100k' => (clone $facilities)->ofType('hospital')->count(),
            'pharmacies_per_100k' => (clone $facilities)->ofType('pharmacy')->count(),
            'schools_per_100k' => (clone $facilities)->ofSector('education')->count(),
            'elementary_schools_per_100k' => (clone $facilities)->ofLevel('elementary')->count(),
            'safety_facilities_per_100k' => (clone $facilities)->ofSector('safety')->count(),
            'laboratories_per_million' => (clone $laboratories)->count(),
            'laboratory_tests_per_million' => $this->distinctTestCount($location),
            'telecom_sites_per_100k' => $telecomTotal,
            'telecom_5g_share' => $telecom5gShare,
            'unknown_education_share' => $this->unknownEducationShare($location),
        ];
    }

    /**
     * Total telecom cell sites and percentage carrying 5G NR for a location.
     *
     * @return array{0: int, 1: float|null}
     */
    private function telecomMetricsFor(Model $location): array
    {
        if (! Schema::hasTable('telecom_sites')) {
            return [0, null];
        }

        $query = TelecomSite::query();
        $psgCode = (string) ($location->psg_code ?? '');

        if ($location instanceof Barangay) {
            $query->where(function ($q) use ($location, $psgCode) {
                $q->where('barangay_id', $location->id);
                if ($psgCode !== '') {
                    $q->orWhere('barangay_psgc', $psgCode);
                }
            });
        } else {
            $prefix = str_starts_with($psgCode, '13806')
                ? substr($psgCode, 0, 5)
                : substr($psgCode, 0, 7);

            $query->where(function ($q) use ($location, $prefix) {
                $q->where('municipality_id', $location->id);
                if ($prefix !== '') {
                    $q->orWhere('barangay_psgc', 'like', $prefix.'%');
                }
            });
        }

        $sites = $query->get(['id', 'technologies']);
        $total = $sites->count();

        if ($total === 0) {
            return [0, null];
        }

        $with5g = $sites->filter(fn (TelecomSite $site) => $site->supports('5G NR') || $site->supports('5G'))->count();

        return [$total, round($with5g / $total * 100, 1)];
    }

    /**
     * Distinct services/tests offered by laboratories in the location.
     *
     * lab_services holds 2,846 rows but only 2,556 distinct
     * (laboratory, test, method) combinations, so a plain count overstates
     * capability.
     */
    private function distinctTestCount(Model $location): int
    {
        return $location->laboratories()
            ->with('tests')
            ->get()
            ->flatMap(fn ($lab) => $lab->tests->map(fn ($service) => [
                $service->service_name ?? $service->test_name ?? $service->name,
                $service->description ?? $service->method ?? null,
            ]))
            ->unique()
            ->count();
    }

    /**
     * Share of education facilities in the area whose level is unclassified.
     *
     * A high share means a level-specific indicator measures the completeness
     * of the source data rather than the condition of the area.
     */
    private function unknownEducationShare(Model $location): float
    {
        $total = (clone $location->facilities())->where('sector', 'education')->count();

        if ($total === 0) {
            return 0.0;
        }

        $unknown = (clone $location->facilities())
            ->where('sector', 'education')
            ->where(function ($query) {
                $query->whereNull('level')->orWhere('level', '')->orWhere('level', 'unknown');
            })
            ->count();

        return round($unknown / $total, 4);
    }

    /**
     * Compute one indicator and compare it to its benchmark.
     *
     * @param  array{population: int|null, area_km2: float|null, counts: array<string, int|float|null>}  $context
     */
    private function measure(Indicator $indicator, array $context): IndicatorResult
    {
        $population = $context['population'];

        if ($population === null || $population <= 0) {
            return new IndicatorResult(
                indicator: $indicator,
                status: IndicatorResult::STATUS_NO_DATA,
                reason: 'No matched population figure is available for this area.',
            );
        }

        $value = match ($indicator->key) {
            'population_density' => $this->density($population, $context['area_km2']),
            'health_facilities_per_100k' => $this->per($context['counts'][$indicator->key] ?? 0, $population, self::PER_100K),
            'hospitals_per_100k' => $this->per($context['counts'][$indicator->key] ?? 0, $population, self::PER_100K),
            'pharmacies_per_100k' => $this->per($context['counts'][$indicator->key] ?? 0, $population, self::PER_100K),
            'schools_per_100k' => $this->per($context['counts'][$indicator->key] ?? 0, $population, self::PER_100K),
            'elementary_schools_per_100k' => $this->per($context['counts'][$indicator->key] ?? 0, $population, self::PER_100K),
            'safety_facilities_per_100k' => $this->per($context['counts'][$indicator->key] ?? 0, $population, self::PER_100K),
            'laboratories_per_million' => $this->per($context['counts'][$indicator->key] ?? 0, $population, self::PER_MILLION),
            'laboratory_tests_per_million' => $this->per($context['counts'][$indicator->key] ?? 0, $population, self::PER_MILLION),
            'telecom_sites_per_100k' => $this->per($context['counts'][$indicator->key] ?? 0, $population, self::PER_100K),
            'telecom_5g_share' => $context['counts']['telecom_5g_share'] ?? null,
            default => null,
        };

        if ($value === null) {
            return new IndicatorResult(
                indicator: $indicator,
                status: IndicatorResult::STATUS_NO_DATA,
                reason: 'This indicator is not yet implemented.',
            );
        }

        $benchmarkValue = $this->dynamicBenchmarkFor($indicator->key);

        // Confidence is a property of the measurement, so it is evaluated
        // independently of whether a benchmark happens to exist.
        [$confidence, $confidenceNote] = $this->confidenceFor($indicator, $value, $context);

        if ($benchmarkValue === null) {
            return new IndicatorResult(
                indicator: $indicator,
                value: $value,
                median: null,
                status: IndicatorResult::STATUS_NO_BENCHMARK,
                confidence: $confidence,
                reason: $confidenceNote !== ''
                    ? $confidenceNote
                    : 'Measured, but underlying source tables do not have sufficient data to compute a reference rate.',
            );
        }

        $gap = $benchmarkValue > 0
            ? ($value - $benchmarkValue) / $benchmarkValue * 100
            : null;

        return new IndicatorResult(
            indicator: $indicator,
            value: $value,
            benchmark: $benchmarkValue,
            median: null,
            gap: $gap,
            status: IndicatorResult::STATUS_AVAILABLE,
            confidence: $confidence,
            reason: $confidenceNote,
            basisNote: 'Reference rate computed dynamically from source tables across all recorded areas.',
        );
    }

    /**
     * Compute national reference rates directly from the live domain tables (without a static benchmarks table).
     */
    private function dynamicBenchmarkFor(string $indicatorKey): ?float
    {
        if (self::$computedBenchmarks === null) {
            self::$computedBenchmarks = $this->computeAllDynamicBenchmarks();
        }

        return self::$computedBenchmarks[$indicatorKey] ?? null;
    }

    /**
     * @return array<string, float|null>
     */
    private function computeAllDynamicBenchmarks(): array
    {
        if (! Schema::hasTable('population_figures') || ! Schema::hasTable('place_descriptions') || ! Schema::hasTable('collection_years')) {
            return [];
        }

        $activeYear = CollectionYear::activeYear();
        if ($activeYear === null) {
            return [];
        }

        // Compute total national population from municipality/city level figures in the active census year.
        $nationalPopulation = (int) DB::table('population_figures')
            ->where('year', $activeYear)
            ->whereNotNull('population')
            ->whereIn('place_description_id', PlaceDescription::levelIdsQuery(PlaceDescription::LGU_LEVELS))
            ->sum('population');

        if ($nationalPopulation <= 0) {
            return [];
        }

        $totalAreaKm2 = Schema::hasTable('municipalities') && Schema::hasColumn('municipalities', 'area_km2')
            ? (float) DB::table('municipalities')->whereNotNull('area_km2')->where('area_km2', '>', 0)->sum('area_km2')
            : 0.0;

        $rates = [
            'population_density' => $totalAreaKm2 > 0 ? round($nationalPopulation / $totalAreaKm2, 4) : null,
        ];

        if (Schema::hasTable('facilities')) {
            $facilityCounts = DB::table('facilities')
                ->select([
                    DB::raw("SUM(CASE WHEN sector = 'health' THEN 1 ELSE 0 END) as health_total"),
                    DB::raw("SUM(CASE WHEN facility_type = 'hospital' THEN 1 ELSE 0 END) as hospital_total"),
                    DB::raw("SUM(CASE WHEN facility_type = 'pharmacy' THEN 1 ELSE 0 END) as pharmacy_total"),
                    DB::raw("SUM(CASE WHEN sector = 'education' THEN 1 ELSE 0 END) as school_total"),
                    DB::raw("SUM(CASE WHEN level = 'elementary' THEN 1 ELSE 0 END) as elem_total"),
                    DB::raw("SUM(CASE WHEN sector = 'safety' THEN 1 ELSE 0 END) as safety_total"),
                ])
                ->first();

            if ($facilityCounts !== null) {
                $rates['health_facilities_per_100k'] = $this->per((int) $facilityCounts->health_total, $nationalPopulation, self::PER_100K);
                $rates['hospitals_per_100k'] = $this->per((int) $facilityCounts->hospital_total, $nationalPopulation, self::PER_100K);
                $rates['pharmacies_per_100k'] = $this->per((int) $facilityCounts->pharmacy_total, $nationalPopulation, self::PER_100K);
                $rates['schools_per_100k'] = $this->per((int) $facilityCounts->school_total, $nationalPopulation, self::PER_100K);
                $rates['elementary_schools_per_100k'] = $this->per((int) $facilityCounts->elem_total, $nationalPopulation, self::PER_100K);
                $rates['safety_facilities_per_100k'] = $this->per((int) $facilityCounts->safety_total, $nationalPopulation, self::PER_100K);
            }
        }

        if (Schema::hasTable('laboratories')) {
            $labTotal = (int) DB::table('laboratories')
                ->where(fn ($q) => $q->where('is_outside_philippines', false)->orWhereNull('is_outside_philippines'))
                ->count();
            $rates['laboratories_per_million'] = $this->per($labTotal, $nationalPopulation, self::PER_MILLION);
        }

        if (Schema::hasTable('lab_services')) {
            $serviceTotal = (int) DB::table('lab_services')->count();
            $rates['laboratory_tests_per_million'] = $this->per($serviceTotal, $nationalPopulation, self::PER_MILLION);
        }

        if (Schema::hasTable('telecom_sites')) {
            $telecomSites = DB::table('telecom_sites')->get(['id', 'technologies']);
            $telecomTotal = $telecomSites->count();
            $rates['telecom_sites_per_100k'] = $this->per($telecomTotal, $nationalPopulation, self::PER_100K);

            if ($telecomTotal > 0) {
                $with5g = $telecomSites->filter(function ($site) {
                    $techs = is_string($site->technologies) ? (json_decode($site->technologies, true) ?: []) : (array) $site->technologies;
                    foreach ($techs as $t) {
                        $lower = strtolower((string) $t);
                        if ($lower === '5g nr' || $lower === '5g') {
                            return true;
                        }
                    }

                    return false;
                })->count();
                $rates['telecom_5g_share'] = round($with5g / $telecomTotal * 100, 1);
            }
        }

        return $rates;
    }

    /**
     * Decide how much a measured value can be trusted.
     *
     * @param  array{population: int|null, area_km2: float|null, counts: array<string, int|float|null>}  $context
     * @return array{0: string, 1: string} Confidence and the reason for it.
     */
    private function confidenceFor(Indicator $indicator, float $value, array $context): array
    {
        $reason = '';

        // A zero from a crowd-sourced dataset records an absence in the map,
        // not an absence in the area. Never present it as a confident finding.
        if ($indicator->volunteerSourced && $value === 0.0) {
            $reason = 'No facilities are recorded for this area in OpenStreetMap. '
                .'This may mean they were never mapped rather than that none exist. '
                .'Treat as unknown until confirmed against an official source.';
        }

        if ($indicator->coverageCheck !== null) {
            $share = (float) ($context['counts'][$indicator->coverageCheck] ?? 0);

            if ($share > $indicator->coverageWarnAbove) {
                $note = sprintf(
                    '%.0f%% of education facilities in this area have no recorded level, so this figure may reflect unclassified schools rather than a real shortage.',
                    $share * 100
                );
                $reason = $reason !== '' ? $reason.' '.$note : $note;
            }
        }

        return [
            $reason === '' ? IndicatorResult::CONFIDENCE_HIGH : IndicatorResult::CONFIDENCE_LOW,
            $reason,
        ];
    }

    private function density(int $population, ?float $areaKm2): ?float
    {
        if ($areaKm2 === null || $areaKm2 <= 0) {
            return null;
        }

        return round($population / $areaKm2, 2);
    }

    private function per(int $count, int $population, int $multiplier): float
    {
        return round($count / $population * $multiplier, 3);
    }
}
