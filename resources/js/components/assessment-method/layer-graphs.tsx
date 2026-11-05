import { assessmentSectors } from '@/components/assessment-ai-analysis';
import { indicatorSector } from '@/components/assessment-sector-tables';
import { evidenceState, type EvidenceState } from '@/lib/assessment-planning';
import {
    reportGaps,
    reportMatches,
    reportNumber,
    reportRecord,
    reportTechnologyOptions,
    reportText,
    type AssessmentReportInput,
} from '@/lib/assessment-report';
import type { AssessmentResult, BarangayItem } from '@/pages/location-assessment/types';
import { BipartiteFlow, ChartCard, SEVERITY_COLORS, Scatter, StackedBars, type StackKey } from './charts';
import { DriversExplain, FlowExplain, GapSeverityExplain, ScatterExplain, SectorScorecardExplain } from './explain';
import { MATCH_COMPONENTS, RelevanceExplanation } from './relevance-explainer';

export { MATCH_COMPONENTS };

const STATE_KEYS: (StackKey & { key: EvidenceState })[] = [
    { key: 'priority', label: 'Flagged need', color: '#d97706' },
    { key: 'within', label: 'Within reference', color: 'var(--series-1)' },
    { key: 'review', label: 'Low confidence', color: '#64748b' },
    { key: 'reference', label: 'No reference', color: '#93c5fd' },
    { key: 'missing', label: 'No data', color: '#cbd5e1' },
];

/** Per sector: how many indicators are flagged, fine, uncertain or missing. */
export function SectorScorecard({ results }: { results: AssessmentResult[] }) {
    const bySector = new Map<string, Record<string, number>>();
    for (const row of results) {
        const sector = indicatorSector(row.dimension, row.key);
        const counts = bySector.get(sector) ?? {};
        const state = evidenceState(row);
        counts[state] = (counts[state] ?? 0) + 1;
        bySector.set(sector, counts);
    }
    const rows = [...bySector].map(([sector, values]) => ({
        label: assessmentSectors[sector] ?? sector.replaceAll('_', ' '),
        total: Object.values(values).reduce((sum, value) => sum + value, 0),
        values,
    }));
    const max = Math.max(1, ...rows.map((row) => row.total));
    return (
        <ChartCard title="Sector scorecard" hint="Indicators per sector, by result." explain={<SectorScorecardExplain />}>
            <StackedBars keys={STATE_KEYS} rows={rows} max={max} unit="indicators" />
        </ChartCard>
    );
}

const SEVERITY_KEYS: StackKey[] = ['critical', 'high', 'moderate', 'low'].map((key) => ({
    key,
    label: key.charAt(0).toUpperCase() + key.slice(1),
    color: SEVERITY_COLORS[key],
}));

/** How many gaps fall in each severity band, high-confidence vs provisional. */
export function GapSeverityMix({ report, className }: { report: AssessmentReportInput; className?: string }) {
    const gaps = reportGaps(report);
    if (!gaps.length) return null;
    const row = (label: string, list: Record<string, unknown>[]) => {
        const values: Record<string, number> = {};
        list.forEach((gap) => (values[String(gap.severity_level)] = (values[String(gap.severity_level)] ?? 0) + 1));
        return { label, total: list.length, values };
    };
    const rows = [
        row(
            'High confidence',
            gaps.filter((gap) => gap.confidence === 'high'),
        ),
        row(
            'Provisional',
            gaps.filter((gap) => gap.confidence !== 'high'),
        ),
    ].filter((item) => item.total > 0);
    return (
        <ChartCard title="Gap severity mix" hint="Number of gaps per severity band." explain={<GapSeverityExplain />} className={className}>
            <StackedBars keys={SEVERITY_KEYS} rows={rows} max={Math.max(1, ...rows.map((item) => item.total))} unit="gaps" />
        </ChartCard>
    );
}

export const PRIORITY_COMPONENTS: StackKey[] = [
    { key: 'population_need', label: 'Population' },
    { key: 'health_access_gap', label: 'Health gap' },
    { key: 'education_access_gap', label: 'Education gap' },
    { key: 'road_accessibility_gap', label: 'Road gap' },
    { key: 'digital_connectivity_gap', label: 'Connectivity gap' },
    { key: 'safety_and_drrm_gap', label: 'Safety gap' },
    { key: 'geographic_isolation', label: 'Isolation' },
];

const LEVEL_GROUPS = [
    { key: 'high', label: 'Underserved · high population' },
    { key: 'moderate', label: 'No facilities recorded' },
    { key: 'covered', label: 'Facilities recorded' },
];

/** Where people and priority meet: scatter + what drives the top scores (two cards for the parent grid). */
export function BarangayPriorityGraphs({ barangays, weights }: { barangays: BarangayItem[]; weights?: Record<string, unknown> }) {
    const scored = barangays.filter((row) => row.priority_score != null && row.population != null);
    if (!scored.length) return null;
    const top = [...scored].sort((a, b) => b.priority_score! - a.priority_score!).slice(0, 8);
    return (
        <>
            <ChartCard
                title="Population vs priority score"
                hint="Each dot is a barangay. Top-right = many people and high priority."
                explain={<ScatterExplain />}
            >
                <Scatter
                    groups={LEVEL_GROUPS}
                    xLabel="Population"
                    yLabel="Priority score"
                    points={scored
                        .filter((row) => LEVEL_GROUPS.some((group) => group.key === row.priority_level))
                        .map((row) => ({ id: row.psg_code, label: row.name, x: row.population!, y: row.priority_score!, group: row.priority_level }))}
                />
            </ChartCard>
            <ChartCard
                title="What drives the top 8 scores"
                hint="Points each factor adds to the priority score (0–100)."
                explain={<DriversExplain weights={weights} />}
            >
                <StackedBars
                    keys={PRIORITY_COMPONENTS}
                    rows={top.map((row) => ({
                        label: row.name,
                        sublabel: row.population != null ? `${row.population.toLocaleString('en-PH')} people` : undefined,
                        total: row.priority_score ?? null,
                        values: row.score_components?.normalized_components ?? {},
                    }))}
                />
            </ChartCard>
        </>
    );
}

/** Need → technology links (full width), and how each technology's relevance score is built (cards for the parent grid). */
export function TechnologyFitGraphs({ report }: { report: AssessmentReportInput }) {
    const options = reportTechnologyOptions(report).slice(0, 8);
    const optionIds = new Set(options.map((row) => `${row.source_catalog}:${row.intervention_id}`));
    const gaps = reportMatches(report)
        .map((gap) => ({
            gap,
            links: (Array.isArray(gap.matched_interventions) ? gap.matched_interventions : [])
                .map(reportRecord)
                .filter((item) => optionIds.has(`${item.source_catalog}:${item.intervention_id}`)),
        }))
        .filter((row) => row.links.length > 0)
        .sort((a, b) => (reportNumber(b.gap.severity) ?? 0) - (reportNumber(a.gap.severity) ?? 0))
        .slice(0, 7);
    if (!options.length) return null;
    const usedTech = new Set(gaps.flatMap((row) => row.links.map((item) => `${item.source_catalog}:${item.intervention_id}`)));
    return (
        <>
            <ChartCard
                title="Which technology answers which need"
                hint="Left: detected needs (by severity). Right: matched DOST technologies."
                explain={<FlowExplain />}
                className="lg:col-span-2"
            >
                <BipartiteFlow
                    leftTitle="Need"
                    rightTitle="DOST technology"
                    left={gaps.map(({ gap }) => ({
                        id: String(gap.gap_id),
                        label: reportText(gap.gap_name),
                        badge: `Severity ${reportNumber(gap.severity) ?? '—'}/100`,
                    }))}
                    right={options
                        .filter((row) => usedTech.has(`${row.source_catalog}:${row.intervention_id}`))
                        .map((row) => ({
                            id: `${row.source_catalog}:${row.intervention_id}`,
                            label: reportText(row.name),
                            badge: reportText(row.agency, 'Agency not recorded'),
                        }))}
                    links={gaps.flatMap(({ gap, links }) =>
                        links.map((item) => ({
                            from: String(gap.gap_id),
                            to: `${item.source_catalog}:${item.intervention_id}`,
                            weight: reportNumber(item.relevance_score) ?? 0,
                        })),
                    )}
                />
            </ChartCard>
            <ChartCard
                title="How each relevance score is built"
                hint="Points per rule. Needs ≥ 40 to be listed."
                explain={<RelevanceExplanation options={options} />}
            >
                <StackedBars
                    keys={MATCH_COMPONENTS}
                    threshold={40}
                    thresholdLabel="40-point listing threshold"
                    rows={options.slice(0, 6).map((row) => ({
                        label: reportText(row.name),
                        sublabel: reportText(row.agency, ''),
                        total: reportNumber(row.relevance_score),
                        values: Object.fromEntries(
                            MATCH_COMPONENTS.map((key) => [key.key, reportNumber(reportRecord(row.score_breakdown)[key.key])]),
                        ),
                    }))}
                />
            </ChartCard>
        </>
    );
}
