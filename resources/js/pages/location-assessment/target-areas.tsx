import { ChartCard, StackedBars } from '@/components/assessment-method/charts';
import { ExplainBlocks, Formula } from '@/components/assessment-method/explain';
import { explainNumber } from '@/lib/assessment-explanations';
import { buildTargetAreas } from '@/lib/assessment-targets';
import { Link } from '@inertiajs/react';
import { ArrowRight, Crosshair, Info, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { AssessmentPageProps } from './types';

/** "Where to deploy first": top 5/10 cities (province) or barangays (city), with reasons and suggested technologies. */
export function TargetAreasSection({ report }: { report: AssessmentPageProps }) {
    const [limit, setLimit] = useState<5 | 10>(5);
    const set = useMemo(() => buildTargetAreas(report, 10), [report]);
    if (!set || !set.areas.length) return null;
    const areas = set.areas.slice(0, limit);
    const unit = set.level === 'municipality' ? 'cities / municipalities' : 'barangays';
    const map = report.assessmentLayout ?? 'user';

    return (
        <section
            aria-label="Target areas"
            className="viz-palette min-w-0 space-y-4 rounded-2xl border border-sky-200 bg-white p-4 sm:p-5 dark:border-slate-800 dark:bg-slate-950"
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="flex items-center gap-1.5 text-[10px] font-semibold tracking-wider text-[#087dcc] uppercase">
                        <Crosshair className="size-3.5" aria-hidden="true" /> Where to deploy first
                    </p>
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100">
                        Top {limit} target {unit} in {set.parent}
                    </h2>
                    <p className="text-muted-foreground text-xs">
                        Ranked from {set.total} {unit} by priority score, with the DOST technology that best fits each one's top gap.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <div role="group" aria-label="How many target areas" className="flex rounded-lg bg-slate-100 p-0.5 text-xs dark:bg-slate-800">
                        {([5, 10] as const).map((value) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={limit === value}
                                onClick={() => setLimit(value)}
                                className={`rounded-md px-3 py-1.5 font-medium ${limit === value ? 'bg-white text-[#087dcc] shadow-xs dark:bg-slate-900' : 'text-slate-500'}`}
                            >
                                Top {value}
                            </button>
                        ))}
                    </div>
                </div>
            </header>

            <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                <ChartCard
                    plain
                    title="Priority score breakdown"
                    hint="Points each factor adds (0–100)."
                    explain={<TargetRankingExplanation set={set} />}
                    className="rounded-xl bg-slate-50/70 p-3 dark:bg-slate-900"
                >
                    <StackedBars
                        keys={set.factors}
                        rows={areas.map((area) => ({
                            label: `${area.rank}. ${area.name}`,
                            sublabel: area.population != null ? `${area.population.toLocaleString('en-PH')} people` : undefined,
                            total: area.score,
                            values: area.components,
                        }))}
                    />
                </ChartCard>

                <ol className="min-w-0 space-y-2">
                    {areas.map((area) => (
                        <li key={area.id} className="rounded-xl border border-slate-100 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-900">
                            <div className="flex items-start gap-3">
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-[#087dcc] text-xs font-semibold text-white">
                                    {area.rank}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <p className="text-sm font-semibold text-slate-900 dark:text-slate-100">{area.name}</p>
                                        <span className="font-mono text-xs font-semibold text-slate-700 tabular-nums dark:text-slate-200">
                                            {explainNumber(area.score)}/100
                                        </span>
                                    </div>
                                    <ul className="mt-1 flex flex-wrap gap-1.5">
                                        {area.drivers.map((driver) => (
                                            <li
                                                key={driver.factor}
                                                className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-100 dark:ring-amber-800"
                                                title={`${driver.label}: +${explainNumber(driver.points)} points`}
                                            >
                                                {driver.text}
                                            </li>
                                        ))}
                                    </ul>
                                    <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                                        <Sparkles className="size-3.5 text-[#087dcc]" aria-hidden="true" />
                                        {area.technologies.length ? (
                                            area.technologies.map((tech) => (
                                                <span
                                                    key={tech.ref}
                                                    className="rounded-full bg-white px-2 py-0.5 font-medium text-[#087dcc] ring-1 ring-sky-200 dark:bg-slate-950 dark:ring-slate-700"
                                                    title={`${tech.agency} · relevance ${explainNumber(tech.relevance)}/100 · need: ${tech.need}`}
                                                >
                                                    {tech.name}
                                                    {tech.general ? ' (overall best match)' : ''}
                                                </span>
                                            ))
                                        ) : (
                                            <span className="text-muted-foreground">No catalog match yet — validate with DOST</span>
                                        )}
                                    </div>
                                    {set.level === 'municipality' && typeof area.id === 'number' && (
                                        <Link
                                            href={route('location.assess', { level: 'municipality', location: area.id, focus: 'general', map })}
                                            className="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-[#087dcc] hover:underline"
                                        >
                                            Assess {area.name} <ArrowRight className="size-3" aria-hidden="true" />
                                        </Link>
                                    )}
                                </div>
                            </div>
                        </li>
                    ))}
                </ol>
            </div>
            {set.sparse.length > 0 && (
                <p className="text-muted-foreground flex items-start gap-1.5 text-[11px]">
                    <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    Not scored (recorded in fewer than half of the LGUs):{' '}
                    {set.sparse.map((key) => key.replaceAll('_', ' ').replace(' gap', '')).join(', ')}.
                </p>
            )}
        </section>
    );
}

/** Explain popup body for the target-area ranking chart (also reused by the action plan modal). */
export function TargetRankingExplanation({ set }: { set: NonNullable<ReturnType<typeof buildTargetAreas>> }) {
    const top = set.areas[0];
    const unit = set.level === 'municipality' ? 'city / municipality' : 'barangay';
    return (
        <ExplainBlocks
            what={`The top ${set.areas.length} ${unit === 'barangay' ? 'barangays' : 'cities / municipalities'} in ${set.parent}, and how many points each factor gives them.`}
            how={
                <>
                    <Formula>population need = weight × (0.7 × pop ÷ largest + 0.3 × density ÷ densest)</Formula>
                    <Formula>sector gap = weight × (1 − local rate ÷ {set.level === 'municipality' ? 'province median' : 'reference'})</Formula>
                    <Formula>score = sum of all points (0–100)</Formula>
                </>
            }
            read={
                <ul className="list-disc space-y-1 pl-4">
                    <li>Highest score = check first.</li>
                    <li>The longest color is the main reason the {unit} ranks high.</li>
                    <li>The suggested technology is the best catalog match for that reason.</li>
                </ul>
            }
        >
            {Object.keys(set.weights).length > 0 && (
                <ChartCard title="Maximum points per factor" hint="Weights after removing factors without data.">
                    <StackedBars
                        keys={set.factors}
                        rows={[
                            { label: 'All factors', total: Object.values(set.weights).reduce((sum, value) => sum + value, 0), values: set.weights },
                        ]}
                    />
                </ChartCard>
            )}
            {top && (
                <ChartCard title={`Example: #1 ${top.name}`} hint={`${explainNumber(top.score)}/100`}>
                    <StackedBars keys={set.factors} rows={[{ label: top.name, total: top.score, values: top.components }]} />
                </ChartCard>
            )}
            {set.method && <p className="text-muted-foreground text-xs">{set.method}</p>}
        </ExplainBlocks>
    );
}
