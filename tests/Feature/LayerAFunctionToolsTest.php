<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\Models\CollectionYear;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use Tests\TestCase;

class LayerAFunctionToolsTest extends TestCase
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

    public function test_registry_exposes_all_six_layer_a_function_tools(): void
    {
        $registry = app(FunctionToolRegistry::class);
        $definitions = $registry->definitions('A');

        $names = array_column($definitions, 'name');

        $this->assertSame([
            'get_municipality_profile',
            'get_population_statistics',
            'get_available_datasets',
            'get_local_assets',
            'get_telecom_infrastructure',
            'get_geographic_data_availability',
        ], $names);
    }

    public function test_all_six_layer_a_tools_execute_against_live_database(): void
    {
        $registry = app(FunctionToolRegistry::class);

        // Test with Maribojoc, Bohol (id = 959, psg_code = 0701232000) which has facilities, telecom sites, and agriculture estimates
        $municipality = Municipality::query()->findOrFail(959);

        // A1: get_municipality_profile
        $profile = $registry->execute('get_municipality_profile', ['municipality_id' => 959]);
        $this->assertSame(959, $profile['id']);
        $this->assertSame('municipality', $profile['level']);
        $this->assertSame('Maribojoc', $profile['name']);
        $this->assertSame('Bohol', $profile['province_name']);
        $this->assertNotEmpty($profile['psg_code']);
        $this->assertNotEmpty($profile['income_class']);
        $this->assertGreaterThan(0, $profile['area_km2']);
        $this->assertTrue($profile['has_geometry']);
        $this->assertGreaterThan(0, $profile['barangay_counts']['total_barangays']);
        $this->assertIsArray($profile['barangays']);

        // A2: get_population_statistics
        $pop = $registry->execute('get_population_statistics', ['municipality_id' => 959]);
        $this->assertSame(2024, $pop['active_year']);
        $this->assertGreaterThan(0, $pop['population_current']);
        $this->assertSame(2020, $pop['baseline_year']);
        $this->assertGreaterThan(0, $pop['population_baseline']);
        $this->assertNotNull($pop['population_change']);
        $this->assertNotNull($pop['growth_rate_pct']);
        $this->assertNotNull($pop['annual_growth_rate_pct']);
        $this->assertNotEmpty($pop['concentration']['most_populous_barangay']['name']);
        $this->assertNotEmpty($pop['barangay_distribution']);

        // A3: get_available_datasets
        $datasets = $registry->execute('get_available_datasets', ['municipality_id' => 959]);
        $this->assertSame('available', $datasets['datasets']['population_figures']['status']);
        $this->assertSame('available', $datasets['datasets']['place_descriptions']['status']);
        $this->assertContains($datasets['datasets']['facilities']['status'], ['available', 'partial']);
        $this->assertContains($datasets['datasets']['telecom_sites']['status'], ['available', 'partial']);
        $this->assertContains($datasets['datasets']['agriculture_count_estimates']['status'], ['available', 'partial']);
        $this->assertSame('empty', $datasets['datasets']['roads']['status']);

        // A4: get_local_assets
        $assets = $registry->execute('get_local_assets', ['municipality_id' => 959]);
        $this->assertGreaterThan(0, $assets['facilities']['total']);
        $this->assertArrayHasKey('health', $assets['facilities']['by_sector']);
        $this->assertArrayHasKey('education', $assets['facilities']['by_sector']);
        $this->assertArrayHasKey('safety', $assets['facilities']['by_sector']);
        $this->assertArrayHasKey('unmapped_records', $assets['facilities']);

        // A5: get_telecom_infrastructure
        $telecom = $registry->execute('get_telecom_infrastructure', ['municipality_id' => 959]);
        $this->assertSame(65, $telecom['summary']['total_sites']);
        $this->assertNotEmpty($telecom['by_operator']);
        $this->assertNotEmpty($telecom['by_technology']);
        $this->assertNotNull($telecom['barangay_distribution']);

        // A6: get_geographic_data_availability
        $geo = $registry->execute('get_geographic_data_availability', ['municipality_id' => 959]);
        $this->assertSame('available', $geo['location_boundary']['status']);
        $this->assertTrue($geo['location_boundary']['has_geometry']);
        $this->assertTrue($geo['location_boundary']['has_bounds']);
        $this->assertTrue($geo['location_boundary']['has_centroid']);
        $this->assertGreaterThan(0, $geo['barangay_boundaries']['total_barangays']);
        $this->assertSame('empty', $geo['road_network']['status']);

        // Layer A bundle execution
        $bundle = $registry->executeLayerA($municipality);
        $this->assertArrayHasKey('municipality_profile', $bundle);
        $this->assertArrayHasKey('population_statistics', $bundle);
        $this->assertArrayHasKey('available_datasets', $bundle);
        $this->assertArrayHasKey('local_assets', $bundle);
        $this->assertArrayHasKey('telecom_infrastructure', $bundle);
        $this->assertArrayHasKey('geographic_data_availability', $bundle);
    }
}
