<?php

namespace App\Support\Assessment;

use App\Models\Municipality;
use App\Models\Province;
use App\Support\Caching\ReadCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ranks the cities/municipalities of a province as candidate DOST target areas.
 *
 * Deterministic and transparent, mirroring the barangay priority score:
 *   score = population need + Σ sector deficit points  (0–100)
 *   population need = weight × (0.7 × pop ÷ max pop + 0.3 × density ÷ max density)
 *   sector deficit  = weight × (1 − min(1, local rate ÷ province median rate))
 * When the province median is zero, a location with no records gets the full weight.
 * A sector with no records anywhere in the province is inactive; its weight is
 * redistributed proportionally across the active factors.
 */
class TargetAreaRanking
{
    public const WEIGHTS = [
        'population_need' => 25.0,
        'health_access_gap' => 20.0,
        'education_access_gap' => 15.0,
        'digital_connectivity_gap' => 15.0,
        'sti_lab_access_gap' => 15.0,
        'safety_and_drrm_gap' => 10.0,
    ];

    /** Minimum share of LGUs with at least one record for a sector to be scored. */
    public const MIN_COVERAGE = 0.5;

    /** factor => [rate key, rate label, multiplier] */
    private const SECTORS = [
        'health_access_gap' => ['health', 'health facilities per 100k', 100_000],
        'education_access_gap' => ['education', 'schools per 100k', 100_000],
        'digital_connectivity_gap' => ['telecom', 'cell sites per 100k', 100_000],
        'sti_lab_access_gap' => ['labs', 'accredited labs per 1M', 1_000_000],
        'safety_and_drrm_gap' => ['safety', 'safety facilities per 100k', 100_000],
    ];

    public function forProvince(Province $province): array
    {
        return app(ReadCache::class)->remember('assessment.target-areas.province', ['province' => $province->id], fn () => $this->build($province));
    }

    private function build(Province $province): array
    {
        $municipalities = Municipality::query()->where('province_id', $province->id)
            ->with('populationFigure')->get(['id', 'name', 'psg_code', 'type', 'area_km2']);
        $ids = $municipalities->pluck('id')->all();

        $facilities = $this->grouped('facilities', $ids, 'sector');
        $telecom = $this->grouped('telecom_sites', $ids);
        $labs = $this->grouped('laboratories', $ids);

        $rows = $municipalities->map(function (Municipality $m) use ($facilities, $telecom, $labs) {
            $population = $m->populationFigure?->population !== null ? (int) $m->populationFigure->population : null;
            $area = $m->area_km2 !== null ? (float) $m->area_km2 : null;
            $counts = [
                'health' => $facilities[$m->id]['health'] ?? 0,
                'education' => $facilities[$m->id]['education'] ?? 0,
                'safety' => $facilities[$m->id]['safety'] ?? 0,
                'telecom' => $telecom[$m->id][''] ?? 0,
                'labs' => $labs[$m->id][''] ?? 0,
            ];
            $rates = [];
            foreach (self::SECTORS as [$key, , $multiplier]) {
                $rates[$key] = $population ? round($counts[$key] / $population * $multiplier, 2) : null;
            }

            return [
                'id' => $m->id, 'name' => $m->name, 'psg_code' => $m->psg_code, 'type' => $m->type,
                'population' => $population, 'area_km2' => $area,
                'density' => $population !== null && $area ? round($population / $area, 1) : null,
                'counts' => $counts, 'rates' => $rates,
            ];
        })->values()->all();

        $measured = array_values(array_filter($rows, fn ($row) => $row['population'] !== null));
        $medians = [];
        $coverage = [];
        $active = ['population_need' => $measured !== []];
        foreach (self::SECTORS as $factor => [$key]) {
            $medians[$key] = $this->median(array_column(array_column($measured, 'rates'), $key));
            // Score a sector only where the dataset covers most of the province; sparse data is not evidence of need.
            $covered = count(array_filter($rows, fn ($row) => $row['counts'][$key] > 0));
            $active[$factor] = $rows !== [] && $covered / count($rows) >= self::MIN_COVERAGE;
            $coverage[$factor] = $rows === [] ? 0 : round($covered / count($rows) * 100, 1);
        }
        $weights = $this->activeWeights($active);
        $maxPop = max([1, ...array_column($measured, 'population')]);
        $maxDensity = max([1, ...array_filter(array_column($measured, 'density'))]);

        foreach ($rows as &$row) {
            if ($row['population'] === null) {
                $row += ['components' => [], 'score' => null, 'drivers' => []];

                continue;
            }
            $components = ['population_need' => round($weights['population_need'] * (0.7 * $row['population'] / $maxPop + 0.3 * (($row['density'] ?? 0) / $maxDensity)), 1)];
            foreach (self::SECTORS as $factor => [$key]) {
                if (! $active[$factor]) {
                    $components[$factor] = 0.0;

                    continue;
                }
                $median = $medians[$key];
                $rate = $row['rates'][$key] ?? 0;
                $deficit = $median > 0 ? 1 - min(1, $rate / $median) : ($rate > 0 ? 0 : 1);
                $components[$factor] = round($weights[$factor] * $deficit, 1);
            }
            $row['components'] = $components;
            $row['score'] = round(array_sum($components), 1);
            $row['drivers'] = $this->drivers($row, $components, $medians, $maxPop);
        }
        unset($row);

        usort($rows, fn ($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1) ?: strcmp($a['name'], $b['name']));
        $rank = 0;
        foreach ($rows as &$row) {
            $row['rank'] = $row['score'] === null ? null : ++$rank;
        }
        unset($row);

        return [
            'level' => 'municipality',
            'parent' => $province->name,
            'method' => 'Population need plus facility deficits compared with the province median. Sectors recorded in fewer than half of the LGUs are not scored; their weight is redistributed.',
            'weights' => $weights,
            'coverage_pct' => $coverage,
            'sparse_factors' => array_keys(array_filter($active, fn ($on, $factor) => ! $on && $factor !== 'population_need', ARRAY_FILTER_USE_BOTH)),
            'medians' => $medians,
            'areas' => $rows,
        ];
    }

    /** @return array<int, array<string, int>> municipality_id => [group => count] */
    private function grouped(string $table, array $ids, ?string $column = null): array
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'municipality_id') || ($column && ! Schema::hasColumn($table, $column))) {
            return [];
        }
        $select = $column ? "municipality_id, {$column} as grp, count(*) as total" : "municipality_id, '' as grp, count(*) as total";
        $query = DB::table($table)->whereIn('municipality_id', $ids)->selectRaw($select)->groupBy('municipality_id');
        if ($column) {
            $query->groupBy($column);
        }
        $out = [];
        foreach ($query->get() as $row) {
            $out[(int) $row->municipality_id][(string) $row->grp] = (int) $row->total;
        }

        return $out;
    }

    private function activeWeights(array $active): array
    {
        $total = array_sum(array_map(fn ($factor) => $active[$factor] ? self::WEIGHTS[$factor] : 0, array_keys(self::WEIGHTS)));
        $weights = [];
        foreach (self::WEIGHTS as $factor => $weight) {
            $weights[$factor] = $active[$factor] && $total > 0 ? round($weight / $total * 100, 2) : 0.0;
        }

        return $weights;
    }

    private function median(array $values): float
    {
        $values = array_values(array_filter($values, fn ($value) => $value !== null));
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return (float) (count($values) % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2);
    }

    /** The two factors adding the most points, in plain words. */
    private function drivers(array $row, array $components, array $medians, int $maxPop): array
    {
        arsort($components);
        $drivers = [];
        foreach (array_slice(array_filter($components, fn ($points) => $points > 0), 0, 2, true) as $factor => $points) {
            if ($factor === 'population_need') {
                $text = number_format($row['population']).' people ('.round($row['population'] / $maxPop * 100).'% of the largest LGU)';
            } else {
                [$key, $label] = self::SECTORS[$factor];
                $text = ($row['counts'][$key] === 0 ? 'No '.explode(' per ', $label)[0].' recorded' : $row['rates'][$key].' '.$label)
                    .' vs province median '.round($medians[$key], 1);
            }
            $drivers[] = ['factor' => $factor, 'points' => $points, 'text' => $text];
        }

        return $drivers;
    }
}
