<?php

namespace Tests\Feature;

use App\Models\DataVintage;
use App\Models\PopulationFigure;
use App\Models\User;
use App\Support\Import\PopulationCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PopulationDataManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('municipalities', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('name');
        });

        Schema::create('barangays', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('municipality_gadm_id')->nullable();
            $table->string('name');
        });

        // Imported table, so the audit-columns migration skips it; build it here
        // with those columns present.
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

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pop').'.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    private function validCsv(string $vintage = '2030_cph'): string
    {
        return $this->csv(<<<CSV
        area_level,area_ref,population,census_year,vintage,area_name
        municipality,MUN.1,50000,2030,{$vintage},Alpha
        barangay,BRG.1,5000,2030,{$vintage},Poblacion
        CSV);
    }

    public function test_it_validates_a_well_formed_file(): void
    {
        $result = (new PopulationCsvImporter)->preview($this->validCsv(), 'pop.csv');

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('pop.csv', $result['filename']);
        $this->assertSame(2, $result['rows_total']);
        $this->assertSame(1, $result['rows_municipality']);
        $this->assertSame(1, $result['rows_barangay']);
        $this->assertSame(2030, $result['census_year']);
        $this->assertFalse($result['committed']);
    }

    public function test_it_rejects_a_file_missing_required_columns(): void
    {
        $result = (new PopulationCsvImporter)->preview(
            $this->csv("foo,bar\n1,2\n"),
            'bad.csv'
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('_header', $result['errors']);
        $this->assertStringContainsString('area_ref', json_encode($result['errors']));
    }

    public function test_it_rejects_an_unknown_area_reference(): void
    {
        $result = (new PopulationCsvImporter)->preview(
            $this->csv("area_level,area_ref,population,vintage\nmunicipality,MUN.NOPE,10,2030_cph\n"),
            'bad.csv'
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('MUN.NOPE', json_encode($result['errors']['area_ref']));
    }

    public function test_it_rejects_a_negative_population(): void
    {
        $result = (new PopulationCsvImporter)->preview(
            $this->csv("area_level,area_ref,population,vintage\nmunicipality,MUN.1,-5,2030_cph\n"),
            'bad.csv'
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('non-negative', json_encode($result['errors']['population']));
    }

    public function test_it_rejects_an_invalid_area_level(): void
    {
        $result = (new PopulationCsvImporter)->preview(
            $this->csv("area_level,area_ref,population,vintage\nprovince,MUN.1,10,2030_cph\n"),
            'bad.csv'
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('area_level', $result['errors']);
    }

    public function test_it_flags_duplicate_areas_within_one_file(): void
    {
        $result = (new PopulationCsvImporter)->preview(
            $this->csv("area_level,area_ref,population,vintage\nmunicipality,MUN.1,10,2030_cph\nmunicipality,MUN.1,20,2030_cph\n"),
            'dupe.csv'
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('duplicate', $result['errors']);
    }

    public function test_a_small_file_warns_but_stays_valid(): void
    {
        // A provincial extract is legitimate; it should not be rejected.
        $result = (new PopulationCsvImporter)->preview($this->validCsv(), 'partial.csv');

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_it_commits_a_new_vintage_without_touching_the_existing_one(): void
    {
        $existing = PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'population' => 40000,
            'census_year' => 2024,
            'vintage' => '2024_cph',
            'match_status' => 'matched',
        ]);

        $result = (new PopulationCsvImporter)->commit(
            $this->validCsv(),
            '2030_cph',
            null,
            true
        );

        $this->assertTrue($result['committed'], json_encode($result['errors']));
        $this->assertSame(2, $result['inserted']);

        // The 2024 figure is untouched.
        $this->assertSame(40000, $existing->fresh()->population);

        // The 2030 figure exists alongside it.
        $this->assertSame(50000, PopulationFigure::where('vintage', '2030_cph')
            ->where('area_ref', 'MUN.1')
            ->value('population'));
    }

    public function test_it_updates_rows_that_already_exist_for_the_same_vintage(): void
    {
        PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'population' => 40000,
            'vintage' => '2030_cph',
            'match_status' => 'matched',
        ]);

        PopulationFigure::create([
            'area_level' => 'barangay',
            'area_ref' => 'BRG.1',
            'population' => 4000,
            'vintage' => '2030_cph',
            'match_status' => 'matched',
        ]);

        $result = (new PopulationCsvImporter)->commit($this->validCsv(), '2030_cph', null, false);

        $this->assertSame(2, $result['updated']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(50000, PopulationFigure::where('vintage', '2030_cph')
            ->where('area_ref', 'MUN.1')
            ->value('population'));
    }

    public function test_it_inserts_only_the_rows_that_are_new(): void
    {
        PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'population' => 40000,
            'vintage' => '2030_cph',
            'match_status' => 'matched',
        ]);

        $result = (new PopulationCsvImporter)->commit($this->validCsv(), '2030_cph', null, false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(2, PopulationFigure::where('vintage', '2030_cph')->count());
    }

    public function test_a_failed_validation_commits_nothing(): void
    {
        $result = (new PopulationCsvImporter)->commit(
            $this->csv("area_level,area_ref,population,vintage\nmunicipality,MUN.NOPE,10,2030_cph\n"),
            '2030_cph'
        );

        $this->assertFalse($result['committed']);
        $this->assertSame(0, PopulationFigure::where('vintage', '2030_cph')->count());
    }

    public function test_import_registers_a_vintage_and_can_make_it_current(): void
    {
        (new PopulationCsvImporter)->commit($this->validCsv(), '2030_cph', null, true);

        $vintage = DataVintage::where('vintage', '2030_cph')->first();

        $this->assertNotNull($vintage);
        $this->assertTrue($vintage->is_current);
        $this->assertSame('2030_cph', DataVintage::activeVintage());
    }

    public function test_marking_a_vintage_current_clears_the_previous_one(): void
    {
        $first = DataVintage::create(['vintage' => '2024_cph', 'is_current' => true]);
        $second = DataVintage::create(['vintage' => '2030_cph', 'is_current' => false]);

        $second->markCurrent();

        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
        $this->assertSame(1, DataVintage::current()->count());
    }

    public function test_active_vintage_falls_back_to_the_newest_when_none_is_marked(): void
    {
        DataVintage::create(['vintage' => '2024_cph', 'census_year' => 2024, 'is_current' => false]);
        DataVintage::create(['vintage' => '2030_cph', 'census_year' => 2030, 'is_current' => false]);

        $this->assertSame('2030_cph', DataVintage::activeVintage());
    }

    public function test_correcting_a_figure_retains_the_original_value_and_editor(): void
    {
        $editor = User::factory()->create();

        $figure = PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'population' => 40000,
            'vintage' => '2024_cph',
            'match_status' => 'matched',
        ]);

        $figure->applyPopulationCorrection(45000, $editor->id);

        $figure->refresh();

        $this->assertSame(45000, $figure->population);
        $this->assertSame(40000, $figure->original_population);
        $this->assertSame($editor->id, $figure->edited_by);
        $this->assertNotNull($figure->edited_at);
        $this->assertTrue($figure->isCorrected());
        $this->assertSame('corrected', PopulationFigure::corrected()->first()->id === $figure->id ? 'corrected' : 'missing');
    }

    public function test_a_second_correction_does_not_overwrite_the_first_original(): void
    {
        $figure = PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'population' => 40000,
            'vintage' => '2024_cph',
            'match_status' => 'matched',
        ]);

        $figure->applyPopulationCorrection(45000, null);
        $figure->applyPopulationCorrection(46000, null);
        $figure->refresh();

        $this->assertSame(46000, $figure->population);
        $this->assertSame(40000, $figure->original_population);
    }

    public function test_audit_columns_are_not_mass_assignable(): void
    {
        // A request must never be able to backdate or fake a correction.
        $figure = PopulationFigure::create([
            'area_level' => 'municipality',
            'area_ref' => 'MUN.1',
            'population' => 40000,
            'vintage' => '2024_cph',
            'match_status' => 'matched',
        ]);

        $this->assertNull($figure->original_population);
        $this->assertNull($figure->edited_by);
        $this->assertFalse($figure->isCorrected());
    }
}
