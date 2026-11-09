<?php

namespace App\Support\Map;

use App\Models\DostTech;
use App\Models\Province;
use App\Models\TelecomSite;
use App\Support\Caching\ReadCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A coordinate inventory for the map, independent of stale imported location foreign keys. */
class MapMarkers
{
    /** @param  bool  $forGuest  Leaves out laboratory contact numbers for visitors who are not signed in. */
    public function all(GeographyExplorer $geography, bool $forGuest = false): array
    {
        return app(ReadCache::class)->remember('map.markers', ['guest' => $forGuest], fn () => $this->buildMarkers($geography, $forGuest));
    }

    private function buildMarkers(GeographyExplorer $geography, bool $forGuest): array
    {
        $pins = [];
        $datasets = [];
        foreach (['facilities', 'laboratories', 'telecom_sites'] as $table) {
            if (! Schema::hasColumns($table, ['id', 'longitude', 'latitude'])) {
                $datasets[$table] = ['status' => 'unavailable', 'count' => null, 'pinned_count' => 0];

                continue;
            }
            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);
            if ($table === 'laboratories' && in_array('is_outside_philippines', $columns, true)) {
                $query->where(fn ($query) => $query->where('is_outside_philippines', false)->orWhereNull('is_outside_philippines'));
            }
            $count = (clone $query)->count();
            $pinned = 0;
            $rows = $query->orderBy('id')->cursor(array_values(array_intersect($columns, [
                'id', 'name', 'agency_name', 'latitude', 'longitude', 'address', 'city', 'barangay_name',
                'sector', 'facility_type', 'source', 'code', 'website', 'contact_information', 'services_count',
                'operator', 'tower_company', 'technologies', 'bands', 'structure_type', 'source_record_id', 'barangay_psgc',
            ])));
            foreach ($rows as $row) {
                $coordinates = $this->coordinates($row->longitude, $row->latitude);
                if ($coordinates === null) {
                    continue;
                }
                $lab = $table === 'laboratories';
                $telecom = $table === 'telecom_sites';
                $category = $lab ? 'laboratory' : ($telecom ? 'telecom' : (in_array($row->sector ?? null, ['health', 'education', 'safety'], true) ? $row->sector : 'facility'));
                $details = $lab ? [
                    ['label' => 'Laboratory code', 'value' => $row->code ?? null],
                    ['label' => 'Source-listed services', 'value' => isset($row->services_count) ? (string) $row->services_count : null],
                    ['label' => 'Contact', 'value' => $forGuest ? null : ($row->contact_information ?? null)],
                    ['label' => 'Website', 'value' => $row->website ?? null],
                ] : [
                    ['label' => 'Network operator', 'value' => $row->operator ?? null],
                    ['label' => 'Tower company', 'value' => $row->tower_company ?? null],
                ];
                if ($telecom) {
                    $site = new TelecomSite;
                    $site->setRawAttributes((array) $row);
                    $details[] = ['label' => 'Technologies', 'value' => implode(', ', array_filter($site->technologyList(), is_scalar(...)))];
                    $details[] = ['label' => 'Bands', 'value' => implode(', ', array_filter($site->bandList(), is_scalar(...)))];
                }
                $pins[] = [
                    'key' => ($lab ? 'laboratory:' : ($telecom ? 'telecom:' : 'facility:')).$row->id,
                    'name' => trim((string) ($lab ? ($row->agency_name ?? '') : ($row->name ?? ''))) ?: ($lab ? 'Unnamed laboratory' : ($telecom ? 'Cell site #'.$row->id : 'Unnamed facility')),
                    'category' => $category, 'coordinates' => $coordinates,
                    // Do not present stale imported IDs as verified map membership.
                    'municipality_id' => null, 'barangay_id' => null, 'municipality' => null,
                    'barangay_psgc' => $telecom && preg_match('/^\d{10}$/D', (string) ($row->barangay_psgc ?? '')) ? $row->barangay_psgc : null,
                    'address' => $row->address ?? $row->city ?? $row->barangay_name ?? null,
                    'detail' => $lab ? 'DOST / OneLab laboratory · recorded coordinates' : ($telecom ? 'Recorded cell site; inventory does not measure signal coverage.' : ucwords(str_replace('_', ' ', $row->facility_type ?? 'Facility'))),
                    'source' => $row->source ?? null,
                    'details' => array_values(array_filter($details, fn ($detail) => $detail['value'] !== null && $detail['value'] !== '')),
                ];
                $pinned++;
            }
            $datasets[$table] = ['status' => 'available', 'count' => $count, 'pinned_count' => $pinned];
        }

        $technologyPins = [];
        if (Schema::hasTable('dost_techs')) {
            $catalog = DostTech::query()->orderBy('name')->get();
            $locations = app(DostTechnologyLocations::class);
            foreach (Province::query()->get() as $province) {
                $scope = GeographicScope::province($province);
                if (! $locations->supportsProvince($scope)) {
                    continue;
                }
                $cities = collect($geography->locations('municipality', (int) $province->id)['locations'])->keyBy('id');
                $summary = $locations->summary($scope, $cities, $catalog);
                array_push($technologyPins, ...$summary['pins']);
            }
            $datasets['technologies'] = ['status' => 'available', 'count' => $catalog->count(), 'pinned_count' => count($technologyPins)];
        } else {
            $datasets['technologies'] = ['status' => 'unavailable', 'count' => null, 'pinned_count' => 0];
        }

        return ['pins' => [...$pins, ...$technologyPins], 'datasets' => $datasets, 'scope_basis' => 'recorded_coordinates'];
    }

    private function coordinates(mixed $longitude, mixed $latitude): ?array
    {
        if (! is_numeric($longitude) || ! is_numeric($latitude)) {
            return null;
        }
        $lng = (float) $longitude;
        $lat = (float) $latitude;

        return is_finite($lng) && is_finite($lat) && abs($lng) <= 180 && abs($lat) <= 90 && ($lng !== 0.0 || $lat !== 0.0) ? [$lng, $lat] : null;
    }
}
