<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Benchmark;
use App\Models\Municipality;
use App\Models\User;
use App\Support\Assessment\IndicatorCatalog;
use App\Support\Assessment\IndicatorResult;
use App\Support\Assessment\LocationAssessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationAssessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('municipalities', function ($table) {
            $table->id();
            $table->string('psg_code')->unique();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('province_name')->nullable();
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->decimal('area_km2', 12, 4)->nullable();
        });

        Schema::create('barangays', function ($table) {
            $table->id();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('psg_code')->unique();
            $table->string('brgy_name');
            $table->decimal('area_km2', 12, 4)->nullable();
        });

        // psgc_units is the source of truth for population, joined on psg_code.
        //
        // The migration creates this table with the older psgc10/population_2024
        // column names, but the live database (and this app's code) uses
        // psg_code/population. Drop the migrated shape and rebuild to match the
        // live schema the psgcUnit() relation actually runs against.
        Schema::drop('psgc_units');
        Schema::drop('psgc_releases');

        Schema::create('psgc_releases', function ($table) {
            $table->id();
            $table->string('label');
        });

        Schema::create('psgc_units', function ($table) {
            $table->id();
            $table->unsignedBigInteger('release_id');
            $table->string('psg_code');
            $table->string('name');
            $table->string('level');
            $table->unsignedBigInteger('population')->nullable();
        });

        Schema::create('facilities', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('facility_type')->nullable();
            $table->string('sector')->nullable();
            $table->string('level')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('laboratories', function ($table) {
            $table->id();
            $table->string('agency_name')->nullable();
            $table->string('code')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->boolean('is_outside_philippines')->default(false);
            $table->timestamps();
        });

        Schema::create('facility_tests', function ($table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_id')->nullable();
            $table->string('test_name')->nullable();
            $table->string('method')->nullable();
            $table->timestamps();
        });

        // A well-served city: 100,000 people over 100 km².
        DB::table('municipalities')->insert([
            ['id' => 1, 'psg_code' => '0102801000', 'name' => 'WellServedCity', 'type' => 'municipality', 'province_name' => 'IlocosNorte', 'area_km2' => 100],
            ['id' => 2, 'psg_code' => '0102802000', 'name' => 'UnderservedMunicipality', 'type' => 'municipality', 'province_name' => 'IlocosNorte', 'area_km2' => 100],
            // No matched population figure, to exercise the unavailable path.
            ['id' => 3, 'psg_code' => '0102803000', 'name' => 'NoPopulationTown', 'type' => 'municipality', 'province_name' => 'IlocosNorte', 'area_km2' => 50],
        ]);

        DB::table('barangays')->insert([
            ['id' => 1, 'municipality_id' => 1, 'psg_code' => '0102801001', 'brgy_name' => 'Poblacion', 'area_km2' => 10],
        ]);

        DB::table('psgc_releases')->insert([
            ['id' => 1, 'label' => '3Q 2026'],
        ]);

        DB::table('psgc_units')->insert([
            ['release_id' => 1, 'psg_code' => '0102801000', 'name' => 'WellServedCity', 'level' => 'City', 'population' => 100000],
            ['release_id' => 1, 'psg_code' => '0102802000', 'name' => 'UnderservedMunicipality', 'level' => 'Mun', 'population' => 50000],
            // Population column is null: PSA published no figure, so every
            // indicator must report no data rather than treating it as zero.
            ['release_id' => 1, 'psg_code' => '0102803000', 'name' => 'NoPopulationTown', 'level' => 'Mun', 'population' => null],
            ['release_id' => 1, 'psg_code' => '0102801001', 'name' => 'Poblacion', 'level' => 'Bgy', 'population' => 20000],
        ]);

        // 20 health facilities and 20 schools over 100,000 people => 20 per 100k.
        $facilities = [];
        for ($i = 0; $i < 20; $i++) {
            $facilities[] = ['municipality_id' => 1, 'name' => "Health $i", 'facility_type' => 'clinic',
                'sector' => 'health', 'level' => null];
            $facilities[] = ['municipality_id' => 1, 'name' => "School $i", 'facility_type' => 'school',
                'sector' => 'education', 'level' => 'elementary'];
        }
        // The underserved municipality has nothing recorded at all.
        DB::table('facilities')->insert($facilities);

        DB::table('laboratories')->insert([
            ['id' => 1, 'agency_name' => 'Lab A', 'code' => 'A', 'municipality_id' => 1, 'is_outside_philippines' => false],
        ]);

        DB::table('facility_tests')->insert([
            ['id' => 1, 'laboratory_id' => 1, 'test_name' => 'Coliform', 'method' => 'MPN'],
            // Same test and method under a different row: must not double count.
            ['id' => 2, 'laboratory_id' => 1, 'test_name' => 'Coliform', 'method' => 'MPN'],
            ['id' => 3, 'laboratory_id' => 1, 'test_name' => 'Salmonella', 'method' => 'Pour Plate'],
        ]);

        Benchmark::create([
            'indicator_key' => 'health_facilities_per_100k', 'scope' => 'national', 'scope_ref' => '',
            'value' => 10, 'unit' => 'facilities per 100,000 people', 'basis' => Benchmark::BASIS_NATIONAL_RATE,
            'source' => 'OpenStreetMap', 'vintage' => '2024_cph',
        ]);
        Benchmark::create([
            'indicator_key' => 'population_density', 'scope' => 'national', 'scope_ref' => '',
            'value' => 400, 'unit' => 'persons per km²', 'basis' => Benchmark::BASIS_NATIONAL_RATE,
            'source' => 'PSA 2024 POPCEN', 'vintage' => '2024_cph',
        ]);
    }

    /**
     * @return Collection<string, IndicatorResult>
     */
    private function resultsFor(int $municipalityId)
    {
        return collect((new LocationAssessor)->assess(Municipality::find($municipalityId)))
            ->keyBy(fn (IndicatorResult $r) => $r->indicator->key);
    }

    public function test_it_computes_rates_against_a_matched_population(): void
    {
        $results = $this->resultsFor(1);

        $this->assertSame(20.0, $results['health_facilities_per_100k']->value);
        $this->assertSame(20.0, $results['schools_per_100k']->value);
        $this->assertSame(20.0, $results['elementary_schools_per_100k']->value);
    }

    public function test_it_computes_density_from_population_and_area(): void
    {
        $result = $this->resultsFor(1)['population_density'];

        $this->assertSame(1000.0, $result->value);
        $this->assertSame(400.0, $result->benchmark);
    }

    public function test_it_flags_a_shortfall_against_the_national_rate(): void
    {
        $results = $this->resultsFor(2);

        // 50,000 people with nothing recorded: 0 per 100,000 against a
        // national rate of 10.
        $health = $results['health_facilities_per_100k'];
        $this->assertSame(0.0, $health->value);
        $this->assertSame(10.0, $health->benchmark);
        $this->assertSame(-100.0, $health->gap);
        $this->assertTrue($health->isShortfall());
    }

    public function test_a_well_served_area_is_not_a_shortfall(): void
    {
        $this->assertFalse($this->resultsFor(1)['health_facilities_per_100k']->isShortfall());
    }

    public function test_density_above_the_national_rate_reads_as_pressure(): void
    {
        // Density is inverted: exceeding the national rate signals pressure on
        // services rather than surplus.
        $result = $this->resultsFor(1)['population_density'];

        $this->assertTrue($result->isShortfall());
    }

    public function test_an_area_without_a_matched_population_reports_no_data(): void
    {
        $results = $this->resultsFor(3);

        foreach ($results as $result) {
            $this->assertSame(IndicatorResult::STATUS_NO_DATA, $result->status);
            $this->assertNull($result->value);
            $this->assertFalse($result->isShortfall());
        }
    }

    public function test_an_indicator_without_a_benchmark_reports_no_benchmark(): void
    {
        $result = $this->resultsFor(1)['hospitals_per_100k'];

        $this->assertSame(IndicatorResult::STATUS_NO_BENCHMARK, $result->status);
        $this->assertSame(0.0, $result->value);
        $this->assertNull($result->benchmark);
        // No benchmark means no shortfall may be claimed.
        $this->assertFalse($result->isShortfall());
    }

    public function test_a_zero_from_a_volunteer_source_is_low_confidence(): void
    {
        $result = $this->resultsFor(2)['hospitals_per_100k'];

        $this->assertSame(0.0, $result->value);
        $this->assertTrue($result->isLowConfidence());
        $this->assertStringContainsString('may mean they were never mapped', $result->reason);
    }

    public function test_an_area_with_no_facilities_does_not_trip_the_zero_guard_when_unmeasured(): void
    {
        // Schools are present and classified in this fixture, so neither the
        // zero guard nor the level coverage check should fire.
        $result = $this->resultsFor(1)['elementary_schools_per_100k'];

        $this->assertSame(20.0, $result->value);
        $this->assertFalse($result->isLowConfidence());
        $this->assertStringNotContainsString('OpenStreetMap', $result->reason);
    }

    public function test_laboratory_tests_are_counted_distinctly(): void
    {
        $result = $this->resultsFor(1)['laboratory_tests_per_million'];

        // Three rows but only two distinct (test, method) pairs, expressed per
        // million: 2 tests / 100,000 people = 20 per million.
        $this->assertSame(20.0, $result->value);
    }

    public function test_it_can_assess_a_single_dimension(): void
    {
        $results = collect((new LocationAssessor)->assess(
            Municipality::find(1),
            IndicatorCatalog::DIMENSION_HEALTH
        ));

        $this->assertCount(3, $results);
        $this->assertSame(
            [IndicatorCatalog::DIMENSION_HEALTH],
            $results->map(fn (IndicatorResult $r) => $r->indicator->dimension)->unique()->values()->all()
        );
    }

    public function test_it_can_assess_a_barangay(): void
    {
        $results = collect((new LocationAssessor)->assess(Barangay::find(1)))
            ->keyBy(fn (IndicatorResult $r) => $r->indicator->key);

        // 20,000 people over 10 km².
        $this->assertSame(2000.0, $results['population_density']->value);
        $this->assertSame(0.0, $results['schools_per_100k']->value);
    }

    public function test_every_result_carries_its_provenance(): void
    {
        $result = $this->resultsFor(1)['health_facilities_per_100k']->toArray();

        $this->assertStringContainsString('OpenStreetMap', $result['source']);
        $this->assertSame(IndicatorCatalog::DIMENSION_HEALTH, $result['dimension']);
        $this->assertNotEmpty($result['unit']);
        $this->assertArrayHasKey('caveat', $result);
    }

    public function test_guests_cannot_run_an_assessment(): void
    {
        $this->get('/location/assess?city=0102801000')->assertRedirect('/login');
    }

    public function test_the_assessment_page_renders_measured_results(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/location/assess?city=0102801000&focus=general')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('location-assessment')
            ->where('focus', 'general')
            ->where('location.psg_code', '0102801000')
            ->where('location.province', 'IlocosNorte')
            ->where('location.population', 100000)
            // All ten framework dimensions are sent so the UI can show which
            // are supported, even though only eight indicators exist.
            ->has('dimensions', 10)
            ->has('results')
        );

        $results = collect($response->viewData('page')['props']['results']);

        $this->assertSame(1000.0, $results->firstWhere('key', 'population_density')['value']);
        $this->assertSame(20.0, $results->firstWhere('key', 'health_facilities_per_100k')['value']);
    }

    public function test_an_unsupported_dimension_reports_nothing_rather_than_guessing(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/location/assess?city=0102801000&focus=environment_climate_disaster')
            ->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertSame('environment_climate_disaster', $props['focus']);
        $this->assertSame([], $props['results']);
        $this->assertFalse(
            collect($props['dimensions'])->firstWhere('key', 'environment_climate_disaster')['supported']
        );
    }

    public function test_an_unknown_city_redirects_back_to_the_map(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/location/assess?city=DOES-NOT-EXIST')
            ->assertRedirect(route('location.show'));
    }

    public function test_the_catalog_only_exposes_supported_dimensions(): void
    {
        $this->assertNotEmpty(IndicatorCatalog::supportedDimensions());

        foreach (IndicatorCatalog::all() as $indicator) {
            $this->assertContains(
                $indicator->dimension,
                IndicatorCatalog::supportedDimensions(),
                "{$indicator->key} declares a dimension the catalog does not support"
            );
        }

        // Environment, agriculture and connectivity are declared but unsupported.
        $dimensions = collect(IndicatorCatalog::dimensions());
        $this->assertTrue($dimensions->firstWhere('key', 'environment_climate_disaster')['supported'] === false);
        $this->assertTrue($dimensions->firstWhere('key', 'infrastructure_and_connectivity')['supported'] === false);
    }
}
