<?php

namespace App\Support\Assessment;

use App\FunctionTools\LayerB\AnalyzeAgricultureTool;
use App\FunctionTools\LayerB\AnalyzeDigitalConnectivityTool;
use App\FunctionTools\LayerB\AnalyzeEducationAccessTool;
use App\FunctionTools\LayerB\AnalyzeHealthAccessTool;
use App\FunctionTools\LayerB\AnalyzeRoadAccessibilityTool;
use App\FunctionTools\LayerB\AnalyzeSafetyAndDrrmTool;
use App\FunctionTools\LayerB\AnalyzeStiLaboratoryCapacityTool;
use App\FunctionTools\LayerB\CalculateIndicatorsTool;
use App\FunctionTools\Support\SpatialMetrics;
use Illuminate\Database\Eloquent\Model;

/**
 * Facade/adapter over the Layer B Function Tools:
 * - B1: CalculateIndicatorsTool (`calculate_indicators`)
 * - B2: AnalyzeHealthAccessTool (`analyze_health_access`)
 * - B3: AnalyzeEducationAccessTool (`analyze_education_access`)
 * - B4: AnalyzeDigitalConnectivityTool (`analyze_digital_connectivity`)
 * - B5: AnalyzeRoadAccessibilityTool (`analyze_road_accessibility`)
 * - B6: AnalyzeAgricultureTool (`analyze_agriculture`)
 * - B7: AnalyzeStiLaboratoryCapacityTool (`analyze_sti_laboratory_capacity`)
 * - B8: AnalyzeSafetyAndDrrmTool (`analyze_safety_and_drrm`)
 */
class SectorAnalysisService
{
    public function __construct(
        private readonly CalculateIndicatorsTool $indicatorsTool,
        private readonly AnalyzeHealthAccessTool $healthTool,
        private readonly AnalyzeEducationAccessTool $educationTool,
        private readonly AnalyzeDigitalConnectivityTool $connectivityTool,
        private readonly AnalyzeRoadAccessibilityTool $roadTool,
        private readonly AnalyzeAgricultureTool $agricultureTool,
        private readonly AnalyzeStiLaboratoryCapacityTool $stiLabTool,
        private readonly AnalyzeSafetyAndDrrmTool $safetyTool,
    ) {}

    /**
     * Tool B1: calculate_indicators
     *
     * @return array<string, mixed>
     */
    public function calculateIndicators(Model $location): array
    {
        return $this->indicatorsTool->forLocation($location);
    }

    /**
     * Tool B2: analyze_health_access
     *
     * @return array<string, mixed>
     */
    public function analyzeHealthAccess(Model $location): array
    {
        return $this->healthTool->forLocation($location);
    }

    /**
     * Tool B3: analyze_education_access
     *
     * @return array<string, mixed>
     */
    public function analyzeEducationAccess(Model $location): array
    {
        return $this->educationTool->forLocation($location);
    }

    /**
     * Tool B4: analyze_digital_connectivity
     *
     * @return array<string, mixed>
     */
    public function analyzeDigitalConnectivity(Model $location): array
    {
        return $this->connectivityTool->forLocation($location);
    }

    /**
     * Tool B5: analyze_road_accessibility
     *
     * @return array<string, mixed>
     */
    public function analyzeRoadAccessibility(Model $location): array
    {
        return $this->roadTool->forLocation($location);
    }

    /**
     * Tool B6: analyze_agriculture
     *
     * @return array<string, mixed>
     */
    public function analyzeAgriculture(Model $location): array
    {
        return $this->agricultureTool->forLocation($location);
    }

    /**
     * Tool B7: analyze_sti_laboratory_capacity
     *
     * @return array<string, mixed>
     */
    public function analyzeStiLaboratoryCapacity(Model $location): array
    {
        return $this->stiLabTool->forLocation($location);
    }

    /**
     * Tool B8: analyze_safety_and_drrm
     *
     * @return array<string, mixed>
     */
    public function analyzeSafetyAndDrrm(Model $location): array
    {
        return $this->safetyTool->forLocation($location);
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return SpatialMetrics::haversineKm($lat1, $lng1, $lat2, $lng2);
    }
}
