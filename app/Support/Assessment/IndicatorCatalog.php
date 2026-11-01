<?php

namespace App\Support\Assessment;

/**
 * The indicators this application can currently compute, and the assessment
 * dimensions they belong to.
 *
 * Only dimensions backed by real data appear here. Dimensions without a
 * dataset (environment, agriculture, infrastructure/digital, economy,
 * institutional) are deliberately absent so that an assessment reports them as
 * unavailable rather than guessing.
 */
final class IndicatorCatalog
{
    public const DIMENSION_GENERAL = 'general';

    public const DIMENSION_POPULATION = 'population_and_social_needs';

    public const DIMENSION_HEALTH = 'health_and_public_health';

    public const DIMENSION_EDUCATION = 'education_skills_and_scientific_talent';

    public const DIMENSION_STI = 'science_technology_and_innovation_capacity';

    public const DIMENSION_CONNECTIVITY = 'infrastructure_and_connectivity';

    public const DIMENSION_DRR_SAFETY = 'environment_climate_disaster';

    /**
     * Dimensions proposed in the project brief, with the ones we can actually
     * assess today. The rest must be surfaced as "no data available".
     *
     * @var list<array{key: string, label: string, supported: bool}>
     */
    public static function dimensions(): array
    {
        return [
            ['key' => self::DIMENSION_POPULATION, 'label' => 'Population and social needs', 'supported' => true],
            ['key' => self::DIMENSION_HEALTH, 'label' => 'Health and public health', 'supported' => true],
            ['key' => self::DIMENSION_EDUCATION, 'label' => 'Education, skills and scientific talent', 'supported' => true],
            ['key' => self::DIMENSION_STI, 'label' => 'Science, technology and innovation capacity', 'supported' => true],
            ['key' => self::DIMENSION_CONNECTIVITY, 'label' => 'Infrastructure and digital connectivity', 'supported' => true],
            ['key' => self::DIMENSION_DRR_SAFETY, 'label' => 'Environment, climate and disaster risk', 'supported' => true],
            ['key' => 'economy_and_livelihood', 'label' => 'Economy and livelihood opportunities', 'supported' => false],
            ['key' => 'agriculture_fisheries_food', 'label' => 'Agriculture, fisheries and food systems', 'supported' => false],
            ['key' => 'essential_resources', 'label' => 'Essential resources and environmental services', 'supported' => false],
            ['key' => 'institutional_capacity', 'label' => 'Institutional capacity and service delivery', 'supported' => false],
        ];
    }

    /**
     * Dimensions that currently have at least one computable indicator.
     *
     * @return list<string>
     */
    public static function supportedDimensions(): array
    {
        return array_values(array_unique(array_map(
            fn (Indicator $indicator) => $indicator->dimension,
            self::all()
        )));
    }

    /**
     * @return array<string, Indicator>
     */
    public static function all(): array
    {
        $facilitySource = 'OpenStreetMap (osm_hot), imported';

        return [
            'population_density' => new Indicator(
                key: 'population_density',
                label: 'Population density',
                description: 'Residents per square kilometre. Computed from active-collection-year population and land area derived from the boundary polygon.',
                unit: 'persons per km²',
                dimension: self::DIMENSION_POPULATION,
                source: 'PSA population (active collection year); GADM boundary',
                higherIsBetter: false,
                caveat: 'Land area is derived from simplified boundary polygons and differs slightly from PSA administrative land area.',
            ),

            'health_facilities_per_100k' => new Indicator(
                key: 'health_facilities_per_100k',
                label: 'Health facilities per 100,000',
                description: 'All facilities recorded in the health sector, including pharmacies, clinics, health posts and birthing centres.',
                unit: 'facilities per 100,000 people',
                dimension: self::DIMENSION_HEALTH,
                source: $facilitySource,
                volunteerSourced: true,
            ),

            'hospitals_per_100k' => new Indicator(
                key: 'hospitals_per_100k',
                label: 'Hospitals per 100,000',
                description: 'Facilities typed as hospital. Most Philippine municipalities have none; the nearest may be in an adjacent province.',
                unit: 'hospitals per 100,000 people',
                dimension: self::DIMENSION_HEALTH,
                source: $facilitySource,
                volunteerSourced: true,
            ),

            'pharmacies_per_100k' => new Indicator(
                key: 'pharmacies_per_100k',
                label: 'Pharmacies per 100,000',
                description: 'Facilities typed as pharmacy.',
                unit: 'pharmacies per 100,000 people',
                dimension: self::DIMENSION_HEALTH,
                source: $facilitySource,
                volunteerSourced: true,
            ),

            'schools_per_100k' => new Indicator(
                key: 'schools_per_100k',
                label: 'Schools per 100,000',
                description: 'All facilities recorded in the education sector.',
                unit: 'schools per 100,000 people',
                dimension: self::DIMENSION_EDUCATION,
                source: $facilitySource,
                volunteerSourced: true,
            ),

            'elementary_schools_per_100k' => new Indicator(
                key: 'elementary_schools_per_100k',
                label: 'Elementary schools per 100,000',
                description: 'Education facilities recorded at elementary level.',
                unit: 'schools per 100,000 people',
                dimension: self::DIMENSION_EDUCATION,
                source: $facilitySource,
                volunteerSourced: true,
                caveat: 'Depends on the facility level field, which is tagged "unknown" for a large share of schools in some areas. A low figure may reflect unclassified schools rather than a real absence.',
                coverageCheck: 'unknown_education_share',
                coverageWarnAbove: 0.20,
            ),

            'laboratories_per_million' => new Indicator(
                key: 'laboratories_per_million',
                label: 'Accredited laboratories per million',
                description: 'Accredited testing laboratories located within the area, from the OneLab register.',
                unit: 'laboratories per million people',
                dimension: self::DIMENSION_STI,
                source: 'OneLab',
                caveat: 'Only 52 of 69 registered laboratories carry a municipality, and 5 are outside the Philippines. A low count often means unlocated facilities rather than absent ones.',
            ),

            'laboratory_tests_per_million' => new Indicator(
                key: 'laboratory_tests_per_million',
                label: 'Laboratory tests offered per million',
                description: 'Distinct tests with a method and reference offered by laboratories in the area.',
                unit: 'tests per million people',
                dimension: self::DIMENSION_STI,
                source: 'OneLab',
                caveat: 'Tests can be offered remotely by a laboratory elsewhere, so a low local count does not prove a lack of testing capability.',
            ),

            'telecom_sites_per_100k' => new Indicator(
                key: 'telecom_sites_per_100k',
                label: 'Telecom cell sites per 100,000',
                description: 'Registered telecommunication base stations and cell towers in the area divided by population.',
                unit: 'cell sites per 100,000 people',
                dimension: self::DIMENSION_CONNECTIVITY,
                source: 'Cell Sites Inventory (telecom_sites)',
                caveat: 'Cell site inventories are imported per LGU workbook; a zero in an unimported municipality means no inventory was loaded yet.',
            ),

            'telecom_5g_share' => new Indicator(
                key: 'telecom_5g_share',
                label: '5G-ready cell sites share',
                description: 'Percentage of recorded telecommunication cell sites in the area equipped with 5G NR radio technology.',
                unit: '% of recorded cell sites',
                dimension: self::DIMENSION_CONNECTIVITY,
                source: 'Cell Sites Inventory (telecom_sites)',
            ),

            'safety_facilities_per_100k' => new Indicator(
                key: 'safety_facilities_per_100k',
                label: 'Emergency & safety facilities per 100,000',
                description: 'Facilities recorded in the safety sector (fire stations, police stations, emergency and DRRM posts).',
                unit: 'facilities per 100,000 people',
                dimension: self::DIMENSION_DRR_SAFETY,
                source: $facilitySource,
                volunteerSourced: true,
            ),
        ];
    }

    /**
     * @return array<string, Indicator>
     */
    public static function forDimension(?string $dimension): array
    {
        if ($dimension === null || $dimension === self::DIMENSION_GENERAL) {
            return self::all();
        }

        return array_filter(
            self::all(),
            fn (Indicator $indicator) => $indicator->dimension === $dimension
        );
    }

    public static function get(string $key): ?Indicator
    {
        return self::all()[$key] ?? null;
    }
}
