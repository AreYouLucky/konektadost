<?php

namespace App\Support\Geometry;

/**
 * Computes the spherical surface area of GeoJSON geometry, in km².
 *
 * Uses the standard spherical-excess approximation:
 *
 *     A = R² / 2 · Σ (Δλ · (2 + sin φ₁ + sin φ₂))
 *
 * Accurate to roughly 0.1% for small polygons and degrades gracefully for
 * larger ones, which is well inside the error already introduced by the
 * simplified GADM polygons these values are derived from.
 */
class PolygonArea
{
    /** Mean Earth radius (IUGG), in kilometres. */
    private const EARTH_RADIUS_KM = 6371.0088;

    /**
     * Area of a GeoJSON geometry in km².
     *
     * Returns 0.0 for missing, unparseable or unsupported geometry rather than
     * throwing, so a single bad row cannot abort a bulk run.
     */
    public static function ofGeometry(string|array|object|null $geometry): float
    {
        if ($geometry === null || $geometry === '') {
            return 0.0;
        }

        if (is_string($geometry)) {
            $geometry = json_decode($geometry);
        }

        if (! is_object($geometry) || ! isset($geometry->type)) {
            return 0.0;
        }

        return match ($geometry->type) {
            'Polygon' => self::polygonArea($geometry->coordinates),
            'MultiPolygon' => array_sum(
                array_map(self::polygonArea(...), $geometry->coordinates)
            ),
            default => 0.0,
        };
    }

    /**
     * Area of a GeoJSON Polygon: outer ring minus its holes.
     *
     * @param  array<int, array<int, array{0: float, 1: float}>>  $rings
     */
    private static function polygonArea(array $rings): float
    {
        if ($rings === []) {
            return 0.0;
        }

        $outer = abs(self::ringArea($rings[0]));

        $holes = 0.0;
        foreach (array_slice($rings, 1) as $hole) {
            $holes += abs(self::ringArea($hole));
        }

        return max(0.0, $outer - $holes);
    }

    /**
     * Signed spherical area of a single closed ring.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring  Points as [lon, lat].
     */
    private static function ringArea(array $ring): float
    {
        $points = self::dedupeClosingPoint($ring);

        if (count($points) < 3) {
            return 0.0;
        }

        $total = 0.0;
        $count = count($points);

        for ($i = 0; $i < $count; $i++) {
            [$lon1, $lat1] = $points[$i];
            [$lon2, $lat2] = $points[($i + 1) % $count];

            $total += deg2rad($lon2 - $lon1) * (2.0 + sin(deg2rad($lat1)) + sin(deg2rad($lat2)));
        }

        return $total * self::EARTH_RADIUS_KM ** 2 / 2.0;
    }

    /**
     * GeoJSON rings repeat the first point at the end; the closing segment is
     * already implied by wrapping the loop, so drop it to avoid
     * double-counting the edge across the antimeridian seam.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring
     * @return array<int, array{0: float, 1: float}>
     */
    private static function dedupeClosingPoint(array $ring): array
    {
        $count = count($ring);

        if ($count > 1 && $ring[0] === $ring[$count - 1]) {
            array_pop($ring);
        }

        return $ring;
    }
}
