<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\LayerC\CalculatePriorityScoresTool;
use App\FunctionTools\LayerE\IdentifyImplementationPartnersTool;
use App\FunctionTools\LayerE\MatchDostInterventionsTool;
use App\FunctionTools\Support\LocationResolver;
use App\Models\CollectionYear;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use App\Support\Assessment\LocationAssessor;
use App\Support\Assessment\AssessmentInventoryIntegrity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/** Isolated source-data regression fixtures; never migrate or change the live database. */
class FunctionToolDataIntegrityTest extends TestCase
{
    public function test_distant_inventory_records_do_not_suppress_gaps_by_inflating_local_rates(): void
    {
        DB::table('municipalities')->where('id', 11)->update(['bounds' => '[9.7,9.8,123.7,123.9]']);
        Schema::create('facilities', function (Blueprint $table) {
            $table->id(); $table->integer('municipality_id'); $table->string('sector');
            $table->string('facility_type'); $table->double('latitude'); $table->double('longitude');
        });
        DB::table('facilities')->insert(['municipality_id' => 11, 'sector' => 'health', 'facility_type' => 'hospital', 'latitude' => 14.6, 'longitude' => 121.0]);
        $municipality = Municipality::findOrFail(11);
        $audit = app(AssessmentInventoryIntegrity::class)->forMunicipality($municipality);
        $this->assertTrue($audit['has_conflicts']);
        $this->assertSame(1, $audit['inventories'][0]['outside_bounds_count']);
        $health = collect(app(LocationAssessor::class)->assess($municipality))->first(fn ($row) => $row->indicator->key === 'health_facilities_per_100k');
        $this->assertSame('no_data', $health->status);
        $this->assertNull($health->value);
        $this->assertStringContainsString('location links are verified', $health->reason);
        $this->assertSame(11, DB::table('facilities')->value('municipality_id'), 'Auditing must never remap source records.');
    }

    public function test_linked_barangay_in_another_municipality_is_flagged_even_without_coordinates(): void
    {
        DB::table('barangays')->insert(['id' => 101, 'municipality_id' => 12, 'brgy_name' => 'Foreign barangay']);
        Schema::create('laboratories', function (Blueprint $table) {
            $table->id(); $table->integer('municipality_id'); $table->integer('barangay_id')->nullable();
        });
        DB::table('laboratories')->insert(['municipality_id' => 11, 'barangay_id' => 101]);
        $audit = app(AssessmentInventoryIntegrity::class)->forMunicipality(Municipality::findOrFail(11));
        $this->assertTrue($audit['has_conflicts']);
        $this->assertSame(1, $audit['inventories'][0]['barangay_parent_conflicts']);
    }

    public function test_valid_coordinates_near_bounds_are_not_reported_as_foreign_inventory(): void
    {
        DB::table('municipalities')->where('id', 11)->update(['bounds' => '[9.7,9.8,123.7,123.9]']);
        Schema::create('telecom_sites', function (Blueprint $table) {
            $table->id(); $table->integer('municipality_id'); $table->double('latitude')->nullable(); $table->double('longitude')->nullable();
        });
        DB::table('telecom_sites')->insert([['municipality_id' => 11, 'latitude' => 9.81, 'longitude' => 123.8], ['municipality_id' => 11, 'latitude' => null, 'longitude' => null]]);
        $this->assertFalse(app(AssessmentInventoryIntegrity::class)->forMunicipality(Municipality::findOrFail(11))['has_conflicts']);
    }

    public function test_relevant_project_after_the_first_five_candidates_is_still_screened(): void
    {
        Schema::create('dost_projects', function (Blueprint $table) {
            $table->id();
            foreach (['project_title', 'sector', 'description', 'objectives', 'agency', 'program'] as $column) {
                $table->text($column)->nullable();
            }
            $table->double('budget')->nullable();
        });
        for ($index = 0; $index < 5; $index++) {
            DB::table('dost_projects')->insert(['project_title' => 'Road study '.$index]);
        }
        $id = DB::table('dost_projects')->insertGetId(['project_title' => 'Road infrastructure transportation design', 'sector' => 'Construction']);
        $method = new ReflectionMethod(MatchDostInterventionsTool::class, 'findInterventionsForGap');
        $matches = $method->invoke(app(MatchDostInterventionsTool::class), ['domain' => 'roads', 'gap_name' => 'Road access'], Municipality::findOrFail(11), []);
        $this->assertContains('PROJ-'.$id, array_column($matches, 'intervention_id'));
    }

    public function test_layer_c_gap_contract_preserves_sector_label_and_severity_in_intervention_matching(): void
    {
        $matcher = new class extends MatchDostInterventionsTool
        {
            protected function gatherLocalContext(Municipality $municipality): array
            {
                return [];
            }

            protected function findInterventionsForGap(array $gap, Municipality $municipality, array $localContext): array
            {
                return [];
            }
        };
        $result = $matcher->execute(['municipality_id' => 11, 'gaps' => [[
            'indicator_key' => 'health_facilities_per_100k', 'label' => 'Health facilities',
            'dimension' => 'health_and_public_health', 'severity_score' => 65.0,
        ], [
            'indicator_key' => 'road_density', 'label' => 'Road density',
            'dimension' => 'infrastructure_and_connectivity', 'severity_score' => 25.0,
        ]]]);
        $this->assertSame('health', $result['gap_interventions'][0]['domain']);
        $this->assertSame('Health facilities', $result['gap_interventions'][0]['gap_name']);
        $this->assertSame('health_facilities_per_100k', $result['gap_interventions'][0]['gap_id']);
        $this->assertSame(65.0, $result['gap_interventions'][0]['severity']);
        $this->assertSame('roads', $result['gap_interventions'][1]['domain']);
        $this->assertFalse($result['gap_interventions'][0]['has_suitable_intervention']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'tools_testing', 'database.connections.tools_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'cache.default' => 'array']);
        CollectionYear::flushActiveYearCache();
        PlaceDescription::flushLatestReleaseCache();
        foreach (['provinces', 'municipalities', 'barangays'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                foreach (['name', 'brgy_name', 'psg_code', 'province_name', 'type', 'geometry', 'bounds'] as $column) {
                    $table->text($column)->nullable();
                }
                $table->unsignedBigInteger('municipality_id')->nullable();
                $table->unsignedBigInteger('province_id')->nullable();
                foreach (['area_km2', 'center_lat', 'center_lng'] as $column) {
                    $table->double($column)->nullable();
                }
            });
        }
        Schema::create('collection_years', function (Blueprint $table) {
            $table->id(); $table->integer('year'); $table->boolean('is_active');
        });
        Schema::create('place_descriptions', function (Blueprint $table) {
            $table->id(); $table->integer('release_id'); $table->string('psg_code'); $table->string('level');
            foreach (['name', 'income_class', 'city_class', 'urban_rural', 'old_name'] as $column) {
                $table->string($column)->nullable();
            }
        });
        Schema::create('population_figures', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('place_description_id');
            $table->string('psg_code'); $table->integer('year'); $table->integer('population')->nullable();
        });
        DB::table('collection_years')->insert(['year' => 2024, 'is_active' => true]);
        DB::table('provinces')->insert(['id' => 1, 'name' => 'Province', 'psg_code' => '0701200000']);
        DB::table('municipalities')->insert(['id' => 11, 'province_id' => 1, 'name' => 'City',
            'province_name' => 'Province', 'psg_code' => '0701232000', 'area_km2' => 20]);
        $this->figure('0701232000', 'Mun', 2024, 2000);
    }

    private function figure(string $code, string $level, int $year, ?int $population, int $release = 1): void
    {
        $id = DB::table('place_descriptions')->insertGetId(['psg_code' => $code, 'level' => $level, 'release_id' => $release]);
        DB::table('population_figures')->insert(['place_description_id' => $id, 'psg_code' => $code, 'year' => $year, 'population' => $population]);
        PlaceDescription::flushLatestReleaseCache();
    }

    private function barangay(int $id, string $code): void
    {
        DB::table('barangays')->insert(['id' => $id, 'municipality_id' => 11, 'psg_code' => $code, 'brgy_name' => 'Barangay '.$id]);
    }

    public function test_retrieval_analysis_and_priority_tools_work_without_optional_datasets_or_barangays(): void
    {
        $registry = app(FunctionToolRegistry::class);
        $failures = [];
        foreach (['A', 'B', 'C', 'D'] as $layer) {
            foreach ($registry->tools($layer) as $name => $tool) {
                try {
                    $this->assertIsArray($tool->execute(['municipality_id' => 11]), $name);
                } catch (\Throwable $exception) {
                    $failures[$name] = $exception->getMessage();
                }
            }
        }
        $this->assertSame([], $failures);
        $priority = app(CalculatePriorityScoresTool::class)->execute(['municipality_id' => 11]);
        $this->assertSame([], $priority['barangays']);
        $this->assertEquals(0, $priority['active_weights']['road_accessibility_gap']);
        $this->assertEquals(0, $priority['active_weights']['digital_connectivity_gap']);
    }

    public function test_population_is_not_doubled_by_releases_or_other_geographic_levels(): void
    {
        $this->figure('0701232000', 'Mun', 2024, 2100, 2);
        $this->figure('0701232000', 'Bgy', 2024, 9000, 2);
        $population = app(GetPopulationStatisticsTool::class)->execute(['municipality_id' => 11]);
        $this->assertSame(2100, $population['population_current']);
        $this->assertSame(1, $population['history'][0]['records_counted']);
    }

    public function test_missing_current_population_stays_unknown_but_complete_historic_barangays_form_a_labelled_baseline(): void
    {
        DB::table('population_figures')->update(['population' => null]);
        $this->barangay(101, '0701232001'); $this->barangay(102, '0701232002');
        $this->figure('0701232001', 'Bgy', 2020, 1000); $this->figure('0701232002', 'Bgy', 2020, 500);
        $this->figure('0701232001', 'Bgy', 2024, 1200); $this->figure('0701232002', 'Bgy', 2024, 600);
        $population = app(GetPopulationStatisticsTool::class)->execute(['municipality_id' => 11]);
        $this->assertNull($population['population_current']);
        $this->assertSame(1500, $population['population_baseline']);
        $this->assertSame('barangay_sum', $population['baseline_basis']);
        $this->assertNull($population['growth_rate_pct']);
        DB::table('population_figures')->where('psg_code', '0701232002')->where('year', 2020)->delete();
        $population = app(GetPopulationStatisticsTool::class)->execute(['municipality_id' => 11]);
        $this->assertNull($population['population_baseline'], 'A partial sum must not masquerade as the municipal baseline.');
    }

    public function test_no_active_year_never_silently_selects_a_different_year(): void
    {
        DB::table('collection_years')->update(['is_active' => false]);
        CollectionYear::flushActiveYearCache();
        $population = app(GetPopulationStatisticsTool::class)->execute(['municipality_id' => 11]);
        $this->assertNull($population['active_year']); $this->assertNull($population['population_current']);
        $this->assertCount(1, $population['history']);
    }

    public function test_explicit_level_and_invalid_identifiers_do_not_fall_back_to_another_location(): void
    {
        foreach ([['psg_code' => '0701232000', 'level' => 'barangay'], ['municipality_id' => 999, 'psg_code' => '0701232000']] as $args) {
            try {
                LocationResolver::resolve($args);
                $this->fail('Invalid selection unexpectedly resolved.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_telecom_membership_uses_verified_links_and_rejects_conflicting_foreign_links(): void
    {
        $this->barangay(101, '0701232001');
        Schema::create('telecom_sites', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('municipality_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable(); $table->string('barangay_psgc')->nullable();
        });
        foreach ([[11, null, null], [null, 101, null], [null, null, '0701232001'], [12, 101, '0701232001'], [null, 999, '0701232001'], [null, null, '0701232999']] as [$municipality, $barangay, $code]) {
            DB::table('telecom_sites')->insert(['municipality_id' => $municipality, 'barangay_id' => $barangay, 'barangay_psgc' => $code]);
        }
        $this->assertSame([1, 2, 3], app(GetAvailableDatasetsTool::class)->telecomQueryFor(Municipality::findOrFail(11))->pluck('id')->all());
    }

    public function test_missing_source_tables_are_not_reported_as_measured_zeros(): void
    {
        $results = collect(app(LocationAssessor::class)->assess(Municipality::findOrFail(11)))->keyBy(fn ($result) => $result->indicator->key);
        $this->assertNull($results['health_facilities_per_100k']->value);
        $this->assertSame('no_data', $results['health_facilities_per_100k']->status);
        $this->assertSame('no_data', $results['telecom_sites_per_100k']->status);
        $this->assertEquals(100, $results['population_density']->value);
    }

    public function test_intervention_context_counts_sectors_and_partner_lookup_handles_absent_tables(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id(); $table->integer('municipality_id'); $table->string('sector'); $table->string('facility_type');
        });
        DB::table('facilities')->insert(['municipality_id' => 11, 'sector' => 'health', 'facility_type' => 'hospital']);
        $method = new ReflectionMethod(MatchDostInterventionsTool::class, 'gatherLocalContext');
        $context = $method->invoke(app(MatchDostInterventionsTool::class), Municipality::findOrFail(11));
        $this->assertSame(1, $context['health_facilities']);
        $this->assertSame(0, $context['education_facilities']);
        Schema::drop('facilities');
        foreach (['findAcademicPartner', 'findLaboratoryPartner'] as $name) {
            $method = new ReflectionMethod(IdentifyImplementationPartnersTool::class, $name);
            $this->assertNull($method->invoke(app(IdentifyImplementationPartnersTool::class), Municipality::findOrFail(11)));
        }
    }
}
