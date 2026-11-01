<?php

namespace App\Support\Map;

use App\Models\DostTech;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Catalog text matches are location context, never verified deployment coordinates. */
class DostTechnologyLocations
{
    public function summary(GeographicScope $scope, Collection $municipalities, ?Collection $catalog = null): array
    {
        $result = ['status' => 'unavailable', 'count' => null, 'pinned_count' => 0, 'records' => [], 'pins' => []];
        if (! Schema::hasTable('dost_techs')) {
            return $result;
        }
        $result['status'] = 'available';
        if (! $this->supportsProvince($scope)) {
            $result['count'] = 0;

            return $result;
        }
        foreach ($catalog ?? DostTech::query()->orderBy('name')->get() as $technology) {
            $clusters = (string) $technology->deployment_clusters;
            $places = [];
            foreach ($municipalities as $city) {
                if (! $this->mentions($clusters, $city['name'])) {
                    continue;
                }
                if ($scope->barangayId !== null && ! $this->mentions($clusters, $scope->name)) {
                    continue;
                }
                $places[] = $city['name'];
                // A city reference point is deliberately not offset or presented as a deployment site.
                if ($scope->barangayId === null && $city['center'] !== null && abs($city['center'][0]) + abs($city['center'][1]) > 0) {
                    $result['pins'][] = [
                        'key' => 'technology:'.$technology->id.':'.$city['id'], 'category' => 'dost_tech',
                        'name' => $technology->name, 'municipality_id' => $city['id'], 'barangay_id' => null,
                        'coordinates' => $city['center'], 'municipality' => $city['name'],
                        'detail' => 'Catalog location match; city reference point, not a deployment coordinate.',
                        'address' => $clusters, 'source' => 'DOST technology catalog',
                        'details' => array_values(array_filter([
                            ['label' => 'Catalog code', 'value' => $technology->code],
                            ['label' => 'Lead agency', 'value' => $technology->agency],
                            ['label' => 'Sector', 'value' => $technology->sector],
                            ['label' => 'Catalog status', 'value' => $technology->status],
                            ['label' => 'Mechanism', 'value' => $technology->mechanism],
                            ['label' => 'Catalog-stated outcome', 'value' => $technology->outcome],
                        ], fn ($detail) => $detail['value'] !== null && $detail['value'] !== '')),
                    ];
                }
            }
            $provinceName = $scope->path[0]['name'];
            $provinceMatch = $scope->level === 'province' && ($this->mentions($clusters, $provinceName)
                || (in_array($this->normalize($provinceName), ['metropolitan manila', 'metro manila', 'national capital region'], true)
                    && preg_match('/\b(all ncr|metro manila|ncr wide)\b/i', $clusters)));
            if ($places === [] && ! $provinceMatch) {
                continue;
            }
            $result['records'][] = [
                ...$technology->only(['id', 'code', 'name', 'agency', 'sector', 'deployment_clusters', 'mechanism', 'status', 'outcome']),
                'locations' => $scope->barangayId !== null ? [$scope->name] : ($places ?: [$provinceName]),
                'location_basis' => 'Catalog deployment-cluster text match; verify local implementation.',
            ];
        }
        $result['count'] = count($result['records']);
        $result['pinned_count'] = count($result['pins']);

        return $result;
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $value);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))));
    }

    public function supportsProvince(GeographicScope $scope): bool
    {
        // This model is the NCR catalog; repeated city names elsewhere do not extend its coverage.
        return in_array($this->normalize($scope->path[0]['name']), ['metropolitan manila', 'metro manila', 'national capital region'], true);
    }

    private function mentions(string $text, string $name): bool
    {
        $needle = preg_replace('/^(city of|municipality of) /', '', $this->normalize($name));
        $haystack = $this->normalize($text);
        // Manila is ambiguous inside a regional name; do not assign Metro Manila to Manila city.
        if ($needle === 'manila') {
            $haystack = str_replace(['metro manila', 'metropolitan manila'], '', $haystack);
        }

        return strlen($needle) >= 3 && str_contains(' '.$haystack.' ', ' '.$needle.' ');
    }
}
