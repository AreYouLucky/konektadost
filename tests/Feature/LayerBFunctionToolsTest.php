<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\Models\CollectionYear;
use App\Models\Laboratory;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use Tests\TestCase;

class LayerBFunctionToolsTest extends TestCase
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

    public function test_registry_exposes_all_eight_layer_b_function_tools(): void
    {
        $registry = app(FunctionToolRegistry::class);
        $definitions = $registry->definitions('B');

        $names = array_column($definitions, 'name');

        $this->assertSame([
            'calculate_indicators',
            'analyze_health_access',
            'analyze_education_access',
            'analyze_digital_connectivity',
            'analyze_road_accessibility',
            'analyze_agriculture',
            'analyze_sti_laboratory_capacity',
            'analyze_safety_and_drrm',
        ], $names);
    }

    public function test_all_eight_layer_b_tools_execute_against_live_database(): void
    {
        $registry = app(FunctionToolRegistry::class);

        // Test with Maribojoc, Bohol (id = 959) which has facilities, telecom sites, and agriculture estimates
        $municipality = Municipality::query()->findOrFail(959);

        // B1: calculate_indicators
        $indicators = $registry->execute('calculate_indicators', ['municipality_id' => 959]);
        $this->assertSame(959, $indicators['location_id']);
        $this->assertSame('municipality', $indicators['location_level']);
        $this->assertGreaterThan(0, $indicators['demographic_indicators']['population_current']);
        $this->assertGreaterThan(0, $indicators['demographic_indicators']['land_area_km2']);
        $this->assertGreaterThan(0, $indicators['demographic_indicators']['population_density_per_km2']);
        $this->assertNotEmpty($indicators['indicators']);
        $this->assertArrayHasKey('health_facilities_per_10k', $indicators['per_capita_ratios']);
        $this->assertArrayHasKey('schools_per_10k', $indicators['per_capita_ratios']);
        $this->assertArrayHasKey('safety_facilities_per_10k', $indicators['per_capita_ratios']);
        $this->assertArrayHasKey('telecom_sites_per_10k', $indicators['per_capita_ratios']);
        $this->assertSame('available', $indicators['agriculture_indicators']['status']);
        $this->assertSame('empty', $indicators['road_indicators']['status']);

        // B2: analyze_health_access
        $health = $registry->execute('analyze_health_access', ['municipality_id' => 959]);
        $this->assertSame('available', $health['status']);
        $this->assertArrayHasKey('total_health_facilities', $health['counts']);
        $this->assertArrayHasKey('health_facilities_per_10k', $health['rates_per_10k']);
        $this->assertNotNull($health['barangay_coverage']);
        $this->assertGreaterThan(0, $health['barangay_coverage']['total_barangays']);
        $this->assertArrayHasKey('served_barangays', $health['barangay_coverage']);
        $this->assertArrayHasKey('underserved_barangays', $health['barangay_coverage']);

        // B3: analyze_education_access
        $education = $registry->execute('analyze_education_access', ['municipality_id' => 959]);
        $this->assertSame('available', $education['status']);
        $this->assertGreaterThan(0, $education['counts']['total_schools']);
        $this->assertNotNull($education['rates_per_10k']['schools_per_10k']);
        $this->assertNotNull($education['barangay_coverage']);
        $this->assertGreaterThan(0, $education['barangay_coverage']['total_barangays']);

        // B4: analyze_digital_connectivity
        $digital = $registry->execute('analyze_digital_connectivity', ['municipality_id' => 959]);
        $this->assertSame('available', $digital['status']);
        $this->assertSame(65, $digital['counts']['total_telecom_sites']);
        $this->assertGreaterThan(0, $digital['rates_per_10k']['telecom_sites_per_10k']);
        $this->assertNotEmpty($digital['operators']);
        $this->assertNotEmpty($digital['technologies']);
        $this->assertNotNull($digital['barangay_coverage']);
        $this->assertGreaterThan(0, $digital['barangay_coverage']['total_barangays']);

        // Also verify B4 barangay coverage on Quezon City (id = 126, psg_code = 1381300000) where barangay_psgc prefix matches
        $digitalQc = $registry->execute('analyze_digital_connectivity', ['municipality_id' => 126]);
        $this->assertSame('available', $digitalQc['status']);
        $this->assertGreaterThan(0, $digitalQc['counts']['total_telecom_sites']);
        $this->assertGreaterThan(0, $digitalQc['barangay_coverage']['barangays_with_telecom']);

        // B5: analyze_road_accessibility (verify on Alicia, Bohol id = 929 and Quezon City id = 126 with imported roads)
        $roadsAlicia = $registry->execute('analyze_road_accessibility', ['municipality_id' => 929]);
        $this->assertSame('available', $roadsAlicia['status']);
        $this->assertGreaterThan(0, $roadsAlicia['summary']['total_road_segments']);
        $this->assertGreaterThan(0, $roadsAlicia['summary']['total_road_length_km']);
        $this->assertGreaterThan(0, $roadsAlicia['summary']['road_density_km_per_km2']);
        $this->assertNotEmpty($roadsAlicia['by_road_class']);
        $this->assertNotNull($roadsAlicia['barangay_coverage']);
        $this->assertGreaterThan(0, $roadsAlicia['barangay_coverage']['barangays_with_roads']);

        $indAlicia = $registry->execute('calculate_indicators', ['municipality_id' => 929]);
        $this->assertSame('available', $indAlicia['road_indicators']['status']);
        $this->assertGreaterThan(0, $indAlicia['road_indicators']['total_road_length_km']);
        $this->assertGreaterThan(0, $indAlicia['road_indicators']['road_density_km_per_km2']);

        // B6: analyze_agriculture
        $agri = $registry->execute('analyze_agriculture', ['municipality_id' => 959]);
        $this->assertSame('available', $agri['status']);
        $this->assertGreaterThan(0, $agri['record_count']);
        $this->assertGreaterThan(0, $agri['total_area_hectares']);
        $this->assertGreaterThan(0, $agri['financials_php_year']['estimated_revenue_php']);
        $this->assertNotEmpty($agri['top_commodities']);
        $this->assertNotEmpty($agri['categories']);

        // B7: analyze_sti_laboratory_capacity
        $sti = $registry->execute('analyze_sti_laboratory_capacity', ['municipality_id' => 959]);
        $this->assertSame('available', $sti['status']);
        $this->assertArrayHasKey('local_laboratories', $sti['counts']);
        if ($sti['counts']['local_laboratories'] === 0) {
            $this->assertNotNull($sti['nearest_accredited_laboratory_if_zero']);
            $this->assertGreaterThan(0, $sti['nearest_accredited_laboratory_if_zero']['distance_km']);
        } else {
            $this->assertGreaterThan(0, $sti['counts']['local_laboratories']);
        }

        // Also test B7 on a municipality with 0 local laboratories (e.g., Lila, Bohol id = 954)
        $stiZero = $registry->execute('analyze_sti_laboratory_capacity', ['municipality_id' => 954]);
        $this->assertSame('available', $stiZero['status']);
        if ($stiZero['counts']['local_laboratories'] === 0) {
            $this->assertNotNull($stiZero['nearest_accredited_laboratory_if_zero']);
            $this->assertGreaterThan(0, $stiZero['nearest_accredited_laboratory_if_zero']['distance_km']);
        }

        // Also test B1 and B7 on a municipality that directly hosts an accredited laboratory
        $labMunicipalityId = Laboratory::query()->whereNotNull('municipality_id')->value('municipality_id');
        if ($labMunicipalityId !== null) {
            $stiWithLab = $registry->execute('analyze_sti_laboratory_capacity', ['municipality_id' => (int) $labMunicipalityId]);
            $this->assertSame('available', $stiWithLab['status']);
            $this->assertGreaterThan(0, $stiWithLab['counts']['local_laboratories']);
            $this->assertGreaterThan(0, $stiWithLab['counts']['distinct_services_offered']);

            $indWithLab = $registry->execute('calculate_indicators', ['municipality_id' => (int) $labMunicipalityId]);
            $this->assertSame((int) $labMunicipalityId, $indWithLab['location_id']);
        }

        // B8: analyze_safety_and_drrm
        $safety = $registry->execute('analyze_safety_and_drrm', ['municipality_id' => 959]);
        $this->assertSame('available', $safety['status']);
        $this->assertArrayHasKey('total_safety_facilities', $safety['counts']);
        $this->assertArrayHasKey('safety_facilities_per_10k', $safety['rates_per_10k']);
        $this->assertNotNull($safety['barangay_coverage']);

        // Layer B bundle execution
        $bundle = $registry->executeLayerB($municipality);
        $this->assertArrayHasKey('indicators', $bundle);
        $this->assertArrayHasKey('health_access', $bundle);
        $this->assertArrayHasKey('education_access', $bundle);
        $this->assertArrayHasKey('digital_connectivity', $bundle);
        $this->assertArrayHasKey('road_accessibility', $bundle);
        $this->assertArrayHasKey('agriculture', $bundle);
        $this->assertArrayHasKey('sti_laboratory_capacity', $bundle);
        $this->assertArrayHasKey('safety_and_drrm', $bundle);
    }
}
