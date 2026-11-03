<?php

namespace App\Support\Assessment;

/** Explains why each sector did or did not produce comparable development gaps. */
class GapAssessmentDiagnostics
{
    public const SECTORS = ['population', 'health', 'education', 'digital_connectivity', 'roads', 'agriculture', 'sti_capacity', 'safety_drrm'];

    public static function sector(string $dimension, string $key): string
    {
        return match ($dimension) {
            IndicatorCatalog::DIMENSION_POPULATION => 'population',
            IndicatorCatalog::DIMENSION_HEALTH => 'health',
            IndicatorCatalog::DIMENSION_EDUCATION => 'education',
            IndicatorCatalog::DIMENSION_STI => 'sti_capacity',
            IndicatorCatalog::DIMENSION_CONNECTIVITY => str_contains($key, 'road') ? 'roads' : 'digital_connectivity',
            IndicatorCatalog::DIMENSION_DRR_SAFETY => 'safety_drrm',
            'agriculture_fisheries_food' => 'agriculture',
            default => 'general',
        };
    }

    /** @param list<IndicatorResult> $results */
    public function summarize(array $results): array
    {
        $summary = [];
        foreach (self::SECTORS as $sector) {
            $rows = array_values(array_filter($results, fn ($row) => self::sector($row->indicator->dimension, $row->indicator->key) === $sector));
            $compared = array_filter($rows, fn ($row) => $row->isAvailable() && $row->value !== null && $row->benchmark !== null);
            $gaps = array_filter($rows, fn ($row) => $row->isShortfall());
            $status = match (true) {
                $rows === [] => 'not_assessed',
                $gaps !== [] => 'gaps_detected',
                $compared === [] => 'insufficient_evidence',
                default => 'no_shortfall',
            };
            $summary[] = [
                'sector' => $sector, 'status' => $status, 'indicator_count' => count($rows),
                'comparable_count' => count($compared), 'gap_count' => count($gaps),
                'message' => match ($status) {
                    'not_assessed' => $sector === 'agriculture'
                        ? 'Agriculture estimates describe local assets and opportunities. No comparable agriculture deficit indicator is currently implemented, so this engine cannot calculate an agriculture gap.'
                        : 'Road inventories are reported separately. No comparable road deficit indicator is currently implemented, so this engine cannot calculate a road gap.',
                    'insufficient_evidence' => 'No reliable local measurement and national reference can be compared for this sector. Resolve the evidence issues below before screening interventions.',
                    'no_shortfall' => count($compared).' comparable indicator(s) did not fall short of their national reference. This does not establish adequate access within every barangay.',
                    default => count($gaps).' comparison gap(s) detected. Low-confidence comparisons remain provisional.',
                },
                'evidence_issues' => array_values(array_map(fn ($row) => [
                    'indicator_key' => $row->indicator->key, 'label' => $row->indicator->label, 'reason' => $row->reason,
                ], array_filter($rows, fn ($row) => ! $row->isAvailable() || $row->value === null || $row->benchmark === null))),
            ];
        }

        return $summary;
    }
}
