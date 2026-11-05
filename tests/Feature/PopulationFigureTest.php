<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\PopulationFigure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PopulationFigureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Imported straight into MySQL, so build just enough schema here.
        Schema::create('provinces', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('name');
        });

        Schema::create('municipalities', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('name');
            $table->string('municipality')->nullable();
        });

        Schema::create('barangays', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('municipality_gadm_id')->nullable();
            $table->string('name');
        });

        Schema::create('population_figures', function ($table) {
            $table->id();
            $table->string('area_level')->nullable();
            $table->string('area_ref')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('area_name')->nullable();
            $table->string('province')->nullable();
            $table->string('municipality')->nullable();
            $table->string('psgc10')->nullable();
            $table->string('psgc_corr')->nullable();
            $table->unsignedBigInteger('population')->nullable();
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
            ['id' => 1, 'gadm_id' => 'PHL.47.14_1', 'name' => 'QuezonCity', 'municipality' => 'QuezonCity'],
        ]);

        DB::table('barangays')->insert([
            ['id' => 1, 'gadm_id' => 'PHL.47.14.1_1', 'municipality_gadm_id' => 'PHL.47.14_1', 'name' => 'Alicia'],
            ['id' => 2, 'gadm_id' => 'PHL.47.14.2_1', 'municipality_gadm_id' => 'PHL.47.14_1', 'name' => 'BagongSilang'],
        ]);

        DB::table('population_figures')->insert([
            [
                'area_level' => 'municipality', 'area_ref' => 'PHL.47.14_1', 'municipality_id' => 1,
                'area_name' => 'QuezonCity', 'municipality' => null, 'population' => 3084270,
                'census_year' => 2024, 'vintage' => '2024_cph', 'match_status' => 'matched',
                'psgc_corr' => '137404000',
            ],
            [
                'area_level' => 'barangay', 'area_ref' => 'PHL.47.14.1_1', 'municipality_id' => 1,
                'area_name' => 'Alicia', 'municipality' => 'QuezonCity', 'population' => 6298,
                'census_year' => 2024, 'vintage' => '2024_cph', 'match_status' => 'matched',
                'psgc_corr' => null,
            ],
            [
                'area_level' => 'barangay', 'area_ref' => 'PHL.47.14.2_1', 'municipality_id' => 1,
                'area_name' => 'BagongSilang', 'municipality' => 'QuezonCity', 'population' => null,
                'census_year' => 2024, 'vintage' => '2024_cph', 'match_status' => 'unmatched_parent',
                'psgc_corr' => null,
            ],
        ]);
    }

    public function test_it_casts_population_and_census_year_to_integers(): void
    {
        $figure = PopulationFigure::ofLevel('municipality')->first();

        $this->assertIsInt($figure->population);
        $this->assertIsInt($figure->census_year);
        $this->assertSame(3084270, $figure->population);
        $this->assertTrue($figure->isMatched());
        $this->assertFalse($figure->isBarangayLevel());
    }

    public function test_scopes_filter_by_level_vintage_and_match_status(): void
    {
        $this->assertSame(1, PopulationFigure::ofLevel(PopulationFigure::LEVEL_MUNICIPALITY)->count());
        $this->assertSame(2, PopulationFigure::ofLevel(PopulationFigure::LEVEL_BARANGAY)->count());
        $this->assertSame(3, PopulationFigure::ofVintage('2024_cph')->count());
        $this->assertSame(0, PopulationFigure::ofVintage('2020_cph')->count());
        $this->assertSame(2, PopulationFigure::matched()->count());
        $this->assertSame(1, PopulationFigure::needsReview()->count());
    }

    public function test_area_relation_resolves_to_the_correct_model_per_level(): void
    {
        $municipalityFigure = PopulationFigure::ofLevel('municipality')->first();
        $barangayFigure = PopulationFigure::ofLevel('barangay')->whereNotNull('population')->first();

        $this->assertInstanceOf(Municipality::class, $municipalityFigure->area);
        $this->assertSame('QuezonCity', $municipalityFigure->area->name);

        $this->assertInstanceOf(Barangay::class, $barangayFigure->area);
        $this->assertSame('Alicia', $barangayFigure->area->name);
    }

    public function test_level_specific_relations_are_null_on_the_wrong_level(): void
    {
        $municipalityFigure = PopulationFigure::ofLevel('municipality')->first();
        $this->assertNull($municipalityFigure->barangayArea);

        $barangayFigure = PopulationFigure::ofLevel('barangay')->first();
        $this->assertNull($barangayFigure->municipalityArea);
        $this->assertSame('Alicia', $barangayFigure->barangayArea->name);
    }

    public function test_municipality_model_relation_returns_the_parent_for_barangay_rows(): void
    {
        $barangayFigure = PopulationFigure::ofLevel('barangay')->first();

        $this->assertInstanceOf(Municipality::class, $barangayFigure->municipalityModel);
        $this->assertSame('QuezonCity', $barangayFigure->municipalityModel->name);
    }

    public function test_municipality_string_column_shadows_a_same_named_relation(): void
    {
        $barangayFigure = PopulationFigure::ofLevel('barangay')->first();

        // Guards the reason the relation is named municipalityModel(): the raw
        // column must win over any relation called "municipality".
        $this->assertSame('QuezonCity', $barangayFigure->municipality);
    }

    public function test_barangay_population_figures_are_joined_on_gadm_id_not_municipality_id(): void
    {
        $barangay = Barangay::where('gadm_id', 'PHL.47.14.1_1')->first();

        // Regression guard: both barangays share municipality_id 1, so joining
        // on that column would return 2 rows per barangay.
        $this->assertSame(1, $barangay->populationFigures()->count());
        $this->assertSame('Alicia', $barangay->populationFigures()->first()->area_name);
    }

    public function test_municipality_population_figures_span_its_own_row_and_its_barangays(): void
    {
        $municipality = Municipality::where('gadm_id', 'PHL.47.14_1')->first();

        // 1 own row + 2 barangay rows.
        $this->assertSame(3, $municipality->populationFigures()->count());
        $this->assertSame(1, $municipality->municipalityPopulationFigures()->count());
    }

    public function test_a_figure_is_only_matched_when_it_has_a_population(): void
    {
        // Mirrors the real dataset: matched rows always carry a figure and
        // unresolved rows never do, so the two sets must never overlap.
        $this->assertSame(
            PopulationFigure::count(),
            PopulationFigure::matched()->whereNotNull('population')->count()
                + PopulationFigure::needsReview()->whereNull('population')->count()
        );
        $this->assertSame(0, PopulationFigure::matched()->whereNull('population')->count());
    }
}
