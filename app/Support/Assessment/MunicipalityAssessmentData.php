<?php

namespace App\Support\Assessment;

use App\FunctionTools\FunctionToolRegistry;
use App\Models\CollectionYear;
use App\Models\Municipality;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** The current municipality analysis shared by both assessment entry points. */
class MunicipalityAssessmentData
{
    public function props(Municipality $municipality, string $focus): array
    {
        return app(AssessmentComputationCache::class)->within(fn () => $this->buildProps($municipality, $focus));
    }

    private function buildProps(Municipality $municipality, string $focus): array
    {
        $this->validateFocus($focus);

        $profile = app(MunicipalityProfileService::class)->getMunicipalityProfile($municipality);
        $population = $profile['population_statistics']['population_current'] ?? null;
        $populationYear = CollectionYear::activeYear();

        return [
            'location' => [
                'psg_code' => $municipality->psg_code,
                'province_psg_code' => $municipality->provinceModel?->psg_code,
                'name' => $municipality->name,
                'type' => $municipality->type,
                'province' => $municipality->province_name,
                'population' => $population,
                'area_km2' => $profile['area_km2'] ?? null,
                'income_class' => $profile['income_class'] ?? null,
                'city_class' => $profile['city_class'] ?? null,
                'urban_rural' => $profile['urban_rural'] ?? null,
                'barangay_counts' => $profile['barangay_counts'] ?? null,
                'population_statistics' => $profile['population_statistics'] ?? null,
            ],
            'municipalityProfile' => $profile,
            // The interactive report shows Layers A-E. Generate executive plans only on explicit Layer F requests.
            'functionToolsBundle' => app(AssessmentFunctionTools::class)->computeLocationBundle($municipality, includeActionPlan: false),
            'layerEResult' => app(FunctionToolRegistry::class)->executeLayerE($municipality),
            'populationYear' => $populationYear,
            'populationReason' => $population === null
                ? ($populationYear === null ? 'No active population collection year is configured.'
                    : 'No verified local population figure is available for the active collection year. Barangay figures are shown separately.')
                : null,
            'focus' => $focus,
            'dimensions' => IndicatorCatalog::dimensions(),
            'results' => array_map(
                fn (IndicatorResult $result) => $result->toArray(),
                app(LocationAssessor::class)->assess($municipality, $focus)
            ),
            'barangayAssessment' => app(PrioritizationService::class)->calculatePriorityScores($municipality, $focus),
        ];
    }

    public function validateFocus(string $focus): void
    {
        Validator::make(['focus' => $focus], [
            'focus' => ['required', Rule::in([IndicatorCatalog::DIMENSION_GENERAL, ...IndicatorCatalog::supportedDimensions()])],
        ])->validate();
    }
}
