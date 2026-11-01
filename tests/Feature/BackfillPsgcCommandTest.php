<?php

namespace Tests\Feature;

use App\Models\DataVintage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackfillPsgcCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Imported tables, so build just enough schema here.
        Schema::create('municipalities', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('name');
            // Deliberately stored short, as the real data is for Iloilo.
            $table->string('psgc_code')->nullable();
        });

        Schema::create('barangays', function ($table) {
            $table->id();
            $table->string('gadm_id')->unique();
            $table->string('municipality_gadm_id')->nullable();
            $table->string('name');
            $table->string('variant_name')->nullable();
            $table->string('psgc_code')->nullable();
            $table->string('psgc_match_status')->nullable();
            $table->string('psgc_release')->nullable();
            $table->string('psgc10')->nullable();
            $table->string('old_name')->nullable();
            $table->string('urban_rural')->nullable();
            $table->unsignedBigInteger('population_2024')->nullable();
            $table->string('status')->nullable();
        });

        DB::table('municipalities')->insert([
            ['gadm_id' => 'MUN.1', 'name' => 'Cabatuan', 'psgc_code' => '63022'],
            ['gadm_id' => 'MUN.2', 'name' => 'Bayugan City', 'psgc_code' => '160301'],
            ['gadm_id' => 'MUN.3', 'name' => 'No Code', 'psgc_code' => null],
        ]);

        DB::table('barangays')->insert([
            // Resolves via the short parent code and a name PSA spells with a
            // diacritic that GADM drops.
            ['gadm_id' => 'BRG.1', 'municipality_gadm_id' => 'MUN.1', 'name' => 'SantoNino', 'variant_name' => null],
            // Resolves through the variant name.
            ['gadm_id' => 'BRG.2', 'municipality_gadm_id' => 'MUN.2', 'name' => 'Poblacion', 'variant_name' => 'Osmena'],
            // Parent has no PSGC code of its own.
            ['gadm_id' => 'BRG.3', 'municipality_gadm_id' => 'MUN.3', 'name' => 'Anywhere', 'variant_name' => null],
            // Parent resolved but the name does not exist in the release.
            ['gadm_id' => 'BRG.4', 'municipality_gadm_id' => 'MUN.1', 'name' => 'Nowhere', 'variant_name' => null],
        ]);

        $release = DB::table('psgc_releases')->insertGetId([
            'label' => '3Q 2026',
            'publication_date' => '2026-09-30',
            'source_filename' => 'PSGC-3Q-2026-Publication-Datafile.xlsx',
            'source_sha256' => str_repeat('a', 64),
            'rows_read' => 6,
            'units_accepted' => 6,
            'rows_rejected' => 0,
            'imported_at' => now(),
        ]);

        // Every row carries the same keys, because a bulk insert needs the
        // same shape throughout.
        DB::table('psgc_units')->insert([
            [
                'release_id' => $release, 'psgc10' => '0631000000', 'name' => 'Iloilo City',
                'correspondence_code' => '063022000', 'level' => 'City', 'parent_correspondence_code' => null,
                'urban_rural' => null, 'population_2024' => null,
            ],
            [
                'release_id' => $release, 'psgc10' => '0630220001', 'name' => 'Cabatuan',
                'correspondence_code' => '063022001', 'level' => 'Bgy', 'parent_correspondence_code' => '063022000',
                'urban_rural' => 'R', 'population_2024' => 2400,
            ],
            [
                'release_id' => $release, 'psgc10' => '0630220002', 'name' => 'Santo Niño',
                'correspondence_code' => '063022002', 'level' => 'Bgy', 'parent_correspondence_code' => '063022000',
                'urban_rural' => 'U', 'population_2024' => 1500,
            ],
            [
                'release_id' => $release, 'psgc10' => '0160301013', 'name' => 'Osmeña',
                'correspondence_code' => '160301013', 'level' => 'Bgy', 'parent_correspondence_code' => '160301000',
                'urban_rural' => null, 'population_2024' => 900,
            ],
        ]);
    }

    public function test_it_fills_psgc_codes(): void
    {
        $this->runCommand();

        $this->assertSame('063022002', DB::table('barangays')->where('gadm_id', 'BRG.1')->value('psgc_code'));
        $this->assertSame('160301013', DB::table('barangays')->where('gadm_id', 'BRG.2')->value('psgc_code'));
    }

    public function test_it_carries_the_psa_attributes_with_a_resolved_code(): void
    {
        $this->runCommand();

        $row = DB::table('barangays')->where('gadm_id', 'BRG.1')->first();

        $this->assertSame('0630220002', $row->psgc10);
        $this->assertSame('U', $row->urban_rural);
        $this->assertSame(1500, (int) $row->population_2024);
    }

    public function test_it_never_writes_attributes_onto_an_unresolved_row(): void
    {
        $this->runCommand();

        // A blank must stay blank. Copying a parent or inventing a zero would
        // present a missing fact as a measured one.
        $row = DB::table('barangays')->where('gadm_id', 'BRG.4')->first();

        $this->assertNull($row->psgc_code);
        $this->assertNull($row->psgc10);
        $this->assertNull($row->population_2024);
        $this->assertNull($row->urban_rural);
    }

    public function test_it_records_why_a_row_is_unresolved(): void
    {
        $this->runCommand();

        // A blank code is explained rather than left silently empty.
        $this->assertNull(DB::table('barangays')->where('gadm_id', 'BRG.3')->value('psgc_code'));
        $this->assertSame(
            'no_parent_psgc_code',
            DB::table('barangays')->where('gadm_id', 'BRG.3')->value('psgc_match_status')
        );

        $this->assertNull(DB::table('barangays')->where('gadm_id', 'BRG.4')->value('psgc_code'));
        $this->assertSame(
            'name_not_found',
            DB::table('barangays')->where('gadm_id', 'BRG.4')->value('psgc_match_status')
        );
    }

    public function test_it_labels_rows_with_the_release(): void
    {
        $this->runCommand();

        $this->assertSame(
            '3Q 2026',
            DB::table('barangays')->where('gadm_id', 'BRG.1')->value('psgc_release')
        );
    }

    public function test_it_does_not_invent_rows(): void
    {
        $this->runCommand();

        // Backfilling must never change the row count.
        $this->assertSame(4, DB::table('barangays')->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        Artisan::call('geo:backfill-psgc', ['--dry-run' => true]);

        $this->assertSame(0, DB::table('barangays')->whereNotNull('psgc_code')->count());
    }

    public function test_it_records_a_data_vintage_without_becoming_current(): void
    {
        $this->runCommand();

        $vintage = DataVintage::where('vintage', 'psgc_3Q 2026')->first();

        $this->assertNotNull($vintage);
        $this->assertSame('Philippine Statistics Authority', $vintage->source);
        // A geography release must not displace the population vintage that
        // assessments read.
        $this->assertFalse((bool) $vintage->is_current);
        $this->assertStringContainsString('matched=2', $vintage->notes);
        // The notes must name the exact file the codes came from.
        $this->assertStringContainsString(str_repeat('a', 64), $vintage->notes);
    }

    public function test_it_reports_when_no_release_has_been_imported(): void
    {
        DB::table('psgc_units')->delete();

        $exit = Artisan::call('geo:backfill-psgc');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('geo:import-psgc', Artisan::output());
    }

    public function test_it_rejects_an_unknown_release_label(): void
    {
        $exit = Artisan::call('geo:backfill-psgc', ['--release' => '1Q 1999']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('1Q 1999', Artisan::output());
    }

    private function runCommand(): void
    {
        Artisan::call('geo:backfill-psgc');
    }
}
