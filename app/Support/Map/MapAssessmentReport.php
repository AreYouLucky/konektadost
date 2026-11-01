<?php

namespace App\Support\Map;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use App\Support\Assessment\AssessmentFunctionTools;
use App\Support\Assessment\IndicatorCatalog;
use App\Support\Assessment\MunicipalityProfileService;
use App\Support\Assessment\PrioritizationService;
use App\Support\Assessment\ProjectCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Page props for the existing assessment screen, scoped through verified explorer geography. */
class MapAssessmentReport
{
    public function props(Request $request): array
    {
        $data = $request->validate([
            'level' => ['required', Rule::in(['province', 'municipality', 'barangay'])],
            'location' => ['required', 'integer', 'min:1'],
            'focus' => ['sometimes', Rule::in(['general', ...IndicatorCatalog::supportedDimensions()])],
        ]);
        $geography = app(GeographyExplorer::class);
        $path = $geography->selection($data['level'], (int) $data['location'], false)['path'];
        $province = Province::query()->findOrFail($path[0]['id']);
        $scope = GeographicScope::fromRequest($request, $province, $geography);
        $resources = app(ProvinceResources::class)->summary($province, $geography, $scope);
        $focus = $data['focus'] ?? 'general';
        $results = array_values(array_filter($resources['assessment']['results'], fn ($row) => $focus === 'general' || $row['dimension'] === $focus));
        $table = match ($scope->level) {
            'province' => 'provinces', 'municipality' => 'municipalities', default => 'barangays'
        };
        $code = Schema::hasColumn($table, 'psg_code') ? DB::table($table)->where('id', $scope->id)->value('psg_code') : null;
        $locationModel = match ($scope->level) {
            'province' => $province,
            'municipality' => Municipality::query()->find($scope->id),
            default => Barangay::query()->find($scope->id),
        };
        $profile = $locationModel !== null ? app(MunicipalityProfileService::class)->getMunicipalityProfile($locationModel) : null;
        $functionToolsBundle = $locationModel !== null ? app(AssessmentFunctionTools::class)->computeLocationBundle($locationModel) : null;
        $barangayAssessment = $locationModel instanceof Municipality
            ? app(PrioritizationService::class)->calculatePriorityScores($locationModel, $focus)
            : null;
        $layerEResult = $locationModel instanceof Municipality
            ? app(\App\FunctionTools\FunctionToolRegistry::class)->executeLayerE($locationModel)
            : null;

        return [
            'location' => [
                'psg_code' => $code,
                'name' => $scope->name,
                'type' => ucfirst($scope->level),
                'province' => $path[0]['name'],
                'population' => $resources['population']['value'],
                'area_km2' => $resources['assessment']['area_km2'],
                'income_class' => $profile['income_class'] ?? null,
                'city_class' => $profile['city_class'] ?? null,
                'urban_rural' => $profile['urban_rural'] ?? null,
                'barangay_counts' => $profile['barangay_counts'] ?? null,
                'population_statistics' => $profile['population_statistics'] ?? null,
            ],
            'municipalityProfile' => $profile,
            'functionToolsBundle' => $functionToolsBundle,
            'layerEResult' => $layerEResult,
            'barangayAssessment' => $barangayAssessment,
            'populationYear' => $resources['population']['year'], 'populationReason' => $resources['population']['reason'],
            'focus' => $focus, 'dimensions' => IndicatorCatalog::dimensions(), 'results' => $results,
            'assessmentParams' => ['level' => $scope->level, 'location' => $scope->id],
            'mapReturnUrl' => route('admin.search-map.index', ['level' => $scope->level, 'location' => $scope->id]),
            'resourceSummary' => $resources,
            'agricultureSummary' => app(ProvinceAgriculture::class)->summary($scope->provinceId, $scope),
            'localLaboratories' => $this->laboratories($resources),
            'projectCatalog' => app(ProjectCatalog::class)->summary(),
            'dostInterventions' => array_map(fn ($row) => [...$row, 'matches_city_cluster' => true, 'matches_focus' => $focus === 'general'], $resources['technologies']['records']),
        ];
    }

    private function laboratories(array $resources): array
    {
        $labs = collect($resources['records'])->where('category', 'laboratory');
        $ids = $labs->map(fn ($row) => (int) substr($row['key'], strlen('laboratory:')));
        $columns = Schema::hasTable('lab_services') ? Schema::getColumnListing('lab_services') : [];
        $nameCol = in_array('service_name', $columns, true) ? 'service_name' : (in_array('test_name', $columns, true) ? 'test_name' : null);
        $methodCol = in_array('description', $columns, true) ? 'description' : (in_array('method', $columns, true) ? 'method' : null);
        $hasServices = in_array('laboratory_id', $columns, true) && $nameCol !== null;

        $services = $hasServices ? DB::table('lab_services')->whereIn('laboratory_id', $ids)
            ->whereNotNull($nameCol)->where($nameCol, '<>', '')
            ->get(array_values(array_intersect($columns, array_filter(['laboratory_id', $nameCol, $methodCol, 'reference', 'fee']))))
            ->groupBy('laboratory_id') : collect();

        return $labs->map(function ($row) use ($hasServices, $services, $nameCol, $methodCol) {
            $id = (int) substr($row['key'], strlen('laboratory:'));
            $tests = $services->get($id, collect())->unique(fn ($test) => json_encode([
                $nameCol ? ($test->{$nameCol} ?? null) : null,
                $methodCol ? ($test->{$methodCol} ?? null) : null,
            ]))->values();

            return [
                'id' => $id, 'agency_name' => $row['name'], 'code' => $row['metadata']['code'], 'city' => $row['municipality'],
                'website' => $row['metadata']['website'], 'contact_information' => $row['metadata']['contact'],
                'total_services' => $hasServices ? $tests->count() : $row['metadata']['source_listed_service_count'],
                'services_status' => $hasServices ? 'available' : 'unavailable',
                'services' => $tests->take(12)->map(fn ($test) => [
                    'test_name' => $nameCol ? ($test->{$nameCol} ?? null) : null,
                    'method' => $methodCol ? ($test->{$methodCol} ?? null) : null,
                    'reference' => $test->reference ?? null,
                    'fee' => isset($test->fee) ? (float) $test->fee : null,
                ])->all(),
            ];
        })->values()->all();
    }
}
