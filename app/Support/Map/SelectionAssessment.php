<?php

namespace App\Support\Map;

use App\Models\TelecomSite;
use App\Support\Assessment\IndicatorResult;
use App\Support\Assessment\LocationAssessor;
use App\Support\Geometry\PolygonArea;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Assess only the inventory and population belonging to the exact selected area. */
class SelectionAssessment
{
    public function summary(GeographicScope $scope, array $population, array $inventories, array $municipalityIds, Builder $barangays): array
    {
        $counts = [];
        $unavailable = [];
        $facilities = $inventories['facilities'] ?? null;
        $labs = $inventories['laboratories'] ?? null;
        foreach (['health_facilities_per_100k' => ['sector', 'health'], 'hospitals_per_100k' => ['facility_type', 'hospital'],
            'pharmacies_per_100k' => ['facility_type', 'pharmacy'], 'schools_per_100k' => ['sector', 'education'],
            'elementary_schools_per_100k' => ['level', 'elementary'], 'safety_facilities_per_100k' => ['sector', 'safety']] as $key => [$column, $value]) {
            if ($facilities === null || ! Schema::hasColumn('facilities', $column)) {
                $unavailable[$key] = 'The required facility dataset or classification is unavailable.';
            } else {
                $counts[$key] = $facilities->where($column, $value)->count();
            }
        }
        $schools = $facilities?->where('sector', 'education') ?? collect();
        $counts['unknown_education_share'] = $schools->isEmpty() ? 0 : $schools->filter(fn ($row) => in_array($row->level ?? null, [null, '', 'unknown'], true))->count() / $schools->count();
        if ($labs === null) {
            $unavailable['laboratories_per_million'] = 'The laboratory dataset is unavailable.';
        } else {
            $counts['laboratories_per_million'] = $labs->count();
        }
        if ($labs === null || ! Schema::hasColumns('lab_services', ['laboratory_id', 'test_name', 'method'])) {
            $unavailable['laboratory_tests_per_million'] = 'Normalized laboratory test and method records are unavailable.';
        } else {
            $counts['laboratory_tests_per_million'] = DB::table('lab_services')->whereIn('laboratory_id', $labs->pluck('id'))
                ->whereNotNull('test_name')->where('test_name', '<>', '')->select('test_name', 'method')->distinct()->get()->count();
        }
        if (! Schema::hasColumns('telecom_sites', ['municipality_id', 'barangay_id'])) {
            $unavailable['telecom_sites_per_100k'] = 'No compatible telecom inventory is available.';
            $unavailable['telecom_5g_share'] = 'No compatible telecom inventory is available.';
        } else {
            $sites = app(TelecomResources::class)->query($scope, $municipalityIds, $barangays);
            $counts['telecom_sites_per_100k'] = (clone $sites)->count();
            if (! Schema::hasColumn('telecom_sites', 'technologies') || $counts['telecom_sites_per_100k'] === 0) {
                $unavailable['telecom_5g_share'] = 'No recorded sites with technology data are available for a 5G comparison.';
            } else {
                $rows = $sites->get(['technologies']);
                // Unknown technology records must not be interpreted as sites without 5G.
                if ($rows->contains(fn (TelecomSite $site) => $site->technologyList() === [])) {
                    $unavailable['telecom_5g_share'] = 'Some recorded sites have no technology classification; 5G share is unavailable.';
                } else {
                    $counts['telecom_5g_sites'] = $rows->filter(fn (TelecomSite $site) => $site->supports('5G NR') || $site->supports('5G'))->count();
                    $counts['telecom_5g_share'] = round($counts['telecom_5g_sites'] / $rows->count() * 100, 1);
                }
            }
        }
        $boundary = app(GeographyExplorer::class)->selection($scope->level, $scope->id)['boundary'];
        $area = PolygonArea::ofGeometry($boundary === null ? null : json_encode($boundary['geometry']));
        if ($area <= 0) {
            $unavailable['population_density'] = 'No usable boundary polygon is available to calculate land area.';
        }
        $results = app(LocationAssessor::class)->assessSnapshot($population['value'], $area > 0 ? $area : null, $counts, $unavailable);

        return [
            'area_km2' => $area > 0 ? round($area, 3) : null,
            'results' => array_map(fn (IndicatorResult $result) => [...$result->toArray(), 'basis_note' => $result->basisNote], $results),
        ];
    }
}
