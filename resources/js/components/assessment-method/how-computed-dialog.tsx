import { Button } from '@/components/ui/button';
import { explainNumber, indicatorMethod, workedRate } from '@/lib/assessment-explanations';
import {
    reportCatalog,
    reportGaps,
    reportMatches,
    reportNumber,
    reportRecord,
    reportTechnologyOptions,
    reportText,
    type AssessmentLayer,
} from '@/lib/assessment-report';
import type { AssessmentPageProps } from '@/pages/location-assessment/types';
import { Workflow } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { ChartCard, CompareBars, ComputeStep, FlowSteps, SeverityScale, StackedBars, Waterfall } from './charts';
import { MATCH_COMPONENTS, PRIORITY_COMPONENTS } from './layer-graphs';
import WideModal from './wide-modal';

function SectionLabel({ children }: { children: ReactNode }) {
    return <p className="mb-3 text-xs font-semibold tracking-wide text-slate-500 uppercase">{children}</p>;
}

const LAYER_INDEX: Record<AssessmentLayer, number> = {
    profile_assets: 0,
    sectors_roads: 1,
    gaps_barangays: 2,
    dost_catalog: 3,
    matched_interventions: 4,
};
const signed = (value: number | null) => (value == null ? 'Unavailable' : `${value > 0 ? '+' : ''}${explainNumber(value)}%`);

/** End-to-end pipeline with this location's live counts. */
export function AssessmentPipeline({ report, active }: { report: AssessmentPageProps; active?: AssessmentLayer }) {
    const bundle = reportRecord(report.functionToolsBundle);
    const datasets = Object.keys(reportRecord(reportRecord(bundle.dataset_audit).datasets)).length;
    const measured = report.results.filter((row) => row.status !== 'no_data' && row.value !== null).length;
    const compared = report.results.filter((row) => row.status === 'available' && row.value !== null && row.benchmark !== null).length;
    const gaps = reportGaps(report).length;
    const ranked = (report.barangayAssessment?.barangays ?? []).filter((row) => row.priority_score != null).length;
    const options = reportTechnologyOptions(report).length;
    return (
        <FlowSteps
            activeIndex={active ? LAYER_INDEX[active] : undefined}
            steps={[
                {
                    title: 'A · Area & assets',
                    value: `${datasets || '—'} datasets`,
                    caption: report.location.population ? `${report.location.population.toLocaleString('en-PH')} people` : undefined,
                },
                { title: 'B · Sector needs', value: `${compared} compared`, caption: `${measured} measured` },
                { title: 'C · Gaps & priority', value: `${gaps} gaps`, caption: ranked ? `${ranked} barangays ranked` : undefined },
                { title: 'D · DOST catalog', value: `${reportCatalog(report).length} entries`, caption: 'technologies & programs' },
                { title: 'E · Technology fit', value: `${options} options`, caption: 'matched to needs' },
                { title: 'Action plan', value: '5 outputs', caption: 'draft for validation' },
            ]}
        />
    );
}

function Picker<T>({
    label,
    items,
    value,
    onChange,
    name,
}: {
    label: string;
    items: T[];
    value: number;
    onChange: (index: number) => void;
    name: (item: T) => string;
}) {
    if (items.length < 2) return null;
    return (
        <label className="flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
            {label}
            <select
                value={value}
                onChange={(event) => onChange(Number(event.target.value))}
                className="bg-background max-w-full rounded-md border px-2 py-1"
            >
                {items.map((item, index) => (
                    <option key={index} value={index}>
                        {name(item)}
                    </option>
                ))}
            </select>
        </label>
    );
}

function Steps({ children }: { children: ReactNode }) {
    return <ol className="rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">{children}</ol>;
}

function AreaMethod({ report }: { report: AssessmentPageProps }) {
    const { population, area_km2: area, population_statistics: stats } = report.location;
    const density = population != null && area ? population / area : null;
    return (
        <Steps>
            <ComputeStep
                index={1}
                title="Find the location"
                worked={`${report.location.name}${report.location.psg_code ? ` · PSGC ${report.location.psg_code}` : ''}`}
            />
            <ComputeStep
                index={2}
                title="Read its population"
                worked={
                    population != null ? `${population.toLocaleString('en-PH')} people (${report.populationYear ?? 'active year'})` : 'Not recorded'
                }
            />
            <ComputeStep
                index={3}
                title="Population density"
                formula="population ÷ land area (km²)"
                worked={density != null ? `${population!.toLocaleString('en-PH')} ÷ ${explainNumber(area)} ≈ ${explainNumber(density)} /km²` : null}
            />
            <ComputeStep
                index={4}
                title="Population growth"
                formula="(current − baseline) ÷ baseline × 100"
                worked={
                    stats?.population_baseline != null && stats.population_current != null
                        ? `(${stats.population_current.toLocaleString('en-PH')} − ${stats.population_baseline.toLocaleString('en-PH')}) ÷ ${stats.population_baseline.toLocaleString('en-PH')} × 100 ≈ ${signed(stats.growth_rate_pct)}`
                        : null
                }
                note={stats?.baseline_year ? `${stats.baseline_year} → ${stats.active_year ?? report.populationYear}` : undefined}
            />
            <ComputeStep index={5} title="Count local assets" note="Facilities, labs and cell sites linked to this area. No record ≠ no service." />
        </Steps>
    );
}

function SectorMethod({ report }: { report: AssessmentPageProps }) {
    const rows = report.results.filter((row) => row.value !== null);
    const first = Math.max(
        0,
        rows.findIndex((row) => row.is_shortfall),
    );
    const [index, setIndex] = useState(first);
    const row = rows[index];
    if (!row) return <p className="text-muted-foreground text-sm">No measured indicator for this selection.</p>;
    const method = indicatorMethod(row.key);
    return (
        <div className="space-y-3">
            <Picker label="Example indicator" items={rows} value={index} onChange={setIndex} name={(item) => item.label} />
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                <Steps>
                    <ComputeStep
                        index={1}
                        title="Local rate"
                        formula={method.formula}
                        worked={workedRate(row.calculation, row.value) ?? explainNumber(row.value)}
                    />
                    <ComputeStep
                        index={2}
                        title="National reference"
                        formula="same formula over all recorded LGUs"
                        worked={explainNumber(row.benchmark)}
                    />
                    <ComputeStep
                        index={3}
                        title="Gap %"
                        formula="(local − reference) ÷ reference × 100"
                        worked={
                            row.benchmark
                                ? `(${explainNumber(row.value)} − ${explainNumber(row.benchmark)}) ÷ ${explainNumber(row.benchmark)} × 100 ≈ ${signed(row.gap)}`
                                : 'No reference, so no gap'
                        }
                    />
                    <ComputeStep
                        index={4}
                        title="Result"
                        worked={row.is_shortfall ? 'Flagged as a possible need' : row.benchmark ? 'Not flagged' : 'Measured only'}
                        note={row.confidence === 'low' ? 'Low confidence: treat as provisional.' : undefined}
                    />
                </Steps>
                <ChartCard title={row.label} hint="Same scale for both bars.">
                    <CompareBars local={row.value} reference={row.benchmark} unit={row.unit} flagged={row.is_shortfall} />
                </ChartCard>
            </div>
        </div>
    );
}

function GapMethod({ report }: { report: AssessmentPageProps }) {
    const gaps = useMemo(
        () => [...reportGaps(report)].sort((a, b) => (reportNumber(b.severity_score) ?? 0) - (reportNumber(a.severity_score) ?? 0)),
        [report],
    );
    const [index, setIndex] = useState(0);
    const gap = gaps[index];
    const top = [...(report.barangayAssessment?.barangays ?? [])]
        .filter((row) => row.priority_score != null)
        .sort((a, b) => b.priority_score! - a.priority_score!)[0];
    const weights = reportRecord(reportRecord(reportRecord(report.functionToolsBundle).priority_scores).active_weights);
    return (
        <div className="space-y-4">
            {gap ? (
                <>
                    <Picker label="Example gap" items={gaps} value={index} onChange={setIndex} name={(item) => reportText(item.label)} />
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                        <Steps>
                            <ComputeStep index={1} title="Gap %" worked={signed(reportNumber(gap.percentage_gap))} />
                            <ComputeStep
                                index={2}
                                title="Size of the gap (max 100)"
                                formula="min(100, |gap %|)"
                                worked={explainNumber(Math.min(100, Math.abs(reportNumber(gap.percentage_gap) ?? 0)))}
                            />
                            <ComputeStep
                                index={3}
                                title="Confidence weight"
                                formula="× 1 (high) or × 0.65 (provisional)"
                                worked={`× ${gap.confidence === 'high' ? 1 : 0.65}`}
                            />
                            <ComputeStep
                                index={4}
                                title="Severity"
                                worked={`${explainNumber(reportNumber(gap.severity_score))}/100 · ${reportText(gap.severity_level)}`}
                            />
                        </Steps>
                        <ChartCard title="Where it lands on the severity scale">
                            <SeverityScale value={reportNumber(gap.severity_score)} />
                        </ChartCard>
                    </div>
                </>
            ) : (
                <p className="text-muted-foreground text-sm">No computed gap for this selection.</p>
            )}
            {top && (
                <ChartCard title={`Barangay priority example: ${top.name}`} hint="Each factor's points are added. Weights below.">
                    <StackedBars
                        keys={PRIORITY_COMPONENTS}
                        rows={[{ label: top.name, total: top.priority_score ?? null, values: top.score_components?.normalized_components ?? {} }]}
                    />
                    {Object.keys(weights).length > 0 && (
                        <p className="text-muted-foreground mt-3 text-[11px]">
                            Max points:{' '}
                            {Object.entries(weights)
                                .filter(([, value]) => (reportNumber(value) ?? 0) > 0)
                                .map(([key, value]) => `${key.replaceAll('_', ' ')} ${explainNumber(reportNumber(value))}`)
                                .join(' · ')}
                        </p>
                    )}
                </ChartCard>
            )}
        </div>
    );
}

function CatalogMethod({ report }: { report: AssessmentPageProps }) {
    const projects = reportNumber(reportRecord(reportRecord(report.functionToolsBundle).local_dost_projects).total_matched_projects);
    return (
        <Steps>
            <ComputeStep index={1} title="Load the DOST catalog" worked={`${reportCatalog(report).length} technology / program entries`} />
            <ComputeStep
                index={2}
                title="Find past projects here"
                formula="project location text contains this city or province"
                worked={`${projects ?? 0} project records`}
            />
            <ComputeStep
                index={3}
                title="Clean up status"
                note="“comleted” and similar spellings are read as completed. Completed ≠ available now."
            />
            <ComputeStep index={4} title="Send to matching" note="Layer E scores each entry against the detected needs." />
        </Steps>
    );
}

function MatchMethod({ report }: { report: AssessmentPageProps }) {
    const options = reportTechnologyOptions(report);
    const [index, setIndex] = useState(0);
    const option = options[index];
    const suitability = reportRecord(reportRecord(report.layerEResult).suitability_assessment);
    const dims = reportRecord(suitability.dimension_scores);
    const suitWeights: Record<string, number> = {
        need_relevance: 0.3,
        beneficiary_reach: 0.2,
        resource_readiness: 0.15,
        infrastructure_readiness: 0.2,
        institutional_readiness: 0.15,
    };
    const matchesTotal = reportMatches(report).length;
    if (!option) return <p className="text-muted-foreground text-sm">No technology matched the {matchesTotal} detected needs.</p>;
    const points = reportRecord(option.score_breakdown);
    return (
        <div className="space-y-4">
            <Picker label="Example technology" items={options} value={index} onChange={setIndex} name={(item) => reportText(item.name)} />
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                <Steps>
                    <ComputeStep index={1} title="Take a detected need" worked={reportText(option.gap_name)} />
                    <ComputeStep index={2} title="Score the catalog entry" formula="start + sector + keywords + local assets + active status" />
                    <ComputeStep
                        index={3}
                        title="Keep if ≥ 40 points"
                        worked={`${explainNumber(reportNumber(option.relevance_score))}/100 → listed`}
                    />
                    <ComputeStep index={4} title="Rank and shortlist" note="Highest relevance first; up to 4 per need. Severity is not added." />
                </Steps>
                <ChartCard title={reportText(option.name)} hint="Points added by each rule.">
                    <Waterfall
                        steps={MATCH_COMPONENTS.map((key) => ({ label: key.label, value: reportNumber(points[key.key]) ?? 0 }))}
                        finalValue={reportNumber(option.relevance_score)}
                        finalLabel="Relevance"
                        threshold={40}
                        thresholdLabel="Listed when ≥ 40"
                    />
                </ChartCard>
            </div>
            {Object.keys(dims).length > 0 && (
                <ChartCard
                    title={`Suitability: ${reportText(reportRecord(suitability.intervention).name, 'top candidate')}`}
                    hint="Each dimension score × its weight, added up."
                >
                    <StackedBars
                        keys={Object.keys(suitWeights).map((key) => ({ key, label: `${key.replaceAll('_', ' ')} ×${suitWeights[key]}` }))}
                        rows={[
                            {
                                label: 'Suitability',
                                total: reportNumber(suitability.suitability_score),
                                values: Object.fromEntries(
                                    Object.entries(suitWeights).map(([key, weight]) => [key, (reportNumber(dims[key]) ?? 0) * weight]),
                                ),
                            },
                        ]}
                    />
                </ChartCard>
            )}
        </div>
    );
}

const TITLES: Record<AssessmentLayer, string> = {
    profile_assets: 'Area & assets',
    sectors_roads: 'Sector needs',
    gaps_barangays: 'Gaps & priority',
    dost_catalog: 'DOST catalog',
    matched_interventions: 'Technology & lab fit',
};

export default function HowComputedDialog({
    layer,
    report,
    variant = 'button',
}: {
    layer: AssessmentLayer;
    report: AssessmentPageProps;
    variant?: 'button' | 'link';
}) {
    return (
        <WideModal
            size="lg"
            title={`How we got this result · ${TITLES[layer]}`}
            description="Every number comes from fixed formulas on the recorded data. AI does not change scores."
            trigger={
                variant === 'button' ? (
                    <Button variant="outline" size="sm" className="shrink-0 border-[#087dcc]/30 text-[#087dcc] hover:bg-blue-50">
                        <Workflow className="size-4" aria-hidden="true" /> How was this computed?
                    </Button>
                ) : (
                    <Button variant="link" size="sm" className="h-auto p-0 text-xs text-[#087dcc]">
                        <Workflow className="size-3.5" aria-hidden="true" /> See the computation
                    </Button>
                )
            }
        >
            <div className="min-w-0 space-y-6">
                <section>
                    <SectionLabel>The full pipeline</SectionLabel>
                    <AssessmentPipeline report={report} active={layer} />
                </section>
                <section>
                    <SectionLabel>This step, with {report.location.name}'s numbers</SectionLabel>
                    {layer === 'profile_assets' && <AreaMethod report={report} />}
                    {layer === 'sectors_roads' && <SectorMethod report={report} />}
                    {layer === 'gaps_barangays' && <GapMethod report={report} />}
                    {layer === 'dost_catalog' && <CatalogMethod report={report} />}
                    {layer === 'matched_interventions' && <MatchMethod report={report} />}
                </section>
                <p className="text-muted-foreground border-t border-slate-100 pt-4 text-[11px] dark:border-slate-800">
                    References are averages of the recorded data, not official standards. Missing data is never counted as zero.
                </p>
            </div>
        </WideModal>
    );
}
