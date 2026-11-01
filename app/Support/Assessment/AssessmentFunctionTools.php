<?php

namespace App\Support\Assessment;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Central Function Tool Registry & Dispatcher (Section 6 of INSTRUCTION.md).
 *
 * Exposes all 14 deterministic backend Function Tools across 4 layers:
 *
 * Layer A (Municipality & Data Retrieval):
 *  1. get_municipality_profile
 *  2. get_population_statistics
 *  3. get_available_datasets
 *  4. get_local_assets
 *
 * Layer B (Statistical & Sector Analysis):
 *  5. calculate_indicators
 *  6. analyze_health_access
 *  7. analyze_education_access
 *  8. analyze_digital_connectivity
 *  9. analyze_agriculture
 * 10. analyze_sti_laboratory_capacity
 *
 * Layer C (Gap Detection & Barangay Prioritization):
 * 11. detect_development_gaps
 * 12. calculate_priority_scores (also aliased as identify_underserved_areas)
 *
 * Layer D (DOST Catalog & Project Query):
 * 13. search_dost_technologies
 * 14. get_local_dost_projects
 */
class AssessmentFunctionTools
{
    public function __construct(
        private readonly MunicipalityProfileService $profileService,
        private readonly DatasetAndAssetService $datasetService,
        private readonly LocationAssessor $assessor,
        private readonly SectorAnalysisService $sectorService,
        private readonly PrioritizationService $prioritizationService,
        private readonly DostCatalogToolService $dostCatalogService,
    ) {}

    /**
     * Return the OpenAI / Gemini compatible Function Tool schemas for all 14 tools.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $locationParams = [
            'type' => 'object',
            'properties' => [
                'psg_code' => ['type' => 'string', 'description' => '10-digit PSGC code of the municipality, province, or barangay.'],
                'level' => ['type' => 'string', 'enum' => ['province', 'municipality', 'barangay'], 'description' => 'Administrative level.'],
                'location_id' => ['type' => 'integer', 'description' => 'Database primary key ID if psg_code is omitted.'],
            ],
        ];

        return [
            [
                'name' => 'get_municipality_profile',
                'layer' => 'Layer A: Municipality & Data Retrieval',
                'description' => 'Retrieve geographic, administrative, classification, land area, density, barangay counts, and multi-year population profile.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'get_population_statistics',
                'layer' => 'Layer A: Municipality & Data Retrieval',
                'description' => 'Compute active census population (2024), baseline population (2020), absolute change, growth rate %, and annualized CAGR %.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'get_available_datasets',
                'layer' => 'Layer A: Municipality & Data Retrieval',
                'description' => 'Audit all domain tables for the location and classify each dataset as available, partial, or unavailable with record counts.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'get_local_assets',
                'layer' => 'Layer A: Municipality & Data Retrieval',
                'description' => 'Retrieve exact local inventory counts and records for facilities (health, education, safety), accredited laboratories, and telecom sites.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'get_telecom_infrastructure',
                'layer' => 'Layer A: Municipality & Data Retrieval',
                'description' => 'Retrieve TelecomSite records, operators, tower companies, 2G/3G/4G/5G radio technologies, frequency bands, colocation status, and barangay distribution.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'get_geographic_data_availability',
                'layer' => 'Layer A: Municipality & Data Retrieval',
                'description' => 'Check whether boundary geometry, bounds, centroids, and area_km2 exist for the municipality and its barangays, plus road network availability.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'calculate_indicators',
                'layer' => 'Layer B: Statistical & Sector Analysis',
                'description' => 'Calculate all per-capita and density indicators for the location and compare against dynamically computed national benchmarks.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        ...$locationParams['properties'],
                        'dimension' => ['type' => 'string', 'description' => 'Optional assessment dimension filter (or general).'],
                    ],
                ],
            ],
            [
                'name' => 'analyze_health_access',
                'layer' => 'Layer B: Statistical & Sector Analysis',
                'description' => 'Compute health facility counts by type, per-100k rates, barangays with 0 health facilities, and nearest hospital distance in km.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'analyze_education_access',
                'layer' => 'Layer B: Statistical & Sector Analysis',
                'description' => 'Compute school counts by level (elementary, secondary, tertiary, unclassified), per-100k rates, barangays with 0 schools, and nearest school distance.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'analyze_digital_connectivity',
                'layer' => 'Layer B: Statistical & Sector Analysis',
                'description' => 'Compute cell site counts, 5G/4G shares, per-100k rate, network operators, tower companies, and barangays with 0 cell sites.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'analyze_agriculture',
                'layer' => 'Layer B: Statistical & Sector Analysis',
                'description' => 'Aggregate agriculture, fisheries, and livestock production estimates, planted area (sqm), top commodities, and annual revenue/cost/net PHP.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'analyze_sti_laboratory_capacity',
                'layer' => 'Layer B: Statistical & Sector Analysis',
                'description' => 'Compute accredited OneLab laboratories, distinct testing/calibration services, free vs paid tests, and nearest laboratory distance in km.',
                'parameters' => $locationParams,
            ],
            [
                'name' => 'detect_development_gaps',
                'layer' => 'Layer C: Gap Detection & Barangay Prioritization',
                'description' => 'Mathematically detect indicator shortfalls and service pressures against dynamic benchmarks and compute severity scores (0-100).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        ...$locationParams['properties'],
                        'dimension' => ['type' => 'string', 'description' => 'Optional dimension filter.'],
                    ],
                ],
            ],
            [
                'name' => 'calculate_priority_scores',
                'layer' => 'Layer C: Gap Detection & Barangay Prioritization',
                'description' => 'Compute composite priority scores (0-100) for all barangays in a municipality based on population, density, facility deficits, and isolation distance.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        ...$locationParams['properties'],
                        'focus' => ['type' => 'string', 'description' => 'Optional sector focus.'],
                    ],
                ],
            ],
            [
                'name' => 'search_dost_technologies',
                'layer' => 'Layer D: DOST Catalog & Project Query',
                'description' => 'Search DOST technologies and agency projects (DostTech, Technology, DostProject) by keyword, sector, agency, or normalized tech_status.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string'],
                        'sector' => ['type' => 'string'],
                        'agency' => ['type' => 'string'],
                        'tech_status' => ['type' => 'string', 'enum' => ['available', 'limited', 'research_stage', 'unavailable', 'unknown']],
                        'limit' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'get_local_dost_projects',
                'layer' => 'Layer D: DOST Catalog & Project Query',
                'description' => 'Retrieve historical and ongoing DOST projects matching the municipality or province name, with agency, status, and budget breakdowns.',
                'parameters' => $locationParams,
            ],
        ];
    }

    /**
     * Execute any of the 14 Function Tools by name.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $arguments = []): array
    {
        if ($toolName === 'search_dost_technologies') {
            return $this->dostCatalogService->searchDostTechnologies($arguments);
        }

        $location = $this->resolveLocation($arguments);

        return match ($toolName) {
            'get_municipality_profile' => $this->profileService->getMunicipalityProfile($location),
            'get_population_statistics' => $this->profileService->getPopulationStatistics($location),
            'get_available_datasets' => $this->datasetService->getAvailableDatasets($location),
            'get_local_assets' => $this->datasetService->getLocalAssets($location),
            'get_telecom_infrastructure' => $this->datasetService->getTelecomInfrastructure($location, includeSiteRecords: true),
            'get_geographic_data_availability' => $this->datasetService->getGeographicDataAvailability($location),
            'calculate_indicators' => [
                'indicators' => array_map(
                    fn (IndicatorResult $r) => $r->toArray(),
                    $this->assessor->assess($location, isset($arguments['dimension']) ? (string) $arguments['dimension'] : null)
                ),
            ],
            'analyze_health_access' => $this->sectorService->analyzeHealthAccess($location),
            'analyze_education_access' => $this->sectorService->analyzeEducationAccess($location),
            'analyze_digital_connectivity' => $this->sectorService->analyzeDigitalConnectivity($location),
            'analyze_agriculture' => $this->sectorService->analyzeAgriculture($location),
            'analyze_sti_laboratory_capacity' => $this->sectorService->analyzeStiLaboratoryCapacity($location),
            'detect_development_gaps' => $this->prioritizationService->detectDevelopmentGaps(
                $location,
                isset($arguments['dimension']) ? (string) $arguments['dimension'] : null
            ),
            'calculate_priority_scores', 'identify_underserved_areas' => $location instanceof Municipality
                ? $this->prioritizationService->calculatePriorityScores($location, (string) ($arguments['focus'] ?? 'general'))
                : ['error' => 'calculate_priority_scores requires a Municipality model.'],
            'get_local_dost_projects' => $this->dostCatalogService->getLocalDostProjects($location),
            default => throw new InvalidArgumentException("Unknown assessment function tool: {$toolName}"),
        };
    }

    /**
     * Compute the complete deterministic data & indicator bundle for a location
     * (ready for UI inspection or AI summarization).
     *
     * @return array<string, mixed>
     */
    public function computeLocationBundle(Model $location, string $focus = 'general'): array
    {
        return [
            'dataset_audit' => $this->datasetService->getAvailableDatasets($location),
            'local_assets' => $this->datasetService->getLocalAssets($location),
            'telecom_infrastructure' => $this->datasetService->getTelecomInfrastructure($location),
            'geographic_data_availability' => $this->datasetService->getGeographicDataAvailability($location),
            'sector_analysis' => [
                'health' => $this->sectorService->analyzeHealthAccess($location),
                'education' => $this->sectorService->analyzeEducationAccess($location),
                'connectivity' => $this->sectorService->analyzeDigitalConnectivity($location),
                'agriculture' => $this->sectorService->analyzeAgriculture($location),
                'sti_laboratory' => $this->sectorService->analyzeStiLaboratoryCapacity($location),
            ],
            'detected_gaps' => $this->prioritizationService->detectDevelopmentGaps($location, $focus),
            'local_dost_projects' => $this->dostCatalogService->getLocalDostProjects($location),
        ];
    }

    /**
     * Resolve a Province, Municipality, or Barangay from tool arguments (`model`, `psg_code`, or `level` + `location_id`).
     *
     * @param  array<string, mixed>  $arguments
     */
    private function resolveLocation(array $arguments): Model
    {
        if (isset($arguments['model']) && $arguments['model'] instanceof Model) {
            return $arguments['model'];
        }

        $psgCode = trim((string) ($arguments['psg_code'] ?? ''));
        $level = strtolower(trim((string) ($arguments['level'] ?? 'municipality')));
        $locationId = isset($arguments['location_id']) ? (int) $arguments['location_id'] : null;

        if ($psgCode !== '') {
            $found = match ($level) {
                'province' => Province::query()->where('psg_code', $psgCode)->first(),
                'barangay' => Barangay::query()->where('psg_code', $psgCode)->first(),
                default => Municipality::query()->where('psg_code', $psgCode)->first()
                    ?? Province::query()->where('psg_code', $psgCode)->first()
                    ?? Barangay::query()->where('psg_code', $psgCode)->first(),
            };

            if ($found !== null) {
                return $found;
            }
        }

        if ($locationId !== null && $locationId > 0) {
            $found = match ($level) {
                'province' => Province::query()->find($locationId),
                'barangay' => Barangay::query()->find($locationId),
                default => Municipality::query()->find($locationId),
            };

            if ($found !== null) {
                return $found;
            }
        }

        throw new InvalidArgumentException('Could not resolve location from arguments. Provide a valid psg_code or level + location_id.');
    }
}

