<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The geography tables are imported rather than migrated, so the map
        // route needs them to exist even empty. The columns mirror what
        // LocationController::show() selects.
        Schema::create('provinces', function ($table) {
            $table->id();
            $table->string('psg_code')->unique();
            $table->string('name');
            $table->string('variant_name')->nullable();
            $table->string('english_type')->nullable();
            $table->string('cc_code')->nullable();
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
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
            $table->text('geometry')->nullable();
        });

        Schema::create('barangays', function ($table) {
            $table->id();
            $table->string('psg_code')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('name');
        });
    }

    public function test_guests_are_redirected_to_login_from_the_admin_area(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_admins_reach_the_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/dashboard'));
    }

    public function test_non_admins_are_bounced_to_their_own_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]))
            ->get('/admin/dashboard')
            ->assertRedirect(route('user.dashboard.index'));
    }

    public function test_a_null_role_is_treated_as_a_non_admin(): void
    {
        // Null must never grant access; least privilege has to be the default
        // for accounts that predate the role column.
        $this->actingAs(User::factory()->create(['role' => null]))
            ->get('/admin/dashboard')
            ->assertRedirect(route('user.dashboard.index'));
    }

    public function test_role_matching_is_case_insensitive(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'ADMIN']))
            ->get('/admin/dashboard')
            ->assertOk();
    }

    public function test_non_admins_can_still_use_the_map(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]))
            ->get('/location')
            ->assertOk();
    }

    public function test_the_user_dashboard_renders_for_admins_too(): void
    {
        // The map and standard dashboard are shared, so an admin should not be
        // locked out of them.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_dashboard_route_resolves_by_role(): void
    {
        $this->assertSame(
            'admin.dashboard.index',
            (new User(['role' => 'admin']))->dashboardRoute()
        );

        $this->assertSame(
            'user.dashboard.index',
            (new User(['role' => 'user']))->dashboardRoute()
        );

        $this->assertSame(
            'user.dashboard.index',
            (new User(['role' => null]))->dashboardRoute()
        );
    }

    public function test_is_admin_matches_the_role_used_by_the_middleware(): void
    {
        $this->assertTrue((new User(['role' => 'admin']))->isAdmin());
        $this->assertTrue((new User(['role' => 'ADMIN']))->isAdmin());
        $this->assertFalse((new User(['role' => 'user']))->isAdmin());
        $this->assertFalse((new User(['role' => null]))->isAdmin());
    }

    public function test_registration_assigns_the_default_user_role(): void
    {
        $this->post('/register', [
            'name' => 'New Person',
            'email' => 'new.person@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'new.person@example.com',
            'role' => User::ROLE_USER,
        ]);
    }

    public function test_the_shared_props_expose_the_role_to_the_frontend(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/dashboard')
            ->assertOk();

        $auth = $response->viewData('page')['props']['auth'];

        $this->assertSame('admin', $auth['user']['role']);
    }

    public function test_a_full_page_response_still_carries_the_role(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]))
            ->get('/dashboard')
            ->assertOk();

        $this->assertSame(
            'user',
            $response->viewData('page')['props']['auth']['user']['role']
        );
    }

    public function test_login_records_last_login(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'last_login' => null,
        ]);

        $this->assertNull($user->fresh()->last_login);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // Regression guard: last_login is not mass assignable, so using
        // update() silently dropped it and the column stayed empty forever.
        $this->assertNotNull($user->fresh()->last_login);
    }

    public function test_login_sends_each_role_to_its_own_dashboard(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard.index'));

        $this->post('/logout');

        $plain = User::factory()->create(['role' => User::ROLE_USER]);

        $this->post('/login', [
            'email' => $plain->email,
            'password' => 'password',
        ])->assertRedirect(route('user.dashboard.index'));
    }

    public function test_the_admin_route_group_is_registered_under_the_admin_prefix(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === 'admin.dashboard.index');

        $this->assertNotNull($route, 'The admin.dashboard.index route is not registered.');
        $this->assertSame('admin/dashboard', $route->uri());
    }
}
