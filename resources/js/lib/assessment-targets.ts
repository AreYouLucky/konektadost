import { explainNumber } from './assessment-explanations';
import { reportCandidates, reportList, reportNumber, reportRecord, reportText, type AssessmentReportInput } from './assessment-report';

/** Points-based factors shared by barangay and city/municipality target ranking. */
export const TARGET_FACTORS: { key: string; label: string; sector: string | null }[] = [
    { key: 'population_need', label: 'Population', sector: null },
    { key: 'health_access_gap', label: 'Health gap', sector: 'health' },
    { key: 'education_access_gap', label: 'Education gap', sector: 'education' },
    { key: 'road_accessibility_gap', label: 'Road gap', sector: 'roads' },
    { key: 'digital_connectivity_gap', label: 'Connectivity gap', sector: 'digital_connectivity' },
    { key: 'sti_lab_access_gap', label: 'Lab access gap', sector: 'sti_capacity' },
    { key: 'safety_and_drrm_gap', label: 'Safety gap', sector: 'safety_drrm' },
    { key: 'geographic_isolation', label: 'Isolation', sector: null },
];

export interface TargetTechnology {
    ref: string;
    name: string;
    agency: string;
    relevance: number | null;
    need: string;
    /** True when no option matched this area's leading factors, so the overall best match is shown. */
    general: boolean;
}

export interface TargetArea {
    id: number | string;
    name: string;
    rank: number;
    score: number;
    population: number | null;
    components: Record<string, number>;
    drivers: { factor: string; label: string; points: number; text: string }[];
    technologies: TargetTechnology[];
}

export interface TargetAreaSet {
    level: 'municipality' | 'barangay';
    parent: string;
    areas: TargetArea[];
    total: number;
    factors: { key: string; label: string }[];
    weights: Record<string, number>;
    sparse: string[];
    method: string;
}

const factorLabel = (key: string) => TARGET_FACTORS.find((factor) => factor.key === key)?.label ?? key.replaceAll('_', ' ');

/** Barangay driver wording from recorded counts (never inferred). */
function barangayDriverText(factor: string, row: Record<string, unknown>) {
    const recorded = (key: string, noun: string) => {
        const n = reportNumber(row[key]) ?? 0;
        return n === 0 ? `No ${noun} recorded` : `${n} ${noun} recorded`;
    };
    switch (factor) {
        case 'population_need':
            return `${explainNumber(reportNumber(row.population))} people`;
        case 'health_access_gap':
            return recorded('health_facilities_count', 'health facilities');
        case 'education_access_gap':
            return recorded('schools_count', 'schools');
        case 'digital_connectivity_gap':
            return recorded('telecom_sites_count', 'cell sites');
        case 'safety_and_drrm_gap':
            return recorded('safety_facilities_count', 'safety facilities');
        case 'geographic_isolation':
            return `Nearest facility ${explainNumber(reportNumber(row.nearest_facility_km))} km away`;
        case 'road_accessibility_gap':
            return 'Few recorded road segments';
        default:
            return factorLabel(factor);
    }
}

function topDrivers(components: Record<string, number>, text: (factor: string) => string) {
    return Object.entries(components)
        .filter(([, points]) => points > 0)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 2)
        .map(([factor, points]) => ({ factor, label: factorLabel(factor), points, text: text(factor) }));
}

/** Best technology/program per area: first matching the area's leading gap sectors, else the overall best match. */
function technologiesFor(drivers: TargetArea['drivers'], candidates: Record<string, unknown>[]): TargetTechnology[] {
    const picked = new Map<string, TargetTechnology>();
    const toTech = (row: Record<string, unknown>, general: boolean): TargetTechnology => ({
        ref: `${row.source_catalog}:${row.intervention_id}`,
        name: reportText(row.name),
        agency: reportText(row.agency, 'Agency not recorded'),
        relevance: reportNumber(row.relevance_score),
        need: reportText(row.gap_name),
        general,
    });
    for (const driver of drivers) {
        const sector = TARGET_FACTORS.find((factor) => factor.key === driver.factor)?.sector;
        if (!sector) continue;
        const best = candidates.find((row) => row.domain === sector && !picked.has(`${row.source_catalog}:${row.intervention_id}`));
        if (best) picked.set(`${best.source_catalog}:${best.intervention_id}`, toTech(best, false));
        if (picked.size >= 2) break;
    }
    if (!picked.size && candidates[0]) picked.set('general', toTech(candidates[0], true));
    return [...picked.values()];
}

/** Province → ranked cities/municipalities; city/municipality → ranked barangays. */
export function buildTargetAreas(report: AssessmentReportInput & { targetAreas?: unknown }, limit = 10): TargetAreaSet | null {
    const candidates = reportCandidates(report)
        .filter((row) => ['technologies', 'dost_techs'].includes(String(row.source_catalog)))
        .sort((a, b) => (reportNumber(b.relevance_score) ?? -1) - (reportNumber(a.relevance_score) ?? -1));
    const province = reportRecord(report.targetAreas);
    if (Array.isArray(province.areas)) {
        const all = reportList(province.areas).filter((row) => reportNumber(row.score) !== null);
        const weights = Object.fromEntries(Object.entries(reportRecord(province.weights)).map(([key, value]) => [key, reportNumber(value) ?? 0]));
        return {
            level: 'municipality',
            parent: reportText(province.parent, report.location.name),
            total: all.length,
            weights,
            sparse: (Array.isArray(province.sparse_factors) ? province.sparse_factors : []).map(String),
            method: reportText(province.method, ''),
            factors: TARGET_FACTORS.filter((factor) => (weights[factor.key] ?? 0) > 0),
            areas: all.slice(0, limit).map((row, index) => {
                const components = Object.fromEntries(
                    Object.entries(reportRecord(row.components)).map(([key, value]) => [key, reportNumber(value) ?? 0]),
                );
                const drivers = reportList(row.drivers).map((driver) => ({
                    factor: String(driver.factor),
                    label: factorLabel(String(driver.factor)),
                    points: reportNumber(driver.points) ?? 0,
                    text: reportText(driver.text),
                }));
                return {
                    id: reportNumber(row.id) ?? index,
                    name: reportText(row.name),
                    rank: reportNumber(row.rank) ?? index + 1,
                    score: reportNumber(row.score) ?? 0,
                    population: reportNumber(row.population),
                    components,
                    drivers,
                    technologies: technologiesFor(drivers, candidates),
                };
            }),
        };
    }
    const barangays = (report.barangayAssessment?.barangays ?? []).map(reportRecord).filter((row) => reportNumber(row.priority_score) !== null);
    if (!barangays.length) return null;
    const ranked = [...barangays].sort((a, b) => (reportNumber(b.priority_score) ?? 0) - (reportNumber(a.priority_score) ?? 0));
    const weights = Object.fromEntries(
        Object.entries(reportRecord(reportRecord(reportRecord(report.functionToolsBundle).priority_scores).active_weights)).map(([key, value]) => [
            key,
            reportNumber(value) ?? 0,
        ]),
    );
    return {
        level: 'barangay',
        parent: report.location.name,
        total: ranked.length,
        weights,
        sparse: [],
        method: 'Barangay priority score: population need plus recorded facility, road, connectivity and isolation deficits.',
        factors: TARGET_FACTORS.filter((factor) => !Object.keys(weights).length || (weights[factor.key] ?? 0) > 0),
        areas: ranked.slice(0, limit).map((row, index) => {
            const components = Object.fromEntries(
                Object.entries(reportRecord(reportRecord(row.score_components).normalized_components)).map(([key, value]) => [
                    key,
                    reportNumber(value) ?? 0,
                ]),
            );
            const drivers = topDrivers(components, (factor) => barangayDriverText(factor, row));
            return {
                id: reportText(row.psg_code, String(index)),
                name: reportText(row.name),
                rank: index + 1,
                score: reportNumber(row.priority_score) ?? 0,
                population: reportNumber(row.population),
                components,
                drivers,
                technologies: technologiesFor(drivers, candidates),
            };
        }),
    };
}
