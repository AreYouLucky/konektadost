<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoadMapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'road_testing', 'database.connections.road_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'cache.default' => 'array', 'session.driver' => 'array']);
        foreach (['provinces', 'municipalities', 'barangays'] as $table) {
            Schema::create($table, function (Blueprint $schema) {
                $schema->id();
                $schema->string('name');
                $schema->integer('province_id')->nullable();
                $schema->integer('municipality_id')->nullable();
            });
        }
        DB::table('provinces')->insert([['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
        DB::table('municipalities')->insert([['id' => 11, 'name' => 'City One', 'province_id' => 1], ['id' => 12, 'name' => 'City Two', 'province_id' => 2]]);
        DB::table('barangays')->insert([['id' => 101, 'name' => 'First', 'municipality_id' => 11], ['id' => 102, 'name' => 'Second', 'municipality_id' => 11], ['id' => 103, 'name' => 'Unlinked', 'municipality_id' => null]]);
        Schema::create('roads', function (Blueprint $table) {
            $table->id();
            $table->integer('municipality_id');
            $table->text('geometry')->nullable();
            $table->string('name')->nullable();
            $table->string('road_class')->nullable();
            $table->double('length_m')->nullable();
        });
        Schema::create('road_barangay', function (Blueprint $table) {
            $table->id();
            $table->integer('road_id');
            $table->integer('barangay_id');
            $table->double('length_m')->nullable();
        });
        $line = json_encode(['type' => 'LineString', 'coordinates' => [[121, 14], [121.01, 14.01]]]);
        DB::table('roads')->insert([
            ['id' => 1, 'municipality_id' => 11, 'name' => 'First road', 'road_class' => 'municipal', 'length_m' => 100.5, 'geometry' => $line],
            ['id' => 2, 'municipality_id' => 11, 'name' => '<script>unsafe name</script>', 'road_class' => 'barangay', 'length_m' => 0, 'geometry' => $line],
            ['id' => 3, 'municipality_id' => 12, 'name' => 'Foreign road', 'road_class' => null, 'length_m' => null, 'geometry' => $line],
        ]);
        DB::table('road_barangay')->insert([
            ['road_id' => 1, 'barangay_id' => 101, 'length_m' => 40.25], ['road_id' => 1, 'barangay_id' => 101, 'length_m' => 40.25],
            ['road_id' => 2, 'barangay_id' => 102, 'length_m' => 0], ['road_id' => 3, 'barangay_id' => 101, 'length_m' => 12],
        ]);
    }

    public function test_road_endpoints_require_authentication_and_admin_route_keeps_its_role_check(): void
    {
        $this->getJson('/intelligence-map/locations/municipality/11/roads')->assertUnauthorized();
        $this->getJson('/admin/search-map/locations/municipality/11/roads')->assertUnauthorized();
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        $this->get('/admin/search-map/locations/municipality/11/roads')->assertRedirect();
    }

    public function test_province_and_municipality_roads_do_not_widen_to_another_province(): void
    {
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        foreach (['province/1', 'municipality/11'] as $selection) {
            $this->getJson('/intelligence-map/locations/'.$selection.'/roads')->assertOk()
                ->assertJsonCount(2, 'features')->assertJsonPath('features.0.id', 1)->assertJsonPath('features.1.id', 2);
        }
        $this->getJson('/intelligence-map/locations/municipality/12/roads')->assertOk()->assertJsonCount(1, 'features')->assertJsonPath('features.0.id', 3);
    }

    public function test_barangay_uses_explicit_pivot_links_including_cross_municipality_segments_without_duplicates(): void
    {
        $this->actingAs(new User(['id' => 1, 'role' => 'admin']));
        foreach (['/intelligence-map', '/admin/search-map'] as $prefix) {
            $this->getJson($prefix.'/locations/barangay/101/roads')->assertOk()->assertJsonCount(2, 'features')
                ->assertJsonPath('features.0.id', 1)->assertJsonPath('features.0.geometry.type', 'LineString')
                ->assertJsonPath('features.0.properties.barangay_id', 101)
                ->assertJsonPath('features.0.properties.barangay_length_m', 40.25)
                ->assertJsonPath('features.1.id', 3)->assertJsonPath('features.1.properties.barangay_length_m', 12)
                ->assertJsonPath('meta.scope.key', 'barangay:101');
        }
    }

    public function test_barangay_without_pivot_links_never_falls_back_to_all_municipal_roads(): void
    {
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        DB::table('road_barangay')->where('barangay_id', 101)->delete();
        $this->getJson('/intelligence-map/locations/barangay/101/roads')->assertOk()
            ->assertJsonCount(0, 'features')->assertJsonPath('meta.status', 'empty');
        $this->getJson('/intelligence-map/locations/barangay/102/roads')->assertOk()
            ->assertJsonCount(1, 'features')->assertJsonPath('features.0.id', 2)
            ->assertJsonPath('features.0.properties.barangay_length_m', 0);
    }

    public function test_cursor_paging_preserves_geometry_and_numeric_zero_without_a_silent_result_cap(): void
    {
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        $url = '/intelligence-map/locations/municipality/11/roads?limit=1';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'features')->assertJsonPath('meta.next_cursor', 1);
        $this->getJson($url.'&cursor=1')->assertOk()->assertJsonPath('features.0.id', 2)->assertJsonPath('features.0.properties.length_m', 0)
            ->assertJsonPath('features.0.properties.name', '<script>unsafe name</script>')->assertJsonPath('meta.next_cursor', null);
    }

    public function test_invalid_selections_and_unlinked_barangays_are_rejected(): void
    {
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        $this->getJson('/intelligence-map/locations/country/1/roads')->assertUnprocessable();
        $this->getJson('/intelligence-map/locations/municipality/999/roads')->assertNotFound();
        $this->getJson('/intelligence-map/locations/barangay/103/roads')->assertUnprocessable();
        $this->getJson('/intelligence-map/locations/municipality/11/roads?cursor=-1')->assertUnprocessable();
        $this->getJson('/intelligence-map/locations/municipality/11/roads?limit=2001')->assertUnprocessable();
    }

    public function test_empty_missing_and_invalid_geometry_datasets_return_explicit_results(): void
    {
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        DB::table('roads')->where('municipality_id', 11)->update(['geometry' => '{bad json}']);
        $this->getJson('/intelligence-map/locations/municipality/11/roads')->assertOk()->assertJsonCount(0, 'features')->assertJsonPath('meta.omitted_geometries', 2);
        Schema::drop('road_barangay');
        $this->getJson('/intelligence-map/locations/barangay/101/roads')->assertOk()->assertJsonPath('meta.status', 'unavailable');
        DB::table('roads')->delete();
        $this->getJson('/intelligence-map/locations/municipality/11/roads')->assertOk()->assertJsonPath('meta.status', 'empty');
        Schema::drop('roads');
        $this->getJson('/intelligence-map/locations/province/1/roads')->assertOk()->assertJsonPath('meta.status', 'unavailable');
    }
}
