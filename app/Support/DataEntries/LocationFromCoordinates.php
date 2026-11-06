<?php

namespace App\Support\DataEntries;

use App\Support\Geometry\PointInPolygon;
use Illuminate\Support\Facades\DB;

/**
 * The municipality and barangay whose stored polygon contains a coordinate.
 *
 * Same rule as `resources:relink-locations`, so new entries cannot reintroduce stale links.
 * Candidates are tried nearest-center first; a point outside every polygon resolves to NULL.
 */
class LocationFromCoordinates
{
    /** @return array{municipality_id: int|null, barangay_id: int|null} */
    public function resolve(float $latitude, float $longitude): array
    {
        $municipality = $this->containing('municipalities', $latitude, $longitude, 1.0, 30);
        $barangay = $municipality === null ? null
            : $this->containing('barangays', $latitude, $longitude, 0.3, 60, fn ($query) => $query->where('municipality_id', $municipality));

        return ['municipality_id' => $municipality, 'barangay_id' => $barangay];
    }

    private function containing(string $table, float $latitude, float $longitude, float $radius, int $limit, ?callable $scope = null): ?int
    {
        $query = DB::table($table)
            ->whereNotNull('geometry')
            ->whereBetween('center_lat', [$latitude - $radius, $latitude + $radius])
            ->whereBetween('center_lng', [$longitude - $radius, $longitude + $radius])
            ->orderByRaw('(center_lat - ?) * (center_lat - ?) + (center_lng - ?) * (center_lng - ?)', [$latitude, $latitude, $longitude, $longitude])
            ->limit($limit)
            ->select('id', 'geometry');
        if ($scope) {
            $scope($query);
        }

        foreach ($query->cursor() as $row) {
            if (PointInPolygon::contains(PointInPolygon::decode($row->geometry), $longitude, $latitude)) {
                return (int) $row->id;
            }
        }

        return null;
    }
}
