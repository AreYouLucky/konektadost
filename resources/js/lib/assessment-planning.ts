/** Display and screening rules only. The server remains the source of indicator measurements and shortfall directions. */
export interface IndicatorEvidence {
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
    reason?: string;
    source?: string;
}

export interface CatalogProject {
    id: number;
    code: string | null;
    name: string;
    agency: string | null;
    sector: string | null;
    mechanism: string | null;
    status: string | null;
    outcome: string | null;
    deployment_clusters: string | null;
}

export interface ProjectCatalogData {
    status: 'available' | 'unavailable';
    source: string;
    coverage: string;
    records: CatalogProject[];
}

export type EvidenceState = 'priority' | 'within' | 'review' | 'reference' | 'missing';
export const EVIDENCE_STATES: Record<EvidenceState, { label: string; color: string }> = {
    priority: { label: 'High-confidence flags', color: '#d97706' },
    within: { label: 'Within comparison', color: '#087dcc' },
    review: { label: 'Low-confidence comparisons', color: '#64748b' },
    reference: { label: 'Measured; no reference', color: '#087dcc' },
    missing: { label: 'No measurement', color: '#cbd5e1' },
};

const finite = (value: number | null): value is number => value !== null && Number.isFinite(value);
export function evidenceState(row: IndicatorEvidence): EvidenceState {
    if (row.status === 'no_data' || !finite(row.value)) return 'missing';
    if (row.status !== 'available' || !finite(row.benchmark)) return 'reference';
    if (row.confidence !== 'high') return 'review';
    return row.is_shortfall ? 'priority' : 'within';
}

/** A dimensionless comparison, not a service-adequacy score; density has the opposite pressure direction. */
export function referenceIndex(row: IndicatorEvidence): number | null {
    if (row.status !== 'available' || !finite(row.value) || !finite(row.benchmark) || row.benchmark <= 0) return null;
    const index = (row.value / row.benchmark) * 100;
    return Number.isFinite(index) ? index : null;
}

export function rankedShortfalls(results: IndicatorEvidence[]): IndicatorEvidence[] {
    return results
        .filter((row) => ['priority', 'review'].includes(evidenceState(row)) && row.is_shortfall)
        .sort(
            (a, b) =>
                Number(b.confidence === 'high') - Number(a.confidence === 'high') ||
                Math.abs(b.gap ?? 0) - Math.abs(a.gap ?? 0) ||
                a.label.localeCompare(b.label),
        );
}

interface TopicRule {
    label: string;
    terms: string[];
    check: string;
}

const RULES: Record<string, TopicRule> = {
    health_and_public_health: {
        label: 'Health access',
        terms: ['health', 'medical', 'nutrition', 'telehealth', 'diagnostic', 'diagnostics'],
        check: 'Verify operating facilities, staffing, referral access, demand and clinical workflows with the local health office. A technology does not replace a hospital or clinical staff.',
    },
    education_skills_and_scientific_talent: {
        label: 'Science learning',
        terms: ['education', 'school', 'schools', 'learning', 'stem', 'science kiosks'],
        check: 'Confirm school enrolment, current learning resources, teachers, power and maintenance arrangements. A learning tool does not replace missing school capacity.',
    },
    science_technology_and_innovation_capacity: {
        label: 'Testing and laboratory access',
        terms: ['testing', 'laboratory', 'laboratories', 'calibration', 'research'],
        check: 'Identify the tests actually needed, existing referral laboratories, accreditation scope, turnaround time and cost before proposing new equipment or a laboratory.',
    },
    infrastructure_and_connectivity: {
        label: 'Connectivity',
        terms: ['connectivity', 'broadband', 'internet', 'network infrastructure', 'telecommunications'],
        check: 'Measure service availability, speed, reliability and affordability with local users and providers. Site counts do not measure coverage; a sensor using a network does not supply network coverage.',
    },
    environment_climate_disaster: {
        label: 'Emergency preparedness',
        terms: ['disaster', 'drrm', 'flood', 'water level', 'rain gauges', 'early warning'],
        check: 'Validate hazards, exposed communities, warning protocols and response capacity with the LGU DRRM office. A safety-facility comparison alone does not establish flood risk or sensor requirements.',
    },
    population_and_social_needs: {
        label: 'Population and service pressure',
        terms: ['urban', 'community', 'planning', 'traffic', 'hydroponics'],
        check: 'Map actual service demand and affected communities. Density is pressure context, not evidence of unemployment, food insecurity or a specific traffic hazard.',
    },
};

export interface PlanningTopic extends TopicRule {
    id: string;
    basis: 'comparison' | 'provisional' | 'pressure' | 'modeled';
    evidence: string[];
}

export interface AgricultureContext {
    status: string;
    model_year: number | null;
    categories: { code: string | null; name: string; record_count: number }[];
}

export function planningTopics(results: IndicatorEvidence[], agriculture?: AgricultureContext): PlanningTopic[] {
    const topics = new Map<string, PlanningTopic>();
    for (const row of rankedShortfalls(results)) {
        const rule = RULES[row.dimension];
        if (!rule) continue;
        const basis = row.confidence !== 'high' ? 'provisional' : row.key === 'population_density' ? 'pressure' : 'comparison';
        const key = `${row.dimension}:${basis}`;
        const topic = topics.get(key) ?? { ...rule, id: key, basis, evidence: [] };
        topic.evidence.push(row.label);
        topics.set(key, topic);
    }
    if (agriculture?.status === 'available') {
        for (const category of agriculture.categories.filter((row) => row.record_count > 0)) {
            const code = category.code;
            const terms =
                code === 'fisheries'
                    ? ['fish', 'fisheries', 'aquaculture', 'shellfish', 'depuration']
                    : code === 'livestock_poultry'
                      ? ['meat', 'poultry', 'livestock', 'food safety', 'thermal processing']
                      : code === 'crops'
                        ? ['agriculture', 'hydroponics', 'gardening', 'food processing', 'packaging', 'shelf life']
                        : [];
            if (!terms.length) continue;
            const id = `agriculture:${code}`;
            topics.set(id, {
                id,
                basis: 'modeled',
                label: category.name,
                terms,
                evidence: [
                    `${category.name}: ${category.record_count.toLocaleString('en-PH')} modeled entries (${agriculture.model_year ?? 'year unknown'})`,
                ],
                check: 'Confirm actual local commodities, producers or handlers, demand, food-safety needs and operating costs. Category-level model entries are planning context, not measured production or a demonstrated technology gap.',
            });
        }
    }
    return [...topics.values()];
}

const normalize = (text: string) =>
    text
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
export interface ProjectMatch {
    project: CatalogProject;
    reasons: { topic: PlanningTopic; terms: string[] }[];
    locationMatch: boolean;
}

/** Whole-word/phrase evidence matching; using a technology is not proof it remedies the triggering gap. */
export function matchProjects(catalog: CatalogProject[], topics: PlanningTopic[], localIds: number[] = []): ProjectMatch[] {
    return catalog
        .flatMap((project) => {
            const text = ` ${normalize([project.name, project.sector, project.mechanism].filter(Boolean).join(' '))} `;
            const reasons = topics
                .map((topic) => ({ topic, terms: topic.terms.filter((term) => text.includes(` ${normalize(term)} `)) }))
                .filter((reason) => reason.terms.length > 0);
            return reasons.length ? [{ project, reasons, locationMatch: localIds.includes(project.id) }] : [];
        })
        .sort(
            (a, b) =>
                Number(b.reasons.some((r) => r.topic.basis === 'comparison')) - Number(a.reasons.some((r) => r.topic.basis === 'comparison')) ||
                a.project.name.localeCompare(b.project.name),
        );
}

export function validationAction(row: IndicatorEvidence): string {
    if (evidenceState(row) === 'missing') return row.reason || 'Obtain the required local measurement and verify the selected-area assignment.';
    if (evidenceState(row) === 'reference') return 'Verify the measurement and establish an appropriate reference before labeling a shortfall.';
    return RULES[row.dimension]?.check ?? 'Validate the source inventory and local service demand before project design.';
}
