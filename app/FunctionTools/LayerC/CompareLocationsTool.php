<?php

namespace App\FunctionTools\LayerC;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerE\MatchDostInterventionsTool;
use App\FunctionTools\Support\LocationNameLookup;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Municipality;
use App\Support\Assessment\IndicatorResult;
use App\Support\Assessment\LocationAssessor;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Layer C (C4) Function Tool: compare_locations
 *
 * Side-by-side comparison of two provinces, cities/municipalities or barangays:
 * the same indicators against the same dynamic national references, detected gap
 * counts and the top matched DOST technologies for each. Deterministic; no AI.
 */
class CompareLocationsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'compare_locations';
    }

    public function layer(): string
    {
        return 'C';
    }

    public function description(): string
    {
        return 'Compare two locations, each a province, city/municipality or barangay: population, density, service indicators vs the same national references, detected gap counts and top matched DOST technologies for each. The first location defaults to the selected municipality.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'municipality_id' => ['type' => 'integer', 'description' => 'Selected municipality (server supplied).'],
                'compare_with' => ['type' => 'string', 'description' => '10-digit PSGC code of the second location, from get_city_by_name, get_province_by_name or get_barangay_by_name. A plain city or municipality name also works, e.g. "Iloilo City".'],
                'compare_province' => ['type' => 'string', 'description' => 'Optional province name to narrow a city or municipality name given in compare_with.'],
                'compare_from' => ['type' => 'string', 'description' => '10-digit PSGC code of the first location. Omit it to use the selected municipality.'],
            ],
            'required' => ['compare_with'],
        ];
    }

    public function definition(): array
    {
        return ['name' => $this->name(), 'layer' => $this->layer(), 'description' => $this->description(), 'parameters' => $this->parameters()];
    }

    public function execute(array $args): array
    {
        $from = trim((string) ($args['compare_from'] ?? ''));
        $base = $from !== '' ? $this->resolve($from, '') : Municipality::query()->find((int) ($args['municipality_id'] ?? 0));
        if ($base === null) {
            return ['error' => 'The selected municipality could not be resolved.'];
        }
        if (is_array($base)) {
            return $base;
        }
        $other = $this->resolve(trim((string) ($args['compare_with'] ?? '')), trim((string) ($args['compare_province'] ?? '')));
        if (is_array($other)) {
            return $other;
        }
        if ($other->is($base)) {
            return ['error' => 'Both locations are the same. Ask the user which other location to compare.'];
        }

        $a = $this->snapshot($base);
        $b = $this->snapshot($other);
        if ($a['name'] === $b['name']) {
            // Two places can share a name, such as Poblacion; keep the chart labels distinct.
            $a['name'] .= ', '.($a['city'] ?? $a['province']);
            $b['name'] .= ', '.($b['city'] ?? $b['province']);
        }
        $indicators = [];
        foreach ($a['indicators'] as $key => $row) {
            $otherRow = $b['indicators'][$key] ?? null;
            $higherIsBetter = $key !== 'population_density';
            $better = null;
            if ($row['value'] !== null && ($otherRow['value'] ?? null) !== null && $row['value'] != $otherRow['value']) {
                $better = (($row['value'] > $otherRow['value']) === $higherIsBetter) ? 'a' : 'b';
            }
            $indicators[] = [
                'key' => $key, 'label' => $row['label'], 'unit' => $row['unit'],
                'a' => $row['value'], 'b' => $otherRow['value'] ?? null,
                'national_reference' => $row['benchmark'],
                'a_flagged' => $row['is_shortfall'], 'b_flagged' => $otherRow['is_shortfall'] ?? false,
                'higher_is_better' => $higherIsBetter, 'better_served' => $better,
            ];
        }

        return [
            'locations' => [
                'a' => array_diff_key($a, ['indicators' => true]),
                'b' => array_diff_key($b, ['indicators' => true]),
            ],
            'indicators' => $indicators,
            'shared_technologies' => array_values(array_intersect(array_column($a['top_technologies'], 'name'), array_column($b['top_technologies'], 'name'))),
            'note' => implode(' ', array_filter([
                'Both locations use the same formulas and national references. "better_served" compares recorded rates only; missing records are not zero and do not prove absence of service.',
                $a['level'] !== $b['level'] ? 'These locations are at different administrative levels, so only per-population rates are comparable; tell the user.' : null,
                in_array('barangay', [$a['level'], $b['level']], true) ? 'Barangay populations are small, so one recorded facility can change a rate sharply.' : null,
            ])),
        ];
    }

    /**
     * Resolve a 10-digit PSGC code at any level, or a city/municipality name.
     *
     * @return Model|array{error: string, candidates?: list<array<string, mixed>>}
     */
    private function resolve(string $query, string $province): Model|array
    {
        if ($query === '') {
            return ['error' => 'Ask the user which location to compare with.'];
        }
        if (! preg_match('/^\d{10}$/', $query)) {
            $found = app(LocationNameLookup::class)->city($query, $province);
            if (isset($found['error'])) {
                return $found;
            }
            if ($found['status'] !== 'found') {
                return ['error' => 'Several locations match. Ask the user to choose one, then call again with its psg_code.', 'candidates' => $found['matches']];
            }
            $query = $found['location']['psg_code'];
        }

        try {
            return LocationResolver::resolve(['psg_code' => $query]);
        } catch (InvalidArgumentException) {
            return ['error' => 'No location has that PSGC code.'];
        }
    }

    private function snapshot(Model $m): array
    {
        $results = app(LocationAssessor::class)->assess($m);
        $indicators = [];
        foreach ($results as $result) {
            /** @var IndicatorResult $result */
            $row = $result->toArray();
            $indicators[$row['key']] = $row;
        }
        $matches = app(MatchDostInterventionsTool::class)->execute(['location' => $m]);
        $techs = [];
        foreach ($matches['gap_interventions'] ?? [] as $gap) {
            foreach ($gap['matched_interventions'] ?? [] as $item) {
                if (! in_array($item['source_catalog'] ?? '', ['technologies', 'dost_techs'], true)) {
                    continue;
                }
                $key = $item['source_catalog'].':'.$item['intervention_id'];
                if (! isset($techs[$key]) || ($item['relevance_score'] ?? 0) > $techs[$key]['relevance_score']) {
                    $techs[$key] = ['name' => $item['name'], 'agency' => $item['agency'] ?? null, 'relevance_score' => $item['relevance_score'] ?? null, 'need' => $gap['gap_name'] ?? null];
                }
            }
        }
        usort($techs, fn ($x, $y) => ($y['relevance_score'] ?? 0) <=> ($x['relevance_score'] ?? 0));

        return [
            ...app(LocationNameLookup::class)->describe($m),
            'gaps_detected' => count(array_filter($results, fn ($r) => $r->isShortfall())),
            'top_technologies' => array_slice(array_values($techs), 0, 3),
            'indicators' => $indicators,
        ];
    }
}
