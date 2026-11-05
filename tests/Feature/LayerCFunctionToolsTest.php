<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\Models\CollectionYear;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use Tests\TestCase;

class LayerCFunctionToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('DB_TEST_LIVE_DATABASE', 'konektadost'),
            'cache.default' => 'array',
        ]);

        CollectionYear::flushActiveYearCache();
        PlaceDescription::flushLatestReleaseCache();
    }

    public function test_registry_exposes_all_three_layer_c_function_tools(): void
    {
        $registry = app(FunctionToolRegistry::class);
        $definitions = $registry->definitions('C');

        $names = array_column($definitions, 'name');

        $this->assertSame([
            'detect_development_gaps',
            'identify_underserved_areas',
            'calculate_priority_scores',
        ], $names);
    }

    public function test_all_three_layer_c_tools_execute_against_live_database(): void
    {
        $registry = app(FunctionToolRegistry::class);

        // Test with Maribojoc, Bohol (id = 959)
        $municipality = Municipality::query()->findOrFail(959);

        // C1: detect_development_gaps
        $gaps = $registry->execute('detect_development_gaps', ['municipality_id' => 959]);
        $this->assertSame(959, $gaps['location_id']);
        $this->assertSame('municipality', $gaps['location_level']);
        $this->assertGreaterThan(0, $gaps['total_indicators_measured']);
        $this->assertIsArray($gaps['gaps']);
        $this->assertArrayHasKey('critical_gaps_count', $gaps);
        $this->assertArrayHasKey('high_gaps_count', $gaps);
        $this->assertArrayHasKey('moderate_gaps_count', $gaps);
        $this->assertArrayHasKey('low_gaps_count', $gaps);
        $this->assertArrayHasKey('data_limitations', $gaps);

        if (! empty($gaps['gaps'])) {
            $firstGap = $gaps['gaps'][0];
            $this->assertArrayHasKey('indicator_key', $firstGap);
            $this->assertArrayHasKey('severity_score', $firstGap);
            $this->assertGreaterThanOrEqual(0, $firstGap['severity_score']);
            $this->assertLessThanOrEqual(100, $firstGap['severity_score']);
            $this->assertContains($firstGap['severity_level'], ['critical', 'high', 'moderate', 'low']);
            $this->assertArrayHasKey('affected_population', $firstGap);
            $this->assertArrayHasKey('affected_barangays', $firstGap);
        }

        // C2: identify_underserved_areas
        $underserved = $registry->execute('identify_underserved_areas', ['municipality_id' => 959]);
        $this->assertSame('available', $underserved['status']);
        $this->assertSame(959, $underserved['location_id']);
        $this->assertGreaterThan(0, $underserved['summary']['total_barangays']);
        $this->assertArrayHasKey('underserved_barangays_count', $underserved['summary']);
        $this->assertArrayHasKey('underserved_population', $underserved['summary']);
        $this->assertArrayHasKey('by_need_level', $underserved['summary']);
        $this->assertIsArray($underserved['underserved_barangays']);
        $this->assertIsArray($underserved['all_barangays']);

        if (! empty($underserved['all_barangays'])) {
            $firstBgy = $underserved['all_barangays'][0];
            $this->assertArrayHasKey('barangay_id', $firstBgy);
            $this->assertArrayHasKey('name', $firstBgy);
            $this->assertArrayHasKey('need_level', $firstBgy);
            $this->assertArrayHasKey('unmet_domains', $firstBgy);
            $this->assertArrayHasKey('access_status', $firstBgy);
            $this->assertArrayHasKey('has_health_facility', $firstBgy['access_status']);
            $this->assertArrayHasKey('has_school', $firstBgy['access_status']);
            $this->assertArrayHasKey('has_safety_facility', $firstBgy['access_status']);
            $this->assertArrayHasKey('has_road_network_records', $firstBgy['access_status']);
            $this->assertArrayHasKey('spatial_metrics', $firstBgy);
        }

        // C3: calculate_priority_scores
        $scores = $registry->execute('calculate_priority_scores', ['municipality_id' => 959]);
        $this->assertSame('1.0.0', $scores['scoring_version']);
        $this->assertSame(959, $scores['location_id']);
        $this->assertNotEmpty($scores['methodology_explanation']);
        $this->assertArrayHasKey('population_need', $scores['active_weights']);
        $this->assertArrayHasKey('health_access_gap', $scores['active_weights']);
        $this->assertArrayHasKey('education_access_gap', $scores['active_weights']);
        $this->assertGreaterThan(0, $scores['summary']['total_barangays']);
        $this->assertIsArray($scores['barangays']);

        if (! empty($scores['barangays'])) {
            $topScoredBgy = $scores['barangays'][0];
            $this->assertArrayHasKey('name', $topScoredBgy);
            $this->assertArrayHasKey('composite_score', $topScoredBgy);
            $this->assertGreaterThanOrEqual(0, $topScoredBgy['composite_score']);
            $this->assertLessThanOrEqual(100, $topScoredBgy['composite_score']);
            $this->assertArrayHasKey('priority_tier', $topScoredBgy);
            $this->assertContains($topScoredBgy['priority_tier'], ['tier_1_urgent', 'tier_2_high', 'tier_3_moderate', 'tier_4_low']);
            $this->assertArrayHasKey('score_components', $topScoredBgy);
            $this->assertArrayHasKey('normalized_components', $topScoredBgy['score_components']);
        }

        // Also test C2 and C3 on Alicia, Bohol (id = 929) where roads exist in road_barangay
        $scoresAlicia = $registry->execute('calculate_priority_scores', ['municipality_id' => 929]);
        $this->assertSame(929, $scoresAlicia['location_id']);
        $this->assertGreaterThan(0, $scoresAlicia['active_weights']['road_accessibility_gap']);
        $this->assertGreaterThan(0, $scoresAlicia['summary']['total_barangays']);

        $underservedAlicia = $registry->execute('identify_underserved_areas', ['municipality_id' => 929]);
        $this->assertGreaterThan(0, $underservedAlicia['summary']['total_barangays']);

        // Layer C bundle execution
        $bundle = $registry->executeLayerC($municipality);
        $this->assertArrayHasKey('detected_gaps', $bundle);
        $this->assertArrayHasKey('underserved_areas', $bundle);
        $this->assertArrayHasKey('priority_scores', $bundle);
    }
}

