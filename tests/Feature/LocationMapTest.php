<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tables are imported straight into MySQL and have no migrations,
        // so stand up just enough schema for the test.
        Schema::create('provinces', function ($table) {
            $table->id();
            $table->string('psg_code')->unique();
            $table->string('name');
            $table->string('variant_name')->nullable();
            $table->string('english_type')->nullable();
            $table->string('cc_code')->nullable();
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->decimal('area_km2', 12, 4)->nullable();
            $table->text('bounds')->nullable();
            $table->text('geometry')->nullable();
        });

        Schema::create('municipalities', function ($table) {
            $table->id();
            $table->string('psg_code')->unique();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('province_name')->nullable();
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->decimal('area_km2', 12, 4)->nullable();
            $table->text('bounds')->nullable();
            $table->text('geometry')->nullable();
        });

        $mm = ['type' => 'MultiPolygon', 'coordinates' => [[[[121.0, 14.6], [121.1, 14.6], [121.1, 14.7], [121.0, 14.6]]]]];
        $qc = ['type' => 'MultiPolygon', 'coordinates' => [[[[121.03, 14.60], [121.07, 14.60], [121.07, 14.67], [121.03, 14.60]]]]];

        DB::table('provinces')->insert([
            'psg_code' => '1300000000',
            'name' => 'MetropolitanManila',
            'english_type' => 'Region',
            'center_lat' => 14.6126,
            'center_lng' => 120.9824,
            'geometry' => json_encode($mm),
        ]);

        DB::table('municipalities')->insert([
            [
                'psg_code' => '1381300000',
                'province_id' => 1,
                'name' => 'QuezonCity',
                'type' => 'municipality',
                'province_name' => 'MetropolitanManila',
                'center_lat' => 14.67465,
                'center_lng' => 121.0589,
                'geometry' => json_encode($qc),
            ],
            [
                'psg_code' => '1381700000',
                'province_id' => 1,
                'name' => 'Parañaque',
                'type' => 'municipality',
                'province_name' => 'MetropolitanManila',
                'center_lat' => 14.48575,
                'center_lng' => 121.0106,
                'geometry' => json_encode($qc),
            ],
            [
                // Belongs to a different province (or none), so the
                // province_id filter must exclude it.
                'psg_code' => '8640300000',
                'province_id' => 99,
                'name' => 'Hinunangan',
                'type' => 'municipality',
                'province_name' => 'SouthernLeyte',
                'center_lat' => 10.44955,
                'center_lng' => 125.22455,
                'geometry' => json_encode($qc),
            ],
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/location')->assertRedirect('/login');
    }

    public function test_it_renders_provinces_as_a_geojson_feature_collection(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/location')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('location-map')
            ->where('selectedProvince', null)
            ->where('selectedCity', null)
            ->has('provinces.features', 1)
            ->etc()
        );

        $provinces = $response->viewData('page')['props']['provinces'];

        $this->assertSame('FeatureCollection', $provinces['type']);

        $feature = $provinces['features'][0];

        $this->assertSame('Feature', $feature['type']);
        // Geometry must be decoded into a structure, not passed through as a
        // string, or Leaflet renders nothing.
        $this->assertIsObject($feature['geometry']);
        $this->assertSame('MultiPolygon', $feature['geometry']->type);
        // Names have spaces stripped in the source data.
        $this->assertSame('Metropolitan Manila', $feature['properties']['name']);
        // The province is keyed by its PSGC code.
        $this->assertSame('1300000000', $feature['properties']['psg_code']);
        // Decimal columns arrive as strings from a raw query; cast to numbers.
        $this->assertIsFloat($feature['properties']['center_lat']);
    }

    public function test_municipalities_are_only_returned_for_the_selected_province(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/location?province=1300000000')
            ->assertOk();

        $municipalities = $response->viewData('page')['props']['municipalities'];

        $this->assertCount(2, $municipalities['features']);

        $names = array_column(array_column($municipalities['features'], 'properties'), 'name');

        $this->assertContains('Quezon City', $names);
        $this->assertContains('Parañaque', $names);
        // Belongs to a different province, so must not be included.
        $this->assertNotContains('Hinunangan', $names);

        $qc = collect($municipalities['features'])->firstWhere('properties.psg_code', '1381300000');
        $this->assertSame('1381300000', $qc['properties']['psg_code']);
    }

    public function test_municipalities_prop_is_null_without_a_province(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/location')
            ->assertOk();

        // A bare page load must not ship any municipality geometry.
        $props = $response->viewData('page')['props'];

        $this->assertNull($props['municipalities']);
    }

    public function test_an_unknown_province_code_renders_as_no_selection(): void
    {
        // Stale bookmarks must land on a working map rather than a 500.
        $response = $this->actingAs(User::factory()->create())
            ->get('/location?province=9999999999')
            ->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertNull($props['selectedProvince']);
        $this->assertNull($props['municipalities']);
    }

    public function test_a_shared_province_link_renders_with_municipalities(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/location?province=1300000000&city=1381300000')
            ->assertOk();

        $props = $response->viewData('page')['props'];

        // Regression guard: with Inertia::optional() this key was dropped on a
        // full page load, so a bookmarked deep link rendered an empty map.
        $this->assertCount(2, $props['municipalities']['features']);
        $this->assertSame('1381300000', $props['selectedCity']);
    }

    public function test_selected_city_is_echoed_back(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/location?province=1300000000&city=1381300000')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('location-map')
            ->where('selectedProvince', '1300000000')
            ->where('selectedCity', '1381300000')
        );
    }

    public function test_a_partial_reload_returns_the_selected_province_alongside_municipalities(): void
    {
        // Documents the contract the map depends on. An Inertia partial reload
        // only returns the props named in X-Inertia-Partial-Data, and the map
        // requests "selectedProvince,municipalities" together. When it
        // requested only "municipalities", selectedProvince was never sent back
        // and stayed null on the client, leaving the city dropdown disabled.
        //
        // Note this asserts the server contract only. Which props the
        // frontend asks for lives in location-map.tsx and cannot be asserted
        // from PHP.
        $manifest = public_path('build/manifest.json');

        $headers = [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'location-map',
            'X-Inertia-Partial-Data' => 'selectedProvince,municipalities',
        ];

        // Inertia rejects a partial reload whose version does not match, and
        // the version is a hash of the build manifest when one exists.
        if (file_exists($manifest)) {
            $headers['X-Inertia-Version'] = hash_file('xxh128', $manifest);
        }

        $response = $this->actingAs(User::factory()->create())
            ->get('/location?province=1300000000', $headers)
            ->assertOk();

        // A partial reload responds with JSON rather than the full page view.
        $props = $response->json('props');

        $this->assertSame('1300000000', $props['selectedProvince']);
        $this->assertCount(2, $props['municipalities']['features']);
    }
}
