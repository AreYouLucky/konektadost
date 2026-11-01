<?php

namespace Tests\Feature;

use App\Console\Commands\ImportKonektaWorkbookCommand;
use App\Models\EvidenceSource;
use App\Models\Technology;
use App\Support\Import\KonektaWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class KonektaWorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    private string $workbookPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workbookPath = sys_get_temp_dir().'/konekta-test-'.uniqid().'.xlsx';

        $this->buildWorkbook();
    }

    protected function tearDown(): void
    {
        if (is_file($this->workbookPath)) {
            unlink($this->workbookPath);
        }

        parent::tearDown();
    }

    /**
     * Write a sheet in the workbook's own layout: field names across row 1 and
     * one record per row beneath it.
     *
     * Cells are addressed by string coordinate rather than by array, because
     * PhpSpreadsheet's array form is [column, row] and the mismatch is silent.
     *
     * @param  list<list<string>>  $rows
     */
    private function writeSheet(
        Spreadsheet $spreadsheet,
        string $title,
        array $rows,
        ?Worksheet $sheet = null
    ): void {
        $sheet ??= $spreadsheet->createSheet();
        $sheet->setTitle($title);

        foreach ($rows as $rowOffset => $cells) {
            foreach ($cells as $columnOffset => $value) {
                // The field name in row 1 is always written, even when every
                // record leaves that field blank.
                if ($value === '' && $rowOffset > 0) {
                    continue;
                }

                $sheet->setCellValue(
                    Coordinate::stringFromColumnIndex($columnOffset + 1).($rowOffset + 1),
                    $value
                );
            }
        }
    }

    private function buildWorkbook(): void
    {
        $spreadsheet = new Spreadsheet;

        // A new Spreadsheet already holds one sheet; reuse it as the first.
        $this->writeSheet($spreadsheet, 'DOST Technology', [
            ['Technology_ID', 'Technology_Name', 'Agency', 'Technology Duration Date', 'Category', 'Target_Audience', 'Delivery_Mode', 'Short_Description', 'URL_or_Contact', 'Tags', 'Active_Status', 'Source_Reference'],
            ['RES-001', 'STARBOOKS', 'DOST-STII', '', 'Science Learning', 'Students; teachers', 'Hybrid', 'Digital library', 'https://stii.dost.gov.ph/', 'science learning; digital library', 'Active', 'https://stii.dost.gov.ph/'],
            ['RES-002', 'OneLab', 'DOST', '', 'Testing & Technical', 'MSMEs; industry', 'Referral', 'Lab referral', 'https://web.onelab.ph/', 'testing; laboratory', 'Active', 'https://web.onelab.ph/'],
            ['RES-003', 'PAGASA', 'DOST-PAGASA', '', 'Climate & DRR', 'General public', 'Online', 'Forecasts', 'https://pagasa.dost.gov.ph/', 'weather; DRR', 'Retired', 'https://pagasa.dost.gov.ph/'],
            // Padded rows, as the real workbook has dozens of.
            ['', '', '', '', '', '', '', '', '', '', '', ''],
        ], $spreadsheet->getSheet(0));

        // Source IDs repeat per region, so two records share a code.
        $this->writeSheet($spreadsheet, 'Evidence Source', [
            ['Source ID', 'Publisher', 'Dataset', 'URL', 'Description', 'Source checked'],
            ['S01', 'PSA PSGC', 'CAR dataset', 'https://psa.gov.ph/a', 'CAR LGUs', '2026-10-13'],
            ['S01', 'PSA PSGC', 'MIMAROPA dataset', 'https://psa.gov.ph/b', 'MIMAROPA LGUs', '2026-10-08'],
            ['S21', 'Ookla', 'Speedtest tiles', 'https://ookla.io/', 'Performance tiles', '2026-10-13'],
        ]);

        (new Xlsx($spreadsheet))->save($this->workbookPath);
    }

    public function test_it_reads_records_from_a_header_row(): void
    {
        $records = (new KonektaWorkbook($this->workbookPath))->records('DOST Technology');

        $this->assertCount(3, $records);
        $this->assertSame('RES-001', $records[0]['Technology_ID']);
        $this->assertSame('STARBOOKS', $records[0]['Technology_Name']);
        $this->assertSame('PAGASA', $records[2]['Technology_Name']);
        // The unused field is present but empty rather than shifting columns.
        $this->assertArrayHasKey('Technology Duration Date', $records[0]);
        $this->assertNull($records[0]['Technology Duration Date']);
        $this->assertSame('Climate & DRR', $records[2]['Category']);
    }

    public function test_it_skips_padded_rows(): void
    {
        // The fixture carries a trailing all-blank row, as the real workbook
        // carries dozens.
        $records = (new KonektaWorkbook($this->workbookPath))->records('DOST Technology');

        foreach ($records as $record) {
            $this->assertNotNull($record['Technology_ID']);
        }
    }

    public function test_it_ignores_columns_with_no_content(): void
    {
        $records = (new KonektaWorkbook($this->workbookPath))->records('Evidence Source');

        $this->assertCount(3, $records);
        $this->assertArrayNotHasKey('', $records[0]);
    }

    public function test_it_returns_nothing_for_an_unknown_sheet(): void
    {
        $this->assertSame([], (new KonektaWorkbook($this->workbookPath))->records('Nope'));
    }

    public function test_the_command_imports_technologies_and_sources(): void
    {
        Artisan::call('konekta:import-workbook', ['--path' => $this->workbookPath]);

        $this->assertSame(3, Technology::count());
        $this->assertSame(3, EvidenceSource::count());

        $starbooks = Technology::where('code', 'RES-001')->first();

        $this->assertSame('STARBOOKS', $starbooks->name);
        $this->assertSame('DOST-STII', $starbooks->agency);
        $this->assertSame('Science Learning', $starbooks->category);
        $this->assertSame('Hybrid', $starbooks->delivery_mode);
        $this->assertNull($starbooks->duration_date);
    }

    public function test_import_is_idempotent(): void
    {
        Artisan::call('konekta:import-workbook', ['--path' => $this->workbookPath]);
        Artisan::call('konekta:import-workbook', ['--path' => $this->workbookPath]);

        // Re-running updates rather than duplicating.
        $this->assertSame(3, Technology::count());
        $this->assertSame(3, EvidenceSource::count());
    }

    public function test_a_repeated_source_code_is_stored_as_separate_records(): void
    {
        Artisan::call('konekta:import-workbook', ['--path' => $this->workbookPath]);

        // S01 is used once for CAR and once for MIMAROPA; both must survive.
        $this->assertSame(2, EvidenceSource::where('code', 'S01')->count());
        $this->assertSame(
            ['CAR dataset', 'MIMAROPA dataset'],
            EvidenceSource::withCode('S01')->orderBy('dataset')->pluck('dataset')->all()
        );
    }

    public function test_source_checked_is_stored_as_a_date(): void
    {
        Artisan::call('konekta:import-workbook', ['--path' => $this->workbookPath]);

        $this->assertSame('2026-10-13', EvidenceSource::where('code', 'S21')->value('source_checked'));
    }

    public function test_it_reports_a_missing_workbook(): void
    {
        $exit = Artisan::call('konekta:import-workbook', ['--path' => 'storage/app/does-not-exist.xlsx']);

        $this->assertSame(ImportKonektaWorkbookCommand::FAILURE, $exit);
        $this->assertStringContainsString('not found', Artisan::output());
    }

    public function test_technology_helpers(): void
    {
        Artisan::call('konekta:import-workbook', ['--path' => $this->workbookPath]);

        $this->assertSame(
            ['science learning', 'digital library'],
            Technology::where('code', 'RES-001')->first()->tagList()
        );

        // Retired resources are excluded from the active scope.
        $this->assertSame(2, Technology::active()->count());
        $this->assertNull(Technology::active()->where('code', 'RES-003')->first());

        $this->assertSame(
            ['Climate & DRR', 'Science Learning', 'Testing & Technical'],
            Technology::categories()
        );
    }

    public function test_evidence_source_lookup_by_code_list(): void
    {
        Artisan::call('konekta:import-workbook', ['--path' => $this->workbookPath]);

        // The Baseline sheet records provenance as a semicolon separated list.
        $sources = EvidenceSource::forCodeList('S01; S21');

        $this->assertCount(3, $sources);
        $this->assertSame([], EvidenceSource::forCodeList('')->all());
        $this->assertSame([], EvidenceSource::forCodeList(null)->all());
    }
}
