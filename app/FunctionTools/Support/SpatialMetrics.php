<?php

namespace App\FunctionTools\Support;

use App\Models\Barangay;
use App\Models\Facility;
use App\Models\Laboratory;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class SpatialMetrics
{
    /**
     * Compute a per-capita rate (e.g., per 10,000, 100,000, or 1,000,000 residents).
     */
    public static function ratePer(int|float $count, ?int $population, int $multiplier): ?float
    {
        if ($population === null || $population <= 0) {
            return null;
        }

        return round(($count / $population) * $multiplier, 3);
    }

    /**
     * Compute Haversine great-circle distance in kilometers between two coordinates.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);

        return 2 * $earthRadiusKm * asin(min(1.0, sqrt($a)));
    }

    /**
     * Find the nearest geocoded Facility in a given sector (and optional facility_type)
     * to the location's center coordinates.
     *
     * @return array{id: int, name: string|null, facility_type: string|null, level: string|null, municipality_id: int|null, distance_km: float}|null
     */
    public static function findNearestFacility(Model $location, string $sector, ?string $facilityType = null): ?array
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

        $candidates = $query->limit(500)->get(['id', 'name', 'facility_type', 'level', 'municipality_id', 'latitude', 'longitude']);
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
                    'level' => $fac->level,
                    'municipality_id' => $fac->municipality_id !== null ? (int) $fac->municipality_id : null,
                    'distance_km' => round($dist, 2),
                ];
            }
        }

        return $nearest;
    }

    /**
     * Find the nearest accredited Philippine Laboratory to the location's center coordinates.
     *
     * @return array{id: int, agency_name: string|null, city: string|null, province: string|null, distance_km: float}|null
     */
    public static function findNearestLaboratory(Model $location): ?array
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
            ->get(['id', 'agency_name', 'city', 'province', 'latitude', 'longitude']);

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
                    'province' => $lab->province,
                    'distance_km' => round($dist, 2),
                ];
            }
        }

        return $nearest;
    }

    /**
     * Compute barangay-level coverage for a given facility sector within a Municipality.
     *
     * @return array{
     *     total_barangays: int,
     *     barangays_with_facility: int,
     *     zero_facility_barangays: int,
     *     coverage_pct: float|null,
     *     affected_population: int,
     *     affected_population_share_pct: float|null,
     *     underserved_barangays: list<array{id: int, psg_code: string, name: string, population: int|null}>,
     *     served_barangays: list<array{id: int, psg_code: string, name: string, population: int|null, facilities_count: int}>
     * }
     */
    public static function barangayCoverageForSector(
        Municipality $municipality,
        string $sector,
        ?int $municipalityPopulation = null
    ): array {
        if (
            ! Schema::hasTable('barangays')
            || ! Schema::hasColumn('barangays', 'municipality_id')
            || ! Schema::hasColumn('barangays', 'brgy_name')
        ) {
            return [
                'total_barangays' => 0,
                'barangays_with_facility' => 0,
                'zero_facility_barangays' => 0,
                'coverage_pct' => null,
                'affected_population' => 0,
                'affected_population_share_pct' => null,
                'underserved_barangays' => [],
                'served_barangays' => [],
            ];
        }

        $barangays = Barangay::query()
            ->where('municipality_id', $municipality->id)
            ->with('populationFigure')
            ->withCount(['facilities as sector_count' => fn ($q) => $q->where('sector', $sector)])
            ->orderBy('brgy_name')
            ->get();

        $total = $barangays->count();
        $zeroCount = 0;
        $affectedPop = 0;
        $underservedList = [];
        $servedList = [];

        foreach ($barangays as $brgy) {
            $cnt = (int) ($brgy->sector_count ?? 0);
            $pop = $brgy->populationFigure?->population !== null ? (int) $brgy->populationFigure->population : null;

            if ($cnt === 0) {
                $zeroCount++;
                $affectedPop += (int) ($pop ?? 0);
                $underservedList[] = [
                    'id' => (int) $brgy->id,
                    'psg_code' => (string) $brgy->psg_code,
                    'name' => (string) $brgy->brgy_name,
                    'population' => $pop,
                ];
            } else {
                $servedList[] = [
                    'id' => (int) $brgy->id,
                    'psg_code' => (string) $brgy->psg_code,
                    'name' => (string) $brgy->brgy_name,
                    'population' => $pop,
                    'facilities_count' => $cnt,
                ];
            }
        }

        usort($underservedList, fn ($a, $b) => ($b['population'] ?? -1) <=> ($a['population'] ?? -1));
        usort($servedList, fn ($a, $b) => $b['facilities_count'] <=> $a['facilities_count']);

        $servedCount = $total - $zeroCount;

        return [
            'total_barangays' => $total,
            'barangays_with_facility' => $servedCount,
            'zero_facility_barangays' => $zeroCount,
            'coverage_pct' => $total > 0 ? round(($servedCount / $total) * 100, 1) : null,
            'affected_population' => $affectedPop,
            'affected_population_share_pct' => ($municipalityPopulation !== null && $municipalityPopulation > 0)
                ? round(($affectedPop / $municipalityPopulation) * 100, 2)
                : null,
            'underserved_barangays' => $underservedList,
            'served_barangays' => $servedList,
        ];
    }
}

