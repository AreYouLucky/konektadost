<?php

namespace App\Support\Assessment;

use App\FunctionTools\LayerC\CalculatePriorityScoresTool;
use App\FunctionTools\LayerC\DetectDevelopmentGapsTool;
use App\FunctionTools\LayerC\IdentifyUnderservedAreasTool;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Model;

/**
 * Backward-compatible facade delegating directly to Layer C Function Tool classes:
 * - DetectDevelopmentGapsTool (C1: detect_development_gaps)
 * - IdentifyUnderservedAreasTool (C2: identify_underserved_areas)
 * - CalculatePriorityScoresTool (C3: calculate_priority_scores)
 */
class PrioritizationService
{
    /**
     * Tool: detect_development_gaps
     *
     * @return array<string, mixed>
     */
    public function detectDevelopmentGaps(Model $location): array
    {
        return app(DetectDevelopmentGapsTool::class)->forLocation($location);
    }

    /**
     * Tool: identify_underserved_areas
     *
     * @return array<string, mixed>
     */
    public function identifyUnderservedAreas(Model $location): array
    {
        return app(IdentifyUnderservedAreasTool::class)->forLocation($location);
    }

    /**
     * Tool: calculate_priority_scores
     *
     * @return array<string, mixed>
     */
    public function calculatePriorityScores(Municipality $municipality, string $focus = 'general'): array
    {
        return app(CalculatePriorityScoresTool::class)->forLocation($municipality);
    }
}
