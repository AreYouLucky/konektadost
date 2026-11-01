<?php

namespace Tests\Feature;

use App\Models\DataImport;
use App\Models\DataVintage;
use App\Models\PopulationFigure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('provinces', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('name');
            $table->text('geometry')->nullable();
        });

        Schema::create('municipalities', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('province_gadm_id')->nullable();
            $table->string('name');
            $table->text('geometry')->nullable();
        });

        Schema::create('barangays', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('municipality_gadm_id')->nullable();
            $table->string('name');
        });

        Schema::create('population_figures', function ($table) {
            $table->id();
            $table->string('area_level');
            $table->string('area_ref')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('area_name')->nullable();
            $table->string('province')->nullable();
            $table->string('municipality')->nullable();
            $table->string('psgc10')->nullable();
            $table->string('psgc_corr')->nullable();
            $table->unsignedBigInteger('population')->nullable();
            $table->unsignedBigInteger('original_population')->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->smallInteger('census_year')->nullable();
            $table->string('figure_basis')->nullable();
            $table->string('vintage');
            $table->string('match_status')->nullable();
            $table->string('city_class')->nullable();
            $table->string('income_class')->nullable();
            $table->string('urban_rural')->nullable();
            $table->string('source')->nullable();
            $table->string('source_url')->nullable();
            $table->timestamps();
        });

        DB::table('municipalities')->insert([
            ['id' => 1, 'gadm_id' => 'MUN.1', 'name' => 'Alpha'],
        ]);

        DB::table('barangays')->insert([
            ['id' => 1, 'gadm_id' => 'BRG.1', 'municipality_gadm_id' => 'MUN.1', 'name' => 'Poblacion'],
        ]);
    }

    public function test_guests_cannot_reach_the_data_modules(): void
    {
        $this->get('/admin/data/population')->assertRedirect('/login');
        $this->get('/admin/data/import')->assertRedirect('/login');
    }

    public function test_admins_can_reach_the_data_modules(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/data/population')->assertOk();
        $this->actingAs($admin)->get('/admin/data/import')->assertOk();
    }

    public function test_technicians_can_reach_the_data_modules(): void
    {
        $technician = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        $this->actingAs($technician)->get('/admin/data/population')->assertOk();
        $this->actingAs($technician)->get('/admin/data/import')->assertOk();
    }

    public function test_technicians_cannot_reach_the_admin_dashboard(): void
    {
        // Technicians maintain data; they are not administrators.
        $technician = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        $this->actingAs($technician)
            ->get('/admin/dashboard')
            ->assertRedirect(route('user.dashboard.index'));
    }

    public function test_standard_users_are_bounced_from_the_data_modules(): void
    {
        $plain = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($plain)
            ->get('/admin/data/population')
            ->assertRedirect(route('user.dashboard.index'));

        $this->actingAs($plain)
            ->get('/admin/data/import')
            ->assertRedirect(route('user.dashboard.index'));
    }

    public function test_the_population_page_can_be_filtered(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'area_name' => 'Alpha',
            'population' => 100,
            'vintage' => '2024_cph',
            'match_status' => 'matched',
        ]);

        PopulationFigure::create([
            'area_level' => 'barangay',
            'area_ref' => 'BRG.1',
            'area_name' => 'Poblacion',
            'population' => null,
            'vintage' => '2024_cph',
            'match_status' => 'unmatched_parent',
        ]);

        $this->actingAs($admin)
            ->get('/admin/data/population?level=barangay')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/data/population')
                ->where('filters.level', 'barangay')
                ->has('figures.data', 1)
            );

        $this->actingAs($admin)
            ->get('/admin/data/population?status=unmatched_parent')
            ->assertInertia(fn ($page) => $page->has('figures.data', 1));

        $this->actingAs($admin)
            ->get('/admin/data/population?q=Alpha')
            ->assertInertia(fn ($page) => $page->has('figures.data', 1));
    }

    public function test_correcting_a_figure_through_the_endpoint_records_the_audit_trail(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $figure = PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'area_name' => 'Alpha',
            'population' => 40000,
            'vintage' => '2024_cph',
            'match_status' => 'matched',
        ]);

        $this->actingAs($admin)
            ->patch("/admin/data/population/{$figure->id}", ['population' => 45000])
            ->assertRedirect();

        $figure->refresh();

        $this->assertSame(45000, $figure->population);
        $this->assertSame(40000, $figure->original_population);
        $this->assertSame($admin->id, $figure->edited_by);
    }

    public function test_a_standard_user_cannot_correct_a_figure(): void
    {
        $plain = User::factory()->create(['role' => User::ROLE_USER]);

        $figure = PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'population' => 40000,
            'vintage' => '2024_cph',
            'match_status' => 'matched',
        ]);

        $this->actingAs($plain)
            ->patch("/admin/data/population/{$figure->id}", ['population' => 1])
            ->assertRedirect(route('user.dashboard.index'));

        $this->assertSame(40000, $figure->fresh()->population);
    }

    public function test_a_negative_population_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $figure = PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'population' => 40000,
            'vintage' => '2024_cph',
            'match_status' => 'matched',
        ]);

        $this->actingAs($admin)
            ->patch("/admin/data/population/{$figure->id}", ['population' => -1])
            ->assertSessionHasErrors('population');

        $this->assertSame(40000, $figure->fresh()->population);
    }

    public function test_import_preview_rejects_a_file_with_no_upload(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post('/admin/data/import/preview')
            ->assertSessionHasErrors('file');
    }

    private function csvUpload(string $contents, string $name = 'population.csv')
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_import_preview_reports_errors_without_writing(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->post('/admin/data/import/preview', [
            'file' => $this->csvUpload(
                "area_level,area_ref,population,vintage\nmunicipality,MUN.NOPE,10,2030_cph\n"
            ),
        ]);

        $response->assertOk();

        $json = $response->json();

        $this->assertFalse($json['valid']);
        $this->assertArrayHasKey('area_ref', $json['errors']);
        $this->assertStringContainsString('MUN.NOPE', json_encode($json['errors']));
        $this->assertFalse($json['committed']);
        $this->assertSame(0, PopulationFigure::where('vintage', '2030_cph')->count());
    }

    public function test_import_preview_accepts_a_valid_file(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->post('/admin/data/import/preview', [
            'file' => $this->csvUpload(
                "area_level,area_ref,population,census_year,vintage,area_name\nmunicipality,MUN.1,50000,2030,2030_cph,Alpha\n"
            ),
        ]);

        $json = $response->json();

        $this->assertTrue($json['valid'], json_encode($json['errors']));
        $this->assertSame(1, $json['rows_total']);
        $this->assertSame(1, $json['rows_with_population']);

        // A preview must never write.
        $this->assertSame(0, PopulationFigure::where('vintage', '2030_cph')->count());
        $this->assertSame(1, DataImport::where('status', 'preview')->count());
    }

    public function test_import_commit_writes_the_figures_and_registers_the_vintage(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->post('/admin/data/import/commit', [
            'file' => $this->csvUpload(
                "area_level,area_ref,population,census_year,vintage,area_name\nmunicipality,MUN.1,50000,2030,2030_cph,Alpha\nbarangay,BRG.1,5000,2030,2030_cph,Poblacion\n"
            ),
            'vintage' => '2030_cph',
            'make_current' => true,
        ]);

        $response->assertOk();

        $json = $response->json();

        $this->assertTrue($json['committed'], json_encode($json['errors']));
        $this->assertSame(2, $json['inserted']);
        $this->assertSame(2, PopulationFigure::where('vintage', '2030_cph')->count());

        $vintage = DataVintage::where('vintage', '2030_cph')->first();
        $this->assertNotNull($vintage);
        $this->assertTrue($vintage->is_current);
        $this->assertSame($admin->id, $vintage->imported_by);
    }

    public function test_import_commit_requires_a_vintage(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/data/import/commit', [
            'file' => $this->csvUpload("area_level,area_ref,population,vintage\nmunicipality,MUN.1,1,2030_cph\n"),
        ])->assertSessionHasErrors('vintage');
    }

    public function test_a_standard_user_cannot_import(): void
    {
        $plain = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($plain)
            ->post('/admin/data/import/preview', [
                'file' => $this->csvUpload("area_level,area_ref,population,vintage\nmunicipality,MUN.1,1,2030_cph\n"),
            ])
            ->assertRedirect(route('user.dashboard.index'));
    }

    public function test_the_temporary_uploaded_file_is_removed_after_committing(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Storage::fake('local');

        $this->actingAs($admin)->post('/admin/data/import/commit', [
            'file' => $this->csvUpload("area_level,area_ref,population,vintage\nmunicipality,MUN.1,1,2030_cph\n"),
            'vintage' => '2030_cph',
        ])->assertOk();

        $this->assertEmpty(Storage::disk('local')->files('imports'));
    }
}
