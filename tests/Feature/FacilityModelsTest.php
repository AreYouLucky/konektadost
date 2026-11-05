<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Facility;
use App\Models\FacilityTest;
use App\Models\Laboratory;
use App\Models\Municipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FacilityModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Imported data, so build just enough schema here.
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

        Schema::create('facilities', function ($table) {
            $table->id();
            $table->string('source')->nullable();
            $table->string('source_record_id')->nullable();
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->text('description')->nullable();
            $table->string('facility_type')->nullable();
            $table->string('sector')->nullable();
            $table->string('level')->nullable();
            $table->string('level_source')->nullable();
            $table->string('operator')->nullable();
            $table->string('capacity')->nullable();
            $table->unsignedSmallInteger('capacity_no')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('laboratories', function ($table) {
            $table->id();
            $table->string('agency_name')->nullable();
            $table->string('code')->nullable()->unique();
            $table->string('source')->nullable();
            $table->string('source_record_id')->nullable();
            $table->string('source_url')->nullable();
            $table->string('country')->nullable();
            $table->string('region')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_information')->nullable();
            $table->unsignedInteger('services_count')->default(0);
            $table->longText('services')->nullable();
            $table->boolean('has_coordinates')->default(false);
            $table->boolean('is_outside_philippines')->default(false);
            $table->timestamps();
        });

        Schema::create('facility_tests', function ($table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_id')->nullable();
            $table->string('test_name')->nullable();
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->unsignedInteger('fee')->nullable();
            $table->string('source_record_id')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });

        DB::table('municipalities')->insert([
            ['id' => 1, 'gadm_id' => 'PHL.47.14_1', 'name' => 'QuezonCity'],
        ]);

        DB::table('barangays')->insert([
            ['id' => 1, 'gadm_id' => 'PHL.47.14.1_1', 'municipality_gadm_id' => 'PHL.47.14_1', 'name' => 'Paligsahan'],
        ]);

        DB::table('facilities')->insert([
            [
                'id' => 1, 'name' => 'Mercury Drug', 'facility_type' => 'pharmacy',
                'sector' => 'health', 'level' => null, 'municipality_id' => 1, 'barangay_id' => 1,
                'latitude' => 14.6339034, 'longitude' => 121.0589000,
            ],
            [
                'id' => 2, 'name' => 'QC Elementary School', 'facility_type' => 'school',
                'sector' => 'education', 'level' => 'elementary', 'municipality_id' => 1, 'barangay_id' => 1,
                'latitude' => 14.6100000, 'longitude' => 121.0500000,
            ],
            [
                'id' => 3, 'name' => 'Unlocated Clinic', 'facility_type' => 'clinic',
                'sector' => 'health', 'level' => null, 'municipality_id' => null, 'barangay_id' => null,
                'latitude' => null, 'longitude' => null,
            ],
        ]);

        DB::table('laboratories')->insert([
            [
                'id' => 1, 'agency_name' => 'DOST-CARAGA', 'code' => 'R13',
                'source' => 'onelab', 'province' => 'Agusan del Norte',
                'municipality_id' => 1, 'barangay_id' => 1,
                'latitude' => 8.9810000, 'longitude' => 125.5000000,
                'services_count' => 2, 'has_coordinates' => true,
                'is_outside_philippines' => false,
                'services' => json_encode([['testname' => 'Aerobic Plate Count', 'fee' => 550]]),
            ],
            [
                'id' => 2, 'agency_name' => 'Foreign Lab', 'code' => 'X99',
                'source' => 'onelab', 'province' => null,
                'municipality_id' => null, 'barangay_id' => null,
                'latitude' => null, 'longitude' => null,
                'services_count' => 0, 'has_coordinates' => false,
                'is_outside_philippines' => true, 'services' => null,
            ],
        ]);

        DB::table('facility_tests')->insert([
            [
                'id' => 1, 'laboratory_id' => 1, 'test_name' => 'Aerobic Plate Count',
                'method' => 'Pour Plate', 'reference' => 'BAM 2001', 'fee' => 550, 'source' => 'onelab',
            ],
            [
                'id' => 2, 'laboratory_id' => 1, 'test_name' => 'Total Coliform',
                'method' => 'MPN', 'reference' => 'BAM 2001', 'fee' => null, 'source' => 'onelab',
            ],
        ]);
    }

    public function test_it_casts_coordinates_and_numbers(): void
    {
        $facility = Facility::find(1);
        $this->assertIsFloat($facility->latitude);
        $this->assertIsFloat($facility->longitude);
        $this->assertNull($facility->capacity_no);

        $test = FacilityTest::find(1);
        $this->assertIsInt($test->fee);
        $this->assertSame(550, $test->fee);
    }

    public function test_facility_scopes_filter_by_sector_type_and_level(): void
    {
        $this->assertSame(2, Facility::ofSector(Facility::SECTOR_HEALTH)->count());
        $this->assertSame(1, Facility::ofSector(Facility::SECTOR_EDUCATION)->count());
        $this->assertSame(0, Facility::ofSector(Facility::SECTOR_SAFETY)->count());

        $this->assertSame(1, Facility::ofType('school')->count());
        $this->assertSame(2, Facility::ofType(['school', 'pharmacy'])->count());

        $this->assertSame(1, Facility::ofLevel('elementary')->count());

        // Only two of the three fixtures carry coordinates.
        $this->assertSame(2, Facility::geocoded()->count());
    }

    public function test_facility_classifiers(): void
    {
        $this->assertTrue(Facility::find(1)->isHealthcare());
        $this->assertFalse(Facility::find(1)->isSchool());
        $this->assertTrue(Facility::find(2)->isSchool());
        $this->assertFalse(Facility::find(2)->isHealthcare());
    }

    public function test_facility_resolves_to_its_municipality_and_barangay(): void
    {
        $facility = Facility::find(1);

        $this->assertInstanceOf(Municipality::class, $facility->municipalityModel);
        $this->assertSame('QuezonCity', $facility->municipalityModel->name);
        $this->assertSame('Paligsahan', $facility->barangayModel->name);
    }

    public function test_unlocated_facility_has_null_relations(): void
    {
        $facility = Facility::find(3);

        $this->assertNull($facility->municipalityModel);
        $this->assertNull($facility->barangayModel);
    }

    public function test_municipality_and_barangay_expose_their_facilities(): void
    {
        $municipality = Municipality::find(1);

        $this->assertSame(2, $municipality->facilities()->count());
        $this->assertSame(1, $municipality->facilities()->ofSector('health')->count());
        $this->assertSame(2, Barangay::find(1)->facilities()->count());
    }

    public function test_laboratory_scopes_and_classifiers(): void
    {
        $this->assertSame(1, Laboratory::inPhilippines()->count());
        $this->assertSame(1, Laboratory::geocoded()->count());
        $this->assertSame(1, Laboratory::whereNotNull('municipality_id')->count());

        $this->assertTrue(Laboratory::find(1)->isLocated());
        $this->assertFalse(Laboratory::find(2)->isLocated());
    }

    public function test_laboratory_decodes_its_denormalised_services_json(): void
    {
        $lab = Laboratory::find(1);

        $this->assertIsArray($lab->services);
        $this->assertSame('Aerobic Plate Count', $lab->services[0]['testname']);
        $this->assertNull(Laboratory::find(2)->services);
    }

    public function test_laboratory_has_many_tests_each_pointing_back(): void
    {
        $lab = Laboratory::find(1);

        $this->assertSame(2, $lab->tests()->count());

        $test = $lab->tests()->first();
        $this->assertSame('R13', $test->laboratory->code);
        $this->assertFalse($test->isFree());

        // A null fee means the fee is unknown, which is not the same as free.
        $this->assertTrue($lab->tests()->where('test_name', 'Total Coliform')->first()->isFree());
    }

    public function test_municipality_exposes_its_laboratories(): void
    {
        $this->assertSame(1, Municipality::find(1)->laboratories()->count());
    }
}
