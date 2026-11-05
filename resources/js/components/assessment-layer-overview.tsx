import { ExplainButton } from '@/components/assessment-method/charts';
import { ExplainBlocks, OVERVIEW_EXPLAIN } from '@/components/assessment-method/explain';
import { explainNumber } from '@/lib/assessment-explanations';
import type { LayerOverview, OverviewChart } from '@/lib/assessment-report';
import { Lightbulb, type LucideIcon } from 'lucide-react';
import { useId, type ReactNode } from 'react';

/** One overview bar chart as a card; `className` lets a grid make it span both columns. */
export function OverviewBars({ chart, className = '' }: { chart: OverviewChart; className?: string }) {
    const id = useId();
    const maximum = chart.maximum ?? Math.max(1, ...chart.rows.map((row) => row.value ?? 0));
    const explain = OVERVIEW_EXPLAIN[chart.title];
    return (
        <figure
            aria-labelledby={id}
            className={`min-w-0 rounded-2xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950 ${className}`}
        >
            <figcaption id={id} className="flex items-start gap-2">
                <span className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold text-slate-800 dark:text-slate-100">{chart.title}</h3>
                    <p className="text-muted-foreground mt-1 text-[11px] leading-relaxed">{chart.description}</p>
                </span>
                {explain && (
                    <ExplainButton title={chart.title}>
                        <ExplainBlocks what={explain.what} how={explain.how} read={explain.read} />
                    </ExplainButton>
                )}
            </figcaption>

            <div className="mt-4 space-y-3">
                {chart.rows.map((row, index) => (
                    <div key={`${row.label}:${index}`}>
                        <div className="mb-1 flex items-baseline justify-between gap-3 text-xs">
                            <span className="min-w-0 break-words">{row.label}</span>
                            <span className="shrink-0 font-medium tabular-nums">
                                {row.value == null ? 'Unavailable' : (row.formatted ?? explainNumber(row.value))}
                            </span>
                        </div>
                        <div aria-hidden="true" className="relative h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            {row.value != null && (
                                <span
                                    className="absolute inset-y-0 left-0 rounded-full"
                                    style={{
                                        width: `${Math.min(100, (Math.max(0, row.value) / maximum) * 100)}%`,
                                        backgroundColor: row.color ?? '#087dcc',
                                    }}
                                />
                            )}
                            {chart.reference != null && (
                                <span
                                    className="absolute inset-y-0 w-0.5 bg-slate-700 dark:bg-white"
                                    style={{ left: `${(chart.reference / maximum) * 100}%` }}
                                />
                            )}
                        </div>
                    </div>
                ))}
                {chart.rows.length === 0 && <p className="text-muted-foreground text-xs">No recorded figures are available for this chart.</p>}
            </div>
            {chart.reference != null && (
                <p className="text-muted-foreground mt-3 text-[11px]">The vertical mark is {chart.reference}% of the national reference.</p>
            )}
        </figure>
    );
}

/** Full-width step header: step badge, title, one-line summary, key numbers, takeaway and the method button. */
export function StepHeader({
    overview,
    step,
    label,
    icon: Icon,
    action,
}: {
    overview: LayerOverview;
    step: string;
    label: string;
    icon: LucideIcon;
    action?: ReactNode;
}) {
    return (
        <section
            aria-label={`${label} summary`}
            className="min-w-0 rounded-2xl border border-sky-200 bg-linear-to-br from-sky-50 via-white to-white p-4 sm:p-5 dark:border-slate-800 dark:from-slate-900 dark:via-slate-950 dark:to-slate-950"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#087dcc] text-white shadow-sm">
                        <Icon className="size-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-[10px] font-semibold tracking-wider text-[#087dcc] uppercase">Step {step}</p>
                        <h2 className="text-lg leading-snug font-semibold text-slate-900 dark:text-slate-100">{label}</h2>
                        <p className="text-muted-foreground text-sm">{overview.summary}</p>
                    </div>
                </div>
                {action}
            </div>
            {overview.metrics.length > 0 && (
                <dl className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-[repeat(auto-fit,minmax(10rem,1fr))]">
                    {overview.metrics.map((metric) => (
                        <div
                            key={metric.label}
                            className="min-w-0 rounded-xl border border-sky-100 bg-white px-3 py-2.5 dark:border-slate-800 dark:bg-slate-950"
                        >
                            <dt className="truncate text-[11px] font-medium text-slate-600 dark:text-slate-300">{metric.label}</dt>
                            <dd className="mt-0.5 text-xl font-semibold break-words text-[#087dcc] tabular-nums">{metric.value}</dd>
                            {metric.note && (
                                <p className="text-muted-foreground truncate text-[10px]" title={metric.note}>
                                    {metric.note}
                                </p>
                            )}
                        </div>
                    ))}
                </dl>
            )}
            <p className="mt-3 flex gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-950 dark:bg-amber-950/40 dark:text-amber-100">
                <Lightbulb className="mt-0.5 size-3.5 shrink-0 text-amber-500" aria-hidden="true" />
                {overview.interpretation}
            </p>
        </section>
    );
}
