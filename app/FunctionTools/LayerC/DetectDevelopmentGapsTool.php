<?php

namespace App\FunctionTools\LayerC;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\FunctionTools\LayerB\AnalyzeEducationAccessTool;
use App\FunctionTools\LayerB\AnalyzeHealthAccessTool;
use App\FunctionTools\LayerB\AnalyzeRoadAccessibilityTool;
use App\FunctionTools\LayerB\AnalyzeSafetyAndDrrmTool;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Municipality;
use App\Support\Assessment\AssessmentComputationCache;
use App\Support\Assessment\IndicatorResult;
use App\Support\Assessment\LocationAssessor;
use App\Support\Assessment\GapAssessmentDiagnostics;
use Illuminate\Database\Eloquent\Model;

/**
 * Layer C (C1) Function Tool: detect_development_gaps
 *
 * Mathematically compares observed indicators against live table-computed benchmarks.
 * Evaluates relative gap percentages, determines deterministic severity scores (0-100),
 * identifies affected population and specific barangays, and notes data limitations.
 */
class DetectDevelopmentGapsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'detect_development_gaps';
    }

    public function layer(): string
    {
        return 'C';
    }

    public function description(): string
    {
        return 'Mathematically detect development shortfalls and infrastructure deficits against dynamic benchmarks, calculate absolute and percentage gaps, evaluate severity scores (0-100), and quantify affected population and barangays.';
    }

    public function parameters(): array
    {
        return LocationResolver::locationParameterSchema();
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'layer' => $this->layer(),
            'description' => $this->description(),
            'parameters' => $this->parameters(),
        ];
    }

    public function execute(array $args): array
    {
        $location = LocationResolver::resolve($args);

        return $this->forLocation($location);
    }

    /**
     * Detect all development gaps for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location): array
    {
        return app(AssessmentComputationCache::class)->remember(self::class, $location, [], fn () => $this->computeForLocation($location));
    }

    private function computeForLocation(Model $location): array
    {
        $assessor = app(LocationAssessor::class);
        $results = $assessor->assess($location);

        $popStats = app(GetPopulationStatisticsTool::class)->forLocation($location, includeBarangayDistribution: false);
        $population = $popStats['population_current'] ?? $location->populationFigure?->population;

        $datasetsTool = app(GetAvailableDatasetsTool::class);
        $datasetAudit = $datasetsTool->forLocation($location);

        $measuredCount = 0;
        $gaps = [];
        $dataLimitations = [];

        // Track data limitations where benchmarks or data are missing/empty
        foreach ($datasetAudit['datasets'] ?? [] as $key => $meta) {
            if ($meta['status'] === 'empty' || $meta['status'] === 'unavailable') {
                $dataLimitations[] = [
                    'dataset' => $key,
                    'status' => $meta['status'],
                    'notes' => $meta['notes'] ?? 'Dataset is not populated or unavailable.',
                ];
            }
        }

        // Cache sector coverage for municipalities to pinpoint affected barangays
        $healthCoverage = $location instanceof Municipality
            ? app(AnalyzeHealthAccessTool::class)->forLocation($location)['barangay_coverage'] ?? null
            : null;
        $educationCoverage = $location instanceof Municipality
            ? app(AnalyzeEducationAccessTool::class)->forLocation($location)['barangay_coverage'] ?? null
            : null;
        $safetyCoverage = $location instanceof Municipality
            ? app(AnalyzeSafetyAndDrrmTool::class)->forLocation($location)['barangay_coverage'] ?? null
            : null;
        $roadAnalysis = $location instanceof Municipality
            ? app(AnalyzeRoadAccessibilityTool::class)->forLocation($location)
            : null;

        foreach ($results as $result) {
            if ($result->status !== IndicatorResult::STATUS_NO_DATA && $result->value !== null) {
                $measuredCount++;
            }

            if (! $result->isShortfall()) {
                continue;
            }

            $gapPct = $result->gap !== null ? abs($result->gap) : 0.0;
            $confidenceMultiplier = $result->confidence === IndicatorResult::CONFIDENCE_HIGH ? 1.0 : 0.65;

            // Deterministic 0-100 severity score
            $rawMagnitude = min(100.0, $gapPct);
            $severityScore = round($rawMagnitude * $confidenceMultiplier, 1);

            $severityLevel = match (true) {
                $severityScore >= 75.0 => 'critical',
                $severityScore >= 50.0 => 'high',
                $severityScore >= 25.0 => 'moderate',
                default => 'low',
            };

            $absoluteGap = $result->benchmark !== null && $result->value !== null
                ? round(abs($result->value - $result->benchmark), 3)
                : null;

            // Associate specific affected barangays based on the indicator dimension/key
            $affectedBarangays = [];
            $affectedPop = $population;

            if ($location instanceof Municipality) {
                if (str_contains($result->indicator->key, 'health') || str_contains($result->indicator->key, 'hospital')) {
                    $affectedBarangays = $healthCoverage['underserved_barangays'] ?? [];
                    if (! empty($healthCoverage['affected_population'])) {
                        $affectedPop = (int) $healthCoverage['affected_population'];
                    }
                } elseif (str_contains($result->indicator->key, 'school')) {
                    $affectedBarangays = $educationCoverage['underserved_barangays'] ?? [];
                    if (! empty($educationCoverage['affected_population'])) {
                        $affectedPop = (int) $educationCoverage['affected_population'];
                    }
                } elseif (str_contains($result->indicator->key, 'safety')) {
                    $affectedBarangays = $safetyCoverage['underserved_barangays'] ?? [];
                    if (! empty($safetyCoverage['affected_population'])) {
                        $affectedPop = (int) $safetyCoverage['affected_population'];
                    }
                } elseif (str_contains($result->indicator->key, 'road')) {
                    $affectedBarangays = $roadAnalysis['barangay_coverage']['isolated_barangays'] ?? [];
                }
            }

            $gaps[] = [
                'indicator_key' => $result->indicator->key,
                'label' => $result->indicator->label,
                'dimension' => $result->indicator->dimension,
                'gap_type' => $result->indicator->higherIsBetter ? 'service_shortfall' : 'population_pressure',
                'measured_value' => $result->value,
                'benchmark_value' => $result->benchmark,
                'unit' => $result->indicator->unit,
                'absolute_gap' => $absoluteGap,
                'percentage_gap' => $result->gap !== null ? round($result->gap, 2) : null,
                'confidence' => $result->confidence,
                'severity_score' => $severityScore,
                'severity_level' => $severityLevel,
                'affected_population' => $affectedPop,
                'affected_barangays_count' => count($affectedBarangays),
                'affected_barangays' => array_slice($affectedBarangays, 0, 10),
                'reference_year' => $result->indicator->referenceYear ?? 'Active Census',
                'source' => $result->indicator->source,
                'reason' => $result->reason,
                'calculation' => $result->calculation,
            ];
        }

        usort($gaps, fn (array $a, array $b) => ($b['severity_score'] <=> $a['severity_score'])
            ?: strcmp($a['label'], $b['label']));

        $highConfidenceGaps = count(array_filter($gaps, fn ($g) => $g['confidence'] === IndicatorResult::CONFIDENCE_HIGH));

        return [
            'location_level' => LocationResolver::levelOf($location),
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'total_indicators_measured' => $measuredCount,
            'total_gaps_detected' => count($gaps),
            'critical_gaps_count' => count(array_filter($gaps, fn ($g) => $g['severity_level'] === 'critical')),
            'high_gaps_count' => count(array_filter($gaps, fn ($g) => $g['severity_level'] === 'high')),
            'moderate_gaps_count' => count(array_filter($gaps, fn ($g) => $g['severity_level'] === 'moderate')),
            'low_gaps_count' => count(array_filter($gaps, fn ($g) => $g['severity_level'] === 'low')),
            'high_confidence_gaps' => $highConfidenceGaps,
            'provisional_gaps' => count($gaps) - $highConfidenceGaps,
            'gaps' => $gaps,
            'sector_diagnostics' => app(GapAssessmentDiagnostics::class)->summarize($results),
            'data_limitations' => $dataLimitations,
        ];
    }
}
