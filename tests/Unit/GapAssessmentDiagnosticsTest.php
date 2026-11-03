<?php

namespace Tests\Unit;

use App\Support\Assessment\GapAssessmentDiagnostics;
use App\Support\Assessment\IndicatorCatalog;
use App\Support\Assessment\IndicatorResult;
use PHPUnit\Framework\TestCase;

class GapAssessmentDiagnosticsTest extends TestCase
{
    public function test_sector_reasons_distinguish_no_shortfall_missing_evidence_and_unimplemented_models(): void
    {
        $summary = array_column((new GapAssessmentDiagnostics)->summarize([
            new IndicatorResult(IndicatorCatalog::get('health_facilities_per_100k'), value: 20, benchmark: 10),
            new IndicatorResult(IndicatorCatalog::get('schools_per_100k'), status: 'no_data', reason: 'Population is missing.'),
            new IndicatorResult(IndicatorCatalog::get('laboratories_per_million'), value: 0, status: 'no_benchmark', reason: 'Reference is unavailable.'),
            new IndicatorResult(IndicatorCatalog::get('population_density'), value: 500, benchmark: 100),
        ]), null, 'sector');
        $this->assertCount(8, $summary);
        $this->assertSame('no_shortfall', $summary['health']['status']);
        $this->assertSame(1, $summary['health']['comparable_count']);
        $this->assertSame('insufficient_evidence', $summary['education']['status']);
        $this->assertSame('Population is missing.', $summary['education']['evidence_issues'][0]['reason']);
        $this->assertSame('insufficient_evidence', $summary['sti_capacity']['status']);
        $this->assertSame('not_assessed', $summary['agriculture']['status']);
        $this->assertSame('not_assessed', $summary['roads']['status']);
        $this->assertSame('gaps_detected', $summary['population']['status']);
    }
}
