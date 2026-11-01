<?php

/**
 * Loads a city cell site workbook into the telecom_sites table.
 *
 * Usage:
 *
 *   php scripts/import_cellsites.php <workbook> <source> <municipality_gadm_id> <psgc_series_prefix>
 *
 *   php scripts/import_cellsites.php \
 *       storage/app/novotas_cellsites.xlsx novotas_cellsites PHL.47.9_1 13809
 *
 * Only the "Cell Sites Inventory" sheet is read. The header sits on row 4 of
 * every one of these workbooks, under a title row and a blank row.
 *
 * Barangay resolution is a strict name match against the PSGC release. A name
 * that does not match exactly is left unresolved: no psgc code, no barangay_id.
 * Nothing is guessed, so an unmatched row is an honest gap for a human to fill.
 *
 * The load is idempotent: rows are upserted on (source, source_record_id), so a
 * re-run updates in place instead of duplicating.
 */

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\PsgcRelease;
use App\Models\PsgcUnit;
use Illuminate\Support\Facades\DB;

$base = dirname(__DIR__);

require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$script, $workbookArg, $sourceKey, $gadmId, $series] = array_pad($argv, 5, null);

if (! $workbookArg || ! $sourceKey || ! $gadmId || ! $series) {
    fwrite(STDERR, "Usage: php scripts/import_cellsites.php <workbook> <source> <municipality_gadm_id> <psgc_series_prefix>\n");
    exit(1);
}

$workbookPath = str_starts_with($workbookArg, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $workbookArg)
    ? $workbookArg
    : $base.'/'.$workbookArg;

if (! is_readable($workbookPath)) {
    fwrite(STDERR, "Workbook not readable: {$workbookPath}\n");
    exit(1);
}

echo 'workbook: '.$workbookPath.PHP_EOL;
echo 'source: '.$sourceKey.PHP_EOL;
echo 'MySQL: '.DB::selectOne('select version() v')->v.PHP_EOL;

// --------------------------------------------------------------- workbook ---
$sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($workbookPath)
    ->getSheetByName('Cell Sites Inventory');

if ($sheet === null) {
    fwrite(STDERR, "Sheet 'Cell Sites Inventory' not found\n");
    exit(1);
}

$rows = $sheet->toArray();
$headerRow = null;

foreach ($rows as $i => $row) {
    if (($row[0] ?? null) === 'Site ID') {
        $headerRow = $i;
        break;
    }
}

if ($headerRow === null) {
    throw new RuntimeException('Header row "Site ID" not found');
}

$headers = array_map(fn ($h) => trim((string) $h), $rows[$headerRow]);
$records = [];

for ($i = $headerRow + 1; $i < count($rows); $i++) {
    $row = $rows[$i];

    if (($row[0] ?? null) === null || trim((string) $row[0]) === '') {
        continue;
    }

    $record = [];

    foreach ($headers as $column => $header) {
        $record[$header] = $row[$column] ?? null;
    }

    $records[] = $record;
}

echo 'inventory rows read: '.count($records).PHP_EOL;

// ------------------------------------------------------------ resolution ---
$release = PsgcRelease::query()->orderByDesc('id')->first();
$municipality = Municipality::query()->where('gadm_id', $gadmId)->first();

if ($municipality === null) {
    throw new RuntimeException("No municipality with gadm_id {$gadmId}");
}

echo 'PSGC release: '.$release->label.' | municipality: '.$municipality->name.' ('.$municipality->gadm_id.')'.PHP_EOL;

$operatorByMnc = [
    '2' => 'Globe Telecom',
    '3' => 'Smart Communications',
    '66' => 'DITO Telecommunity',
];

$towerCompanies = ['MIESCOR', 'EDOTCO', 'Phil-Tower', 'EdgePoint'];

$units = PsgcUnit::query()
    ->where('release_id', $release->id)
    ->where('level', 'Bgy')
    ->where('psgc10', 'like', $series.'%')
    ->get(['name', 'psgc10', 'correspondence_code'])
    ->keyBy('name');

$barangaysByCode = Barangay::query()
    ->whereNotNull('psgc_code')
    ->get(['id', 'psgc_code', 'name'])
    ->keyBy('psgc_code');

// The landmark column is named differently per workbook.
$landmarkColumn = collect($headers)
    ->first(fn ($h) => str_starts_with($h, 'Cluster /'));

$now = now();
$inserted = $updated = 0;
$noPsgc = [];
$noBarangay = [];

foreach ($records as $record) {
    $siteId = trim((string) $record['Site ID']);
    $barangayName = trim((string) $record['Barangay']);

    // Strict name match. No aliasing, no guessing.
    $unit = $units->get($barangayName);
    $psgc10 = $unit?->psgc10;
    $psgcCode = $unit?->correspondence_code;
    $barangayId = $psgcCode !== null ? ($barangaysByCode->get($psgcCode)?->id) : null;

    if ($psgc10 === null) {
        $noPsgc[$barangayName] = ($noPsgc[$barangayName] ?? 0) + 1;
    }

    if ($barangayId === null) {
        $noBarangay[$barangayName] = ($noBarangay[$barangayName] ?? 0) + 1;
    }

    $operatorRaw = trim((string) $record['Operator / TowerCo']);
    $mnc = trim((string) $record['MNC']);
    $isColocated = str_contains($operatorRaw, 'Co-located');

    $towerCompany = null;

    foreach ($towerCompanies as $company) {
        if (str_contains($operatorRaw, $company)) {
            $towerCompany = $company;
            break;
        }
    }

    $technologies = array_values(array_filter(array_map(
        'trim',
        explode('/', (string) $record['Supported Technologies'])
    )));

    $bands = array_values(array_filter(array_map(
        'trim',
        explode(',', (string) $record['Frequency Bands'])
    )));

    $payload = [
        'name' => trim((string) $record[$landmarkColumn]),
        'district' => trim((string) ($record['District'] ?? '')) ?: null,
        'barangay_name' => $barangayName,
        'barangay_psgc10' => $psgc10,
        'barangay_psgc_code' => $psgcCode,
        'barangay_id' => $barangayId,
        'municipality_id' => $municipality->id,
        'tower_company' => $towerCompany,
        'operator' => $operatorByMnc[$mnc] ?? null,
        'operator_raw' => $operatorRaw,
        'is_colocated' => $isColocated ? 1 : 0,
        'mcc' => trim((string) $record['MCC']),
        'mnc' => $mnc,
        'lac' => trim((string) $record['LAC / TAC']),
        'cell_id' => trim((string) $record['Cell ID / eNB']),
        'latitude' => (float) $record['Latitude'],
        'longitude' => (float) $record['Longitude'],
        'technologies' => json_encode($technologies),
        'bands' => json_encode($bands),
        'structure_type' => trim((string) $record['Structure Type']),
        'coverage_area' => trim((string) $record['Primary Service Coverage Area']),
        'source_checked' => '2026-10-19',
        'updated_at' => $now,
    ];

    $existing = DB::table('telecom_sites')
        ->where('source', $sourceKey)
        ->where('source_record_id', $siteId)
        ->first();

    if ($existing === null) {
        DB::table('telecom_sites')->insert($payload + [
            'source' => $sourceKey,
            'source_record_id' => $siteId,
            'created_at' => $now,
        ]);
        $inserted++;
    } else {
        DB::table('telecom_sites')
            ->where('id', $existing->id)
            ->update($payload);
        $updated++;
    }
}

echo "inserted: {$inserted} | updated: {$updated}".PHP_EOL;

// ------------------------------------------------------------ verification --
echo PHP_EOL.'--- verification ---'.PHP_EOL;
echo 'rows in telecom_sites: '.DB::table('telecom_sites')->count().PHP_EOL;

foreach (DB::table('telecom_sites')->select('source', DB::raw('count(*) c'))
    ->groupBy('source')->orderBy('source')->get() as $row) {
    echo '  '.$row->source.': '.$row->c.PHP_EOL;
}

echo 'rows with barangay_id: '.DB::table('telecom_sites')->where('source', $sourceKey)->whereNotNull('barangay_id')->count().PHP_EOL;
echo 'rows with barangay_id NULL: '.DB::table('telecom_sites')->where('source', $sourceKey)->whereNull('barangay_id')->count().PHP_EOL;
echo 'rows with psgc10 NULL: '.DB::table('telecom_sites')->where('source', $sourceKey)->whereNull('barangay_psgc10')->count().PHP_EOL;
echo 'psgc10 values missing from psgc_units: '.DB::table('telecom_sites')
    ->where('source', $sourceKey)
    ->whereNotIn('barangay_psgc10', DB::table('psgc_units')->select('psgc10'))
    ->distinct()->count('barangay_psgc10').PHP_EOL;

echo PHP_EOL.'by district:'.PHP_EOL;

foreach (DB::table('telecom_sites')->where('source', $sourceKey)
    ->select('district', DB::raw('count(*) c'))->groupBy('district')->orderBy('district')->get() as $row) {
    echo '  '.($row->district ?? 'NULL').': '.$row->c.PHP_EOL;
}

echo PHP_EOL.'by operator:'.PHP_EOL;

foreach (DB::table('telecom_sites')->where('source', $sourceKey)
    ->select('operator', DB::raw('count(*) c'))->groupBy('operator')->orderByDesc('c')->get() as $row) {
    echo '  '.($row->operator ?? 'NULL').': '.$row->c.PHP_EOL;
}

echo PHP_EOL.'unresolved barangays (no PSGC name match):'.PHP_EOL;

foreach ($noPsgc as $name => $count) {
    echo '  '.$name.': '.$count.' site(s)'.PHP_EOL;
}

echo PHP_EOL.'no barangays row (PSGC matched, GADM missing):'.PHP_EOL;

foreach ($noBarangay as $name => $count) {
    echo '  '.$name.': '.$count.' site(s)'.PHP_EOL;
}
