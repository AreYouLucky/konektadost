<?php

namespace App\Support\Map;

use App\Models\TelecomSite;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Recorded cell-site pins, restricted to verified municipality/barangay membership. */
class TelecomResources
{
    public function query(GeographicScope $scope, array $municipalityIds, Builder $barangays): EloquentBuilder
    {
        $query = TelecomSite::query()->where(function ($query) use ($municipalityIds, $barangays) {
            $query->whereIn('municipality_id', $municipalityIds)
                ->orWhere(fn ($query) => $query->whereNull('municipality_id')->whereIn('barangay_id', clone $barangays));
        });
        if ($scope->barangayId !== null) {
            $query->where('barangay_id', $scope->barangayId);
        }

        return $query;
    }

    public function summary(GeographicScope $scope, Collection $municipalities, Builder $barangays, Collection $barangayParents): array
    {
        $result = ['status' => 'unavailable', 'count' => null, 'pinned_count' => 0, 'pins' => []];
        if (! Schema::hasColumns('telecom_sites', ['municipality_id', 'barangay_id'])) {
            return $result;
        }
        $columns = Schema::getColumnListing('telecom_sites');
        $rows = $this->query($scope, $municipalities->keys()->all(), $barangays)->orderBy('id')
            ->get(array_values(array_intersect($columns, [
                'id', 'name', 'municipality_id', 'barangay_id', 'barangay_name', 'latitude', 'longitude',
                'operator', 'tower_company', 'technologies', 'bands', 'structure_type', 'source', 'source_record_id', 'source_checked',
            ])));
        $result['status'] = 'available';
        $result['count'] = $rows->count();
        foreach ($rows as $site) {
            $rawLongitude = $site->getRawOriginal('longitude');
            $rawLatitude = $site->getRawOriginal('latitude');
            if (! is_numeric($rawLongitude) || ! is_numeric($rawLatitude)) {
                continue;
            }
            $lng = (float) $rawLongitude;
            $lat = (float) $rawLatitude;
            if (! is_finite($lng) || ! is_finite($lat) || abs($lng) > 180 || abs($lat) > 90 || ($lng === 0.0 && $lat === 0.0)) {
                continue;
            }
            $municipalityId = $site->municipality_id ?? $barangayParents->get($site->barangay_id);
            $details = [
                ['label' => 'Network operator', 'value' => $site->operator ?? null],
                ['label' => 'Tower company', 'value' => $site->tower_company ?? null],
                ['label' => 'Technologies', 'value' => $this->listText($site->technologyList())],
                ['label' => 'Bands', 'value' => $this->listText($site->bandList())],
                ['label' => 'Structure', 'value' => $site->structure_type ?? null],
                ['label' => 'Source record', 'value' => isset($site->source_record_id) ? (string) $site->source_record_id : null],
                ['label' => 'Source checked', 'value' => $site->getRawOriginal('source_checked')],
            ];
            $result['pins'][] = [
                'key' => 'telecom:'.$site->id, 'name' => trim((string) ($site->name ?? '')) ?: 'Cell site #'.$site->id,
                'category' => 'telecom', 'coordinates' => [$lng, $lat],
                'municipality_id' => $municipalityId === null ? null : (int) $municipalityId,
                'barangay_id' => $site->barangay_id === null ? null : (int) $site->barangay_id,
                'municipality' => $municipalities->get($municipalityId)['name'] ?? null,
                'address' => $site->barangay_name ?? null, 'source' => $site->source ?? null,
                'detail' => 'Recorded cell-site coordinates. Site counts do not measure signal coverage.',
                'details' => array_values(array_filter($details, fn ($row) => $row['value'] !== null && $row['value'] !== '')),
            ];
        }
        $result['pinned_count'] = count($result['pins']);

        return $result;
    }

    private function listText(array $values): ?string
    {
        $values = array_filter($values, is_scalar(...));

        return $values === [] ? null : implode(', ', $values);
    }
}
