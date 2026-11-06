<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DataEntriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'entries_testing',
            'database.connections.entries_testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
        $this->withoutVite();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('provinces', fn (Blueprint $table) => [$table->id(), $table->string('name')]);
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('province_name')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->text('geometry')->nullable();
        });
        Schema::create('barangays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('brgy_name');
            $table->string('psg_code')->nullable();
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->text('geometry')->nullable();
        });
        Schema::create('dost_techs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            foreach (['agency', 'sector', 'status'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['deployment_clusters', 'mechanism', 'outcome'] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamps();
        });
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            foreach (['name', 'address', 'sector', 'level', 'operator', 'capacity'] as $column) {
                $table->string($column)->nullable();
            }
            $table->string('facility_type');
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->text('description')->nullable();
            $table->text('source')->nullable();
            $table->unsignedSmallInteger('capacity_no')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();
        });

        // Synthetic squares: the city covers 120–121°E / 14–15°N, its barangay the south-west quarter.
        $square = fn ($west, $south, $east, $north) => json_encode(['type' => 'Polygon', 'coordinates' => [[[$west, $south], [$east, $south], [$east, $north], [$west, $north], [$west, $south]]]]);
        DB::table('provinces')->insert(['id' => 1, 'name' => 'Test Province']);
        DB::table('municipalities')->insert(['id' => 11, 'name' => 'Test City', 'province_name' => 'Test Province', 'province_id' => 1, 'center_lat' => 14.5, 'center_lng' => 120.5, 'geometry' => $square(120, 14, 121, 15)]);
        DB::table('barangays')->insert(['id' => 101, 'municipality_id' => 11, 'brgy_name' => 'Barangay Uno', 'psg_code' => '0000000101', 'center_lat' => 14.25, 'center_lng' => 120.25, 'geometry' => $square(120, 14, 120.5, 14.5)]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'password' => Hash::make('secret-password')],
            ['id' => 2, 'name' => 'Viewer', 'email' => 'viewer@example.test', 'role' => 'user', 'password' => Hash::make('secret-password')],
        ]);
    }

    private function admin(): static
    {
        return $this->actingAs(User::query()->findOrFail(1));
    }

    public function test_only_administrators_reach_data_entries(): void
    {
        $this->actingAs(User::query()->findOrFail(2))->get('/admin/data-entries')->assertRedirect();
        $this->admin()->get('/admin/data-entries')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/data-entries/index')
            ->where('sections', fn ($sections) => collect($sections)->firstWhere('key', 'dost-technologies')['count'] === 0
                && collect($sections)->firstWhere('key', 'laboratories')['available'] === false));
    }

    public function test_dost_technologies_can_be_created_searched_updated_and_deleted(): void
    {
        $this->admin()->post('/admin/data-entries/dost-technologies', ['code' => 'SETUP-FOOD-01', 'name' => 'Food processing', 'sector' => 'Agriculture'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $id = DB::table('dost_techs')->where('code', 'SETUP-FOOD-01')->value('id');
        $this->assertNotNull($id);

        $this->post('/admin/data-entries/dost-technologies', ['code' => 'SETUP-FOOD-01', 'name' => 'Duplicate'])->assertSessionHasErrors('code');

        $this->get('/admin/data-entries/dost-technologies?search=food')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/data-entries/resource')
            ->has('rows', 1)
            ->where('rows.0.name', 'Food processing'));

        $this->put("/admin/data-entries/dost-technologies/{$id}", ['code' => 'SETUP-FOOD-01', 'name' => 'Food processing v2', 'sector' => ''])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dost_techs', ['id' => $id, 'name' => 'Food processing v2', 'sector' => null]);

        $this->delete("/admin/data-entries/dost-technologies/{$id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('dost_techs', ['id' => $id]);
    }

    public function test_a_facility_is_linked_to_the_city_and_barangay_containing_its_coordinates(): void
    {
        $this->admin()->post('/admin/data-entries/health-facilities', [
            'name' => 'Rural Health Unit', 'facility_type' => 'health_post', 'latitude' => 14.2, 'longitude' => 120.2,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('facilities', ['name' => 'Rural Health Unit', 'sector' => 'health', 'municipality_id' => 11, 'barangay_id' => 101]);
        $this->get('/admin/data-entries/health-facilities')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.0.location_label', 'Barangay Uno, Test City, Test Province'));
        // Each sector page only lists its own sector.
        $this->get('/admin/data-entries/safety-facilities')->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0));

        $this->getJson('/admin/data-entries/locate?latitude=14.8&longitude=120.9')
            ->assertOk()->assertJson(['municipality_id' => 11, 'barangay_id' => null, 'label' => 'Test City, Test Province']);
    }

    public function test_admin_overview_reports_usage_and_data_without_inventing_missing_tables(): void
    {
        DB::table('facilities')->insert(['name' => 'Clinic', 'facility_type' => 'clinic', 'sector' => 'health', 'municipality_id' => 11, 'latitude' => 14.2, 'longitude' => 120.2]);

        $this->admin()->get('/admin/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/dashboard')
            ->where('users.total', 2)
            ->where('users.active', null) // no last_login column in this schema
            ->where('planning', null) // planning tables absent
            ->where('data.geography.municipalities', 1)
            ->where('data.datasets', fn ($datasets) => collect($datasets)->firstWhere('key', 'health-facilities')['count'] === 1
                && collect($datasets)->firstWhere('key', 'laboratories')['count'] === null)
            ->where('data.quality.0', ['label' => 'Facilities linked to a city / municipality', 'value' => 1, 'total' => 1, 'note' => 'Counted by assessments only when linked.']));
    }

    public function test_user_management_protects_the_signed_in_administrator(): void
    {
        $this->admin()->post('/admin/data-entries/users', ['name' => 'Tech', 'email' => 'tech@example.test', 'role' => 'technician', 'password' => 'long-enough-password'])
            ->assertSessionHasNoErrors();
        $tech = User::query()->where('email', 'tech@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('long-enough-password', $tech->password));
        $this->assertNotNull($tech->email_verified_at);

        $this->post('/admin/data-entries/users', ['name' => 'No password', 'email' => 'x@example.test', 'role' => 'user'])->assertSessionHasErrors('password');
        $this->put('/admin/data-entries/users/1', ['name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'user'])->assertSessionHasErrors('role');
        $this->delete('/admin/data-entries/users/1')->assertSessionHasErrors('record');
        $this->assertDatabaseHas('users', ['id' => 1, 'role' => 'admin']);

        // A blank password on update keeps the current one.
        $this->put("/admin/data-entries/users/{$tech->id}", ['name' => 'Technician', 'email' => 'tech@example.test', 'role' => 'technician', 'password' => ''])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('long-enough-password', $tech->fresh()->password));
    }
}
