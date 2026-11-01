<?php

namespace App\Support\Map;

use App\Models\CollectionYear;
use App\Models\PlaceDescription;
use App\Models\PopulationFigure;
use App\Models\Province;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProvinceResources
{
    public function summary(Province $province, GeographyExplorer $geography, ?GeographicScope $scope = null): array
    {
        $scope ??= GeographicScope::province($province);
        $municipalities = collect($geography->locations('municipality', (int) $province->id)['locations'])->keyBy('id');
        if ($scope->municipalityId !== null) {
            $municipalities = $municipalities->only([$scope->municipalityId]);
        }
        $ids = $municipalities->keys()->all();
        $barangays = DB::table('barangays')->select('id');
        if (Schema::hasColumn('barangays', 'municipality_id')) {
            $barangays->whereIn('municipality_id', $ids);
        } elseif (Schema::hasColumn('barangays', 'municipality_gadm_id') && Schema::hasColumn('municipalities', 'gadm_id')) {
            $barangays->whereIn('municipality_gadm_id', DB::table('municipalities')->select('gadm_id')->whereIn('id', $ids));
        } else {
            $barangays->whereRaw('1 = 0');
        }

        $records = [];
        $summaries = [];
        $inventories = [];
        foreach (['facilities', 'laboratories'] as $table) {
            if (! Schema::hasTable($table)) {
                $summaries[$table] = ['status' => 'unavailable', 'count' => null, 'pinned_count' => 0, 'by_category' => []];

                continue;
            }
            $columns = Schema::getColumnListing($table);
            $query = DB::table($table)->where(function (Builder $query) use ($ids, $barangays) {
                $query->whereIn('municipality_id', $ids)->orWhere(function (Builder $query) use ($barangays) {
                    $query->whereNull('municipality_id')->whereIn('barangay_id', clone $barangays);
                });
            });
            if ($scope->barangayId !== null) {
                $query->where('barangay_id', $scope->barangayId);
            }
            if ($table === 'laboratories' && in_array('is_outside_philippines', $columns, true)) {
                $query->where(fn (Builder $query) => $query->where('is_outside_philippines', false)->orWhereNull('is_outside_philippines'));
            }
            $rows = $query->get(array_values(array_intersect($columns, [
                'id', 'name', 'agency_name', 'address', 'municipality_id', 'barangay_id', 'sector', 'facility_type', 'latitude', 'longitude', 'source',
                'operator', 'level', 'capacity', 'capacity_no', 'description', 'code', 'website', 'contact_information',
                'source_url', 'source_record_id', 'services_count', 'updated_at',
            ])));
            $inventories[$table] = $rows;
            $serviceCounts = $table === 'laboratories' && Schema::hasTable('lab_services') && Schema::hasColumn('lab_services', 'laboratory_id')
                ? DB::table('lab_services')->whereIn('laboratory_id', $rows->pluck('id'))
                    ->select('laboratory_id')->selectRaw('COUNT(*) AS total')->groupBy('laboratory_id')->pluck('total', 'laboratory_id')
                : null;
            $pinned = 0;
            $categories = [];
            $barangayParents = $this->barangayMunicipalities(
                $rows->filter(fn ($row) => $row->municipality_id === null)->pluck('barangay_id')->filter()->unique()->all(),
                $ids,
            );
            foreach ($rows as $row) {
                $municipalityId = $row->municipality_id ?? $barangayParents->get($row->barangay_id ?? null);
                $laboratory = $table === 'laboratories';
                $category = $laboratory ? 'laboratory' : (in_array($row->sector ?? null, ['health', 'education', 'safety'], true) ? $row->sector : 'facility');
                $coordinates = $this->coordinates($row->longitude ?? null, $row->latitude ?? null);
                $pinned += $coordinates === null ? 0 : 1;
                $categories[$category] = ($categories[$category] ?? 0) + 1;
                $records[] = [
                    'key' => ($laboratory ? 'laboratory:' : 'facility:').$row->id,
                    'name' => trim((string) ($laboratory ? ($row->agency_name ?? '') : ($row->name ?? ''))) ?: ($laboratory ? 'Unnamed laboratory' : 'Unnamed facility'),
                    'category' => $category,
                    'barangay_id' => isset($row->barangay_id) ? (int) $row->barangay_id : null,
                    'municipality_id' => $municipalityId === null ? null : (int) $municipalityId,
                    'coordinates' => $coordinates,
                    'detail' => $laboratory ? 'Laboratory' : ucwords(str_replace('_', ' ', $row->facility_type ?? 'Facility')),
                    'municipality' => $municipalities->get($municipalityId)['name'] ?? null,
                    'address' => $row->address ?? null,
                    'source' => $row->source ?? null,
                    'metadata' => [
                        'operator' => $row->operator ?? null,
                        'level' => $row->level ?? null,
                        'capacity' => $row->capacity ?? null,
                        'capacity_no' => $row->capacity_no ?? null,
                        'description' => $row->description ?? null,
                        'code' => $row->code ?? null,
                        'website' => $row->website ?? null,
                        'contact' => $row->contact_information ?? null,
                        'source_url' => $row->source_url ?? null,
                        'source_record_id' => isset($row->source_record_id) ? (string) $row->source_record_id : null,
                        'record_updated_at' => $row->updated_at ?? null,
                        'catalog_service_count' => $laboratory && $serviceCounts !== null ? (int) ($serviceCounts->get($row->id) ?? 0) : null,
                        'source_listed_service_count' => $laboratory && isset($row->services_count) ? (int) $row->services_count : null,
                    ],
                ];
            }
            $summaries[$table] = ['status' => 'available', 'count' => $rows->count(), 'pinned_count' => $pinned, 'by_category' => $categories];
        }

        $population = $this->population($scope);
        $barangayParents = $this->barangayMunicipalities((clone $barangays)->pluck('id')->all(), $ids);

        return [
            'province_id' => (int) $province->id,
            'scope' => $scope->toArray(),
            'population' => $population,
            'population_breakdown' => $this->populationBreakdown($scope, $geography, $population),
            'technologies' => app(DostTechnologyLocations::class)->summary($scope, $municipalities),
            'telecom_sites' => app(TelecomResources::class)->summary($scope, $municipalities, $barangays, $barangayParents),
            'assessment' => app(SelectionAssessment::class)->summary($scope, $population, $inventories, $ids, $barangays),
            ...$summaries,
            'records' => $records,
        ];
    }

    private function barangayMunicipalities(array $barangayIds, array $municipalityIds): Collection
    {
        if ($barangayIds === []) {
            return collect();
        }
        $query = DB::table('barangays')->whereIn('id', $barangayIds);
        if (Schema::hasColumn('barangays', 'municipality_id')) {
            return $query->whereIn('municipality_id', $municipalityIds)->pluck('municipality_id', 'id');
        }
        if (Schema::hasColumn('barangays', 'municipality_gadm_id') && Schema::hasColumn('municipalities', 'gadm_id')) {
            $parents = DB::table('municipalities')->whereIn('id', $municipalityIds)->pluck('id', 'gadm_id');

            return $query->whereIn('municipality_gadm_id', $parents->keys())
                ->pluck('municipality_gadm_id', 'id')->map(fn ($gadmId) => $parents->get($gadmId));
        }

        return collect();
    }

    private function populationBreakdown(GeographicScope $scope, GeographyExplorer $geography, array $population): array
    {
        $level = $scope->level === 'province' ? 'municipality' : 'barangay';
        $places = $scope->level === 'barangay'
            ? [['id' => $scope->id, 'name' => $scope->name]]
            : $geography->locations($level, $scope->id)['locations'];
        $table = $level === 'municipality' ? 'municipalities' : 'barangays';
        $values = collect();
        if ($population['year'] !== null && Schema::hasColumn($table, 'psg_code')
            && Schema::hasTable('place_descriptions') && Schema::hasColumn('population_figures', 'place_description_id')) {
            $codes = DB::table($table)->whereIn('id', array_column($places, 'id'))->pluck('psg_code', 'id');
            $levels = $level === 'municipality' ? PlaceDescription::LGU_LEVELS : [PlaceDescription::LEVEL_BARANGAY];
            $figures = PopulationFigure::query()->where('year', $population['year'])->whereIn('psg_code', $codes->filter())
                ->whereIn('place_description_id', PlaceDescription::query()->select('id')->whereIn('level', $levels)
                    ->where('release_id', PlaceDescription::query()->max('release_id')))->pluck('population', 'psg_code');
            $values = $codes->map(fn ($code) => $figures->get($code));
        }

        return ['level' => $level, 'year' => $population['year'], 'rows' => array_map(fn ($place) => [
            'id' => $place['id'], 'name' => $place['name'], 'value' => $values->get($place['id']),
        ], $places)];
    }

    private function coordinates(mixed $longitude, mixed $latitude): ?array
    {
        if (! is_numeric($longitude) || ! is_numeric($latitude)) {
            return null;
        }
        $lng = (float) $longitude;
        $lat = (float) $latitude;

        return is_finite($lng) && is_finite($lat) && abs($lng) <= 180 && abs($lat) <= 90 && ($lng !== 0.0 || $lat !== 0.0)
            ? [$lng, $lat] : null;
    }

    private function population(GeographicScope $scope): array
    {
        $result = ['status' => 'unavailable', 'value' => null, 'year' => null, 'reason' => 'No active population collection year is configured.'];
        if (! Schema::hasTable('collection_years')) {
            return $result;
        }
        $year = CollectionYear::activeYear();
        if ($year === null) {
            return $result;
        }
        $result['year'] = $year;
        if (! Schema::hasTable('place_descriptions') || ! Schema::hasColumn('population_figures', 'place_description_id')) {
            $result['reason'] = 'The population dataset is not available in the current format.';

            return $result;
        }
        [$table, $levels] = match ($scope->level) {
            'municipality' => ['municipalities', PlaceDescription::LGU_LEVELS],
            'barangay' => ['barangays', [PlaceDescription::LEVEL_BARANGAY]],
            default => ['provinces', [PlaceDescription::LEVEL_PROVINCE, PlaceDescription::LEVEL_REGION]],
        };
        $code = Schema::hasColumn($table, 'psg_code') ? DB::table($table)->where('id', $scope->id)->value('psg_code') : null;
        if (! is_string($code) || $code === '') {
            $result['reason'] = 'This location has no verified population identity link.';

            return $result;
        }
        $figure = PopulationFigure::query()->where('psg_code', $code)->where('year', $year)
            ->whereIn('place_description_id', PlaceDescription::query()->select('id')->where('psg_code', $code)
                ->whereIn('level', $levels)
                ->where('release_id', PlaceDescription::query()->max('release_id')))->first();

        return [
            'status' => $figure?->population === null ? 'no_data' : 'available',
            'value' => $figure?->population,
            'year' => $year,
            'reason' => $figure?->population === null ? 'No population figure is recorded for the active year.' : null,
        ];
    }
}
