<?php

namespace App\FunctionTools\LayerC;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerE\MatchDostInterventionsTool;
use App\Models\Municipality;
use App\Support\Assessment\IndicatorResult;
use App\Support\Assessment\LocationAssessor;

/**
 * Layer C (C4) Function Tool: compare_locations
 *
 * Side-by-side comparison of the selected city/municipality with another one:
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
        return 'Compare the selected city/municipality with another city/municipality named by the user: population, density, service indicators vs the same national references, detected gap counts and top matched DOST technologies for each.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'municipality_id' => ['type' => 'integer', 'description' => 'Selected municipality (server supplied).'],
                'compare_with' => ['type' => 'string', 'description' => 'Name or 10-digit PSGC code of the other city/municipality, e.g. "Iloilo City" or "Tigbauan".'],
                'compare_province' => ['type' => 'string', 'description' => 'Optional province name to disambiguate the other location.'],
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
        $base = Municipality::query()->find((int) ($args['municipality_id'] ?? 0));
        if ($base === null) {
            return ['error' => 'The selected municipality could not be resolved.'];
        }
        $other = $this->resolveOther(trim((string) ($args['compare_with'] ?? '')), trim((string) ($args['compare_province'] ?? '')));
        if (isset($other['error'])) {
            return $other;
        }
        /** @var Municipality $other */
        if ($other->id === $base->id) {
            return ['error' => 'Both locations are the same. Ask the user which other city or municipality to compare.'];
        }

        $a = $this->snapshot($base);
        $b = $this->snapshot($other);
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
            'note' => 'Both locations use the same formulas and national references. "better_served" compares recorded rates only; missing records are not zero and do not prove absence of service.',
        ];
    }

    /** @return Municipality|array{error: string, candidates?: list<array<string, string>>} */
    private function resolveOther(string $query, string $province): Municipality|array
    {
        if ($query === '') {
            return ['error' => 'Ask the user which city or municipality to compare with.'];
        }
        if (preg_match('/^\d{10}$/', $query)) {
            return Municipality::query()->where('psg_code', $query)->first() ?? ['error' => 'No city/municipality has that PSGC code.'];
        }
        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], preg_replace('/^(city of|municipality of)\s+/i', '', $query));
        $matches = Municipality::query()
            ->where(fn ($q) => $q->where('name', 'like', '%'.$needle.'%'))
            ->when($province !== '', fn ($q) => $q->where('province_name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $province).'%'))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'province_name', 'psg_code']);
        $exact = $matches->filter(fn ($m) => in_array(strtolower($m->name), [strtolower($query), 'city of '.strtolower($needle), strtolower($needle)], true));
        $pick = $exact->count() === 1 ? $exact->first() : ($matches->count() === 1 ? $matches->first() : null);
        if ($pick) {
            return $pick;
        }

        return $matches->isEmpty()
            ? ['error' => "No city or municipality matches \"{$query}\". Ask the user to check the name."]
            : ['error' => 'Several locations match. Ask the user to choose one, then call again with compare_province.',
                'candidates' => $matches->map(fn ($m) => ['name' => $m->name, 'province' => $m->province_name, 'psg_code' => $m->psg_code])->all()];
    }

    private function snapshot(Municipality $m): array
    {
        $results = app(LocationAssessor::class)->assess($m);
        $indicators = [];
        foreach ($results as $result) {
            /** @var IndicatorResult $result */
            $row = $result->toArray();
            $indicators[$row['key']] = $row;
        }
        $matches = app(MatchDostInterventionsTool::class)->execute(['municipality_id' => $m->id]);
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
        $population = $m->populationFigure?->population;

        return [
            'name' => $m->name,
            'province' => $m->province_name,
            'psg_code' => $m->psg_code,
            'population' => $population !== null ? (int) $population : null,
            'area_km2' => $m->area_km2 !== null ? (float) $m->area_km2 : null,
            'gaps_detected' => count(array_filter($results, fn ($r) => $r->isShortfall())),
            'top_technologies' => array_slice(array_values($techs), 0, 3),
            'indicators' => $indicators,
        ];
    }
}
