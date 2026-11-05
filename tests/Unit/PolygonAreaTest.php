<?php

namespace Tests\Unit;

use App\Support\Geometry\PolygonArea;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PolygonAreaTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: float, 2: float}>
     */
    public static function polygonProvider(): array
    {
        return [
            // A 1° x 1° box on the equator is ~111.32 x 110.57 km.
            'equatorial box' => [
                ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]],
                12310.0,
                0.01,
            ],

            // 0.14° of longitude at ~14.7°N by 0.16° of latitude.
            'box at quezon city latitude' => [
                ['type' => 'Polygon', 'coordinates' => [[[121.0, 14.6], [121.14, 14.6], [121.14, 14.76], [121.0, 14.76], [121.0, 14.6]]]],
                267.0,
                0.01,
            ],

            // Two halves of the same box must sum to the whole.
            'multipolygon splits equally' => [
                [
                    'type' => 'MultiPolygon',
                    'coordinates' => [
                        [[[0, 0], [0.5, 0], [0.5, 1], [0, 1], [0, 0]]],
                        [[[0.5, 0], [1, 0], [1, 1], [0.5, 1], [0.5, 0]]],
                    ],
                ],
                12310.0,
                0.01,
            ],

            // A MultiPolygon must give the same answer as a Polygon holding
            // the same ring, which guards the level-of-nesting handling.
            'multipolygon matches single polygon' => [
                [
                    'type' => 'MultiPolygon',
                    'coordinates' => [
                        [[[121.0, 14.6], [121.14, 14.6], [121.14, 14.76], [121.0, 14.76], [121.0, 14.6]]],
                    ],
                ],
                267.0,
                0.01,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $geometry
     */
    #[DataProvider('polygonProvider')]
    public function test_it_computes_area_close_to_the_true_value(array $geometry, float $expected, float $tolerance): void
    {
        $actual = PolygonArea::ofGeometry(json_encode($geometry));

        $this->assertEqualsWithDelta($expected, $actual, $expected * $tolerance);
    }

    public function test_it_subtracts_holes(): void
    {
        $outer = [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]];
        $hole = [[[0.4, 0.4], [0.6, 0.4], [0.6, 0.6], [0.4, 0.6], [0.4, 0.4]]];

        $withHole = PolygonArea::ofGeometry(json_encode([
            'type' => 'Polygon',
            'coordinates' => [$outer[0], $hole[0]],
        ]));

        $solid = PolygonArea::ofGeometry(json_encode(['type' => 'Polygon', 'coordinates' => $outer]));

        // The hole is 0.2° x 0.2°, so exactly 4% of the 1° x 1° box.
        $this->assertEqualsWithDelta($solid * 0.96, $withHole, $solid * 0.001);
        $this->assertLessThan($solid, $withHole);
    }

    public function test_it_accepts_already_decoded_geometry_and_json_strings(): void
    {
        $json = '{"type":"Polygon","coordinates":[[[0,0],[1,0],[1,1],[0,1],[0,0]]]}';
        $decoded = json_decode($json);

        $this->assertSame(
            PolygonArea::ofGeometry($json),
            PolygonArea::ofGeometry($decoded)
        );
    }

    public function test_it_returns_zero_for_missing_or_unsupported_geometry(): void
    {
        $this->assertSame(0.0, PolygonArea::ofGeometry(null));
        $this->assertSame(0.0, PolygonArea::ofGeometry(''));
        $this->assertSame(0.0, PolygonArea::ofGeometry('not json'));
        $this->assertSame(0.0, PolygonArea::ofGeometry('{"type":"Point","coordinates":[1,2]}'));
        $this->assertSame(0.0, PolygonArea::ofGeometry(['type' => 'Point']));
    }

    public function test_it_ignores_degenerate_rings(): void
    {
        // Fewer than three distinct points enclose no area.
        $this->assertSame(0.0, PolygonArea::ofGeometry('{"type":"Polygon","coordinates":[[[0,0],[1,1],[0,0]]]}'));
        $this->assertSame(0.0, PolygonArea::ofGeometry('{"type":"Polygon","coordinates":[[]]}'));
    }
}
