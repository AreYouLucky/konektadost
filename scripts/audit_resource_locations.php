<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Read-only diagnostic: only derived CSV/JSON files are written, never database rows.
$destination = getcwd().'/storage/app/diagnostics';
if (! is_dir($destination)) {
    mkdir($destination, 0777, true);
}
$municipalities = DB::table('municipalities')->get(['id', 'name', 'psg_code', 'bounds'])->keyBy('id');
$provinceNames = DB::table('provinces')->pluck('name', 'id');
$municipalityProvinces = DB::table('municipalities')->pluck('province_id', 'id');
$latestRelease = DB::table('place_descriptions')->max('release_id');
$cityIdentities = DB::table('place_descriptions')->where('release_id', $latestRelease)->whereIn('level', ['City', 'Mun'])->get(['psg_code', 'correspondence_code']);
$cityByCode = [];
foreach ($municipalities as $m) {
    $cityByCode[$m->psg_code] = $m->id;
}
$cityByCorrespondence = [];
foreach ($cityIdentities as $row) {
    if (isset($cityByCode[$row->psg_code])) {
        $cityByCorrespondence[$row->correspondence_code] = $cityByCode[$row->psg_code];
    }
}
$barangayParents = [];
foreach (DB::table('place_descriptions')->where('release_id', $latestRelease)->where('level', 'Bgy')->get(['psg_code', 'parent_correspondence_code']) as $row) {
    if (isset($cityByCorrespondence[$row->parent_correspondence_code])) {
        $barangayParents[$row->psg_code] = $cityByCorrespondence[$row->parent_correspondence_code];
    }
}
$bounds = [];
foreach ($municipalities as $m) {
    $b = json_decode($m->bounds ?? '', true);
    if (is_array($b) && count($b) === 4) {
        $bounds[$m->id] = array_map('floatval', $b);
    }
}
$cache = [];
function inRing(array $point, array $ring): int
{
    [$x,$y] = $point;
    $inside = false;
    $n = count($ring);
    if ($n < 4) {
        return 0;
    }
    for ($i = 0,$j = $n - 1; $i < $n; $j = $i++) {
        [$xi,$yi] = $ring[$i];
        [$xj,$yj] = $ring[$j];
        $cross = ($x - $xi) * ($yj - $yi) - ($y - $yi) * ($xj - $xi);
        if (abs($cross) < 1e-10 && $x >= min($xi, $xj) - 1e-10 && $x <= max($xi, $xj) + 1e-10 && $y >= min($yi, $yj) - 1e-10 && $y <= max($yi, $yj) + 1e-10) {
            return 2;
        }
        if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
            $inside = ! $inside;
        }
    }

    return $inside ? 1 : 0;
}
function inGeometry(array $point, ?array $geometry): int
{
    if (! $geometry || ! isset($geometry['coordinates'])) {
        return 0;
    }
    $polygons = ($geometry['type'] ?? null) === 'Polygon' ? [$geometry['coordinates']] : ((($geometry['type'] ?? null) === 'MultiPolygon') ? $geometry['coordinates'] : []);
    foreach ($polygons as $polygon) {
        if (! $polygon) {
            continue;
        }
        $outer = inRing($point, $polygon[0]);
        if ($outer === 2) {
            return 2;
        }
        if (! $outer) {
            continue;
        }
        $inHole = false;
        foreach (array_slice($polygon, 1) as $hole) {
            $status = inRing($point, $hole);
            if ($status === 2) {
                return 2;
            }
            if ($status === 1) {
                $inHole = true;
                break;
            }
        }
        if (! $inHole) {
            return 1;
        }
    }

    return 0;
}
function geometryFor(int $id): ?array
{
    global $cache;
    if (! array_key_exists($id, $cache)) {
        if (count($cache) >= 32) {
            unset($cache[array_key_first($cache)]);
        }
        $cache[$id] = json_decode(DB::table('municipalities')->where('id', $id)->value('geometry') ?? '', true);
    }

    return $cache[$id];
}
$csv = fopen($destination.'/resource-location-audit.csv', 'w');
fputcsv($csv, ['table', 'record_id', 'status', 'evidence', 'recorded_municipality_id', 'recorded_municipality_name', 'proposed_municipality_id', 'proposed_municipality_name', 'proposed_psgc', 'proposed_province', 'source_barangay_psgc', 'longitude', 'latitude', 'candidate_ids'], ',', '"', '');
$summary = ['read_only' => true, 'release_id' => $latestRelease, 'tables' => [], 'csv' => 'storage/app/diagnostics/resource-location-audit.csv'];
foreach (['facilities', 'laboratories', 'telecom_sites'] as $table) {
    $totals = ['total' => 0, 'unchanged' => 0, 'proposed' => 0, 'review' => 0, 'outside_philippines' => 0];
    foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
        $totals['total']++;
        $current = $row->municipality_id;
        $proposed = null;
        $status = 'review';
        $evidence = '';
        $sourceCode = $table === 'telecom_sites' ? ($row->barangay_psgc ?? null) : null;
        $rawLng = $row->longitude ?? null;
        $rawLat = $row->latitude ?? null;
        $valid = is_numeric($rawLng) && is_numeric($rawLat) && is_finite((float) $rawLng) && is_finite((float) $rawLat) && abs((float) $rawLng) <= 180 && abs((float) $rawLat) <= 90 && ((float) $rawLng !== 0.0 || (float) $rawLat !== 0.0);
        $candidates = [];
        $boundary = false;
        if ($table === 'laboratories' && ($row->is_outside_philippines ?? false)) {
            $status = 'outside_philippines';
            $evidence = 'Source explicitly marks laboratory outside the Philippines';
        } elseif ($sourceCode !== null && isset($barangayParents[$sourceCode])) {
            $proposed = $barangayParents[$sourceCode];
            $evidence = 'Source barangay PSGC and explicit newest-release PSA parent correspondence link';
        } elseif ($valid) {
            $point = [(float) $rawLng, (float) $rawLat];
            foreach ($bounds as $id => $b) {
                if ($point[1] < $b[0] || $point[1] > $b[1] || $point[0] < $b[2] || $point[0] > $b[3]) {
                    continue;
                }
                $match = inGeometry($point, geometryFor($id));
                if ($match === 1) {
                    $candidates[] = $id;
                } elseif ($match === 2) {
                    $boundary = true;
                }
            }
            if (count($candidates) === 1 && ! $boundary) {
                $proposed = $candidates[0];
                $evidence = 'Recorded coordinate strictly inside one stored municipality polygon (holes excluded); advisory spatial proposal';
            } else {
                $evidence = $boundary ? 'Coordinate on a stored boundary; manual review' : (count($candidates) > 1 ? 'Overlapping municipality polygons; manual review' : 'No containing stored municipality polygon; manual review');
            }
        } else {
            $evidence = 'No valid coordinate or verified source barangay PSGC; manual review';
        }
        if ($proposed !== null) {
            $status = (int) $current === (int) $proposed ? 'unchanged' : 'proposed';
        }
        $totals[$status]++;
        fputcsv($csv, [$table, $row->id, $status, $evidence, $current, $municipalities->get($current)->name ?? '', $proposed, $municipalities->get($proposed)->name ?? '', $municipalities->get($proposed)->psg_code ?? '', $provinceNames->get($municipalityProvinces->get($proposed)) ?? '', $sourceCode, $rawLng, $rawLat, implode('|', $candidates)], ',', '"', '');
    }
    $summary['tables'][$table] = $totals;
    echo $table.': '.json_encode($totals).PHP_EOL;
}
fclose($csv);
file_put_contents($destination.'/resource-location-audit.json', json_encode($summary, JSON_PRETTY_PRINT));
echo json_encode($summary, JSON_PRETTY_PRINT).PHP_EOL;
