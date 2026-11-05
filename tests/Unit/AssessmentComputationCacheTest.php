<?php

namespace Tests\Unit;

use App\Models\Municipality;
use App\Models\Province;
use App\Support\Assessment\AssessmentComputationCache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AssessmentComputationCacheTest extends TestCase
{
    public function test_tools_are_not_cached_outside_a_report_computation(): void
    {
        $cache = new AssessmentComputationCache;
        $location = new Municipality;
        $location->id = 11;
        $calls = 0;
        $compute = function () use (&$calls) {
            return ['count' => ++$calls];
        };

        $this->assertSame(['count' => 1], $cache->remember('population', $location, [], $compute));
        $this->assertSame(['count' => 2], $cache->remember('population', $location, [], $compute));
    }

    public function test_reuses_matching_computations_without_mixing_locations_tools_or_options(): void
    {
        $cache = new AssessmentComputationCache;
        $city = new Municipality;
        $city->id = 11;
        $otherCity = new Municipality;
        $otherCity->id = 12;
        $province = new Province;
        $province->id = 11;
        $calls = 0;
        $compute = function () use (&$calls) {
            return ['count' => ++$calls];
        };

        $cache->within(function () use ($cache, $city, $otherCity, $province, $compute) {
            $this->assertSame(['count' => 1], $cache->remember('profile', $city, ['details' => false], $compute));
            $this->assertSame(['count' => 1], $cache->remember('profile', $city, ['details' => false], $compute));
            $this->assertSame(['count' => 2], $cache->remember('profile', $city, ['details' => true], $compute));
            $this->assertSame(['count' => 3], $cache->remember('profile', $otherCity, ['details' => false], $compute));
            $this->assertSame(['count' => 4], $cache->remember('profile', $province, ['details' => false], $compute));
            $this->assertSame(['count' => 5], $cache->remember('population', $city, ['details' => false], $compute));

            return [];
        });
        $this->assertSame(5, $calls);
        $cache->within(fn () => $cache->remember('profile', $city, ['details' => false], $compute));
        $this->assertSame(6, $calls, 'A new report must read fresh values.');
    }

    public function test_exceptions_clear_the_report_cache(): void
    {
        $cache = new AssessmentComputationCache;
        $location = new Municipality;
        $location->id = 11;

        try {
            $cache->within(function () use ($cache, $location) {
                $cache->remember('profile', $location, [], fn () => ['count' => 1]);
                throw new RuntimeException('Failed report');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Failed report', $exception->getMessage());
        }

        $this->assertSame(['count' => 2], $cache->within(fn () => $cache->remember('profile', $location, [], fn () => ['count' => 2])));
    }
}
