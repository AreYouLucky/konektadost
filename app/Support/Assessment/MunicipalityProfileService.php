<?php

namespace App\Support\Assessment;

use App\FunctionTools\LayerA\GetMunicipalityProfileTool;
use App\FunctionTools\LayerA\GetPopulationStatisticsTool;
use App\Models\PlaceDescription;
use Illuminate\Database\Eloquent\Model;

/**
 * Facade/adapter over the Layer A Function Tools:
 * - A1: GetMunicipalityProfileTool (`get_municipality_profile`)
 * - A2: GetPopulationStatisticsTool (`get_population_statistics`)
 */
class MunicipalityProfileService
{
    public function __construct(
        private readonly GetMunicipalityProfileTool $profileTool,
        private readonly GetPopulationStatisticsTool $populationTool,
    ) {}

    /**
     * Retrieve the full geographic and demographic profile for a Municipality (or Province/Barangay).
     *
     * @return array<string, mixed>
     */
    public function getMunicipalityProfile(Model $location, bool $includeBarangayList = false): array
    {
        return $this->profileTool->forLocation($location, $includeBarangayList);
    }

    /**
     * Retrieve multi-year population figures, 2020 -> 2024 change, growth rates, and optional barangay distribution.
     *
     * @return array<string, mixed>
     */
    public function getPopulationStatistics(
        Model $location,
        ?string $level = null,
        ?string $psgCode = null,
        ?PlaceDescription $placeDescription = null,
        bool $includeBarangayDistribution = false
    ): array {
        return $this->populationTool->forLocation($location, $includeBarangayDistribution);
    }
}
