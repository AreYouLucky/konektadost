<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Barangay;
use App\Models\Laboratory;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer A (A4) Function Tool: get_local_assets
 *
 * Retrieves and categorizes existing facilities, accredited laboratories,
 * and laboratory testing services in the selected location, including
 * barangay assignments, coordinates, and explicit flags for unmapped records.
 */
class GetLocalAssetsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'get_local_assets';
    }

    public function layer(): string
    {
        return 'A';
    }

    public function description(): string
    {
        return 'Retrieve and categorize local facilities (health, education, safety), accredited laboratories, and laboratory testing services, including barangay assignments, coordinates, and flags for unmapped records.';
    }

    public function parameters(): array
    {
        $schema = LocationResolver::locationParameterSchema();
        $schema['properties']['include_facility_records'] = [
            'type' => 'boolean',
            'description' => 'Whether to include individual facility records up to the limit (default: true).',
        ];
        $schema['properties']['facility_limit'] = [
            'type' => 'integer',
            'description' => 'Maximum number of individual facility records to return (default: 100).',
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
        $includeRecords = (bool) ($args['include_facility_records'] ?? true);
        $limit = max(1, min(500, (int) ($args['facility_limit'] ?? 100)));

        return $this->forLocation($location, $includeRecords, $limit);
    }

    /**
     * Retrieve categorized local assets for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location, bool $includeFacilityRecords = true, int $facilityLimit = 100): array
    {
        $level = LocationResolver::levelOf($location);
        $datasetsTool = app(GetAvailableDatasetsTool::class);

        $barangayNamesById = [];
        if (
            $location instanceof Municipality
            && Schema::hasTable('barangays')
            && Schema::hasColumn('barangays', 'municipality_id')
            && Schema::hasColumn('barangays', 'brgy_name')
        ) {
            $barangayNamesById = Barangay::query()
                ->where('municipality_id', $location->id)
                ->pluck('brgy_name', 'id')
                ->all();
        }

        // 1. Facilities breakdown by sector, type, education level, barangay assignment, and unmapped flags
        $facilitiesSummary = [
            'total' => 0,
            'geocoded_count' => 0,
            'missing_coordinates_count' => 0,
            'mapped_to_barangay_count' => 0,
            'unmapped_barangay_count' => 0,
            'by_sector' => ['health' => 0, 'education' => 0, 'safety' => 0],
            'by_type' => [],
            'by_education_level' => [],
            'by_barangay' => [],
            'unmapped_records' => [],
            'records' => [],
        ];

        if (Schema::hasTable('facilities')) {
            $baseFac = $datasetsTool->facilityQueryFor($location);
            $totalFac = (clone $baseFac)->count();
            $geocodedCount = (clone $baseFac)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->count();
            $mappedBgyCount = (clone $baseFac)->whereNotNull('barangay_id')->count();
            $unmappedBgyCount = $totalFac - $mappedBgyCount;

            $facilitiesSummary['total'] = $totalFac;
            $facilitiesSummary['geocoded_count'] = $geocodedCount;
            $facilitiesSummary['missing_coordinates_count'] = $totalFac - $geocodedCount;
            $facilitiesSummary['mapped_to_barangay_count'] = $mappedBgyCount;
            $facilitiesSummary['unmapped_barangay_count'] = $unmappedBgyCount;

            $bySector = (clone $baseFac)
                ->select('sector', DB::raw('COUNT(*) as total'))
                ->groupBy('sector')
                ->pluck('total', 'sector');

            foreach (['health', 'education', 'safety'] as $sec) {
                $facilitiesSummary['by_sector'][$sec] = (int) ($bySector[$sec] ?? 0);
            }

            $facilitiesSummary['by_type'] = (clone $baseFac)
                ->whereNotNull('facility_type')
                ->where('facility_type', '<>', '')
                ->select('facility_type', DB::raw('COUNT(*) as total'))
                ->groupBy('facility_type')
                ->orderByDesc('total')
                ->pluck('total', 'facility_type')
                ->map(fn ($v) => (int) $v)
                ->all();

            $facilitiesSummary['by_education_level'] = (clone $baseFac)
                ->where('sector', 'education')
                ->select(DB::raw("COALESCE(NULLIF(level, ''), 'unclassified') as edu_level"), DB::raw('COUNT(*) as total'))
                ->groupBy('edu_level')
                ->orderByDesc('total')
                ->pluck('total', 'edu_level')
                ->map(fn ($v) => (int) $v)
                ->all();

            if ($location instanceof Municipality) {
                $byBarangayRows = (clone $baseFac)
                    ->whereNotNull('barangay_id')
                    ->select([
                        'barangay_id',
                        DB::raw('COUNT(*) as total'),
                        DB::raw("SUM(CASE WHEN sector = 'health' THEN 1 ELSE 0 END) as health_count"),
                        DB::raw("SUM(CASE WHEN sector = 'education' THEN 1 ELSE 0 END) as education_count"),
                        DB::raw("SUM(CASE WHEN sector = 'safety' THEN 1 ELSE 0 END) as safety_count"),
                    ])
                    ->groupBy('barangay_id')
                    ->orderByDesc('total')
                    ->get();

                $facilitiesSummary['by_barangay'] = $byBarangayRows->map(fn ($r) => [
                    'barangay_id' => (int) $r->barangay_id,
                    'barangay_name' => $barangayNamesById[(int) $r->barangay_id] ?? null,
                    'total' => (int) $r->total,
                    'health' => (int) $r->health_count,
                    'education' => (int) $r->education_count,
                    'safety' => (int) $r->safety_count,
                ])->values()->all();
            }

            if ($unmappedBgyCount > 0 || ($totalFac - $geocodedCount) > 0) {
                $facilitiesSummary['unmapped_records'] = (clone $baseFac)
                    ->where(function ($q) {
                        $q->whereNull('barangay_id')
                            ->orWhereNull('latitude')
                            ->orWhereNull('longitude');
                    })
                    ->limit(50)
                    ->get(['id', 'name', 'sector', 'facility_type', 'level', 'barangay_id', 'latitude', 'longitude'])
                    ->map(fn ($f) => [
                        'id' => (int) $f->id,
                        'name' => $f->name,
                        'sector' => $f->sector,
                        'facility_type' => $f->facility_type,
                        'level' => $f->level,
                        'missing_barangay_id' => $f->barangay_id === null,
                        'missing_coordinates' => $f->latitude === null || $f->longitude === null,
                        'latitude' => $f->latitude !== null ? (float) $f->latitude : null,
                        'longitude' => $f->longitude !== null ? (float) $f->longitude : null,
                    ])
                    ->values()
                    ->all();
            }

            if ($includeFacilityRecords) {
                $facilitiesSummary['records'] = (clone $baseFac)
                    ->orderBy('sector')
                    ->orderBy('facility_type')
                    ->orderBy('name')
                    ->limit($facilityLimit)
                    ->get(['id', 'name', 'sector', 'facility_type', 'level', 'operator', 'capacity_no', 'barangay_id', 'latitude', 'longitude'])
                    ->map(fn ($f) => [
                        'id' => (int) $f->id,
                        'name' => $f->name,
                        'sector' => $f->sector,
                        'facility_type' => $f->facility_type,
                        'level' => $f->level ?: null,
                        'operator' => $f->operator ?: null,
                        'capacity_no' => $f->capacity_no !== null ? (int) $f->capacity_no : null,
                        'barangay_id' => $f->barangay_id !== null ? (int) $f->barangay_id : null,
                        'barangay_name' => $f->barangay_id !== null ? ($barangayNamesById[(int) $f->barangay_id] ?? null) : null,
                        'latitude' => $f->latitude !== null ? (float) $f->latitude : null,
                        'longitude' => $f->longitude !== null ? (float) $f->longitude : null,
                        'is_unmapped_barangay' => $f->barangay_id === null,
                    ])
                    ->values()
                    ->all();
            }
        }

        // 2. Accredited Laboratories & Services (mapped + unmapped text-matched candidates)
        $labsList = [];
        $unmappedCandidateLabs = [];

        if (Schema::hasTable('laboratories')) {
            $hasLabServices = Schema::hasTable('lab_services');
            $labQuery = $datasetsTool->laboratoryQueryFor($location);
            if ($hasLabServices) {
                $labQuery->with('tests');
            }

            $labsList = $labQuery->get()->map(function (Laboratory $lab) use ($hasLabServices, $barangayNamesById) {
                return $this->formatLaboratoryRecord($lab, $hasLabServices, $barangayNamesById, isMappedToLocation: true);
            })->values()->all();

            // Also surface any Philippine laboratories whose `city` text matches the municipality name but `municipality_id` is NULL
            if (! ($location instanceof Barangay)) {
                $token = $this->cleanLocationToken(LocationResolver::nameOf($location));
                if ($token !== '') {
                    $unmappedQuery = Laboratory::query()
                        ->whereNull('municipality_id')
                        ->whereNotNull('city')
                        ->where('city', 'like', '%'.$token.'%');

                    if ($hasLabServices) {
                        $unmappedQuery->with('tests');
                    }

                    $unmappedCandidateLabs = $unmappedQuery->get()->map(function (Laboratory $lab) use ($hasLabServices, $barangayNamesById) {
                        return $this->formatLaboratoryRecord($lab, $hasLabServices, $barangayNamesById, isMappedToLocation: false);
                    })->values()->all();
                }
            }
        }

        // 3. Telecom Cell Sites summary (full breakdown available via A5. get_telecom_infrastructure)
        $telecomSummary = app(GetTelecomInfrastructureTool::class)->forLocation($location, includeSiteRecords: false);

        return [
            'location_level' => $level,
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'facilities' => $facilitiesSummary,
            'laboratories' => [
                'count' => count($labsList),
                'unmapped_city_text_matches_count' => count($unmappedCandidateLabs),
                'records' => $labsList,
                'unmapped_city_text_matches' => $unmappedCandidateLabs,
            ],
            'telecom_sites' => [
                'dataset_status' => $telecomSummary['dataset_status'],
                'total_sites' => $telecomSummary['summary']['total_sites'],
                'sites_with_5g' => $telecomSummary['summary']['sites_with_5g'],
                'share_5g_pct' => $telecomSummary['summary']['share_5g_pct'],
                'operators' => $telecomSummary['by_operator'],
                'tower_companies' => $telecomSummary['by_tower_company'],
                'unmapped_barangay_sites_count' => $telecomSummary['summary']['unmapped_barangay_count'],
            ],
        ];
    }

    /**
     * Format a Laboratory model and its LabServices.
     *
     * @param  array<int, string>  $barangayNamesById
     * @return array<string, mixed>
     */
    private function formatLaboratoryRecord(
        Laboratory $lab,
        bool $hasLabServices,
        array $barangayNamesById,
        bool $isMappedToLocation
    ): array {
        $services = $hasLabServices
            ? $lab->tests
                ->map(fn ($s) => [
                    'service_name' => $s->service_name ?? $s->test_name ?? 'Service',
                    'description' => $s->description ?? $s->method ?? null,
                    'reference' => $s->reference ?? null,
                    'fee' => $s->fee !== null ? (float) $s->fee : null,
                ])
                ->unique(fn ($s) => $s['service_name'].'|'.$s['description'])
                ->values()
            : collect();

        return [
            'id' => (int) $lab->id,
            'agency_name' => $lab->agency_name,
            'code' => $lab->code,
            'city' => $lab->city,
            'province' => $lab->province,
            'municipality_id' => $lab->municipality_id !== null ? (int) $lab->municipality_id : null,
            'barangay_id' => $lab->barangay_id !== null ? (int) $lab->barangay_id : null,
            'barangay_name' => $lab->barangay_id !== null ? ($barangayNamesById[(int) $lab->barangay_id] ?? null) : null,
            'latitude' => $lab->latitude !== null ? (float) $lab->latitude : null,
            'longitude' => $lab->longitude !== null ? (float) $lab->longitude : null,
            'is_mapped_to_municipality_id' => $isMappedToLocation,
            'is_unmapped_barangay' => $lab->barangay_id === null,
            'is_unmapped_coordinates' => $lab->latitude === null || $lab->longitude === null,
            'total_services' => $services->count() ?: (int) ($lab->services_count ?? 0),
            'services_sample' => $services->take(15)->all(),
        ];
    }

    private function cleanLocationToken(string $rawName): string
    {
        $spaced = strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $rawName));

        return trim(str_ireplace(['city of', 'municipality of', 'city'], '', $spaced));
    }
}
