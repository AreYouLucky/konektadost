<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\AgricultureCountEstimate;
use App\Models\Barangay;
use App\Models\CollectionYear;
use App\Models\DostProject;
use App\Models\Facility;
use App\Models\Laboratory;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\TelecomSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer A (A3) Function Tool: get_available_datasets
 *
 * Audits all domain tables for a selected location and classifies each dataset
 * as `available`, `partial`, `empty`, or `unavailable`, reporting record counts,
 * barangays represented, unmapped record counts, and completeness notes.
 */
class GetAvailableDatasetsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'get_available_datasets';
    }

    public function layer(): string
    {
        return 'A';
    }

    public function description(): string
    {
        return 'Audit which domain datasets exist for the selected municipality, their status (available, partial, empty, unavailable), local and national record counts, number of barangays represented, and data completeness notes.';
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
     * Audit all domain tables for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        $level = LocationResolver::levelOf($location);
        $psgCode = (string) ($location->psg_code ?? '');
        $prefix = LocationResolver::psgcPrefixFor($level, $psgCode);
        $activeYear = Schema::hasTable('collection_years') ? CollectionYear::activeYear() : null;

        $totalBarangays = $this->totalBarangaysFor($location);
        $datasets = [];

        // 1. Population Figures (population_figures)
        $popTable = Schema::hasTable('population_figures');
        $popNational = $popTable ? (int) DB::table('population_figures')->count() : null;
        $popLocal = 0;
        $popBgysRepresented = 0;

        if ($popTable && $psgCode !== '') {
            $popLocal = (int) DB::table('population_figures')
                ->where(fn ($q) => $prefix !== '' ? $q->where('psg_code', 'like', $prefix.'%') : $q->where('psg_code', $psgCode))
                ->whereNotNull('population')
                ->count();

            if ($level !== 'barangay' && $prefix !== '') {
                $popBgysRepresented = (int) DB::table('population_figures')
                    ->where('psg_code', 'like', $prefix.'%')
                    ->where('psg_code', '<>', $psgCode)
                    ->where('year', $activeYear ?? 2024)
                    ->whereNotNull('population')
                    ->distinct('psg_code')
                    ->count('psg_code');
            } elseif ($level === 'barangay' && $popLocal > 0) {
                $popBgysRepresented = 1;
            }
        }

        $popStatus = ! $popTable
            ? 'unavailable'
            : ($popLocal === 0
                ? 'empty'
                : (($totalBarangays > 0 && $popBgysRepresented < $totalBarangays) ? 'partial' : 'available'));

        $datasets['population_figures'] = [
            'key' => 'population_figures',
            'label' => 'PSA Census Population Figures (2020 & 2024)',
            'table' => 'population_figures',
            'status' => $popStatus,
            'local_records' => $popTable ? $popLocal : null,
            'national_records' => $popNational,
            'barangays_represented' => $popBgysRepresented,
            'total_barangays' => $totalBarangays,
            'unmapped_local_records' => 0,
            'reference_year' => $activeYear ? "2020, {$activeYear}" : '2020, 2024',
            'notes' => $popLocal > 0
                ? "{$popLocal} census population records matched ({$popBgysRepresented}/{$totalBarangays} barangays have {$activeYear} population)."
                : 'No matched census population records for this scope.',
        ];

        // 2. Administrative & Urban/Rural Classifications (place_descriptions)
        $pdTable = Schema::hasTable('place_descriptions');
        $pdNational = $pdTable ? (int) DB::table('place_descriptions')->count() : null;
        $pdLocal = $pdTable && $psgCode !== ''
            ? (int) DB::table('place_descriptions')
                ->where(fn ($q) => $prefix !== '' ? $q->where('psg_code', 'like', $prefix.'%') : $q->where('psg_code', $psgCode))
                ->count()
            : 0;
        $pdBgysRepresented = $pdTable && $prefix !== '' && $level !== 'barangay'
            ? (int) DB::table('place_descriptions')
                ->where('level', 'Bgy')
                ->where('psg_code', 'like', $prefix.'%')
                ->distinct('psg_code')
                ->count('psg_code')
            : ($level === 'barangay' && $pdLocal > 0 ? 1 : 0);

        $datasets['place_descriptions'] = [
            'key' => 'place_descriptions',
            'label' => 'PSGC Administrative & Urban/Rural Classifications',
            'table' => 'place_descriptions',
            'status' => ! $pdTable ? 'unavailable' : ($pdLocal > 0 ? 'available' : 'empty'),
            'local_records' => $pdTable ? $pdLocal : null,
            'national_records' => $pdNational,
            'barangays_represented' => $pdBgysRepresented,
            'total_barangays' => $totalBarangays,
            'unmapped_local_records' => 0,
            'reference_year' => 'PSGC Release',
            'notes' => $pdLocal > 0
                ? "Income class, city class, and barangay urban/rural classifications available ({$pdBgysRepresented}/{$totalBarangays} barangays)."
                : 'No PSGC classification records matched.',
        ];

        // 3. Public Facilities (facilities: health, education, safety)
        $facTable = Schema::hasTable('facilities');
        $facNational = $facTable ? (int) DB::table('facilities')->count() : null;
        $facQuery = $facTable ? $this->facilityQueryFor($location) : null;
        $facLocal = $facQuery !== null ? (clone $facQuery)->count() : null;
        $facBgysRepresented = $facQuery !== null
            ? (int) (clone $facQuery)->whereNotNull('barangay_id')->distinct('barangay_id')->count('barangay_id')
            : 0;
        $facUnmappedBgy = $facQuery !== null
            ? (int) (clone $facQuery)->whereNull('barangay_id')->count()
            : 0;

        $facStatus = ! $facTable
            ? 'unavailable'
            : (($facLocal ?? 0) === 0
                ? 'empty'
                : ($facUnmappedBgy > 0 ? 'partial' : 'available'));

        $datasets['facilities'] = [
            'key' => 'facilities',
            'label' => 'Public Facilities (Health, Education, Safety)',
            'table' => 'facilities',
            'status' => $facStatus,
            'local_records' => $facLocal,
            'national_records' => $facNational,
            'barangays_represented' => $facBgysRepresented,
            'total_barangays' => $totalBarangays,
            'unmapped_local_records' => $facUnmappedBgy,
            'reference_year' => 'OpenStreetMap / Imported',
            'notes' => ($facLocal ?? 0) > 0
                ? sprintf(
                    '%d local facilities across %d/%d barangays%s.',
                    $facLocal,
                    $facBgysRepresented,
                    $totalBarangays,
                    $facUnmappedBgy > 0 ? " ({$facUnmappedBgy} missing barangay_id assignment)" : ''
                )
                : '0 facilities recorded locally in the current table (crowdsourced OpenStreetMap coverage may be incomplete).',
        ];

        // 4. Accredited Laboratories & Services (laboratories + lab_services)
        $labTable = Schema::hasTable('laboratories');
        $labNational = $labTable ? (int) DB::table('laboratories')->count() : null;
        $labQuery = $labTable ? $this->laboratoryQueryFor($location) : null;
        $labLocal = $labQuery !== null ? (clone $labQuery)->count() : null;
        $labBgysRepresented = $labQuery !== null
            ? (int) (clone $labQuery)->whereNotNull('barangay_id')->distinct('barangay_id')->count('barangay_id')
            : 0;
        $labUnmappedTextMatches = $labTable ? $this->unmappedTextMatchedLabsCount($location) : 0;
        $svcNational = Schema::hasTable('lab_services') ? (int) DB::table('lab_services')->count() : 0;

        $labStatus = ! $labTable
            ? 'unavailable'
            : (($labLocal ?? 0) > 0
                ? ($labUnmappedTextMatches > 0 ? 'partial' : 'available')
                : ($labUnmappedTextMatches > 0 ? 'partial' : 'empty'));

        $datasets['laboratories'] = [
            'key' => 'laboratories',
            'label' => 'DOST / OneLab Accredited Laboratories & Testing Services',
            'table' => 'laboratories',
            'status' => $labStatus,
            'local_records' => $labLocal,
            'national_records' => $labNational,
            'barangays_represented' => $labBgysRepresented,
            'total_barangays' => $totalBarangays,
            'unmapped_local_records' => $labUnmappedTextMatches,
            'reference_year' => 'OneLab Registry',
            'notes' => ($labLocal ?? 0) > 0 || $labUnmappedTextMatches > 0
                ? sprintf(
                    '%d mapped laboratories inside this boundary%s (%d total test rows in national registry).',
                    $labLocal ?? 0,
                    $labUnmappedTextMatches > 0 ? " plus {$labUnmappedTextMatches} unmapped city-text match(es) missing municipality_id" : '',
                    $svcNational
                )
                : "0 accredited laboratories inside this boundary ({$labNational} laboratories in national registry).",
        ];

        // 5. Telecom Cell Sites (telecom_sites)
        $telTable = Schema::hasTable('telecom_sites');
        $telNational = $telTable ? (int) DB::table('telecom_sites')->count() : null;
        $telQuery = $telTable ? $this->telecomQueryFor($location) : null;
        $telLocal = $telQuery !== null ? (clone $telQuery)->count() : null;
        $telBgysRepresented = $telQuery !== null
            ? (int) (clone $telQuery)->whereNotNull('barangay_id')->distinct('barangay_id')->count('barangay_id')
            : 0;
        $telUnmappedBgy = $telQuery !== null
            ? (int) (clone $telQuery)->whereNull('barangay_id')->count()
            : 0;

        $telStatus = ! $telTable
            ? 'unavailable'
            : (($telLocal ?? 0) === 0
                ? 'empty'
                : ($telUnmappedBgy > 0 ? 'partial' : 'available'));

        $datasets['telecom_sites'] = [
            'key' => 'telecom_sites',
            'label' => 'Telecommunications Cell Sites & 5G Inventory',
            'table' => 'telecom_sites',
            'status' => $telStatus,
            'local_records' => $telLocal,
            'national_records' => $telNational,
            'barangays_represented' => $telBgysRepresented,
            'total_barangays' => $totalBarangays,
            'unmapped_local_records' => $telUnmappedBgy,
            'reference_year' => 'Workbook Import',
            'notes' => ($telLocal ?? 0) > 0
                ? sprintf(
                    '%d cell sites across %d/%d barangays%s.',
                    $telLocal,
                    $telBgysRepresented,
                    $totalBarangays,
                    $telUnmappedBgy > 0 ? " ({$telUnmappedBgy} missing barangay_id)" : ''
                )
                : '0 cell sites recorded in telecom_sites for this municipality (dataset currently covers 3 imported Bohol municipalities; treat as unimported rather than verified zero coverage).',
        ];

        // 6. Agriculture Count Estimates (agriculture_count_estimates)
        $agriTable = Schema::hasTable('agriculture_count_estimates');
        $agriNational = $agriTable ? (int) DB::table('agriculture_count_estimates')->count() : null;
        $agriQuery = $agriTable ? $this->agricultureQueryFor($location) : null;
        $agriLocal = $agriQuery !== null ? (clone $agriQuery)->count() : null;
        $agriYear = $agriQuery !== null && ($agriLocal ?? 0) > 0 ? (clone $agriQuery)->max('model_year') : null;
        $agriBgysRepresented = $agriQuery !== null && ($agriLocal ?? 0) > 0
            ? (int) (clone $agriQuery)->whereNotNull('barangay_id')->distinct('barangay_id')->count('barangay_id')
            : 0;
        $agriUnmappedBgy = $agriQuery !== null && ($agriLocal ?? 0) > 0
            ? (int) (clone $agriQuery)->whereNull('barangay_id')->count()
            : 0;

        $agriStatus = ! $agriTable
            ? 'unavailable'
            : (($agriLocal ?? 0) === 0
                ? 'empty'
                : ($agriUnmappedBgy > 0 ? 'partial' : 'available'));

        $datasets['agriculture_count_estimates'] = [
            'key' => 'agriculture_count_estimates',
            'label' => 'Agriculture, Fisheries & Livestock Estimates',
            'table' => 'agriculture_count_estimates',
            'status' => $agriStatus,
            'local_records' => $agriLocal,
            'national_records' => $agriNational,
            'barangays_represented' => $agriBgysRepresented,
            'total_barangays' => $totalBarangays,
            'unmapped_local_records' => $agriUnmappedBgy,
            'reference_year' => $agriYear,
            'notes' => ($agriLocal ?? 0) > 0
                ? sprintf(
                    '%d commodity estimate records across %d/%d barangays%s.',
                    $agriLocal,
                    $agriBgysRepresented,
                    $totalBarangays,
                    $agriUnmappedBgy > 0 ? " ({$agriUnmappedBgy} missing barangay_id)" : ''
                )
                : '0 agriculture estimate records for this municipality (dataset currently covers 17 imported Bohol municipalities).',
        ];

        // 7. DOST Projects & Technology Catalogs (dost_projects, dost_techs, technologies)
        $projTable = Schema::hasTable('dost_projects');
        $projNational = $projTable ? (int) DB::table('dost_projects')->count() : 0;
        $techNational = (Schema::hasTable('dost_techs') ? (int) DB::table('dost_techs')->count() : 0)
            + (Schema::hasTable('technologies') ? (int) DB::table('technologies')->count() : 0);
        $localProjectsCount = $projTable ? $this->localDostProjectsCount($location) : 0;

        $datasets['dost_catalog'] = [
            'key' => 'dost_catalog',
            'label' => 'DOST Projects & STI Technology Catalogs',
            'table' => 'dost_projects, dost_techs, technologies',
            'status' => ($projNational + $techNational) > 0 ? 'available' : 'unavailable',
            'local_records' => $localProjectsCount,
            'national_records' => $projNational + $techNational,
            'barangays_represented' => null,
            'total_barangays' => $totalBarangays,
            'unmapped_local_records' => 0,
            'reference_year' => 'DOST Masterlist',
            'notes' => "{$projNational} DOST agency projects and {$techNational} catalog technologies available ({$localProjectsCount} with local location text match).",
        ];

        // 8. Road Network (roads + road_barangay)
        $roadsTable = Schema::hasTable('roads');
        $roadsNational = $roadsTable ? (int) DB::table('roads')->count() : null;
        $roadsLocal = $roadsTable ? $this->roadsLocalCount($location) : null;

        $roadsStatus = ! $roadsTable
            ? 'unavailable'
            : (($roadsNational ?? 0) === 0 || ($roadsLocal ?? 0) === 0 ? 'empty' : 'available');

        $datasets['roads'] = [
            'key' => 'roads',
            'label' => 'Road Network & Barangay Road Links',
            'table' => 'roads, road_barangay',
            'status' => $roadsStatus,
            'local_records' => $roadsLocal,
            'national_records' => $roadsNational,
            'barangays_represented' => 0,
            'total_barangays' => $totalBarangays,
            'unmapped_local_records' => 0,
            'reference_year' => null,
            'notes' => ! $roadsTable
                ? 'Road network tables are not present in the database.'
                : (($roadsNational ?? 0) === 0
                    ? 'The `roads` and `road_barangay` tables exist in the database schema but currently contain 0 records.'
                    : "{$roadsLocal} road segment records matched locally."),
        ];

        $availableCount = count(array_filter($datasets, fn ($d) => $d['status'] === 'available'));
        $partialCount = count(array_filter($datasets, fn ($d) => $d['status'] === 'partial'));
        $emptyCount = count(array_filter($datasets, fn ($d) => $d['status'] === 'empty'));
        $unavailableCount = count(array_filter($datasets, fn ($d) => $d['status'] === 'unavailable'));

        return [
            'location_level' => $level,
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'psg_code' => $psgCode !== '' ? $psgCode : null,
            'summary' => [
                'available_count' => $availableCount,
                'partial_count' => $partialCount,
                'empty_count' => $emptyCount,
                'unavailable_count' => $unavailableCount,
                'total_datasets' => count($datasets),
            ],
            'datasets' => $datasets,
        ];
    }

    public function facilityQueryFor(Model $location)
    {
        if ($location instanceof Barangay) {
            return Facility::query()->where('barangay_id', $location->id);
        }

        if ($location instanceof Municipality) {
            return Facility::query()->where('municipality_id', $location->id);
        }

        if ($location instanceof Province) {
            $munIds = Municipality::query()->where('province_id', $location->id)->pluck('id');

            return Facility::query()->whereIn('municipality_id', $munIds);
        }

        return Facility::query()->whereRaw('1 = 0');
    }

    public function laboratoryQueryFor(Model $location)
    {
        if ($location instanceof Barangay) {
            return Laboratory::query()->inPhilippines()->where('barangay_id', $location->id);
        }

        if ($location instanceof Municipality) {
            return Laboratory::query()->inPhilippines()->where('municipality_id', $location->id);
        }

        if ($location instanceof Province) {
            $munIds = Municipality::query()->where('province_id', $location->id)->pluck('id');

            return Laboratory::query()->inPhilippines()->whereIn('municipality_id', $munIds);
        }

        return Laboratory::query()->whereRaw('1 = 0');
    }

    public function telecomQueryFor(Model $location)
    {
        $psgCode = (string) ($location->psg_code ?? '');
        $level = LocationResolver::levelOf($location);
        $prefix = LocationResolver::psgcPrefixFor($level, $psgCode);

        return TelecomSite::query()->where(function ($q) use ($location, $psgCode, $prefix) {
            if ($location instanceof Barangay) {
                $q->where('barangay_id', $location->id);
                if ($psgCode !== '') {
                    $q->orWhere('barangay_psgc', $psgCode);
                }
            } elseif ($location instanceof Municipality) {
                $q->where('municipality_id', $location->id);
                if ($prefix !== '') {
                    $q->orWhere('barangay_psgc', 'like', $prefix.'%');
                }
            } elseif ($location instanceof Province) {
                $munIds = Municipality::query()->where('province_id', $location->id)->pluck('id');
                $q->whereIn('municipality_id', $munIds);
                if ($prefix !== '') {
                    $q->orWhere('barangay_psgc', 'like', $prefix.'%');
                }
            } else {
                $q->whereRaw('1 = 0');
            }
        });
    }

    public function agricultureQueryFor(Model $location)
    {
        if ($location instanceof Barangay) {
            return AgricultureCountEstimate::query()->where('barangay_id', $location->id);
        }

        if ($location instanceof Municipality) {
            return AgricultureCountEstimate::query()->where('municipality_id', $location->id);
        }

        if ($location instanceof Province) {
            $munIds = Municipality::query()->where('province_id', $location->id)->pluck('id');

            return AgricultureCountEstimate::query()->where(function ($q) use ($location, $munIds) {
                $q->where('province_id', $location->id)
                    ->orWhereIn('municipality_id', $munIds);
            });
        }

        return AgricultureCountEstimate::query()->whereRaw('1 = 0');
    }

    private function totalBarangaysFor(Model $location): int
    {
        if (! Schema::hasTable('barangays')) {
            return 0;
        }

        if ($location instanceof Barangay) {
            return 1;
        }

        if (! Schema::hasColumn('barangays', 'municipality_id')) {
            return 0;
        }

        if ($location instanceof Municipality) {
            return Barangay::query()->where('municipality_id', $location->id)->count();
        }

        if ($location instanceof Province) {
            $munIds = Municipality::query()->where('province_id', $location->id)->pluck('id');

            return Barangay::query()->whereIn('municipality_id', $munIds)->count();
        }

        return 0;
    }

    private function unmappedTextMatchedLabsCount(Model $location): int
    {
        if ($location instanceof Barangay) {
            return 0;
        }

        $token = $this->cleanLocationToken(LocationResolver::nameOf($location));
        if ($token === '') {
            return 0;
        }

        return Laboratory::query()
            ->whereNull('municipality_id')
            ->whereNotNull('city')
            ->where('city', 'like', '%'.$token.'%')
            ->count();
    }

    private function roadsLocalCount(Model $location): int
    {
        if (! Schema::hasTable('roads')) {
            return 0;
        }

        if ($location instanceof Municipality) {
            return (int) DB::table('roads')->where('municipality_id', $location->id)->count();
        }

        if ($location instanceof Barangay && Schema::hasTable('road_barangay')) {
            return (int) DB::table('road_barangay')->where('barangay_id', $location->id)->count();
        }

        if ($location instanceof Province) {
            $munIds = Municipality::query()->where('province_id', $location->id)->pluck('id');

            return (int) DB::table('roads')->whereIn('municipality_id', $munIds)->count();
        }

        return 0;
    }

    private function localDostProjectsCount(Model $location): int
    {
        $name = $this->cleanLocationToken(LocationResolver::nameOf($location));
        if ($name === '') {
            return 0;
        }

        return DostProject::query()
            ->where('location', 'like', '%'.$name.'%')
            ->count();
    }

    private function cleanLocationToken(string $rawName): string
    {
        $spaced = strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $rawName));

        return trim(str_ireplace(['city of', 'municipality of', 'city'], '', $spaced));
    }
}
