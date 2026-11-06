<?php

namespace App\Support\Geometry;

/** Point-in-polygon tests for decoded GeoJSON Polygon/MultiPolygon geometry (lng/lat order, holes respected). */
class PointInPolygon
{
    /** @return array{0: float, 1: float, 2: float, 3: float}|null [west, south, east, north] */
    public static function bounds(?array $geometry): ?array
    {
        $bounds = [INF, INF, -INF, -INF];
        foreach (self::polygons($geometry) as $polygon) {
            foreach ($polygon[0] ?? [] as $point) {
                [$x, $y] = $point;
                $bounds = [min($bounds[0], $x), min($bounds[1], $y), max($bounds[2], $x), max($bounds[3], $y)];
            }
        }

        return is_finite($bounds[0]) ? $bounds : null;
    }

    public static function contains(?array $geometry, float $lng, float $lat): bool
    {
        foreach (self::polygons($geometry) as $polygon) {
            if (! $polygon || ! self::inRing($polygon[0], $lng, $lat)) {
                continue;
            }
            foreach (array_slice($polygon, 1) as $hole) {
                if (self::inRing($hole, $lng, $lat)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    public static function decode(?string $json): ?array
    {
        $geometry = json_decode((string) $json, true);

        return is_array($geometry) && isset($geometry['type'], $geometry['coordinates']) && self::polygons($geometry) !== [] ? $geometry : null;
    }

    /** @return list<array> */
    private static function polygons(?array $geometry): array
    {
        return match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };
    }

    private static function inRing(array $ring, float $x, float $y): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
