import { explainNumber } from '@/lib/assessment-explanations';
import type { LayerOverview, OverviewChart } from '@/lib/assessment-report';
import { BarChart3 } from 'lucide-react';
import { useId } from 'react';

function OverviewBars({ chart }: { chart: OverviewChart }) {
    const id = useId();
    const maximum = chart.maximum ?? Math.max(1, ...chart.rows.map((row) => row.value ?? 0));
    return (
        <figure aria-labelledby={id} className="min-w-0 rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
            <figcaption id={id}>
                <h3 className="text-sm font-semibold text-slate-800 dark:text-slate-100">{chart.title}</h3>
                <p className="text-muted-foreground mt-1 text-xs leading-relaxed">{chart.description}</p>
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

export function AssessmentLayerOverview({ overview }: { overview: LayerOverview }) {
    return (
        <section
            aria-label={`${overview.title} overview`}
            className="min-w-0 rounded-2xl border border-sky-200 bg-linear-to-br from-sky-50 to-white p-4 sm:p-5 dark:border-slate-800 dark:from-slate-900 dark:to-slate-950"
        >
            <div className="mb-4 flex items-start gap-3">
                <span className="rounded-xl bg-[#087dcc] p-2 text-white">
                    <BarChart3 className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0">
                    <p className="text-[10px] font-semibold tracking-wider text-[#087dcc] uppercase">Layer overview</p>
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100">{overview.title}</h2>
                    <p className="text-muted-foreground mt-1 max-w-4xl text-sm leading-relaxed">{overview.summary}</p>
                </div>
            </div>
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                {overview.metrics.map((metric) => (
                    <div
                        key={metric.label}
                        className="min-w-0 rounded-xl border border-sky-100 bg-white/90 p-3 dark:border-slate-800 dark:bg-slate-950"
                    >
                        <p className="text-muted-foreground text-xs">{metric.label}</p>
                        <p className="mt-1 text-xl font-semibold break-words text-[#087dcc] sm:text-2xl">{metric.value}</p>
                        <p className="text-muted-foreground mt-1 text-[11px]">{metric.note}</p>
                    </div>
                ))}
            </div>
            <div className="grid items-start gap-4 xl:grid-cols-2">
                {overview.charts.map((chart) => (
                    <OverviewBars key={chart.title} chart={chart} />
                ))}
            </div>
        </section>
    );
}

export function AssessmentLayerExplanation({ overview }: { overview: LayerOverview }) {
    return (
        <section
            aria-label={`${overview.title} explanation`}
            className="min-w-0 rounded-xl border border-sky-200 bg-white p-4 sm:p-5 dark:border-slate-800 dark:bg-slate-950"
        >
            <p className="text-sm text-slate-700 dark:text-slate-200">{overview.interpretation}</p>
            <p className="text-muted-foreground mt-2 text-xs">
                <strong>How this was calculated: </strong>
                {methodSummaries[overview.key]} Use Source &amp; basis for recorded inputs and worked formulas.
            </p>
        </section>
    );
}

const methodSummaries = {
    profile_assets: 'Counts use records linked to this area; growth compares matching census years.',
    sectors_roads: 'Local count ÷ population × rate unit; gap = (local − national reference) ÷ reference × 100.',
    gaps_barangays: 'Severity = capped absolute gap × confidence weight; barangay priority combines its active component scores.',
    dost_catalog: 'Catalog records are retrieved by source fields; local projects use location-name screening.',
    matched_interventions: 'Relevance adds sector, keyword, asset and status points; each option shows its best returned match.',
};
