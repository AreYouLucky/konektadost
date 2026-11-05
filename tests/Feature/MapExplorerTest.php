<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\FunctionTools\LayerA\GetGeographicDataAvailabilityTool;
use App\FunctionTools\LayerE\MatchDostInterventionsTool;
use App\FunctionTools\LayerF\GenerateActionPlanTool;
use App\Models\CollectionYear;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use App\Support\Assessment\AssessmentFunctionTools;
use App\Support\Assessment\DatasetAndAssetService;
use App\Support\Assessment\DostCatalogToolService;
use App\Support\Assessment\LocationAssessor;
use App\Support\Assessment\MunicipalityAssessmentData;
use App\Support\Assessment\MunicipalityProfileService;
use App\Support\Assessment\PrioritizationService;
use App\Support\Assessment\ProjectCatalog;
use App\Support\Assessment\SectorAnalysisService;
use App\Support\Geometry\PolygonArea;
use App\Support\Map\ProvinceResources;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** These tests create only an isolated in-memory connection; no reference DB migrations. */
class MapExplorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'map_testing',
            'database.connections.map_testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'array',
            'cache.default' => 'array',
            'map.satellite.enabled' => false,
            'map.satellite.url' => '',
            'map.satellite.attribution' => '',
        ]);
        $this->withoutVite();
        CollectionYear::flushActiveYearCache();

        foreach (['provinces', 'municipalities', 'barangays'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->id();
                $table->string('name');
                $table->string('gadm_id');
                $table->string('psgc_code')->nullable();
                $table->string('province_gadm_id')->nullable();
                $table->string('municipality_gadm_id')->nullable();
                if ($tableName === 'municipalities') {
                    $table->unsignedBigInteger('province_id')->nullable();
                }
                $table->decimal('center_lat', 10, 7)->nullable();
                $table->decimal('center_lng', 10, 7)->nullable();
                $table->text('bounds')->nullable();
                $table->text('geometry')->nullable();
            });
        }

        // Minimal synthetic test fixtures; never used as application geographic data.
        $polygon = json_encode(['type' => 'Polygon', 'coordinates' => [[[120, 14], [121, 14], [121, 15], [120, 15], [120, 14]]]]);
        DB::table('provinces')->insert(['id' => 1, 'name' => 'MetropolitanManila', 'gadm_id' => 'PHL.47_1', 'bounds' => '[14,15,120,121]', 'geometry' => $polygon]);
        DB::table('provinces')->insert(['id' => 2, 'name' => 'OtherProvince', 'gadm_id' => 'PHL.48_1']);
        DB::table('municipalities')->insert(['id' => 11, 'name' => 'QuezonCity', 'gadm_id' => 'PHL.47.14_1', 'province_id' => 1, 'geometry' => $polygon]);
        DB::table('municipalities')->insert(['id' => 12, 'name' => 'OtherCity', 'gadm_id' => 'PHL.48.1_1', 'province_id' => 2]);
        DB::table('barangays')->insert(['id' => 101, 'name' => 'SampleBarangay', 'gadm_id' => 'PHL.47.14.1_1', 'municipality_gadm_id' => 'PHL.47.14_1', 'center_lat' => 14.5, 'center_lng' => 120.5]);
        DB::table('barangays')->insert(['id' => 102, 'name' => 'UnlinkedBarangay', 'gadm_id' => 'PHL.0.0.0_1']);
    }

    protected function tearDown(): void
    {
        CollectionYear::flushActiveYearCache();
        DB::purge('map_testing');
        parent::tearDown();
    }

    private function admin(): static
    {
        return $this->actingAs(new User(['id' => 1, 'name' => 'Map tester', 'role' => 'admin']));
    }

    public function test_map_endpoints_require_an_administrator(): void
    {
        $urls = ['/admin/assessment', '/admin/assessment/start?municipality=11&focus=general', '/admin/search-map', '/admin/search-map/geography?level=province', '/admin/search-map/search?q=City', '/admin/search-map/locations/province/1', '/admin/search-map/provinces/1/agriculture', '/admin/search-map/provinces/1/agriculture/records', '/admin/search-map/provinces/1/resources', '/admin/search-map/provinces/1/resources?level=municipality&location=11', '/admin/search-map/provinces/1/agriculture?level=barangay&location=101'];
        $urls[] = '/admin/search-map/markers';
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        foreach (['technician', 'user'] as $role) {
            $this->actingAs(new User(['id' => 2, 'role' => $role]));
            foreach ($urls as $url) {
                $this->get($url)->assertRedirect(route('user.dashboard.index'));
            }
        }
    }

    public function test_assessment_page_preloads_map_data_and_catalog_dimensions(): void
    {
        $this->admin()->get('/admin/assessment')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/assessment')->has('provinces', 2)
            ->where('satellite', null)->where('geographyError', null)
            ->has('dimensions', 10)->where('dimensions.0.key', 'population_and_social_needs')
            ->missing('provinces.0.geometry'));
    }

    public function test_user_intelligence_map_requires_authentication(): void
    {
        foreach ([
            '/intelligence-map',
            '/intelligence-map/markers',
            '/intelligence-map/geography?level=province',
            '/intelligence-map/search?q=City',
            '/intelligence-map/locations/barangay/101',
            '/intelligence-map/provinces/1/resources',
            '/intelligence-map/provinces/1/agriculture',
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_regular_users_can_load_the_intelligence_map_and_its_data(): void
    {
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        $this->get('/intelligence-map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('users/intellegence-map/intellegence-map')->has('provinces', 2)
            ->where('geographyError', null)->where('satellite', null)->missing('provinces.0.geometry'));
        $this->getJson('/intelligence-map/geography?level=municipality&parent=1')->assertOk()
            ->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.id', 11);
        $this->getJson('/intelligence-map/search?q=Quezon%20City')->assertOk()->assertJsonPath('results.0.id', 11);
        $this->getJson('/intelligence-map/locations/barangay/101')->assertOk()->assertJsonPath('path.2.id', 101);
        $this->getJson('/intelligence-map/markers')->assertOk()->assertJsonStructure(['pins', 'datasets']);
        $this->getJson('/intelligence-map/provinces/1/resources?level=municipality&location=11')->assertOk()
            ->assertJsonPath('scope.level', 'municipality')->assertJsonPath('scope.id', 11);
        $this->getJson('/intelligence-map/provinces/1/agriculture?level=barangay&location=101')->assertOk()
            ->assertJsonPath('scope.level', 'barangay')->assertJsonPath('scope.id', 101);
    }

    public function test_user_map_rejects_selections_outside_the_verified_province(): void
    {
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        foreach (['resources', 'agriculture'] as $endpoint) {
            $this->getJson('/intelligence-map/provinces/1/'.$endpoint.'?level=municipality&location=12')->assertNotFound();
        }
        $this->getJson('/intelligence-map/locations/barangay/102')->assertUnprocessable();
    }

    public function test_assessment_uses_the_selected_municipality_psgc_without_losing_leading_zeroes(): void
    {
        Schema::table('municipalities', fn (Blueprint $table) => $table->string('psg_code')->nullable());
        DB::table('municipalities')->where('id', 11)->update(['psg_code' => '0102804000']);

        $this->admin()->get('/admin/assessment/start?municipality=11&focus=health_and_public_health')
            ->assertRedirect(route('location.assess', ['city' => '0102804000', 'focus' => 'health_and_public_health']));
    }

    public function test_assessment_rejects_legacy_display_codes_and_unsupported_focuses(): void
    {
        // A numeric psgc_code display value does not guarantee the assessment's psg_code link exists.
        DB::table('municipalities')->where('id', 11)->update(['psgc_code' => '0102804000']);
        $this->admin()->from('/admin/assessment')->get('/admin/assessment/start?municipality=11&focus=general')
            ->assertRedirect('/admin/assessment')->assertSessionHasErrors('municipality');
        $this->getJson('/admin/assessment/start?municipality=11&focus=agriculture_fisheries_food')
            ->assertUnprocessable()->assertJsonValidationErrors('focus');
        $this->getJson('/admin/assessment/start?focus=general')
            ->assertUnprocessable()->assertJsonValidationErrors('municipality');
        $this->get('/admin/assessment/start?municipality=999&focus=general')->assertNotFound();
    }

    public function test_page_loads_lightweight_provinces_without_unconfigured_imagery(): void
    {
        $this->admin()->get('/admin/search-map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/search-map/search-map')->has('provinces', 2)
            ->where('satellite', null)->missing('provinces.0.geometry'));
    }

    public function test_province_bounds_are_reordered_and_valid_polygons_are_returned(): void
    {
        $this->admin()->getJson('/admin/search-map/geography?level=province')->assertOk()
            ->assertJsonPath('locations.0.bounds', [120, 14, 121, 15])
            ->assertJsonCount(1, 'boundaries.features')
            ->assertJsonPath('boundaries.features.0.id', 'province:1');
    }

    public function test_municipalities_use_real_parent_ids_and_barangays_use_legacy_gadm_links(): void
    {
        $this->admin()->getJson('/admin/search-map/geography?level=municipality&parent=1')->assertOk()
            ->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.name', 'Quezon City');
        $this->getJson('/admin/search-map/geography?level=barangay&parent=11')->assertOk()
            ->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.id', 101);
        $this->getJson('/admin/search-map/locations/barangay/101')->assertOk()->assertJsonCount(3, 'path')
            ->assertJsonPath('path.0.name', 'Metropolitan Manila')
            ->assertJsonPath('path.1.id', 11)->assertJsonPath('path.2.center', [120.5, 14.5])
            ->assertJsonPath('boundary', null);
    }

    public function test_new_schema_names_and_numeric_barangay_links_are_supported(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $table->renameColumn('name', 'brgy_name');
            $table->unsignedBigInteger('municipality_id')->nullable();
        });
        DB::table('barangays')->where('id', 101)->update(['municipality_id' => 11, 'municipality_gadm_id' => null]);
        $this->admin()->getJson('/admin/search-map/geography?level=barangay&parent=11')->assertOk()
            ->assertJsonPath('locations.0.name', 'Sample Barangay');
        $this->getJson('/admin/search-map/locations/barangay/101')->assertOk()->assertJsonCount(3, 'path');
    }

    public function test_search_handles_compact_names_and_resolves_the_administrative_path(): void
    {
        $this->admin()->getJson('/admin/search-map/search?q=Quezon%20City')->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.level', 'municipality')
            ->assertJsonPath('results.0.path.0.id', 1)->assertJsonPath('results.0.path.1.id', 11);
        $this->getJson('/admin/search-map/search?q=Barangay')->assertOk()->assertJsonCount(1, 'results');
    }

    public function test_invalid_geometry_is_omitted_without_fabricating_boundaries(): void
    {
        DB::table('provinces')->where('id', 1)->update(['geometry' => '{"type":"Polygon","coordinates":[[[120,14],[121,15]]] }']);
        $this->admin()->getJson('/admin/search-map/geography?level=province')->assertOk()->assertJsonCount(0, 'boundaries.features');
        $this->getJson('/admin/search-map/provinces/1/resources')->assertOk()->assertJsonPath('assessment.area_km2', null);
        $this->getJson('/admin/search-map/locations/barangay/102')->assertUnprocessable();
        $this->getJson('/admin/search-map/geography?level=barangay')->assertUnprocessable();
        $this->getJson('/admin/search-map/locations/province/999')->assertNotFound();
        $this->getJson('/admin/search-map/search?q=a')->assertUnprocessable();
    }

    public function test_satellite_configuration_is_opt_in_and_requires_attribution_and_xyz_url(): void
    {
        config(['map.satellite.enabled' => true, 'map.satellite.url' => 'https://example.test/{z}/{x}/{y}.jpg']);
        $this->admin()->get('/admin/search-map')->assertInertia(fn (Assert $page) => $page->where('satellite', null));
        config(['map.satellite.attribution' => 'Authorized imagery provider']);
        $this->get('/admin/search-map')->assertInertia(fn (Assert $page) => $page->where('satellite.attribution', 'Authorized imagery provider'));
    }

    public function test_agriculture_distinguishes_missing_tables_empty_provinces_and_unknown_provinces(): void
    {
        $this->admin()->getJson('/admin/search-map/provinces/1/agriculture')->assertOk()
            ->assertJsonPath('status', 'unavailable')->assertJsonPath('categories', []);
        $this->createAgricultureTables();
        $this->getJson('/admin/search-map/provinces/1/agriculture')->assertOk()
            ->assertJsonPath('status', 'no_data')->assertJsonPath('model_year', null);
        $this->getJson('/admin/search-map/provinces/999/agriculture')->assertNotFound();
        $this->getJson('/admin/search-map/provinces/not-an-id/agriculture')->assertNotFound();
    }

    public function test_agriculture_uses_real_province_ids_latest_local_year_and_separate_quantity_bases(): void
    {
        $this->createAgricultureTables();
        DB::table('agriculture_categories')->insert(['id' => 1, 'category_code' => 'crops', 'category_name' => 'Crops']);
        DB::table('agriculture_types')->insert(['id' => 1, 'category_id' => 1, 'type_name' => 'Leafy vegetables']);
        $base = ['category_id' => 1, 'agriculture_type_id' => 1, 'province_id' => 1, 'municipality_id' => 11,
            'quantity_unit' => 'kg', 'quantity_basis' => 'harvested', 'model_year' => 2026,
            'data_source_type' => 'SYNTHETIC', 'source_table' => 'agriculture_estimates'];
        foreach ([
            ['estimated_quantity_year' => '1.2500'],
            ['estimated_quantity_year' => '2.7500'],
            ['estimated_quantity_year' => '99.0000', 'model_year' => 2025],
            ['estimated_quantity_year' => '999.0000', 'province_id' => 2, 'municipality_id' => 12, 'model_year' => 2027],
            ['estimated_quantity_year' => '3.0000', 'quantity_basis' => 'sold'],
            ['estimated_quantity_year' => '1000.0000', 'quantity_unit' => 'g'],
            ['estimated_quantity_year' => null, 'quantity_basis' => 'unknown'],
            ['estimated_quantity_year' => '0.0000', 'quantity_basis' => 'zero'],
        ] as $values) {
            DB::table('agriculture_count_estimates')->insert([...$base, ...$values]);
        }

        $response = $this->admin()->getJson('/admin/search-map/provinces/1/agriculture')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, private')
            ->assertJsonPath('province_id', 1)->assertJsonPath('status', 'available')
            ->assertJsonPath('model_year', 2026)->assertJsonPath('record_count', 6)
            ->assertJsonPath('municipality_count', 1)->assertJsonPath('data_source_types', ['SYNTHETIC'])
            ->assertJsonPath('source_tables', ['agriculture_estimates'])
            ->assertJsonCount(1, 'categories')->assertJsonPath('categories.0.code', 'crops')
            ->assertJsonPath('categories.0.record_count', 6)
            ->assertJsonPath('categories.0.types.0.name', 'Leafy vegetables');
        $quantities = collect($response->json('categories.0.types.0.quantities'));
        $this->assertCount(5, $quantities);
        $this->assertSame('4', $quantities->first(fn ($row) => $row['unit'] === 'kg' && $row['basis'] === 'harvested')['annual_quantity']);
        $this->assertSame('3', $quantities->firstWhere('basis', 'sold')['annual_quantity']);
        $this->assertSame('1000', $quantities->firstWhere('unit', 'g')['annual_quantity']);
        $this->assertNull($quantities->firstWhere('basis', 'unknown')['annual_quantity']);
        $this->assertSame('0', $quantities->firstWhere('basis', 'zero')['annual_quantity']);
    }

    public function test_agriculture_can_display_records_with_an_unknown_model_year(): void
    {
        $this->createAgricultureTables();
        DB::table('agriculture_count_estimates')->insert(['province_id' => 1, 'model_year' => null]);
        $this->admin()->getJson('/admin/search-map/provinces/1/agriculture')->assertOk()
            ->assertJsonPath('status', 'available')->assertJsonPath('model_year', null)
            ->assertJsonPath('record_count', 1)->assertJsonPath('municipality_count', 0)
            ->assertJsonPath('categories.0.name', 'Unclassified agriculture')
            ->assertJsonPath('categories.0.types.0.quantities.0.annual_quantity', null);
    }

    public function test_named_commodities_keep_variants_units_and_bases_separate(): void
    {
        $this->seedDetailedAgriculture();
        $response = $this->admin()->getJson('/admin/search-map/provinces/1/agriculture')->assertOk()
            ->assertJsonPath('record_count', 6)->assertJsonPath('commodity_count', 3)
            ->assertJsonPath('model_year', 2026)->assertJsonCount(6, 'commodities')
            ->assertJsonPath('municipalities.0.name', 'Quezon City');
        $commodities = collect($response->json('commodities'));
        $purple = $commodities->first(fn ($row) => $row['name'] === 'Talong' && $row['variant'] === 'Long purple' && $row['unit'] === 'kg' && $row['basis'] === 'harvested');
        $white = $commodities->firstWhere('variant', 'White');
        $this->assertSame('10.125', $purple['annual_quantity']);
        $this->assertSame('8', $purple['marketable_quantity']);
        $this->assertSame('7.125', $purple['sold_quantity']);
        $this->assertSame('0', $white['marketable_quantity']);
        $this->assertNull($white['sold_quantity']);
        $this->assertSame('1000', $commodities->firstWhere('unit', 'g')['annual_quantity']);
    }

    public function test_agriculture_records_are_paginated_filtered_and_preserve_source_details(): void
    {
        $this->seedDetailedAgriculture();
        $url = '/admin/search-map/provinces/1/agriculture/records';
        $this->admin()->getJson($url.'?commodity=Talong&per_page=2')->assertOk()
            ->assertJsonPath('pagination.total', 4)->assertJsonPath('pagination.last_page', 2)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 1)->assertJsonPath('data.0.commodity_name', 'Talong')
            ->assertJsonPath('data.0.commodity_variant', 'Long purple')->assertJsonPath('data.0.municipality_name', 'Quezon City')
            ->assertJsonPath('data.0.production_method', 'Raised bed')->assertJsonPath('data.0.area_sqm', '12.3456')
            ->assertJsonPath('data.0.estimated_net_php_year', '-12.3400')->assertJsonPath('data.0.source_record_id', '00007')
            ->assertJsonPath('data.0.source_data_json.original_crop', 'Talong')->assertJsonPath('data.0.notes', 'Synthetic test scenario');
        $this->getJson($url.'?commodity=Talong&per_page=2&page=2')->assertOk()->assertJsonPath('data.0.id', 3)->assertJsonPath('data.1.id', 4);
        $this->getJson($url.'?category=2&type=2')->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.commodity_name', 'Tuna');
        $this->getJson($url.'?municipality=12')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson($url.'?q='.rawurlencode('ROW_%'))->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.commodity_name', 'Kamatis');
        $this->getJson($url.'?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson($url.'?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/admin/search-map/provinces/999/agriculture/records')->assertNotFound();
    }

    private function seedDetailedAgriculture(): void
    {
        $this->createAgricultureTables();
        DB::table('agriculture_categories')->insert([
            ['id' => 1, 'category_code' => 'crops', 'category_name' => 'Crops'],
            ['id' => 2, 'category_code' => 'fisheries', 'category_name' => 'Fisheries'],
        ]);
        DB::table('agriculture_types')->insert([
            ['id' => 1, 'category_id' => 1, 'type_name' => 'Fruit vegetables'],
            ['id' => 2, 'category_id' => 2, 'type_name' => 'Finfish trading'],
        ]);
        $base = ['province_id' => 1, 'municipality_id' => 11, 'category_id' => 1, 'agriculture_type_id' => 1,
            'commodity_name' => 'Talong', 'commodity_variant' => 'Long purple', 'quantity_unit' => 'kg', 'quantity_basis' => 'harvested',
            'model_year' => 2026, 'data_source_type' => 'SYNTHETIC', 'source_table' => 'agriculture_estimates'];
        foreach ([
            ['id' => 1, 'record_code' => 'ROW-1', 'estimated_quantity_year' => '10.1250', 'estimated_marketable_quantity_year' => '8.0000', 'estimated_sold_quantity_year' => '7.1250',
                'production_method' => 'Raised bed', 'area_sqm' => '12.3456', 'estimated_net_php_year' => '-12.3400', 'source_record_id' => '00007', 'notes' => 'Synthetic test scenario', 'source_data_json' => '{"original_crop":"Talong"}'],
            ['id' => 2, 'commodity_variant' => 'White', 'estimated_quantity_year' => '2.0000', 'estimated_marketable_quantity_year' => '0.0000'],
            ['id' => 3, 'estimated_quantity_year' => '1000.0000', 'quantity_unit' => 'g'],
            ['id' => 4, 'estimated_quantity_year' => '1.0000', 'quantity_basis' => 'sold'],
            ['id' => 5, 'province_id' => 2, 'municipality_id' => 12, 'model_year' => 2027, 'estimated_quantity_year' => '999.0000'],
            ['id' => 6, 'model_year' => 2025, 'estimated_quantity_year' => '999.0000'],
            ['id' => 7, 'record_code' => 'ROW_%_1', 'commodity_name' => 'Kamatis', 'commodity_variant' => null],
            ['id' => 8, 'record_code' => 'ROW-other', 'commodity_name' => 'Tuna', 'commodity_variant' => null, 'category_id' => 2, 'agriculture_type_id' => 2, 'quantity_basis' => 'fish_handled_not_caught'],
        ] as $row) {
            DB::table('agriculture_count_estimates')->insert([...$base, ...$row]);
        }
    }

    public function test_province_resources_report_missing_datasets_without_inventing_zero_counts(): void
    {
        $this->admin()->getJson('/admin/search-map/provinces/1/resources')->assertOk()
            ->assertJsonPath('population.status', 'unavailable')->assertJsonPath('population.value', null)
            ->assertJsonPath('facilities.status', 'unavailable')->assertJsonPath('facilities.count', null)
            ->assertJsonPath('laboratories.status', 'unavailable')->assertJsonPath('records', []);
        $this->getJson('/admin/search-map/provinces/999/resources')->assertNotFound();
    }

    public function test_resource_counts_use_real_parent_links_and_only_valid_coordinates_get_pins(): void
    {
        $this->createResourceTables();
        $base = ['municipality_id' => 11, 'longitude' => 120.5, 'latitude' => 14.5, 'source' => 'fixture'];
        foreach ([
            ['name' => '<script>Clinic</script>', 'sector' => 'health'],
            ['name' => 'School', 'sector' => 'education', 'latitude' => null],
            ['name' => 'Safety', 'sector' => 'safety', 'municipality_id' => null, 'barangay_id' => 101, 'latitude' => 91],
            ['name' => 'Foreign municipality takes priority', 'municipality_id' => 12, 'barangay_id' => 101],
            ['name' => 'Unlinked barangay', 'municipality_id' => null, 'barangay_id' => 102],
        ] as $row) {
            DB::table('facilities')->insert([...$base, ...$row]);
        }
        foreach ([
            ['agency_name' => 'Local lab'],
            ['agency_name' => 'Ungeocoded lab', 'municipality_id' => null, 'barangay_id' => 101, 'longitude' => 0, 'latitude' => 0],
            ['agency_name' => 'Overseas lab', 'is_outside_philippines' => true],
            ['agency_name' => 'Other lab', 'municipality_id' => 12],
        ] as $row) {
            DB::table('laboratories')->insert([...$base, ...$row]);
        }
        $this->admin()->getJson('/admin/search-map/provinces/1/resources')->assertOk()
            ->assertJsonPath('facilities.count', 3)->assertJsonPath('facilities.pinned_count', 1)
            ->assertJsonPath('facilities.by_category.health', 1)->assertJsonPath('facilities.by_category.education', 1)
            ->assertJsonPath('facilities.by_category.safety', 1)
            ->assertJsonPath('laboratories.count', 2)->assertJsonPath('laboratories.pinned_count', 1)
            ->assertJsonCount(5, 'records')->assertJsonPath('records.0.name', '<script>Clinic</script>')
            ->assertJsonPath('records.0.barangay_id', null)->assertJsonPath('records.2.barangay_id', 101)
            ->assertJsonPath('records.0.municipality_id', 11)->assertJsonPath('records.2.municipality_id', 11)
            ->assertJsonPath('records.2.municipality', 'Quezon City')
            ->assertJsonPath('records.0.coordinates', [120.5, 14.5])->assertJsonPath('records.1.coordinates', null)
            ->assertJsonPath('records.2.coordinates', null)->assertJsonPath('records.4.coordinates', null);

        Schema::table('barangays', fn (Blueprint $table) => $table->unsignedBigInteger('municipality_id')->nullable());
        DB::table('barangays')->where('id', 101)->update(['municipality_id' => 11, 'municipality_gadm_id' => null]);
        $this->getJson('/admin/search-map/provinces/1/resources')->assertOk()->assertJsonPath('facilities.count', 3)->assertJsonPath('laboratories.count', 2)
            ->assertJsonPath('records.2.municipality_id', 11)->assertJsonPath('records.2.municipality', 'Quezon City');
    }

    public function test_population_uses_active_year_latest_release_and_region_level_for_metro_manila(): void
    {
        Schema::table('provinces', fn (Blueprint $table) => $table->string('psg_code')->nullable());
        DB::table('provinces')->where('id', 1)->update(['psg_code' => '1300000000']);
        Schema::create('collection_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('year');
            $table->boolean('is_active')->default(false);
        });
        Schema::create('place_descriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('release_id');
            $table->string('psg_code');
            $table->string('level');
        });
        Schema::create('population_figures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('place_description_id');
            $table->string('psg_code');
            $table->unsignedInteger('year');
            $table->unsignedBigInteger('population')->nullable();
        });
        DB::table('collection_years')->insert([['year' => 2020, 'is_active' => false], ['year' => 2024, 'is_active' => false]]);
        DB::table('place_descriptions')->insert([
            ['id' => 1, 'release_id' => 1, 'psg_code' => '1300000000', 'level' => 'Reg'],
            ['id' => 2, 'release_id' => 2, 'psg_code' => '1300000000', 'level' => 'Reg'],
            ['id' => 3, 'release_id' => 2, 'psg_code' => '1300000000', 'level' => 'City'],
        ]);
        foreach ([[1, 2024, 999], [2, 2024, 15000000], [2, 2020, 0], [3, 2024, 777]] as [$place, $year, $population]) {
            DB::table('population_figures')->insert(['place_description_id' => $place, 'psg_code' => '1300000000', 'year' => $year, 'population' => $population]);
        }
        $this->admin()->getJson('/admin/search-map/provinces/1/resources')->assertOk()
            ->assertJsonPath('population.status', 'unavailable')->assertJsonPath('population.value', null);
        DB::table('collection_years')->where('year', 2024)->update(['is_active' => true]);
        CollectionYear::flushActiveYearCache();
        $this->getJson('/admin/search-map/provinces/1/resources')->assertOk()
            ->assertJsonPath('population.status', 'available')->assertJsonPath('population.year', 2024)->assertJsonPath('population.value', 15000000);
        DB::table('population_figures')->where('place_description_id', 2)->where('year', 2024)->update(['population' => null]);
        $this->getJson('/admin/search-map/provinces/1/resources')->assertOk()
            ->assertJsonPath('population.status', 'no_data')->assertJsonPath('population.value', null);
        DB::table('collection_years')->update(['is_active' => false]);
        DB::table('collection_years')->where('year', 2020)->update(['is_active' => true]);
        CollectionYear::flushActiveYearCache();
        $this->getJson('/admin/search-map/provinces/1/resources')->assertOk()
            ->assertJsonPath('population.status', 'available')->assertJsonPath('population.year', 2020)->assertJsonPath('population.value', 0);

        Schema::table('municipalities', fn (Blueprint $table) => $table->string('psg_code')->nullable());
        Schema::table('barangays', fn (Blueprint $table) => $table->string('psg_code')->nullable());
        DB::table('municipalities')->where('id', 11)->update(['psg_code' => '1300001000']);
        DB::table('barangays')->where('id', 101)->update(['psg_code' => '1300001001']);
        DB::table('place_descriptions')->insert([
            ['id' => 4, 'release_id' => 2, 'psg_code' => '1300001000', 'level' => 'City'],
            ['id' => 5, 'release_id' => 2, 'psg_code' => '1300001001', 'level' => 'Bgy'],
            ['id' => 6, 'release_id' => 1, 'psg_code' => '1300001000', 'level' => 'City'],
            ['id' => 7, 'release_id' => 2, 'psg_code' => '1300001000', 'level' => 'Prov'],
        ]);
        foreach ([[4, '1300001000', 2020, 1234], [4, '1300001000', 2024, 9999], [5, '1300001001', 2020, 321],
            [6, '1300001000', 2020, 8888], [7, '1300001000', 2020, 7777]] as [$place, $code, $year, $population]) {
            DB::table('population_figures')->insert(['place_description_id' => $place, 'psg_code' => $code, 'year' => $year, 'population' => $population]);
        }
        $this->getJson('/admin/search-map/provinces/1/resources?level=municipality&location=11')->assertOk()
            ->assertJsonPath('population.value', 1234)->assertJsonPath('population.year', 2020);
        $this->getJson('/admin/search-map/provinces/1/resources?level=barangay&location=101')->assertOk()
            ->assertJsonPath('population.value', 321)->assertJsonPath('population.year', 2020);
        DB::table('population_figures')->where('place_description_id', 5)->update(['population' => null]);
        $this->getJson('/admin/search-map/provinces/1/resources?level=barangay&location=101')->assertOk()
            ->assertJsonPath('population.status', 'no_data')->assertJsonPath('population.value', null);
    }

    public function test_resource_details_keep_catalog_counts_and_source_listed_counts_separate(): void
    {
        $this->createResourceTables();
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('address')->nullable();
            $table->string('operator')->nullable();
            $table->string('level')->nullable();
            $table->unsignedInteger('capacity_no')->nullable();
            $table->text('description')->nullable();
        });
        Schema::table('laboratories', function (Blueprint $table) {
            $table->string('code')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_information')->nullable();
            $table->string('source_url')->nullable();
            $table->string('source_record_id')->nullable();
            $table->unsignedInteger('services_count')->nullable();
        });
        Schema::create('lab_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
        });
        DB::table('facilities')->insert(['name' => 'School', 'municipality_id' => 11, 'address' => 'Recorded address', 'operator' => 'Local operator',
            'sector' => 'education', 'level' => 'elementary', 'capacity_no' => 100, 'description' => 'Source description']);
        DB::table('laboratories')->insert(['id' => 1, 'agency_name' => 'Detailed lab', 'municipality_id' => 11, 'source' => 'onelab',
            'code' => 'LAB-1', 'website' => 'https://example.test/lab', 'contact_information' => 'Recorded contact',
            'source_url' => 'https://example.test/source', 'source_record_id' => '0001', 'services_count' => 50]);
        DB::table('lab_services')->insert([['laboratory_id' => 1], ['laboratory_id' => 1], ['laboratory_id' => 99]]);
        $this->admin()->getJson('/admin/search-map/provinces/1/resources')->assertOk()
            ->assertJsonPath('records.0.metadata.operator', 'Local operator')
            ->assertJsonPath('records.0.metadata.level', 'elementary')
            ->assertJsonPath('records.0.metadata.capacity_no', 100)
            ->assertJsonPath('records.0.metadata.description', 'Source description')
            ->assertJsonPath('records.1.metadata.code', 'LAB-1')
            ->assertJsonPath('records.1.metadata.website', 'https://example.test/lab')
            ->assertJsonPath('records.1.metadata.contact', 'Recorded contact')
            ->assertJsonPath('records.1.metadata.source_record_id', '0001')
            ->assertJsonPath('records.1.metadata.catalog_service_count', 2)
            ->assertJsonPath('records.1.metadata.source_listed_service_count', 50);
        Schema::drop('lab_services');
        $this->getJson('/admin/search-map/provinces/1/resources')->assertOk()
            ->assertJsonPath('records.1.metadata.catalog_service_count', null)
            ->assertJsonPath('records.1.metadata.source_listed_service_count', 50);
    }

    public function test_city_and_barangay_resource_summaries_exclude_neighboring_locations(): void
    {
        $this->createResourceTables();
        DB::table('municipalities')->insert(['id' => 13, 'name' => 'SecondCity', 'gadm_id' => 'PHL.47.15_1', 'province_id' => 1]);
        DB::table('barangays')->insert([
            ['id' => 103, 'name' => 'SecondBarangay', 'gadm_id' => 'PHL.47.15.1_1', 'municipality_gadm_id' => 'PHL.47.15_1'],
            ['id' => 104, 'name' => 'AnotherBarangay', 'gadm_id' => 'PHL.47.14.2_1', 'municipality_gadm_id' => 'PHL.47.14_1'],
        ]);
        foreach ([[11, 101], [11, 104], [13, 103], [null, 101]] as $index => [$municipality, $barangay]) {
            DB::table('facilities')->insert(['name' => 'Facility '.$index, 'municipality_id' => $municipality, 'barangay_id' => $barangay, 'sector' => 'health', 'latitude' => 14.5, 'longitude' => 120.5]);
        }
        foreach ([[11, 101], [11, 104], [13, 103]] as $index => [$municipality, $barangay]) {
            DB::table('laboratories')->insert(['agency_name' => 'Lab '.$index, 'municipality_id' => $municipality, 'barangay_id' => $barangay, 'latitude' => 14.5, 'longitude' => 120.5]);
        }
        $url = '/admin/search-map/provinces/1/resources';
        $this->admin()->getJson($url)->assertOk()->assertJsonPath('facilities.count', 4)->assertJsonPath('laboratories.count', 3);
        $this->getJson($url.'?level=municipality&location=11')->assertOk()
            ->assertJsonPath('scope.key', 'municipality:11')->assertJsonPath('scope.name', 'Quezon City')
            ->assertJsonPath('facilities.count', 3)->assertJsonPath('laboratories.count', 2)->assertJsonCount(5, 'records');
        $this->getJson($url.'?level=barangay&location=101')->assertOk()
            ->assertJsonPath('scope.key', 'barangay:101')->assertJsonPath('scope.path.1.name', 'Quezon City')
            ->assertJsonPath('facilities.count', 2)->assertJsonPath('laboratories.count', 1)->assertJsonCount(3, 'records');
        $this->getJson($url.'?level=municipality&location=13')->assertOk()
            ->assertJsonPath('facilities.count', 1)->assertJsonPath('laboratories.count', 1);
    }

    public function test_agriculture_scope_changes_year_and_never_promotes_suggested_barangay_matches(): void
    {
        $this->seedDetailedAgriculture();
        DB::table('municipalities')->insert(['id' => 13, 'name' => 'SecondCity', 'gadm_id' => 'PHL.47.15_1', 'province_id' => 1]);
        DB::table('barangays')->insert(['id' => 103, 'name' => 'SecondBarangay', 'gadm_id' => 'PHL.47.15.1_1', 'municipality_gadm_id' => 'PHL.47.15_1']);
        DB::table('agriculture_count_estimates')->whereIn('id', [1, 2])->update(['barangay_id' => 101]);
        $base = ['province_id' => 1, 'category_id' => 1, 'agriculture_type_id' => 1, 'quantity_unit' => 'kg', 'quantity_basis' => 'harvested'];
        DB::table('agriculture_count_estimates')->insert([
            ...$base, 'id' => 9, 'municipality_id' => 13, 'barangay_id' => 103, 'commodity_name' => 'Basil', 'model_year' => 2027,
        ]);
        DB::table('agriculture_count_estimates')->insert([
            ...$base, 'id' => 10, 'municipality_id' => null, 'barangay_id' => 101, 'commodity_name' => 'Kangkong', 'model_year' => 2026,
        ]);
        DB::table('agriculture_count_estimates')->insert([
            ...$base, 'id' => 11, 'municipality_id' => 11, 'barangay_id' => null, 'source_barangay_id' => 101,
            'suggested_parent_barangay_id' => 101, 'commodity_name' => 'Source-only match', 'model_year' => 2026,
        ]);
        $url = '/admin/search-map/provinces/1/agriculture';
        $this->admin()->getJson($url)->assertOk()->assertJsonPath('model_year', 2027)->assertJsonPath('record_count', 1);
        $this->getJson($url.'?level=municipality&location=11')->assertOk()
            ->assertJsonPath('scope.level', 'municipality')->assertJsonPath('model_year', 2026)->assertJsonPath('record_count', 8);
        $this->getJson($url.'?level=barangay&location=101')->assertOk()
            ->assertJsonPath('scope.level', 'barangay')->assertJsonPath('model_year', 2026)->assertJsonPath('record_count', 3)
            ->assertJsonPath('commodity_count', 2);
        $this->getJson($url.'/records?level=barangay&location=101')->assertOk()
            ->assertJsonPath('scope.id', 101)->assertJsonPath('pagination.total', 3);
        $this->getJson($url.'/records?level=barangay&location=101&commodity=Source-only%20match')->assertOk()
            ->assertJsonPath('pagination.total', 0);
        $this->getJson($url.'/records?level=municipality&location=11&municipality=13')->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_detail_scopes_require_verified_parents_and_valid_levels(): void
    {
        $this->admin();
        foreach (['resources', 'agriculture', 'agriculture/records'] as $endpoint) {
            $url = '/admin/search-map/provinces/1/'.$endpoint;
            $this->getJson($url.'?level=municipality&location=12')->assertNotFound();
            $this->getJson($url.'?level=province&location=2')->assertNotFound();
            $this->getJson($url.'?level=barangay&location=102')->assertUnprocessable();
            $this->getJson($url.'?level=municipality')->assertUnprocessable()->assertJsonValidationErrors('location');
            $this->getJson($url.'?level=city&location=11')->assertUnprocessable()->assertJsonValidationErrors('level');
        }
    }

    public function test_agriculture_totals_preserve_missing_values_losses_prices_and_selected_area(): void
    {
        $this->seedDetailedAgriculture();
        DB::table('agriculture_count_estimates')->where('id', 1)->update([
            'barangay_id' => 101, 'estimated_revenue_php_year' => '100.1250', 'estimated_operating_cost_php_year' => '150.5000',
            'estimated_net_php_year' => '-50.3750', 'estimated_unit_price_php' => '10.1250', 'area_kind' => 'planted',
        ]);
        DB::table('agriculture_count_estimates')->where('id', 2)->update([
            'estimated_revenue_php_year' => '0.0000', 'estimated_operating_cost_php_year' => '0.0000', 'estimated_net_php_year' => '0.0000',
        ]);
        DB::table('agriculture_count_estimates')->where('id', 6)->update(['estimated_revenue_php_year' => '999999.0000']);
        DB::table('agriculture_count_estimates')->insert([
            'id' => 9, 'province_id' => 1, 'municipality_id' => 11, 'barangay_id' => 101, 'category_id' => 1, 'agriculture_type_id' => 1,
            'commodity_name' => 'Talong', 'commodity_variant' => 'Long purple', 'quantity_unit' => 'kg', 'quantity_basis' => 'harvested',
            'model_year' => 2026, 'estimated_quantity_year' => '5.0000', 'area_sqm' => '1.0000', 'area_kind' => 'planted',
            'estimated_revenue_php_year' => '200.8750', 'estimated_operating_cost_php_year' => '250.5000',
            'estimated_net_php_year' => '-49.6250', 'estimated_unit_price_php' => '12.1250',
        ]);
        $response = $this->admin()->getJson('/admin/search-map/provinces/1/agriculture')->assertOk()
            ->assertJsonPath('record_count', 7)->assertJsonPath('financials.revenue.total', '301')
            ->assertJsonPath('financials.revenue.reported_count', 3)->assertJsonPath('financials.operating_cost.total', '401')
            ->assertJsonPath('financials.net.total', '-100');
        $items = collect($response->json('commodities'));
        $purple = $items->first(fn ($row) => $row['name'] === 'Talong' && $row['variant'] === 'Long purple' && $row['unit'] === 'kg' && $row['basis'] === 'harvested');
        $this->assertSame('15.125', $purple['annual_quantity']);
        $this->assertSame(['min' => '10.125', 'max' => '12.125', 'reported_count' => 2], $purple['unit_price']);
        $this->assertSame('301', $purple['financials']['revenue']['total']);
        $this->assertSame('0', $items->firstWhere('variant', 'White')['financials']['net']['total']);
        $this->assertNull($items->firstWhere('unit', 'g')['financials']['net']['total']);
        $area = collect($response->json('areas'))->firstWhere('kind', 'planted');
        $this->assertSame('13.3456', $area['area_sqm']);
        $this->assertSame(2, $area['reported_count']);
        $this->getJson('/admin/search-map/provinces/1/agriculture?level=barangay&location=101')->assertOk()
            ->assertJsonPath('record_count', 2)->assertJsonPath('financials.net.total', '-100')
            ->assertJsonPath('financials.revenue.reported_count', 2);
    }

    public function test_map_assessment_uses_local_population_inventory_and_existing_comparison_rules(): void
    {
        $this->seedAssessmentPopulation();
        $this->createResourceTables();
        Schema::table('facilities', fn (Blueprint $table) => $table->string('level')->nullable());
        DB::table('municipalities')->insert(['id' => 13, 'name' => 'SecondCity', 'gadm_id' => 'PHL.47.15_1', 'province_id' => 1]);
        foreach ([
            ['name' => 'Hospital', 'municipality_id' => 11, 'barangay_id' => 101, 'sector' => 'health', 'facility_type' => 'hospital'],
            ['name' => 'Fallback clinic', 'municipality_id' => null, 'barangay_id' => 101, 'sector' => 'health'],
            ['name' => 'School', 'municipality_id' => 11, 'barangay_id' => null, 'sector' => 'education', 'level' => 'unknown'],
            ['name' => 'Second city clinic', 'municipality_id' => 13, 'barangay_id' => null, 'sector' => 'health'],
            ['name' => 'Foreign assignment', 'municipality_id' => 12, 'barangay_id' => 101, 'sector' => 'health'],
        ] as $row) {
            DB::table('facilities')->insert($row);
        }
        Schema::create('benchmarks', function (Blueprint $table) {
            $table->id();
            $table->string('indicator_key');
            $table->string('scope_ref')->default('');
            $table->string('basis');
            $table->double('value');
            $table->string('source');
        });
        DB::table('benchmarks')->insert([
            ['indicator_key' => 'health_facilities_per_100k', 'basis' => 'national_rate', 'value' => 10, 'source' => 'Test national inventory'],
            ['indicator_key' => 'pharmacies_per_100k', 'basis' => 'national_rate', 'value' => 5, 'source' => 'Test national inventory'],
            ['indicator_key' => 'population_density', 'basis' => 'national_rate', 'value' => 1, 'source' => 'Test polygon rate'],
        ]);
        $url = '/admin/search-map/provinces/1/resources';
        $province = collect($this->admin()->getJson($url)->assertOk()->assertJsonCount(11, 'assessment.results')->json('assessment.results'))->keyBy('key');
        $this->assertEquals(0.3, $province['health_facilities_per_100k']['value']);
        $this->assertTrue($province['population_density']['is_shortfall']);
        $city = collect($this->getJson($url.'?level=municipality&location=11')->assertOk()->json('assessment.results'))->keyBy('key');
        $this->assertEquals(2, $city['health_facilities_per_100k']['value']);
        $this->assertEquals(-80, $city['health_facilities_per_100k']['gap']);
        $this->assertTrue($city['health_facilities_per_100k']['is_shortfall']);
        $this->assertSame('high', $city['health_facilities_per_100k']['confidence']);
        $this->assertStringContainsString('Test national inventory', $city['health_facilities_per_100k']['basis_note']);
        $this->assertSame('low', $city['pharmacies_per_100k']['confidence']);
        $this->assertSame('low', $city['elementary_schools_per_100k']['confidence']);
        $this->assertSame('no_benchmark', $city['laboratories_per_million']['status']);
        $this->assertSame('no_data', $city['telecom_sites_per_100k']['status']);
        $barangay = collect($this->getJson($url.'?level=barangay&location=101')->assertOk()->json('assessment.results'))->keyBy('key');
        $this->assertEquals(20, $barangay['health_facilities_per_100k']['value']);
        $this->assertFalse($barangay['health_facilities_per_100k']['is_shortfall']);
        $this->assertSame('no_data', $barangay['population_density']['status']);
        DB::table('collection_years')->update(['is_active' => false]);
        CollectionYear::flushActiveYearCache();
        $unknown = collect($this->getJson($url)->assertOk()->json('assessment.results'));
        $this->assertTrue($unknown->every(fn ($row) => $row['value'] === null && $row['is_shortfall'] === false));
    }

    public function test_map_assessment_deduplicates_local_tests_and_verifies_telecom_membership(): void
    {
        $this->seedAssessmentPopulation();
        $this->createResourceTables();
        Schema::create('lab_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
            $table->string('test_name')->nullable();
            $table->string('method')->nullable();
        });
        DB::table('laboratories')->insert([
            ['id' => 1, 'agency_name' => 'Lab A', 'municipality_id' => 11, 'barangay_id' => 101],
            ['id' => 2, 'agency_name' => 'Lab B', 'municipality_id' => 11, 'barangay_id' => null],
        ]);
        DB::table('lab_services')->insert([
            ['laboratory_id' => 1, 'test_name' => 'Water test', 'method' => 'A'],
            ['laboratory_id' => 1, 'test_name' => 'Water test', 'method' => 'A'],
            ['laboratory_id' => 2, 'test_name' => 'Water test', 'method' => 'A'],
            ['laboratory_id' => 2, 'test_name' => 'Water test', 'method' => 'B'],
            ['laboratory_id' => 99, 'test_name' => 'Foreign test', 'method' => 'C'],
        ]);
        Schema::create('telecom_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->text('technologies')->nullable();
        });
        DB::table('telecom_sites')->insert([
            ['municipality_id' => 11, 'barangay_id' => 101, 'technologies' => '["5G NR"]'],
            ['municipality_id' => null, 'barangay_id' => 101, 'technologies' => '["4G"]'],
            ['municipality_id' => 12, 'barangay_id' => 101, 'technologies' => '["5G NR"]'],
        ]);
        $url = '/admin/search-map/provinces/1/resources';
        $city = collect($this->admin()->getJson($url.'?level=municipality&location=11')->assertOk()->json('assessment.results'))->keyBy('key');
        $this->assertEquals(20, $city['laboratory_tests_per_million']['value']);
        $this->assertEquals(2, $city['telecom_sites_per_100k']['value']);
        $this->assertEquals(50, $city['telecom_5g_share']['value']);
        $barangay = collect($this->getJson($url.'?level=barangay&location=101')->assertOk()->json('assessment.results'))->keyBy('key');
        $this->assertEquals(100, $barangay['laboratory_tests_per_million']['value']);
        $this->assertEquals(20, $barangay['telecom_sites_per_100k']['value']);
        $this->getJson('/location/assess/data?level=municipality&location=11')->assertOk()
            ->assertJsonCount(2, 'localLaboratories')
            ->assertJsonPath('localLaboratories.0.total_services', 1)->assertJsonCount(1, 'localLaboratories.0.services')
            ->assertJsonPath('localLaboratories.1.total_services', 2)->assertJsonCount(2, 'localLaboratories.1.services');
        DB::table('telecom_sites')->where('id', 2)->update(['technologies' => null]);
        $unknown = collect($this->getJson($url)->assertOk()->json('assessment.results'))->keyBy('key');
        $this->assertSame('no_data', $unknown['telecom_5g_share']['status']);
        $this->assertNull($unknown['telecom_5g_share']['value']);
    }

    public function test_selected_map_locations_open_the_existing_assessment_page_with_their_own_data(): void
    {
        $this->seedAssessmentPopulation();
        foreach ([['province', 1, 'Metropolitan Manila', 1000000], ['municipality', 11, 'Quezon City', 100000],
            ['barangay', 101, 'Sample Barangay', 10000]] as [$level, $id, $name, $population]) {
            if ($level === 'municipality') {
                $this->admin()->get(route('location.assess', ['level' => $level, 'location' => $id]))
                    ->assertOk()->assertInertia(fn (Assert $page) => $page->component('location-assessment')
                    ->where('pendingAssessment.name', $name)->missing('results'));
                $this->getJson(route('location.assess.data', ['level' => $level, 'location' => $id]))
                    ->assertOk()->assertJsonPath('location.name', $name)->assertJsonPath('location.population', $population)
                    ->assertJsonPath('resourceSummary.scope.key', $level.':'.$id)->assertJsonCount(11, 'results');

                continue;
            }
            $this->admin()->get(route('location.assess', ['level' => $level, 'location' => $id, 'focus' => 'general']))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->component('location-assessment')
                ->where('location.name', $name)->where('location.population', $population)->where('populationYear', 2024)
                ->where('resourceSummary.scope.key', $level.':'.$id)->where('assessmentParams.level', $level)
                ->where('assessmentParams.location', $id)->has('results', 11)
                ->where('mapReturnUrl', route('admin.search-map.index', ['level' => $level, 'location' => $id]))
                ->where('agricultureSummary.status', 'unavailable'));
        }
        $this->getJson('/location/assess/data?level=municipality&location=11&focus=health_and_public_health')->assertOk()
            ->assertJsonCount(3, 'results')->assertJsonPath('assessmentParams.location', 11)
            ->assertJsonPath('results.0.dimension', 'health_and_public_health');
        Schema::table('municipalities', fn (Blueprint $table) => $table->dropColumn('psg_code'));
        $this->getJson('/location/assess/data?level=municipality&location=11')->assertOk()
            ->assertJsonPath('location.psg_code', null)->assertJsonPath('location.population', null)
            ->assertJsonPath('resourceSummary.population.status', 'unavailable');
    }

    public function test_map_and_service_area_share_the_current_municipality_analysis(): void
    {
        $this->seedAssessmentPopulation();
        $profile = [
            'area_km2' => 42.5,
            'income_class' => '2nd',
            'city_class' => 'Component city',
            'urban_rural' => 'Urban',
            'barangay_counts' => ['total_barangays' => 1],
            'population_statistics' => ['population_current' => 100000, 'population_baseline' => 90000, 'growth_rate_pct' => 11.11],
        ];
        $bundle = [
            'dataset_audit' => ['datasets' => []],
            'local_assets' => ['location_id' => 11],
            'telecom_infrastructure' => ['total_sites' => 3],
            'geographic_data_availability' => ['location_boundary' => ['has_geometry' => true]],
            'sector_analysis' => ['road_accessibility' => ['status' => 'unavailable']],
            'detected_gaps' => ['gaps' => []],
            'local_dost_projects' => ['projects' => []],
            'dost_technologies' => ['results' => []],
            'action_plan' => null,
        ];
        $layerE = [
            'matched_interventions' => ['gap_interventions' => []],
            'suitability_assessment' => ['status' => 'available'],
            'feasibility_assessment' => ['status' => 'available'],
            'implementation_partners' => ['partners' => []],
            'requirements_estimate' => ['status' => 'available'],
        ];
        $isSelectedMunicipality = \Mockery::on(fn ($location) => $location instanceof Municipality && $location->id === 11);
        $this->mock(MunicipalityProfileService::class, fn ($mock) => $mock
            ->shouldReceive('getMunicipalityProfile')->twice()->with($isSelectedMunicipality)->andReturn($profile));
        $this->mock(AssessmentFunctionTools::class, fn ($mock) => $mock
            ->shouldReceive('computeLocationBundle')->twice()->with($isSelectedMunicipality, false)->andReturn($bundle));
        $this->mock(FunctionToolRegistry::class, fn ($mock) => $mock
            ->shouldReceive('executeLayerE')->twice()->with($isSelectedMunicipality)->andReturn($layerE));
        $this->mock(LocationAssessor::class, fn ($mock) => $mock
            ->shouldReceive('assess')->twice()->with($isSelectedMunicipality, 'general')->andReturn([])
            ->shouldReceive('assessSnapshot')->andReturn([]));
        $this->mock(PrioritizationService::class, fn ($mock) => $mock
            ->shouldReceive('calculatePriorityScores')->twice()->with($isSelectedMunicipality, 'general')
            ->andReturn(['summary' => ['high_priority_count' => 1], 'barangays' => [['id' => 101]]]));

        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        foreach (['/location/assess/data?city=1300001000', '/location/assess/data?level=municipality&location=11&map=user'] as $url) {
            $response = $this->getJson($url)->assertOk()
                ->assertJsonPath('municipalityProfile', $profile)->assertJsonPath('functionToolsBundle', $bundle)->assertJsonPath('layerEResult', $layerE)
                ->assertJsonPath('location.psg_code', '1300001000')->assertJsonPath('location.population', 100000)->assertJsonPath('location.area_km2', 42.5)
                ->assertJsonPath('location.income_class', '2nd')->assertJsonPath('populationYear', 2024)->assertJsonPath('populationReason', null)
                ->assertJsonPath('barangayAssessment.summary.high_priority_count', 1);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }
    }

    public function test_city_pages_return_before_any_expensive_assessment_or_resource_computation(): void
    {
        $this->seedAssessmentPopulation();
        $this->partialMock(MunicipalityAssessmentData::class, fn ($mock) => $mock->shouldNotReceive('props'));
        $this->mock(ProvinceResources::class, fn ($mock) => $mock->shouldNotReceive('summary'));
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));

        $this->get('/location/assess?city=1300001000')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('location-assessment')->where('pendingAssessment.name', 'QuezonCity')
            ->where('pendingAssessment.layout', 'user')
            ->where('pendingAssessment.url', route('location.assess.data', ['city' => '1300001000', 'focus' => 'general']))
            ->missing('results')->missing('functionToolsBundle'));
        foreach (['user', 'admin'] as $map) {
            if ($map === 'admin') {
                $this->admin();
            }
            $this->get('/location/assess?level=municipality&location=11&map='.$map)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('location-assessment')->where('pendingAssessment.name', 'Quezon City')
                ->where('pendingAssessment.layout', $map)
                ->where('pendingAssessment.url', route('location.assess.data', ['level' => 'municipality', 'location' => 11, 'focus' => 'general', 'map' => $map]))
                ->where('pendingAssessment.backUrl', route($map === 'admin' ? 'admin.search-map.index' : 'user.intelligence-map.index', ['level' => 'municipality', 'location' => 11]))
                ->missing('results')->missing('functionToolsBundle'));
        }
    }

    public function test_async_assessment_data_requires_authentication_and_revalidates_the_selection(): void
    {
        $this->getJson('/location/assess/data?level=municipality&location=11&map=user')->assertUnauthorized();
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        $this->getJson('/location/assess/data?level=municipality&location=11&map=admin')->assertForbidden();
        $this->getJson('/location/assess/data?level=municipality&location=999&map=user')->assertNotFound();
        $this->getJson('/location/assess/data?level=municipality&location=11&map=user&focus=invented')->assertUnprocessable();
        $this->getJson('/location/assess/data?level=country&location=11&map=user')->assertUnprocessable();
        $this->getJson('/location/assess/data?city=unknown')->assertNotFound();
    }

    public function test_interactive_bundle_skips_action_plan_but_explicit_full_bundle_preserves_it(): void
    {
        $datasets = $this->mock(DatasetAndAssetService::class);
        foreach (['getAvailableDatasets', 'getLocalAssets', 'getTelecomInfrastructure', 'getGeographicDataAvailability'] as $method) {
            $datasets->shouldReceive($method)->twice()->andReturn([]);
        }
        $sectors = $this->mock(SectorAnalysisService::class);
        foreach (['analyzeHealthAccess', 'analyzeEducationAccess', 'analyzeDigitalConnectivity', 'analyzeRoadAccessibility', 'analyzeAgriculture', 'analyzeStiLaboratoryCapacity', 'analyzeSafetyAndDrrm'] as $method) {
            $sectors->shouldReceive($method)->twice()->andReturn([]);
        }
        $priorities = $this->mock(PrioritizationService::class);
        foreach (['detectDevelopmentGaps', 'identifyUnderservedAreas', 'calculatePriorityScores'] as $method) {
            $priorities->shouldReceive($method)->twice()->andReturn([]);
        }
        $catalog = $this->mock(DostCatalogToolService::class);
        $catalog->shouldReceive('getLocalDostProjects', 'searchDostTechnologies')->twice()->andReturn([]);
        $this->mock(MatchDostInterventionsTool::class, fn ($mock) => $mock->shouldReceive('execute')->twice()->andReturn([]));
        $this->mock(GenerateActionPlanTool::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andReturn(['status' => 'proposal']));

        $tools = app(AssessmentFunctionTools::class);
        $municipality = Municipality::query()->findOrFail(11);
        $this->assertNull($tools->computeLocationBundle($municipality, includeActionPlan: false)['action_plan']);
        $this->assertSame(['status' => 'proposal'], $tools->computeLocationBundle($municipality)['action_plan']);
    }

    public function test_user_map_assessment_preserves_its_scope_layout_and_return_path(): void
    {
        $this->seedAssessmentPopulation();
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        foreach ([['province', 1, 1000000], ['barangay', 101, 10000]] as [$level, $id, $population]) {
            $this->get('/location/assess?level='.$level.'&location='.$id.'&map=user')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('location-assessment')->where('assessmentLayout', 'user')
                    ->where('assessmentParams.map', 'user')->where('resourceSummary.scope.key', $level.':'.$id)
                    ->where('location.population', $population)->where('layerEResult', null)
                    ->where('mapReturnUrl', route('user.intelligence-map.index', ['level' => $level, 'location' => $id])));
        }
        $this->admin()->get('/location/assess?level=province&location=1&map=user')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('assessmentLayout', 'user')
                ->where('mapReturnUrl', route('user.intelligence-map.index', ['level' => 'province', 'location' => 1])));
        $this->get('/location/assess?level=province&location=1&map=admin')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('assessmentLayout', 'admin')
                ->where('mapReturnUrl', route('admin.search-map.index', ['level' => 'province', 'location' => 1])));
    }

    public function test_profile_and_geometry_tools_use_the_existing_polygon_area_calculation(): void
    {
        $province = Province::query()->findOrFail(1);
        $expected = round(PolygonArea::ofGeometry($province->geometry), 4);
        $profile = app(MunicipalityProfileService::class)->getMunicipalityProfile($province);
        $geography = app(GetGeographicDataAvailabilityTool::class)->forLocation($province);

        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, $profile['area_km2']);
        $this->assertSame($expected, $geography['location_boundary']['computed_geometry_area_km2']);
    }

    public function test_service_area_assessment_rejects_an_unsupported_focus(): void
    {
        $this->seedAssessmentPopulation();
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        $this->getJson('/location/assess?city=1300001000&focus=invented')->assertUnprocessable()
            ->assertJsonValidationErrors('focus');
    }

    public function test_selected_assessment_scope_requires_authentication_and_valid_geography(): void
    {
        $url = '/location/assess?level=province&location=1';
        $this->get($url)->assertRedirect(route('login'));
        foreach (['user', 'technician'] as $role) {
            $this->actingAs(new User(['id' => 5, 'role' => $role]))->get($url.'&map=admin')->assertForbidden();
        }
        $this->getJson($url.'&map=invented')->assertUnprocessable();
        $this->admin()->getJson('/location/assess?level=country&location=1')->assertUnprocessable();
        $this->getJson('/location/assess?level=municipality')->assertUnprocessable();
        $this->getJson('/location/assess?level=province&location=999')->assertNotFound();
        $this->getJson('/location/assess?level=barangay&location=102')->assertUnprocessable();
        $this->getJson($url.'&focus=invented')->assertUnprocessable();
    }

    public function test_population_charts_use_active_year_verified_child_links_and_keep_parent_totals_separate(): void
    {
        $this->seedAssessmentPopulation();
        $url = '/admin/search-map/provinces/1/resources';
        $this->admin()->getJson($url)->assertOk()->assertJsonPath('population.value', 1000000)
            ->assertJsonPath('population_breakdown.level', 'municipality')->assertJsonPath('population_breakdown.year', 2024)
            ->assertJsonPath('population_breakdown.rows.0.id', 11)->assertJsonPath('population_breakdown.rows.0.value', 100000);
        $this->getJson($url.'?level=municipality&location=11')->assertOk()
            ->assertJsonPath('population_breakdown.rows.0.id', 101)->assertJsonPath('population_breakdown.rows.0.value', 10000);
        DB::table('municipalities')->where('id', 11)->update(['psg_code' => null, 'psgc_code' => '1300001000']);
        $this->getJson($url)->assertOk()->assertJsonPath('population_breakdown.rows.0.value', null);
        DB::table('collection_years')->update(['is_active' => false]);
        CollectionYear::flushActiveYearCache();
        $this->getJson($url.'?level=barangay&location=101')->assertOk()
            ->assertJsonPath('population_breakdown.year', null)->assertJsonPath('population_breakdown.rows.0.value', null);
    }

    public function test_dost_catalog_locations_use_explicit_text_matches_and_labeled_reference_pins(): void
    {
        Schema::create('dost_techs', function (Blueprint $table) {
            $table->id();
            foreach (['code', 'name', 'agency', 'sector', 'deployment_clusters', 'mechanism', 'status', 'outcome'] as $column) {
                $table->string($column)->nullable();
            }
        });
        DB::table('municipalities')->where('id', 11)->update(['center_lng' => 120.6, 'center_lat' => 14.6]);
        DB::table('dost_techs')->insert([
            ['id' => 1, 'code' => 'CITY', 'name' => 'A city technology', 'deployment_clusters' => 'Quezon City', 'sector' => 'Education'],
            ['id' => 2, 'code' => 'BARANGAY', 'name' => 'B barangay technology', 'deployment_clusters' => 'Quezon City: Sample Barangay', 'sector' => 'Health'],
            ['id' => 3, 'code' => 'FOREIGN', 'name' => 'C foreign technology', 'deployment_clusters' => 'Other City', 'sector' => 'Health'],
            ['id' => 4, 'code' => 'REGION', 'name' => 'D region technology', 'deployment_clusters' => 'All NCR', 'sector' => 'Education'],
            ['id' => 5, 'code' => 'PROVINCE', 'name' => 'E ambiguous technology', 'deployment_clusters' => 'Quezon province', 'sector' => 'Agriculture'],
        ]);
        $url = '/admin/search-map/provinces/1/resources';
        $this->admin()->getJson($url)->assertOk()->assertJsonPath('technologies.count', 3)->assertJsonPath('technologies.pinned_count', 2)
            ->assertJsonPath('technologies.pins.0.category', 'dost_tech')->assertJsonPath('technologies.pins.0.coordinates', [120.6, 14.6])
            ->assertJsonPath('technologies.pins.0.details.0.value', 'CITY')->assertJsonPath('technologies.pins.0.details.1.value', 'Education')
            ->assertJsonPath('technologies.pins.0.detail', 'Catalog location match; city reference point, not a deployment coordinate.')
            ->assertJsonPath('technologies.records.2.code', 'REGION');
        $this->getJson($url.'?level=municipality&location=11')->assertOk()->assertJsonPath('technologies.count', 2);
        $this->getJson($url.'?level=barangay&location=101')->assertOk()->assertJsonPath('technologies.count', 1)
            ->assertJsonPath('technologies.records.0.code', 'BARANGAY')->assertJsonPath('technologies.records.0.locations', ['Sample Barangay'])
            ->assertJsonPath('technologies.pins', []);
        DB::table('municipalities')->where('id', 11)->update(['center_lng' => 0, 'center_lat' => 0]);
        $this->getJson($url)->assertOk()->assertJsonPath('technologies.pins', [])->assertJsonPath('technologies.count', 3);
        Schema::drop('dost_techs');
        $this->getJson($url)->assertOk()->assertJsonPath('technologies.count', null)->assertJsonPath('technologies.status', 'unavailable');
    }

    public function test_cell_site_pins_use_verified_membership_and_keep_unmappable_sites_in_totals(): void
    {
        $url = '/admin/search-map/provinces/1/resources';
        $this->admin()->getJson($url)->assertOk()->assertJsonPath('telecom_sites.status', 'unavailable')
            ->assertJsonPath('telecom_sites.count', null)->assertJsonPath('telecom_sites.pins', []);
        Schema::create('telecom_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            foreach (['name', 'latitude', 'longitude', 'operator', 'tower_company', 'source', 'source_record_id', 'technologies', 'bands'] as $column) {
                $table->string($column)->nullable();
            }
        });
        $this->getJson($url)->assertOk()->assertJsonPath('telecom_sites.status', 'available')->assertJsonPath('telecom_sites.count', 0);
        DB::table('municipalities')->insert(['id' => 13, 'name' => 'SecondCity', 'gadm_id' => 'PHL.47.15_1', 'province_id' => 1]);
        $base = ['municipality_id' => 11, 'barangay_id' => 101, 'latitude' => '14.5', 'longitude' => '120.5'];
        foreach ([
            ['name' => '<b>Recorded site</b>', 'operator' => 'Network A', 'tower_company' => 'Tower B', 'technologies' => '["4G","5G NR"]', 'bands' => '["n78"]', 'source' => 'fixture workbook', 'source_record_id' => '00042'],
            ['name' => 'Barangay-linked', 'municipality_id' => null],
            ['name' => 'Other city', 'municipality_id' => 13, 'barangay_id' => null],
            ['name' => 'Foreign city takes precedence', 'municipality_id' => 12],
            ['name' => 'Unlinked barangay', 'municipality_id' => null, 'barangay_id' => 102],
            ['name' => 'Missing coordinates', 'latitude' => null],
            ['name' => 'Placeholder coordinates', 'latitude' => '0', 'longitude' => '0'],
            ['name' => 'Invalid latitude', 'latitude' => '91'],
            ['name' => 'Nonnumeric longitude', 'longitude' => 'not a coordinate'],
            ['name' => 'City-only', 'barangay_id' => null],
        ] as $row) {
            DB::table('telecom_sites')->insert([...$base, ...$row]);
        }
        $this->getJson($url)->assertOk()->assertJsonPath('telecom_sites.count', 8)->assertJsonPath('telecom_sites.pinned_count', 4)
            ->assertJsonPath('telecom_sites.pins.0.category', 'telecom')->assertJsonPath('telecom_sites.pins.0.coordinates', [120.5, 14.5])
            ->assertJsonPath('telecom_sites.pins.0.name', '<b>Recorded site</b>')
            ->assertJsonPath('telecom_sites.pins.0.details.0', ['label' => 'Network operator', 'value' => 'Network A'])
            ->assertJsonPath('telecom_sites.pins.0.details.1', ['label' => 'Tower company', 'value' => 'Tower B'])
            ->assertJsonPath('telecom_sites.pins.0.details.2.value', '4G, 5G NR')
            ->assertJsonPath('telecom_sites.pins.0.details.4.value', '00042')
            ->assertJsonPath('telecom_sites.pins.1.municipality_id', 11)->assertJsonPath('telecom_sites.pins.1.municipality', 'Quezon City');
        $this->getJson($url.'?level=municipality&location=11')->assertOk()->assertJsonPath('telecom_sites.count', 7)
            ->assertJsonPath('telecom_sites.pinned_count', 3);
        $this->getJson($url.'?level=barangay&location=101')->assertOk()->assertJsonPath('telecom_sites.count', 6)
            ->assertJsonPath('telecom_sites.pinned_count', 2);
        Schema::table('barangays', fn (Blueprint $table) => $table->unsignedBigInteger('municipality_id')->nullable());
        DB::table('barangays')->where('id', 101)->update(['municipality_id' => 11, 'municipality_gadm_id' => null]);
        $this->getJson($url.'?level=barangay&location=101')->assertOk()->assertJsonPath('telecom_sites.pins.1.municipality_id', 11);
        Schema::drop('telecom_sites');
        $this->getJson($url)->assertOk()->assertJsonPath('telecom_sites.status', 'unavailable')->assertJsonPath('telecom_sites.pins', []);
    }

    public function test_national_markers_use_recorded_coordinates_independently_of_stale_location_ids(): void
    {
        $this->createResourceTables();
        DB::table('facilities')->insert([
            ['name' => 'Mapped health facility', 'municipality_id' => 999, 'sector' => 'health', 'longitude' => 120.5, 'latitude' => 14.5],
            ['name' => 'Missing coordinates', 'municipality_id' => 11, 'sector' => 'education', 'longitude' => null, 'latitude' => null],
        ]);
        DB::table('laboratories')->insert([
            ['agency_name' => 'Mapped laboratory', 'municipality_id' => 999, 'longitude' => 120.6, 'latitude' => 14.6, 'is_outside_philippines' => false],
            ['agency_name' => 'Overseas lab', 'municipality_id' => 11, 'longitude' => 120.7, 'latitude' => 14.7, 'is_outside_philippines' => true],
        ]);
        Schema::create('telecom_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('municipality_id')->nullable();
            foreach (['name', 'longitude', 'latitude', 'barangay_psgc', 'operator', 'tower_company'] as $column) {
                $table->string($column)->nullable();
            }
        });
        DB::table('telecom_sites')->insert([
            ['name' => 'Mapped cell site', 'municipality_id' => 999, 'longitude' => '120.5', 'latitude' => '14.5', 'barangay_psgc' => '1381500020', 'operator' => 'Network', 'tower_company' => 'Tower owner'],
            ['name' => 'Invalid coordinate', 'municipality_id' => 11, 'longitude' => 'invalid', 'latitude' => '14.5', 'barangay_psgc' => null, 'operator' => null, 'tower_company' => null],
            ['name' => 'Placeholder', 'municipality_id' => 11, 'longitude' => '0', 'latitude' => '0', 'barangay_psgc' => null, 'operator' => null, 'tower_company' => null],
        ]);
        $this->admin()->getJson('/admin/search-map/markers')->assertOk()->assertHeader('Cache-Control', 'max-age=60, private')
            ->assertJsonPath('scope_basis', 'recorded_coordinates')->assertJsonCount(3, 'pins')
            ->assertJsonPath('datasets.facilities.count', 2)->assertJsonPath('datasets.facilities.pinned_count', 1)
            ->assertJsonPath('datasets.laboratories.count', 1)->assertJsonPath('datasets.telecom_sites.count', 3)
            ->assertJsonPath('datasets.telecom_sites.pinned_count', 1)
            ->assertJsonPath('pins.0.category', 'health')->assertJsonPath('pins.0.municipality_id', null)
            ->assertJsonPath('pins.1.category', 'laboratory')->assertJsonPath('pins.2.category', 'telecom')
            ->assertJsonPath('pins.2.barangay_psgc', '1381500020')
            ->assertJsonPath('pins.2.details.0', ['label' => 'Network operator', 'value' => 'Network'])
            ->assertJsonPath('pins.2.details.1', ['label' => 'Tower company', 'value' => 'Tower owner']);
        Schema::drop('telecom_sites');
        $this->getJson('/admin/search-map/markers')->assertOk()->assertJsonPath('datasets.telecom_sites.status', 'unavailable')
            ->assertJsonPath('datasets.telecom_sites.count', null)->assertJsonCount(2, 'pins');
    }

    private function seedAssessmentPopulation(): void
    {
        Schema::create('collection_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('year');
            $table->boolean('is_active');
        });
        Schema::create('place_descriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('release_id');
            $table->string('psg_code');
            $table->string('level');
        });
        Schema::create('population_figures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('place_description_id');
            $table->string('psg_code');
            $table->unsignedInteger('year');
            $table->unsignedBigInteger('population')->nullable();
        });
        DB::table('collection_years')->insert(['year' => 2024, 'is_active' => true]);
        foreach ([['provinces', 1, '1300000000', 'Reg', 1000000], ['municipalities', 11, '1300001000', 'City', 100000],
            ['barangays', 101, '1300001001', 'Bgy', 10000]] as $index => [$table, $id, $code, $level, $population]) {
            Schema::table($table, fn (Blueprint $table) => $table->string('psg_code')->nullable());
            DB::table($table)->where('id', $id)->update(['psg_code' => $code]);
            DB::table('place_descriptions')->insert(['id' => $index + 1, 'release_id' => 1, 'psg_code' => $code, 'level' => $level]);
            DB::table('population_figures')->insert(['place_description_id' => $index + 1, 'psg_code' => $code, 'year' => 2024, 'population' => $population]);
        }
    }

    public function test_city_assessment_does_not_substitute_barangay_totals_for_unknown_population(): void
    {
        $this->seedAssessmentPopulation();
        Schema::table('barangays', function (Blueprint $table) {
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('brgy_name')->nullable();
            $table->decimal('area_km2', 12, 4)->nullable();
        });
        DB::table('barangays')->where('id', 101)->update(['municipality_id' => 11, 'brgy_name' => 'Sample Barangay']);
        DB::table('population_figures')->where('psg_code', '1300001000')->update(['population' => null]);
        $this->admin()->getJson('/location/assess/data?city=1300001000')->assertOk()
            ->assertJsonPath('location.population', null)->assertJsonPath('barangayAssessment.summary.total_population', 10000)
            ->assertJsonPath('populationYear', 2024)->assertJsonStructure(['populationReason'])->assertJsonPath('results.0.status', 'no_data')
            ->assertJsonPath('projectCatalog.status', 'unavailable');
    }

    public function test_assessment_project_catalog_is_separate_from_local_deployment_matches(): void
    {
        $url = '/location/assess/data?level=municipality&location=11';
        $this->admin()->getJson($url)->assertOk()
            ->assertJsonPath('projectCatalog.status', 'unavailable')->assertJsonCount(0, 'projectCatalog.records');
        Schema::create('dost_techs', function (Blueprint $table) {
            $table->id();
            foreach (['code', 'name', 'agency', 'sector', 'mechanism', 'status', 'outcome', 'deployment_clusters'] as $column) {
                $table->text($column)->nullable();
            }
        });
        DB::table('dost_techs')->insert(['id' => 9, 'code' => 'TEST-HEALTH', 'name' => 'Fixture diagnostic equipment',
            'agency' => 'Fixture agency', 'sector' => 'Health', 'mechanism' => 'Fixture health mechanism',
            'deployment_clusters' => 'OtherCity', 'status' => 'Source status', 'outcome' => null]);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('projectCatalog.status', 'available')->assertJsonCount(1, 'projectCatalog.records')
            ->assertJsonPath('projectCatalog.records.0.code', 'TEST-HEALTH')->assertJsonPath('projectCatalog.records.0.agency', 'Fixture agency')
            ->assertJsonPath('projectCatalog.records.0.outcome', null)->assertJsonPath('projectCatalog.records.0.status', 'Source status')
            ->assertJsonPath('projectCatalog.coverage', 'NCR reference catalog. Applicability, eligibility, availability and local deployment must be confirmed with DOST.')
            ->assertJsonMissingPath('projectCatalog.records.0.matches_city_cluster')->assertJsonCount(0, 'dostInterventions')
            ->assertJsonCount(11, 'results')->assertJsonPath('resourceSummary.scope.key', 'municipality:11');
    }

    public function test_project_catalog_keeps_missing_optional_details_null(): void
    {
        Schema::create('dost_techs', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        DB::table('dost_techs')->insert(['id' => 1, 'code' => 'FIXTURE', 'name' => 'Fixture catalog entry']);
        $catalog = app(ProjectCatalog::class)->summary();
        $this->assertSame('available', $catalog['status']);
        $this->assertNull($catalog['records'][0]['agency']);
        $this->assertNull($catalog['records'][0]['outcome']);
        $this->assertNull($catalog['records'][0]['deployment_clusters']);
        DB::table('dost_techs')->delete();
        $this->assertSame([], app(ProjectCatalog::class)->summary()['records']);
    }

    private function createResourceTables(): void
    {
        foreach (['facilities', 'laboratories'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->unsignedBigInteger('municipality_id')->nullable();
                $table->unsignedBigInteger('barangay_id')->nullable();
                $table->string($name === 'facilities' ? 'name' : 'agency_name');
                $table->decimal('longitude', 10, 6)->nullable();
                $table->decimal('latitude', 10, 6)->nullable();
                $table->string('source')->nullable();
                if ($name === 'facilities') {
                    $table->string('sector')->nullable();
                    $table->string('facility_type')->nullable();
                } else {
                    $table->boolean('is_outside_philippines')->default(false);
                }
            });
        }
    }

    private function createAgricultureTables(): void
    {
        Schema::create('agriculture_categories', function (Blueprint $table) {
            $table->id();
            $table->string('category_code');
            $table->string('category_name');
            $table->text('description')->nullable();
        });
        Schema::create('agriculture_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('type_name');
        });
        Schema::create('agriculture_count_estimates', function (Blueprint $table) {
            $table->id();
            foreach (['province_id', 'municipality_id', 'barangay_id', 'source_barangay_id', 'suggested_parent_barangay_id', 'category_id', 'agriculture_type_id'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->unsignedInteger('model_year')->nullable();
            $table->decimal('estimated_quantity_year', 20, 4)->nullable();
            foreach (['quantity_unit', 'quantity_basis', 'data_source_type', 'source_table', 'commodity_name', 'commodity_variant', 'record_code', 'production_method', 'activity_type'] as $column) {
                $table->string($column)->nullable();
            }
            $table->decimal('estimated_marketable_quantity_year', 20, 4)->nullable();
            $table->decimal('estimated_sold_quantity_year', 20, 4)->nullable();
            $table->decimal('area_sqm', 20, 4)->nullable();
            $table->string('area_kind')->nullable();
            $table->decimal('estimated_unit_price_php', 20, 4)->nullable();
            $table->decimal('estimated_revenue_php_year', 20, 4)->nullable();
            $table->decimal('estimated_operating_cost_php_year', 20, 4)->nullable();
            $table->decimal('estimated_net_php_year', 20, 4)->nullable();
            $table->string('source_record_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('source_data_json')->nullable();
        });
    }
}
