<?php

namespace App\Support\Assessment;

use App\Models\AgricultureCategory;
use App\Models\Barangay;
use App\Models\Facility;
use App\Models\Laboratory;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\TelecomSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer B Function Tools:
 * - analyze_health_access
 * - analyze_education_access
 * - analyze_digital_connectivity
 * - analyze_agriculture
 * - analyze_sti_laboratory_capacity
 *
 * Pure deterministic computations, per-capita rates, barangay coverage gaps,
 * and Haversine proximity (nearest facility in km) from the 17 active models.
 */
class SectorAnalysisService
{
    public function __construct(
        private readonly DatasetAndAssetService $assetService,
        private readonly MunicipalityProfileService $profileService,
    ) {}

    /**
     * Tool: analyze_health_access
     *
     * @return array<string, mixed>
     */
    public function analyzeHealthAccess(Model $location): array
    {
        if (! Schema::hasTable('facilities')) {
            return ['status' => 'unavailable', 'reason' => 'facilities table is not present.'];
        }

        $popStats = $this->profileService->getPopulationStatistics($location);
        $population = $popStats['population_current'] ?? $location->populationFigure?->population;

        $baseQuery = $this->assetService->facilityQueryFor($location)->where('sector', 'health');
        $totalHealth = (clone $baseQuery)->count();
        $hospitals = (clone $baseQuery)->where('facility_type', 'hospital')->count();
        $pharmacies = (clone $baseQuery)->where('facility_type', 'pharmacy')->count();
        $clinicsAndPosts = max(0, $totalHealth - $hospitals - $pharmacies);

        $byType = (clone $baseQuery)
            ->select(DB::raw("COALESCE(NULLIF(facility_type, ''), 'unclassified') as ftype"), DB::raw('COUNT(*) as total'))
            ->groupBy('ftype')
            ->pluck('total', 'ftype')
            ->map(fn ($v) => (int) $v)
            ->all();

        $brgyCoverage = $location instanceof Municipality
            ? $this->barangayZeroCountForSector($location, 'health')
            : null;

        $nearestHospital = $this->findNearestFacility($location, 'health', 'hospital');
        $nearestHealthFacility = $totalHealth === 0
            ? $this->findNearestFacility($location, 'health', null)
            : null;

        return [
            'status' => 'available',
            'population_used' => $population,
            'counts' => [
                'total_health_facilities' => $totalHealth,
                'hospitals' => $hospitals,
                'pharmacies' => $pharmacies,
                'clinics_and_centers' => $clinicsAndPosts,
                'by_facility_type' => $byType,
            ],
            'rates_per_100k' => [
                'health_facilities_per_100k' => $this->ratePer($totalHealth, $population, 100_000),
                'hospitals_per_100k' => $this->ratePer($hospitals, $population, 100_000),
                'pharmacies_per_100k' => $this->ratePer($pharmacies, $population, 100_000),
            ],
            'barangay_coverage' => $brgyCoverage,
            'nearest_hospital' => $nearestHospital,
            'nearest_health_facility_if_zero' => $nearestHealthFacility,
        ];
    }

    /**
     * Tool: analyze_education_access
     *
     * @return array<string, mixed>
     */
    public function analyzeEducationAccess(Model $location): array
    {
        if (! Schema::hasTable('facilities')) {
            return ['status' => 'unavailable', 'reason' => 'facilities table is not present.'];
        }

        $popStats = $this->profileService->getPopulationStatistics($location);
        $population = $popStats['population_current'] ?? $location->populationFigure?->population;

        $baseQuery = $this->assetService->facilityQueryFor($location)->where('sector', 'education');
        $totalSchools = (clone $baseQuery)->count();
        $elementary = (clone $baseQuery)->where('level', 'elementary')->count();
        $secondary = (clone $baseQuery)->whereIn('level', ['secondary', 'high_school', 'senior_high'])->count();
        $tertiary = (clone $baseQuery)->whereIn('level', ['tertiary', 'college', 'university'])->count();
        $unclassified = (clone $baseQuery)->where(function ($q) {
            $q->whereNull('level')->orWhere('level', '')->orWhere('level', 'unknown');
        })->count();

        $brgyCoverage = $location instanceof Municipality
            ? $this->barangayZeroCountForSector($location, 'education')
            : null;

        $nearestSchool = $totalSchools === 0
            ? $this->findNearestFacility($location, 'education', null)
            : null;

        return [
            'status' => 'available',
            'population_used' => $population,
            'counts' => [
                'total_schools' => $totalSchools,
                'elementary_schools' => $elementary,
                'secondary_schools' => $secondary,
                'tertiary_institutions' => $tertiary,
                'unclassified_level_schools' => $unclassified,
                'unclassified_share_pct' => $totalSchools > 0 ? round($unclassified / $totalSchools * 100, 1) : 0.0,
            ],
            'rates_per_100k' => [
                'schools_per_100k' => $this->ratePer($totalSchools, $population, 100_000),
                'elementary_schools_per_100k' => $this->ratePer($elementary, $population, 100_000),
                'secondary_schools_per_100k' => $this->ratePer($secondary, $population, 100_000),
            ],
            'barangay_coverage' => $brgyCoverage,
            'nearest_school_if_zero' => $nearestSchool,
        ];
    }

    /**
     * Tool: analyze_digital_connectivity
     *
     * @return array<string, mixed>
     */
    public function analyzeDigitalConnectivity(Model $location): array
    {
        if (! Schema::hasTable('telecom_sites')) {
            return ['status' => 'unavailable', 'reason' => 'telecom_sites table is not present.'];
        }

        $popStats = $this->profileService->getPopulationStatistics($location);
        $population = $popStats['population_current'] ?? $location->populationFigure?->population;

        $sites = $this->assetService->telecomQueryFor($location)->get([
            'id', 'name', 'operator', 'tower_company', 'technologies', 'bands', 'barangay_id', 'barangay_psgc', 'latitude', 'longitude',
        ]);

        $totalSites = $sites->count();
        $sites5g = $sites->filter(fn (TelecomSite $s) => $s->supports('5G NR') || $s->supports('5G'))->count();
        $sites4g = $sites->filter(fn (TelecomSite $s) => $s->supports('4G') || $s->supports('LTE'))->count();

        $operators = $sites->pluck('operator')->filter()->countBy()->all();
        $towerCompanies = $sites->pluck('tower_company')->filter()->countBy()->all();

        $brgyCoverage = $location instanceof Municipality
            ? $this->barangayZeroCountForTelecom($location, $sites)
            : null;

        return [
            'status' => 'available',
            'population_used' => $population,
            'counts' => [
                'total_telecom_sites' => $totalSites,
                'sites_with_5g' => $sites5g,
                'sites_with_4g' => $sites4g,
                'share_5g_pct' => $totalSites > 0 ? round($sites5g / $totalSites * 100, 1) : null,
                'share_4g_pct' => $totalSites > 0 ? round($sites4g / $totalSites * 100, 1) : null,
            ],
            'rates_per_100k' => [
                'telecom_sites_per_100k' => $this->ratePer($totalSites, $population, 100_000),
            ],
            'operators' => $operators,
            'tower_companies' => $towerCompanies,
            'barangay_coverage' => $brgyCoverage,
        ];
    }

    /**
     * Tool: analyze_agriculture
     *
     * @return array<string, mixed>
     */
    public function analyzeAgriculture(Model $location): array
    {
        if (! Schema::hasTable('agriculture_count_estimates')) {
            return ['status' => 'unavailable', 'reason' => 'agriculture_count_estimates table is not present.'];
        }

        $query = $this->assetService->agricultureQueryFor($location);
        $scopeNote = 'municipality';
        if ($location instanceof Municipality) {
            $hasDirect = DB::table('agriculture_count_estimates')->where('municipality_id', $location->id)->exists();
            $scopeNote = $hasDirect ? 'municipality' : 'province_context';
        } elseif ($location instanceof Barangay) {
            $scopeNote = 'barangay';
        } elseif ($location instanceof Province) {
            $scopeNote = 'province';
        }

        $totalRecords = (clone $query)->count();
        if ($totalRecords === 0) {
            return [
                'status' => 'no_data',
                'scope_basis' => $scopeNote,
                'record_count' => 0,
                'model_year' => null,
                'categories' => [],
                'top_commodities' => [],
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

        $byCategory = [];
        foreach ($rows->groupBy('category_id') as $catId => $catRows) {
            $byCategory[] = [
                'category_id' => $catId ?: null,
                'category_code' => $categoryCodesMap[$catId] ?? null,
                'category_name' => $categoriesMap[$catId] ?? 'Unclassified',
                'record_count' => $catRows->count(),
                'total_area_sqm' => round((float) $catRows->whereNotNull('area_sqm')->sum('area_sqm'), 2),
                'estimated_revenue_php_year' => round((float) $catRows->whereNotNull('estimated_revenue_php_year')->sum('estimated_revenue_php_year'), 2),
                'estimated_net_php_year' => round((float) $catRows->whereNotNull('estimated_net_php_year')->sum('estimated_net_php_year'), 2),
            ];
        }

        $topCommodities = $rows
            ->whereNotNull('commodity_name')
            ->groupBy(fn ($r) => $r->commodity_name.'|'.$r->quantity_unit.'|'.$r->quantity_basis)
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'commodity_name' => $first->commodity_name,
                    'quantity_unit' => $first->quantity_unit,
                    'quantity_basis' => $first->quantity_basis,
                    'record_count' => $group->count(),
                    'total_estimated_quantity_year' => round((float) $group->whereNotNull('estimated_quantity_year')->sum('estimated_quantity_year'), 2),
                    'total_area_sqm' => round((float) $group->whereNotNull('area_sqm')->sum('area_sqm'), 2),
                    'estimated_revenue_php_year' => round((float) $group->whereNotNull('estimated_revenue_php_year')->sum('estimated_revenue_php_year'), 2),
                    'estimated_net_php_year' => round((float) $group->whereNotNull('estimated_net_php_year')->sum('estimated_net_php_year'), 2),
                ];
            })
            ->sortByDesc('total_estimated_quantity_year')
            ->take(15)
            ->values()
            ->all();

        $revRows = $rows->whereNotNull('estimated_revenue_php_year');
        $costRows = $rows->whereNotNull('estimated_operating_cost_php_year');
        $netRows = $rows->whereNotNull('estimated_net_php_year');

        return [
            'status' => 'available',
            'scope_basis' => $scopeNote,
            'model_year' => $latestYear !== null ? (int) $latestYear : null,
            'record_count' => $rows->count(),
            'total_area_sqm' => round((float) $rows->whereNotNull('area_sqm')->sum('area_sqm'), 2),
            'categories' => $byCategory,
            'top_commodities' => $topCommodities,
            'financials_php_year' => [
                'estimated_revenue_php' => $revRows->isNotEmpty() ? round((float) $revRows->sum('estimated_revenue_php_year'), 2) : null,
                'estimated_operating_cost_php' => $costRows->isNotEmpty() ? round((float) $costRows->sum('estimated_operating_cost_php_year'), 2) : null,
                'estimated_net_php' => $netRows->isNotEmpty() ? round((float) $netRows->sum('estimated_net_php_year'), 2) : null,
            ],
        ];
    }

    /**
     * Tool: analyze_sti_laboratory_capacity
     *
     * @return array<string, mixed>
     */
    public function analyzeStiLaboratoryCapacity(Model $location): array
    {
        if (! Schema::hasTable('laboratories')) {
            return ['status' => 'unavailable', 'reason' => 'laboratories table is not present.'];
        }

        $popStats = $this->profileService->getPopulationStatistics($location);
        $population = $popStats['population_current'] ?? $location->populationFigure?->population;

        $hasServices = Schema::hasTable('lab_services');
        $labQuery = $this->assetService->laboratoryQueryFor($location);
        if ($hasServices) {
            $labQuery->with('tests');
        }

        $labs = $labQuery->get();
        $localLabCount = $labs->count();

        $distinctServices = collect();
        $freeServicesCount = 0;
        $paidServicesCount = 0;

        foreach ($labs as $lab) {
            if (! $hasServices) {
                continue;
            }
            foreach ($lab->tests as $svc) {
                $name = $svc->service_name ?? $svc->test_name ?? 'Service';
                $desc = $svc->description ?? $svc->method ?? null;
                $distinctServices->push(['service_name' => $name, 'description' => $desc, 'fee' => $svc->fee]);
                if ($svc->fee === null || (float) $svc->fee <= 0) {
                    $freeServicesCount++;
                } else {
                    $paidServicesCount++;
                }
            }
        }

        $uniqueServicesCount = $distinctServices
            ->unique(fn ($s) => $s['service_name'].'|'.$s['description'])
            ->count();

        $nearestLab = $localLabCount === 0 ? $this->findNearestLaboratory($location) : null;

        return [
            'status' => 'available',
            'population_used' => $population,
            'counts' => [
                'local_laboratories' => $localLabCount,
                'distinct_services_offered' => $uniqueServicesCount,
                'free_service_entries' => $freeServicesCount,
                'paid_service_entries' => $paidServicesCount,
            ],
            'rates_per_million' => [
                'laboratories_per_million' => $this->ratePer($localLabCount, $population, 1_000_000),
                'laboratory_tests_per_million' => $this->ratePer($uniqueServicesCount, $population, 1_000_000),
            ],
            'nearest_accredited_laboratory_if_zero' => $nearestLab,
        ];
    }

    /**
     * Count barangays in a municipality that have 0 recorded facilities in a given sector.
     *
     * @return array{total_barangays: int, zero_facility_barangays: int, affected_population: int}
     */
    private function barangayZeroCountForSector(Municipality $municipality, string $sector): array
    {
        if (! Schema::hasTable('barangays') || ! Schema::hasColumn('barangays', 'municipality_id')) {
            return ['total_barangays' => 0, 'zero_facility_barangays' => 0, 'affected_population' => 0];
        }

        $barangays = Barangay::query()
            ->where('municipality_id', $municipality->id)
            ->with('populationFigure')
            ->withCount(['facilities as sector_count' => fn ($q) => $q->where('sector', $sector)])
            ->get();

        $zeroCount = 0;
        $affectedPop = 0;

        foreach ($barangays as $brgy) {
            if ((int) ($brgy->sector_count ?? 0) === 0) {
                $zeroCount++;
                $affectedPop += (int) ($brgy->populationFigure?->population ?? 0);
            }
        }

        return [
            'total_barangays' => $barangays->count(),
            'zero_facility_barangays' => $zeroCount,
            'affected_population' => $affectedPop,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TelecomSite>  $sites
     * @return array{total_barangays: int, zero_telecom_barangays: int, affected_population: int}
     */
    private function barangayZeroCountForTelecom(Municipality $municipality, $sites): array
    {
        if (! Schema::hasTable('barangays') || ! Schema::hasColumn('barangays', 'municipality_id')) {
            return ['total_barangays' => 0, 'zero_telecom_barangays' => 0, 'affected_population' => 0];
        }

        $coveredIds = $sites->pluck('barangay_id')->filter()->map(fn ($id) => (int) $id)->flip();
        $coveredPsgcs = $sites->pluck('barangay_psgc')->filter()->map(fn ($c) => (string) $c)->flip();

        $barangays = Barangay::query()
            ->where('municipality_id', $municipality->id)
            ->with('populationFigure')
            ->get(['id', 'psg_code']);

        $zeroCount = 0;
        $affectedPop = 0;

        foreach ($barangays as $brgy) {
            $hasSite = $coveredIds->has((int) $brgy->id) || $coveredPsgcs->has((string) $brgy->psg_code);
            if (! $hasSite) {
                $zeroCount++;
                $affectedPop += (int) ($brgy->populationFigure?->population ?? 0);
            }
        }

        return [
            'total_barangays' => $barangays->count(),
            'zero_telecom_barangays' => $zeroCount,
            'affected_population' => $affectedPop,
        ];
    }

    /**
     * Find the nearest geocoded Facility (in km via Haversine formula) to the location's center coordinates.
     *
     * @return array{id: int, name: string|null, facility_type: string|null, distance_km: float}|null
     */
    private function findNearestFacility(Model $location, string $sector, ?string $facilityType): ?array
    {
        $lat = $location->center_lat !== null ? (float) $location->center_lat : null;
        $lng = $location->center_lng !== null ? (float) $location->center_lng : null;

        if ($lat === null || $lng === null || ! Schema::hasTable('facilities')) {
            return null;
        }

        $query = Facility::query()
            ->where('sector', $sector)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$lat - 1.5, $lat + 1.5])
            ->whereBetween('longitude', [$lng - 1.5, $lng + 1.5]);

        if ($facilityType !== null) {
            $query->where('facility_type', $facilityType);
        }

        $candidates = $query->limit(500)->get(['id', 'name', 'facility_type', 'latitude', 'longitude']);
        if ($candidates->isEmpty()) {
            return null;
        }

        $nearest = null;
        $minDist = PHP_FLOAT_MAX;

        foreach ($candidates as $fac) {
            $dist = self::haversineKm($lat, $lng, (float) $fac->latitude, (float) $fac->longitude);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = [
                    'id' => (int) $fac->id,
                    'name' => $fac->name,
                    'facility_type' => $fac->facility_type,
                    'distance_km' => round($dist, 2),
                ];
            }
        }

        return $nearest;
    }

    /**
     * Find the nearest accredited Laboratory (in km via Haversine formula) to the location's center coordinates.
     *
     * @return array{id: int, agency_name: string|null, city: string|null, distance_km: float}|null
     */
    private function findNearestLaboratory(Model $location): ?array
    {
        $lat = $location->center_lat !== null ? (float) $location->center_lat : null;
        $lng = $location->center_lng !== null ? (float) $location->center_lng : null;

        if ($lat === null || $lng === null || ! Schema::hasTable('laboratories')) {
            return null;
        }

        $candidates = Laboratory::query()
            ->inPhilippines()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'agency_name', 'city', 'latitude', 'longitude']);

        $nearest = null;
        $minDist = PHP_FLOAT_MAX;

        foreach ($candidates as $lab) {
            $fLat = (float) $lab->latitude;
            $fLng = (float) $lab->longitude;
            if ($fLat === 0.0 && $fLng === 0.0) {
                continue;
            }
            $dist = self::haversineKm($lat, $lng, $fLat, $fLng);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = [
                    'id' => (int) $lab->id,
                    'agency_name' => $lab->agency_name,
                    'city' => $lab->city,
                    'distance_km' => round($dist, 2),
                ];
            }
        }

        return $nearest;
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);

        return 2 * $earthRadiusKm * asin(min(1.0, sqrt($a)));
    }

    private function ratePer(int $count, ?int $population, int $multiplier): ?float
    {
        if ($population === null || $population <= 0) {
            return null;
        }

        return round($count / $population * $multiplier, 3);
    }
}

