<?php

namespace Tests\Feature;

use App\Support\Geo\PsgcMatcher;
use PHPUnit\Framework\TestCase;

class PsgcMatcherTest extends TestCase
{
    public function test_it_pads_parent_codes_to_six_digits(): void
    {
        // Our municipalities store Iloilo without the leading zero.
        $this->assertSame('063022', PsgcMatcher::normaliseParentCode('63022'));
        $this->assertSame('063022', PsgcMatcher::normaliseParentCode('063022'));
        $this->assertSame('141110', PsgcMatcher::normaliseParentCode('141110'));
        $this->assertNull(PsgcMatcher::normaliseParentCode(null));
        $this->assertNull(PsgcMatcher::normaliseParentCode('  '));
    }

    public function test_it_splits_gadm_camel_case(): void
    {
        // GADM concatenates words where PSA separates them.
        $this->assertSame('danac east', PsgcMatcher::comparisonKey('DanacEast'));
        $this->assertSame('north poblacion', PsgcMatcher::comparisonKey('NorthPoblacion'));
        $this->assertSame('zone 1 poblacion', PsgcMatcher::comparisonKey('Zone1Poblacion'));
    }

    public function test_it_drops_diacritics_that_psa_keeps(): void
    {
        $this->assertSame('santo nino', PsgcMatcher::comparisonKey('Santo Niño'));
        $this->assertSame('santo nino', PsgcMatcher::comparisonKey('SantoNino'));
        $this->assertSame('agusan pequeno', PsgcMatcher::comparisonKey('Agusan Pequeño'));
        $this->assertSame('osena', PsgcMatcher::comparisonKey('Oseña'));
    }

    public function test_it_normalises_poblacion_markers(): void
    {
        $this->assertSame('osena', PsgcMatcher::comparisonKey('Oseña (Pob.)'));
        $this->assertSame('agao poblacion', PsgcMatcher::comparisonKey('Agao Pob.'));
        $this->assertSame('agao poblacion', PsgcMatcher::comparisonKey('Agao Poblacion'));
    }

    public function test_it_ignores_the_bo_prefix(): void
    {
        $this->assertSame('barikir', PsgcMatcher::comparisonKey('Bo. Barikir'));
        $this->assertSame('barikir', PsgcMatcher::comparisonKey('Barikir'));
    }

    public function test_it_matches_a_parent_and_name(): void
    {
        $matcher = $this->matcher();

        $match = $matcher->match('63022', 'CABATUAN');

        $this->assertTrue($match->matched());
        $this->assertSame('063022001', $match->code);
        $this->assertSame(PsgcMatcher::MATCHED, $match->status);
    }

    public function test_it_falls_back_to_the_variant_name(): void
    {
        // GADM splits PSA's single "Poblacion" into numbered zones, so the
        // variant carries the real name.
        $match = $this->matcher()->match('063022', 'Zone1Poblacion', 'Nalasin');

        $this->assertTrue($match->matched());
        $this->assertSame('063022002', $match->code);
    }

    public function test_it_reports_a_missing_parent_code(): void
    {
        $match = $this->matcher()->match(null, 'Cabatuan');

        $this->assertFalse($match->matched());
        $this->assertSame(PsgcMatcher::NO_PARENT_CODE, $match->status);
    }

    public function test_it_reports_a_parent_absent_from_the_release(): void
    {
        $match = $this->matcher()->match('099999', 'Cabatuan');

        $this->assertFalse($match->matched());
        $this->assertSame(PsgcMatcher::PARENT_UNKNOWN, $match->status);
    }

    public function test_it_reports_an_unknown_name_and_offers_candidates(): void
    {
        $match = $this->matcher()->match('063022', 'Nowhere');

        $this->assertFalse($match->matched());
        $this->assertSame(PsgcMatcher::NAME_NOT_FOUND, $match->status);
        $this->assertContains('cabatuan', $match->candidates);
    }

    public function test_it_refuses_to_guess_when_names_collide(): void
    {
        $match = $this->matcher()->match('063022', 'Twin');

        $this->assertFalse($match->matched());
        $this->assertSame(PsgcMatcher::AMBIGUOUS, $match->status);
        $this->assertCount(2, $match->candidates);
    }

    public function test_it_recovers_a_glued_connector_word(): void
    {
        // GADM writes "Agusan del Norte" as "AgusandelNorte", which the word
        // split cannot separate. The second pass compares with spaces removed.
        $matcher = PsgcMatcher::fromRows([
            ['code' => '160800001', 'parent' => '160800', 'name' => 'Agusan del Norte', 'oldName' => null],
        ]);

        $match = $matcher->match('160800', 'AgusandelNorte');

        $this->assertTrue($match->matched());
        $this->assertSame('160800001', $match->code);
    }

    public function test_it_matches_a_former_psa_name(): void
    {
        // PSA records the rename, so a GADM row still carrying the old spelling
        // resolves to the code that now sits under the new name.
        $matcher = PsgcMatcher::fromRows([
            [
                'code' => '118200001',
                'parent' => '118200',
                'name' => 'Davao de Oro',
                'oldName' => 'Compostela Valley',
            ],
        ]);

        $match = $matcher->match('118200', 'CompostelaValley');

        $this->assertTrue($match->matched());
        $this->assertSame('118200001', $match->code);
    }

    public function test_a_former_name_still_refuses_to_guess_a_collision(): void
    {
        // Indexing former names must not weaken the refusal to choose between
        // two units that reduce to the same name.
        $matcher = PsgcMatcher::fromRows([
            ['code' => '063022003', 'parent' => '063022', 'name' => 'Twin', 'oldName' => 'San Isidro'],
            ['code' => '063022004', 'parent' => '063022', 'name' => 'Other', 'oldName' => 'San Isidro'],
        ]);

        $match = $matcher->match('063022', 'SanIsidro');

        $this->assertFalse($match->matched());
        $this->assertSame(PsgcMatcher::AMBIGUOUS, $match->status);
    }

    public function test_it_skips_rows_without_a_code_or_parent(): void
    {
        $matcher = PsgcMatcher::fromRows([
            ['name' => 'NoCode', 'parent' => '063022'],
            ['code' => '063022005', 'name' => 'NoParent'],
            ['code' => '063022001', 'parent' => '063022', 'name' => 'Cabatuan', 'oldName' => null],
        ]);

        $this->assertCount(1, $matcher->parentCodes());
        $this->assertTrue($matcher->match('063022', 'Cabatuan')->matched());
    }

    public function test_it_compacts_a_comparison_key(): void
    {
        $this->assertSame('agusandelnorte', PsgcMatcher::compactKey('agusan del norte'));
        $this->assertSame('cabatuan', PsgcMatcher::compactKey('cabatuan'));
        $this->assertSame('', PsgcMatcher::compactKey('   '));
    }

    /**
     * A three barangay release in the shape PSA publishes.
     */
    private function matcher(): PsgcMatcher
    {
        return PsgcMatcher::fromRows([
            ['code' => '063022001', 'parent' => '063022', 'name' => 'Cabatuan', 'oldName' => null],
            ['code' => '063022002', 'parent' => '063022', 'name' => 'Nalasin', 'oldName' => null],
            // Two PSA entries reduce to the same comparison key.
            ['code' => '063022003', 'parent' => '063022', 'name' => 'Twin', 'oldName' => null],
            ['code' => '063022004', 'parent' => '063022', 'name' => 'Twin', 'oldName' => null],
            // Malformed row, skipped.
            ['name' => 'NoCode', 'parent' => '063022'],
        ]);
    }
}
