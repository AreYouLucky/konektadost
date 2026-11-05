export interface CalculationInputs {
    numerator: number | null;
    denominator: number | null;
    multiplier: number;
    population_year: number | null;
}

export const explainNumber = (value: number | null | undefined) =>
    value == null || !Number.isFinite(value) ? 'Unavailable' : value.toLocaleString('en-PH', { maximumFractionDigits: 3 });

const countLabels: Record<string, string> = {
    health_facilities_per_100k: 'Recorded health facilities',
    hospitals_per_100k: 'Recorded hospitals',
    pharmacies_per_100k: 'Recorded pharmacies',
    schools_per_100k: 'Recorded schools',
    elementary_schools_per_100k: 'Recorded elementary schools',
    safety_facilities_per_100k: 'Recorded safety facilities',
    laboratories_per_million: 'Recorded local laboratories',
    laboratory_tests_per_million: 'Distinct local laboratory service and method pairs',
    telecom_sites_per_100k: 'Recorded telecom sites',
};

export function indicatorMethod(key: string) {
    if (key === 'population_density')
        return {
            formula: 'Population ÷ land area in km²',
            reference: 'Total recorded city/municipality census population ÷ total recorded city/municipality land area in km²',
            meaning:
                'This is the average number of residents in one square kilometre. Higher density than the reference flags possible pressure on services.',
        };
    if (key === 'telecom_5g_share')
        return {
            formula: 'Recorded sites with 5G ÷ total recorded sites × 100',
            reference: 'Recorded sites with 5G nationwide ÷ all recorded sites nationwide × 100',
            meaning: 'This is the share of recorded towers that list 5G. It does not measure the percentage of residents with a 5G signal.',
        };
    if (!countLabels[key])
        return {
            formula: 'No calculation formula is available for this indicator.',
            reference: 'Review the source notes for its reference basis',
            meaning: 'An explanation cannot establish a result when the underlying calculation is unavailable.',
        };
    const scale = key.endsWith('_per_million') ? 1_000_000 : 100_000;
    return {
        formula: `${countLabels[key]} ÷ population × ${explainNumber(scale)}`,
        reference: `National recorded count ÷ total recorded city/municipality census population × ${explainNumber(scale)}`,
        meaning: `This puts the recorded count on the same scale for every ${explainNumber(scale)} residents, so places with different population sizes can be compared.`,
    };
}

export function workedRate(inputs: CalculationInputs | null | undefined, value: number | null) {
    if (!inputs || inputs.numerator == null || inputs.denominator == null || inputs.denominator <= 0 || value == null) return null;
    const scale = inputs.multiplier === 1 ? '' : ` × ${explainNumber(inputs.multiplier)}`;
    return `${explainNumber(inputs.numerator)} ÷ ${explainNumber(inputs.denominator)}${scale} ≈ ${explainNumber(value)}`;
}

export function comparisonMeaning(key: string, value: number | null, reference: number | null, gap: number | null, status?: string) {
    if (status === 'no_data' || value == null) return 'The local measurement is unavailable. Missing data cannot establish a shortage.';
    if (reference == null) return 'The local value is measured, but no national reference is available. A comparison gap cannot be confirmed.';
    if (reference <= 0) return 'The reference is zero, so a percentage comparison cannot be calculated by division. No percentage gap is shown.';
    if (gap == null) return 'The percentage gap is unavailable. Review the measured values and evidence notes before drawing a conclusion.';
    if (gap === 0) return 'The local value equals the national reference. This indicator does not flag a shortfall.';
    const distance = `${explainNumber(Math.abs(gap))}% ${gap < 0 ? 'below' : 'above'}`;
    if (key === 'population_density')
        return `Local population density is ${distance} the reference. ${gap > 0 ? 'This flags possible service pressure, rather than a facility shortage or an automatic technology recommendation.' : 'This comparison does not flag high-density service pressure.'}`;
    return `The local recorded rate is ${distance} the reference. ${gap < 0 ? 'This flags a possible shortfall that needs local validation.' : 'This indicator does not flag a shortfall; differences in access within the area may still exist.'}`;
}

export interface MatchScoreBreakdown {
    base: number;
    sector: number;
    keywords: number;
    local_assets: number;
    status: number;
    cap: number;
    threshold: number;
}

export function interventionMethod(catalog: string) {
    if (catalog === 'dost_projects')
        return {
            formula: '15 starting points + 30 for sector alignment + 6 per matching keyword (up to 20 keyword points). Score is capped at 95.',
            threshold: 45,
            assets: 'Local assets and current availability do not add points to the historical-project score.',
        };
    return {
        formula:
            '20 starting points + 35 for sector alignment + 8 per matching keyword (up to 25 keyword points) + 15 for relevant local assets + 10 for active catalog status. Score is capped at 100.',
        threshold: 40,
        assets:
            catalog === 'dost_techs'
                ? 'The local-asset bonus applies to agriculture matches with recorded local commodities.'
                : 'The local-asset bonus applies to education matches with recorded schools, health matches with recorded health facilities, or agriculture matches with recorded commodities.',
    };
}
