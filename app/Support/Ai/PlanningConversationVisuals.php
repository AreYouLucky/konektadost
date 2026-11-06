<?php

namespace App\Support\Ai;

use App\Models\Municipality;

/** Chart/diagram data comes from deterministic tools, never model-authored HTML or scripts. */
class PlanningConversationVisuals
{
    public function fromTurn(array $turn, Municipality $municipality): array
    {
        $calls = [];
        $results = [];
        foreach ($turn as $item) {
            if (($item['type'] ?? '') === 'function_call') {
                $calls[$item['call_id']] = $item['name'];
            } elseif (($item['type'] ?? '') === 'function_call_output' && isset($calls[$item['call_id']])) {
                $result = json_decode($item['output'], true);
                if (is_array($result) && ! isset($result['error'])) {
                    $results[$calls[$item['call_id']]] = $result;
                }
            }
        }
        $plan = $results['generate_action_plan'] ?? null;
        $matches = $results['match_dost_interventions'] ?? null;
        $assignments = $plan['proposed_technology_assignments'] ?? [];
        $source = $plan !== null ? 'generate_action_plan' : 'match_dost_interventions';
        if ($plan === null && $matches !== null) {
            $assignments = $this->matchedAssignments($matches, $municipality);
        }
        $visuals = [];
        $items = [];
        foreach ($assignments as $assignment) {
            $technology = $assignment['technology'] ?? [];
            $score = $assignment['relevance_score'] ?? $technology['relevance_score'] ?? null;
            if ($this->score($score) && filled($technology['name'] ?? null)) {
                $items[] = ['label' => $technology['name'], 'value' => (float) $score, 'reference' => $assignment['catalog_ref'] ?? null];
            }
        }
        if ($items !== []) {
            $visuals[] = $this->bar(
                'DOST technology match scores',
                array_slice($items, 0, 6),
                $source,
                'Deterministic relevance scores (0–100). These compare catalog matches, not approved assignments or guaranteed suitability. Lists may be excerpted.'
            );
        }
        if ($plan !== null || $matches !== null) {
            $flows = [];
            foreach (array_slice($assignments, 0, 4) as $assignment) {
                $technology = $assignment['technology'] ?? [];
                if (empty($technology['name'])) {
                    continue;
                }
                $flows[] = [
                    'reference' => $assignment['catalog_ref'] ?? null,
                    'nodes' => [
                        ['id' => 'location', 'kind' => 'location', 'label' => $assignment['target_location']['name'] ?? $municipality->name],
                        ['id' => 'need', 'kind' => 'need', 'label' => $assignment['matched_needs'][0]['name'] ?? 'Matched local need — validate details'],
                        ['id' => 'technology', 'kind' => 'technology', 'label' => $technology['name']],
                        ['id' => 'deployment', 'kind' => 'deployment', 'label' => 'Proposed assignment — validate site, availability and readiness'],
                    ],
                    'edges' => [['from' => 'location', 'to' => 'need'], ['from' => 'need', 'to' => 'technology'], ['from' => 'technology', 'to' => 'deployment']],
                ];
            }
            if ($flows === []) {
                $flows[] = [
                    'reference' => null,
                    'nodes' => [
                        ['id' => 'location', 'kind' => 'location', 'label' => $municipality->name],
                        ['id' => 'need', 'kind' => 'need', 'label' => 'Validate the local need and missing matching evidence'],
                        ['id' => 'technology', 'kind' => 'technology', 'label' => 'No supported catalog technology assignment'],
                        ['id' => 'deployment', 'kind' => 'deployment', 'label' => 'DOST/LGU validation before choosing a technology'],
                    ],
                    'edges' => [['from' => 'location', 'to' => 'need'], ['from' => 'need', 'to' => 'technology'], ['from' => 'technology', 'to' => 'deployment']],
                ];
            }
            $visuals[] = [
                'type' => 'assignment_diagram',
                'title' => 'Technology-to-location assignment diagram',
                'source_tool' => $source,
                'caption' => 'Arrows show proposed matching/deployment relationships, not approved projects. Exact host sites and technology-specific requirements need validation.',
                'assignments' => $flows,
            ];
        }
        $priorities = $results['calculate_priority_scores']['barangays']
            ?? $plan['executive_outputs']['priority_areas']['top_priority_barangays'] ?? [];
        $items = [];
        foreach (array_slice($priorities, 0, 6) as $row) {
            $score = $row['priority_score'] ?? $row['composite_score'] ?? null;
            if ($this->score($score) && filled($row['name'] ?? null)) {
                $items[] = ['label' => $row['name'], 'value' => (float) $score];
            }
        }
        if ($items !== []) {
            $visuals[] = $this->bar(
                'Location screening priorities',
                $items,
                isset($results['calculate_priority_scores']) ? 'calculate_priority_scores' : 'generate_action_plan',
                'General barangay priority scores, not technology-specific suitability. Do not assign a technology to a barangay from this ranking alone.'
            );
        }

        if (isset($results['compare_locations']['indicators'], $results['compare_locations']['locations'])) {
            $comparison = $results['compare_locations'];
            $rows = [];
            foreach ($comparison['indicators'] as $row) {
                if (is_numeric($row['a'] ?? null) || is_numeric($row['b'] ?? null)) {
                    $rows[] = [
                        'label' => (string) ($row['label'] ?? $row['key'] ?? 'Indicator'),
                        'unit' => (string) ($row['unit'] ?? ''),
                        'a' => is_numeric($row['a'] ?? null) ? (float) $row['a'] : null,
                        'b' => is_numeric($row['b'] ?? null) ? (float) $row['b'] : null,
                        'reference' => is_numeric($row['national_reference'] ?? null) ? (float) $row['national_reference'] : null,
                        'better' => in_array($row['better_served'] ?? null, ['a', 'b'], true) ? $row['better_served'] : null,
                    ];
                }
            }
            if ($rows !== []) {
                $visuals[] = [
                    'type' => 'comparison',
                    'title' => 'Location comparison',
                    'source_tool' => 'compare_locations',
                    'locations' => [
                        'a' => (string) ($comparison['locations']['a']['name'] ?? $municipality->name),
                        'b' => (string) ($comparison['locations']['b']['name'] ?? 'Other location'),
                    ],
                    'rows' => array_slice($rows, 0, 12),
                    'caption' => 'Same formulas and national references for both. Bars share a scale per indicator; the tick marks the national reference. Missing records are not zero.',
                ];
            }
        }

        return $visuals;
    }

    public function previousEvidence(array $visuals, string $reply): array
    {
        $retained = [];
        foreach ($visuals as $visual) {
            if (($visual['type'] ?? '') === 'assignment_diagram') {
                $visual['assignments'] = array_values(array_filter($visual['assignments'] ?? [], function ($assignment) use ($reply) {
                    foreach ($assignment['nodes'] ?? [] as $node) {
                        if (($node['kind'] ?? '') === 'technology') {
                            return mb_stripos($reply, $node['label']) !== false;
                        }
                    }

                    return false;
                }));
                if ($visual['assignments'] === []) {
                    continue;
                }
            }
            $prefix = 'Retained evidence from the previous saved plan; not recomputed for this reply. ';
            if (! str_starts_with($visual['caption'] ?? '', $prefix)) {
                $visual['caption'] = $prefix . ($visual['caption'] ?? '');
            }
            $retained[] = $visual;
        }

        return $retained;
    }

    private function matchedAssignments(array $matches, Municipality $municipality): array
    {
        $assignments = [];
        foreach ($matches['gap_interventions'] ?? [] as $gap) {
            foreach ($gap['matched_interventions'] ?? [] as $technology) {
                if (
                    ! in_array($technology['source_catalog'] ?? null, ['dost_techs', 'technologies'], true)
                    || empty($technology['intervention_id']) || empty($technology['name'])
                    || in_array($technology['availability_status'] ?? '', ['unavailable', 'research_stage', 'historical_benchmark'], true)
                ) {
                    continue;
                }
                $ref = $technology['source_catalog'] . ':' . ($technology['intervention_id'] ?? '');
                $score = $technology['relevance_score'] ?? null;
                if (isset($assignments[$ref]) && ($assignments[$ref]['relevance_score'] ?? -1) >= ($score ?? -1)) {
                    continue;
                }
                $assignments[$ref] = [
                    'catalog_ref' => $ref,
                    'technology' => $technology,
                    'relevance_score' => $score,
                    'target_location' => ['name' => $municipality->name],
                    'matched_needs' => [['name' => $gap['gap_name'] ?? 'Recorded local need']],
                ];
            }
        }
        $rows = array_values($assignments);
        usort($rows, fn($a, $b) => ($b['relevance_score'] ?? -1) <=> ($a['relevance_score'] ?? -1));

        return $rows;
    }

    private function bar(string $title, array $items, string $source, string $caption): array
    {
        return [
            'type' => 'bar',
            'title' => $title,
            'unit' => 'score / 100',
            'maximum' => 100,
            'items' => $items,
            'source_tool' => $source,
            'caption' => $caption
        ];
    }

    private function score(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 100;
    }
}
