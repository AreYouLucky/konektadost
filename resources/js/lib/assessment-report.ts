import { comparisonMeaning, explainNumber, indicatorMethod, interventionMethod, workedRate, type CalculationInputs } from './assessment-explanations';

export const assessmentLayers = ['profile_assets', 'sectors_roads', 'gaps_barangays', 'dost_catalog', 'matched_interventions'] as const;
export type AssessmentLayer = (typeof assessmentLayers)[number];
export interface ReportIndicator {
    key: string;
    label: string;
    dimension: string;
    unit: string;
    value: number | null;
    benchmark: number | null;
    gap: number | null;
    status: string;
    confidence: string;
    is_shortfall: boolean;
    source: string;
    reason: string;
    calculation?: CalculationInputs | null;
}
export interface ReportBarangay {
    name: string;
    population: number | null;
    priority_score?: number | null;
    priority_level: string;
    priority_reason: string;
}
export interface AssessmentReportInput {
    location: {
        name: string;
        province: string | null;
        type: string | null;
        psg_code: string | null;
        population: number | null;
        area_km2: number | null;
        population_statistics?: unknown;
    };
    focus: string;
    populationYear?: number | null;
    results: ReportIndicator[];
    functionToolsBundle?: unknown;
    layerEResult?: unknown;
    barangayAssessment?: { barangays: ReportBarangay[] } | null;
    localLaboratories?: unknown[];
    dostInterventions?: unknown[];
    projectCatalog?: unknown;
    inventoryIntegrity?: unknown;
    analysisScopeNotice?: string;
    resourceSummary?: unknown;
    agricultureSummary?: unknown;
    targetAreas?: unknown;
}
export interface OverviewChart {
    title: string;
    description: string;
    maximum?: number;
    reference?: number;
    rows: { label: string; value: number | null; formatted?: string; color?: string }[];
}
export interface LayerOverview {
    key: AssessmentLayer;
    title: string;
    summary: string;
    metrics: { label: string; value: string; note: string }[];
    charts: OverviewChart[];
    steps: string[];
    interpretation: string;
}
export const reportRecord = (value: unknown): Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
export const reportList = (value: unknown) => (Array.isArray(value) ? value.map(reportRecord) : []);
export const reportNumber = (value: unknown): number | null => {
    if ((typeof value !== 'number' && typeof value !== 'string') || (typeof value === 'string' && value.trim() === '')) return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
};
export const reportText = (value: unknown, fallback = 'Unavailable') => (typeof value === 'string' && value.trim() !== '' ? value : fallback);
export const reportLabel = (value: unknown) => reportText(value).replaceAll('_', ' ');
const metric = (label: string, value: number | null, note: string) => ({ label, value: explainNumber(value), note });
const sectorNames: Record<string, string> = {
    population: 'Population',
    health: 'Health',
    education: 'Education',
    digital_connectivity: 'Digital connectivity',
    roads: 'Roads',
    agriculture: 'Agriculture',
    sti_capacity: 'STI & laboratories',
    safety_drrm: 'Safety & DRRM',
};

export function reportGaps(report: AssessmentReportInput) {
    return reportList(reportRecord(reportRecord(report.functionToolsBundle).detected_gaps).gaps);
}
export function reportMatches(report: AssessmentReportInput) {
    const matched = reportRecord(report.layerEResult).matched_interventions ?? reportRecord(report.functionToolsBundle).matched_interventions;
    return reportList(reportRecord(matched).gap_interventions);
}
export function reportCandidates(report: AssessmentReportInput): Record<string, unknown>[] {
    return reportMatches(report).flatMap((gap) =>
        reportList(gap.matched_interventions).map((candidate) => ({ ...candidate, gap_id: gap.gap_id, gap_name: gap.gap_name, domain: gap.domain })),
    );
}

// Keep historical projects in the evidence tables, separate from technology/program options.
export function reportTechnologyOptions(report: AssessmentReportInput) {
    const unique = new Map<string, Record<string, unknown>>();
    for (const row of reportCandidates(report)) {
        if (!['technologies', 'dost_techs'].includes(String(row.source_catalog))) continue;
        const key = `${row.source_catalog}:${row.intervention_id}`;
        if (!unique.has(key) || (reportNumber(row.relevance_score) ?? -1) > (reportNumber(unique.get(key)?.relevance_score) ?? -1)) {
            unique.set(key, row);
        }
    }
    return [...unique.values()].sort((a, b) => (reportNumber(b.relevance_score) ?? -1) - (reportNumber(a.relevance_score) ?? -1));
}

export function reportLaboratoryOptions(report: AssessmentReportInput) {
    const sector = reportRecord(reportRecord(reportRecord(report.functionToolsBundle).sector_analysis).sti_laboratory);
    const conflict = reportList(reportRecord(report.inventoryIntegrity).inventories).some(
        (row) => row.dataset === 'laboratories' && row.has_conflicts === true,
    );
    const local = conflict ? [] : reportList(report.localLaboratories);
    const nearest = reportRecord(sector.nearest_accredited_laboratory_if_zero);
    return {
        conflict,
        local,
        // Nearby referral partners are not proposed new laboratories or verified service matches.
        nearby: !conflict && local.length === 0 && typeof nearest.agency_name === 'string' ? nearest : null,
    };
}
export function reportCatalog(report: AssessmentReportInput): Record<string, unknown>[] {
    const catalog = reportRecord(reportRecord(report.functionToolsBundle).dost_technologies);
    const records = reportList(catalog.records ?? catalog.results);
    return (records.length ? records : reportList(report.dostInterventions)).map((row) => ({
        ...row,
        name: row.name ?? row.title,
        mechanism: row.mechanism ?? row.application_mechanism,
        outcome: row.outcome ?? row.expected_outcome,
        status: row.tech_status ?? row.status,
    }));
}
export function indicatorExplanation(row: ReportIndicator) {
    const method = indicatorMethod(row.key);
    const worked = row.status === 'no_data' ? null : workedRate(row.calculation, row.value);
    return [method.formula, worked, comparisonMeaning(row.key, row.value, row.benchmark, row.gap, row.status), row.reason].filter(Boolean).join(' ');
}
export function gapExplanation(gap: Record<string, unknown>) {
    const confidence = gap.confidence === 'high' ? 1 : 0.65;
    return `Gap % = (local − reference) ÷ reference × 100. Severity = min(100, absolute gap %) × ${confidence}, rounded to one decimal. ${confidence === 1 ? 'The source checks did not flag low confidence.' : 'Provisional evidence receives 65% weight; this is not a probability.'} Population is context, not a confirmed count of unserved residents.`;
}
export function candidateExplanation(candidate: Record<string, unknown>) {
    const method = interventionMethod(reportText(candidate.source_catalog));
    const points = reportRecord(candidate.score_breakdown);
    const breakdown = ['base', 'sector', 'keywords', 'local_assets', 'status'].map((key) => reportNumber(points[key]));
    const equation = breakdown.every((value) => value !== null)
        ? ` Actual points: ${breakdown.join(' + ')}, capped at ${points.cap}, give ${candidate.relevance_score}/100.`
        : '';
    const criteria = Array.isArray(candidate.matching_criteria)
        ? candidate.matching_criteria.filter((value) => typeof value === 'string').join('; ')
        : '';
    return `${method.formula}${equation} ${criteria} A score is matching strength, not a probability of success or confirmed deployment readiness.`;
}

export function barangayPriorityExplanation(value: unknown) {
    const row = reportRecord(value);
    if (reportNumber(row.priority_score) === null)
        return 'A priority score is unavailable. Missing population does not become a zero score or a confirmed rank.';
    const components = reportRecord(reportRecord(row.score_components).normalized_components);
    const points = Object.entries(components)
        .map(([name, amount]) => `${name.replaceAll('_', ' ')}: ${explainNumber(reportNumber(amount))}`)
        .join(' + ');
    return points
        ? `${points}. These component points are added and rounded to one decimal to give ${explainNumber(reportNumber(row.priority_score))}/100. Active weights and source completeness affect the result; this is a review priority, not verified service need.`
        : 'The returned score is a composite review priority. Detailed component points are unavailable; no component values are inferred.';
}

export function buildLayerOverviews(report: AssessmentReportInput): Record<AssessmentLayer, LayerOverview> {
    const bundle = reportRecord(report.functionToolsBundle);
    const statistics = reportRecord(report.location.population_statistics);
    const history = reportList(statistics.history).length
        ? reportList(statistics.history)
        : [
              { year: statistics.baseline_year, population: statistics.population_baseline },
              { year: statistics.active_year ?? report.populationYear, population: statistics.population_current ?? report.location.population },
          ];
    const censusRows = history
        .filter((row) => reportNumber(row.year) !== null)
        .sort((a, b) => Number(a.year) - Number(b.year))
        .map((row) => ({ label: String(row.year), value: reportNumber(row.population) }));
    const labSector = reportRecord(reportRecord(bundle.sector_analysis).sti_laboratory);
    const labCapabilities = reportRecord(labSector.capability_domains);
    const laboratoryConflict = reportLaboratoryOptions(report).conflict;
    const audit = reportRecord(bundle.dataset_audit);
    const datasets = reportRecord(audit.datasets);
    const audits = Object.values(datasets).map(reportRecord);
    const assets = reportRecord(bundle.local_assets);
    const facilities = reportRecord(reportRecord(assets.facilities).by_sector);
    const integrity = reportRecord(report.inventoryIntegrity);
    const conflicts = reportList(integrity.inventories).filter((row) => row.has_conflicts === true);
    const count = (dataset: string, value: unknown) =>
        reportRecord(datasets[dataset]).status === 'unavailable' || conflicts.some((row) => row.dataset === dataset) ? null : reportNumber(value);
    const assetRows = [
        { label: 'Health facilities', value: count('facilities', facilities.health ?? reportRecord(assets.facility_counts).health) },
        { label: 'Schools', value: count('facilities', facilities.education ?? reportRecord(assets.facility_counts).education) },
        { label: 'Safety facilities', value: count('facilities', facilities.safety ?? reportRecord(assets.facility_counts).safety) },
        { label: 'Laboratories', value: count('laboratories', reportRecord(assets.laboratories).count ?? assets.laboratory_count) },
        { label: 'Telecom sites', value: count('telecom_sites', reportRecord(assets.telecom_sites).total_sites ?? assets.telecom_site_count) },
    ];
    const measured = report.results.filter((row) => row.status !== 'no_data' && row.value !== null);
    const compared = report.results.filter((row) => row.status === 'available' && row.value !== null && row.benchmark !== null);
    const flags = compared.filter((row) => row.is_shortfall);
    const referenceRows = compared
        .filter((row) => row.benchmark! > 0)
        .map((row) => ({
            label: row.label,
            value: (row.value! / row.benchmark!) * 100,
            formatted: `${explainNumber((row.value! / row.benchmark!) * 100)}%`,
            color: row.confidence !== 'high' ? '#94a3b8' : row.is_shortfall ? '#d97706' : '#087dcc',
        }));
    const gaps = reportGaps(report);
    const gapsAvailable = Array.isArray(reportRecord(bundle.detected_gaps).gaps);
    const diagnostics = reportList(reportRecord(bundle.detected_gaps).sector_diagnostics);
    const barangays = report.barangayAssessment?.barangays ?? [];
    const ranked = [...barangays].filter((row) => row.priority_score != null).sort((a, b) => b.priority_score! - a.priority_score!);
    const projects = reportList(reportRecord(bundle.local_dost_projects).projects);
    const catalog = reportCatalog(report);
    const agencies = new Map<string, number>();
    projects.forEach((row) => {
        const agency = reportText(row.agency, 'Agency unrecorded');
        agencies.set(agency, (agencies.get(agency) ?? 0) + 1);
    });
    const statuses = new Map<string, number>();
    catalog.forEach((row) => {
        const status = reportLabel(row.tech_status ?? row.status ?? 'unknown');
        statuses.set(status, (statuses.get(status) ?? 0) + 1);
    });
    const matches = reportMatches(report);
    const matchesAvailable = Array.isArray(
        reportRecord(reportRecord(report.layerEResult).matched_interventions ?? bundle.matched_interventions).gap_interventions,
    );
    const candidates = reportCandidates(report);
    const unique = new Map<string, Record<string, unknown>>();
    candidates.forEach((row) => {
        const key = `${row.source_catalog}:${row.intervention_id}`;
        if (!unique.has(key) || (reportNumber(row.relevance_score) ?? -1) > (reportNumber(unique.get(key)?.relevance_score) ?? -1))
            unique.set(key, row);
    });
    const leading = [...unique.values()].sort((a, b) => (reportNumber(b.relevance_score) ?? -1) - (reportNumber(a.relevance_score) ?? -1));
    const matchedGaps = matches.filter((row) => reportList(row.matched_interventions).length > 0);
    const priorityWeights = reportRecord(reportRecord(bundle.priority_scores).active_weights);
    const activeWeights = Object.entries(priorityWeights)
        .filter(([, value]) => (reportNumber(value) ?? 0) > 0)
        .map(([name, value]) => `${name.replaceAll('_', ' ')} ${explainNumber(reportNumber(value))}%`)
        .join('; ');
    return {
        profile_assets: {
            key: 'profile_assets',
            title: 'Layer A · Area & assets',
            summary: `People and resources in ${report.location.name}.`,
            metrics: [
                metric('Datasets checked', audits.length ? audits.length : null, 'Inventory sources'),
                metric('Location conflicts', report.inventoryIntegrity ? conflicts.length : null, 'Need validation'),
            ],
            charts: [
                {
                    title: 'Population across census years',
                    description: 'By census year.',
                    rows: censusRows,
                },
                {
                    title: 'Recorded local assets',
                    description: 'Recorded counts in this area.',
                    rows: assetRows,
                },
                {
                    title: 'Dataset availability',
                    description: 'Datasets by completeness.',
                    rows: ['available', 'partial', 'empty', 'unavailable'].map((status) => ({
                        label: reportLabel(status),
                        value: audits.length ? audits.filter((row) => row.status === status).length : null,
                        color: status === 'available' ? '#087dcc' : status === 'partial' ? '#d97706' : '#94a3b8',
                    })),
                },
            ],
            steps: [
                'Resolve the selected province, city/municipality or barangay through its verified geographic links.',
                'Use the active census year and matching geographic level for population. Keep missing figures unavailable.',
                'Count inventory records linked to this selection. Check completeness and conflicting location links before interpreting counts.',
                'Population density = population ÷ land area. Growth = (current population − baseline population) ÷ baseline population × 100; annual growth uses the year interval.',
            ],
            interpretation: conflicts.length
                ? `Validate ${conflicts.length} conflicting dataset(s) before choosing a site.`
                : 'Check inventory completeness before choosing a site; no record does not prove no service.',
        },
        sectors_roads: {
            key: 'sectors_roads',
            title: 'Layer B · Sector needs',
            summary: 'Local services compared with the national reference.',
            metrics: [
                metric('Measured', measured.length, 'Indicators with a usable value'),
                metric('With references', compared.length, 'Comparable indicators'),
                metric('Comparison flags', flags.length, 'Includes provisional findings'),
                metric('Unavailable', report.results.length - measured.length, 'Missing measurements stay unknown'),
            ],
            charts: [
                {
                    title: 'Local value relative to its reference',
                    description: '100% = national reference. Amber = flagged, gray = provisional.',
                    maximum: 200,
                    reference: 100,
                    rows: referenceRows,
                },
                {
                    title: 'Indicator evidence',
                    description: 'How solid each comparison is.',
                    rows: [
                        { label: 'High-confidence comparisons', value: compared.filter((row) => row.confidence === 'high').length },
                        { label: 'Provisional comparisons', value: compared.filter((row) => row.confidence !== 'high').length, color: '#94a3b8' },
                        { label: 'Measured without a reference', value: measured.length - compared.length, color: '#64748b' },
                        { label: 'Unavailable measurements', value: report.results.length - measured.length, color: '#94a3b8' },
                    ],
                },
            ],
            steps: [
                'For a service rate: recorded local count ÷ census population × 100,000 (or × 1,000,000 for laboratories and services).',
                'For 5G share: recorded sites listing 5G ÷ all recorded sites × 100. For density: population ÷ land area in km².',
                'Compute national reference rates from the live domain tables. These are descriptive comparisons, not official minimum standards.',
                'Gap % = (local value − reference) ÷ reference × 100. Compare service rates below the reference and density above it; review confidence before drawing a conclusion.',
            ],
            interpretation: `${flags.length} possible needs flagged. Use these to screen technology and lab options; validate provisional findings.`,
        },
        gaps_barangays: {
            key: 'gaps_barangays',
            title: 'Layer C · Priority needs & areas',
            summary: gapsAvailable
                ? `${gaps.length} gap(s) to guide where DOST support may be useful.`
                : 'Gap evidence unavailable; local needs remain unconfirmed.',
            metrics: [
                metric('Detected gaps', gapsAvailable ? gaps.length : null, 'Across all assessed sectors'),
                metric(
                    'High confidence',
                    gapsAvailable ? gaps.filter((row) => row.confidence === 'high').length : null,
                    'Passed current completeness checks',
                ),
                metric('Provisional', gapsAvailable ? gaps.filter((row) => row.confidence !== 'high').length : null, 'Need source/local validation'),
                metric('Ranked barangays', barangays.length ? ranked.length : null, 'Municipality-level scoring only'),
            ],
            charts: [
                {
                    title: 'Largest comparison gaps',
                    description: 'Top 6 severity scores /100. Gray = provisional.',
                    maximum: 100,
                    rows: [...gaps]
                        .sort((a, b) => (reportNumber(b.severity_score) ?? -1) - (reportNumber(a.severity_score) ?? -1))
                        .slice(0, 6)
                        .map((row) => ({
                            label: reportText(row.label),
                            value: reportNumber(row.severity_score),
                            color: row.confidence === 'high' ? '#d97706' : '#94a3b8',
                        })),
                },
                {
                    title: 'Gaps by sector',
                    description: 'Gaps found per sector.',
                    rows: Object.entries(sectorNames).map(([sector, label]) => ({
                        label,
                        value:
                            !gapsAvailable ||
                            diagnostics.some((row) => row.sector === sector && ['not_assessed', 'insufficient_evidence'].includes(String(row.status)))
                                ? null
                                : gaps.filter(
                                      (row) => row.dimension && sectorForDimension(String(row.dimension), String(row.indicator_key)) === sector,
                                  ).length,
                    })),
                },
                ...(barangays.length
                    ? [
                          {
                              title: 'Highest barangay review priorities',
                              description: 'Top 6 priority scores /100.',
                              maximum: 100,
                              rows: ranked.slice(0, 6).map((row) => ({
                                  label: row.name,
                                  value: row.priority_score ?? null,
                                  formatted: `${explainNumber(row.priority_score)}/100`,
                              })),
                          },
                      ]
                    : []),
            ],
            steps: [
                'Start with indicators that have a measured value and a national reference; keep source limitations attached.',
                'Gap detection and intervention screening use all computed sectors for the selected location. The chosen focus limits the Layer B comparison table, so its flag count may differ from the total gaps here.',
                'Severity = min(100, absolute gap %) × confidence weight. Weight is 1 for high confidence or 0.65 for provisional findings; round to one decimal.',
                'Severity bands: critical 75–100, high 50–below 75, moderate 25–below 50, low below 25. This is not the probability that a shortage exists.',
                activeWeights
                    ? `Barangay priority adds its normalized component scores using active weights: ${activeWeights}. Missing/inactive datasets have zero weight; remaining weights are redistributed.`
                    : 'Detailed barangay priority scores require a municipality selection and sufficient inputs. Do not substitute province totals for a barangay ranking.',
            ],
            interpretation: ranked.length
                ? `Review ${ranked[0].name} first (${explainNumber(ranked[0].priority_score)}/100 priority); confirm the need and site readiness.`
                : 'Match support to this location’s gaps. Barangay rankings require municipality-level evidence.',
        },
        dost_catalog: {
            key: 'dost_catalog',
            title: 'Layer D · DOST catalog',
            summary: 'DOST technologies and past projects available to match.',
            metrics: [
                metric(
                    'Local project records',
                    bundle.local_dost_projects ? reportNumber(reportRecord(bundle.local_dost_projects).total_matched_projects) : null,
                    'Location-name screening leads',
                ),
                metric('Catalog entries shown', bundle.dost_technologies ? catalog.length : null, 'Records returned by this search'),
                metric('Agencies in local records', projects.length ? agencies.size : null, 'From the returned project list'),
            ],
            charts: [
                {
                    title: 'Returned local projects by agency',
                    description: 'Past projects matched to this place.',
                    rows: [...agencies]
                        .sort((a, b) => b[1] - a[1])
                        .slice(0, 8)
                        .map(([label, value]) => ({ label, value })),
                },
                {
                    title: 'Catalog status of returned entries',
                    description: 'As recorded in the catalog.',
                    rows: [...statuses].map(([label, value]) => ({ label, value })),
                },
            ],
            steps: [
                'Search the DOST technology/program and historical-project catalogs using the available catalog fields.',
                'Screen local project records by the selected locality/province names. Treat text matches as leads requiring location confirmation.',
                'Normalize recorded status, including legacy status spelling. A completed project describes history rather than a currently available program.',
                'Keep each source catalog and identifier with the result. Matching and feasibility are assessed separately in Layer E.',
            ],
            interpretation: 'Use these records to shortlist options with DOST; confirm current availability, cost and local fit.',
        },
        matched_interventions: {
            key: 'matched_interventions',
            title: 'Layer E · Technology & laboratory fit',
            summary: matchesAvailable
                ? `${unique.size} catalog option(s) match ${matchedGaps.length} of ${matches.length} evaluated needs.`
                : 'Matching evidence unavailable; no technology assignment can be confirmed.',
            metrics: [
                metric('Gaps evaluated', matchesAvailable ? matches.length : null, 'Matching input gaps'),
                metric('Gaps with candidates', matchesAvailable ? matchedGaps.length : null, 'At least one recorded match'),
                metric('Distinct candidates', matchesAvailable ? unique.size : null, 'Catalog + identifier, counted once'),
                metric('Unmatched gaps', matchesAvailable ? matches.length - matchedGaps.length : null, 'No candidate forced'),
            ],
            charts: [
                {
                    title: 'Highest candidate relevance',
                    description: 'Top 6 options /100 (match strength, not success rate).',
                    maximum: 100,
                    rows: leading.slice(0, 6).map((row) => ({
                        label: reportText(row.name),
                        value: reportNumber(row.relevance_score),
                        formatted: `${explainNumber(reportNumber(row.relevance_score))}/100`,
                    })),
                },
                {
                    title: 'Needs with a catalog option',
                    description: 'Needs with at least one matched option.',
                    maximum: 100,
                    rows: Object.entries(sectorNames).map(([sector, label]) => {
                        const total = matches.filter((row) => row.domain === sector).length;
                        const covered = matchedGaps.filter((row) => row.domain === sector).length;
                        return {
                            label,
                            value: matchesAvailable && total > 0 ? (covered / total) * 100 : null,
                            formatted: `${covered} of ${total} needs`,
                        };
                    }),
                },
                {
                    title: 'Recorded local laboratory capabilities',
                    description: 'Lab services grouped by type.',
                    rows: Object.entries({
                        microbiological_and_food_safety: 'Food safety & microbiology',
                        chemical_and_physicochemical: 'Chemical testing',
                        water_and_environmental: 'Water & environment',
                        calibration_and_metrology: 'Calibration & metrology',
                        mechanical_and_materials: 'Materials testing',
                        other_testing_services: 'Other services',
                    }).map(([key, label]) => ({
                        label,
                        value: labSector.status === 'available' && !laboratoryConflict ? reportNumber(labCapabilities[key]) : null,
                    })),
                },
            ],
            steps: [
                'Start with detected gaps and screen recorded catalog entries by sector, keywords, local assets and catalog status.',
                'Technology/program score: 20 base + 35 sector + up to 25 keyword + 15 relevant assets + 10 active status; cap at 100 and require at least 40. Historical projects use 15 base + 30 sector + up to 20 keyword points, require 45, and cap at 95.',
                'Sort candidates by relevance, combine duplicate names, and return up to four per gap. Gap severity is not added to relevance.',
                'Suitability is a weighted screening score (need 30%, reach 20%, resources 15%, infrastructure 20%, institutions 15%). Feasibility is a prerequisites checklist. Both apply only to the named evaluated candidate and require local confirmation.',
            ],
            interpretation: leading.length
                ? 'Next: confirm the need, site, operator, budget and current availability with DOST before deployment.'
                : 'No supported technology match. Validate local needs and ask DOST to review suitable options.',
        },
    };
}

function sectorForDimension(dimension: string, key: string) {
    return (
        (
            {
                population_and_social_needs: 'population',
                health_and_public_health: 'health',
                education_skills_and_scientific_talent: 'education',
                science_technology_and_innovation_capacity: 'sti_capacity',
                infrastructure_and_connectivity: key.includes('road') ? 'roads' : 'digital_connectivity',
                environment_climate_disaster: 'safety_drrm',
                agriculture_fisheries_food: 'agriculture',
            } as Record<string, string>
        )[dimension] ?? dimension
    );
}
