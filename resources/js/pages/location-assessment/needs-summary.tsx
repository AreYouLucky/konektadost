import { explainNumber } from '@/lib/assessment-explanations';
import { reportGaps, reportMatches, reportNumber, reportRecord, reportText, type AssessmentLayer } from '@/lib/assessment-report';
import { cn } from '@/lib/utils';
import { ArrowRight, ListChecks, Sparkles, Users } from 'lucide-react';
import { useMemo } from 'react';
import type { AssessmentPageProps } from './types';

const SEVERITY: Record<string, { label: string; chip: string; bar: string }> = {
    critical: { label: 'Critical', chip: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950 dark:text-red-200', bar: 'bg-red-500' },
    high: { label: 'High', chip: 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-950 dark:text-orange-200', bar: 'bg-orange-500' },
    moderate: { label: 'Moderate', chip: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950 dark:text-amber-200', bar: 'bg-amber-400' },
    low: { label: 'Low', chip: 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300', bar: 'bg-slate-400' },
};
const TECHNOLOGY_CATALOGS = ['technologies', 'dost_techs'];
const SHOWN = 5;

/**
 * "What this area needs": the largest detected gaps (C1), each with its evidence and the
 * best catalog match for that same gap (E1). Unmeasured indicators are listed separately so
 * missing data never reads as "no need".
 */
export function NeedsSummary({ report, onOpen }: { report: AssessmentPageProps; onOpen: (layer: AssessmentLayer) => void }) {
    const { needs, total, counts, unassessed } = useMemo(() => {
        const gaps = [...reportGaps(report)].sort((a, b) => (reportNumber(b.severity_score) ?? -1) - (reportNumber(a.severity_score) ?? -1));
        const bestMatch = new Map<string, Record<string, unknown>>();
        for (const gap of reportMatches(report)) {
            const top = (Array.isArray(gap.matched_interventions) ? gap.matched_interventions.map(reportRecord) : [])
                .filter((row) => TECHNOLOGY_CATALOGS.includes(String(row.source_catalog)))
                .sort((a, b) => (reportNumber(b.relevance_score) ?? -1) - (reportNumber(a.relevance_score) ?? -1))[0];
            if (top) bestMatch.set(String(gap.gap_id), top);
        }
        const detected = reportRecord(reportRecord(report.functionToolsBundle).detected_gaps);
        return {
            needs: gaps.slice(0, SHOWN).map((gap) => ({ gap, match: bestMatch.get(String(gap.indicator_key)) })),
            total: gaps.length,
            counts: (['critical', 'high', 'moderate'] as const).map((level) => ({
                level,
                value: reportNumber(detected[`${level}_gaps_count`]) ?? 0,
            })),
            unassessed: report.results.filter((row) => row.status !== 'available').map((row) => row.label),
        };
    }, [report]);

    return (
        <section
            aria-labelledby="needs-summary-title"
            className="min-w-0 rounded-2xl border border-sky-100 bg-white p-4 shadow-xs sm:p-5 dark:border-slate-800 dark:bg-slate-900"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="needs-summary-title" className="flex items-center gap-2 text-lg font-semibold text-slate-900 dark:text-slate-50">
                        <ListChecks className="size-5 text-[#087dcc]" aria-hidden="true" /> What {report.location.name} needs
                    </h2>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Largest gaps against national reference rates, with the catalog technology that fits each one.
                    </p>
                </div>
                {total > 0 && (
                    <ul className="flex flex-wrap gap-1.5" aria-label="Gaps by severity">
                        {counts
                            .filter((item) => item.value > 0)
                            .map((item) => (
                                <li
                                    key={item.level}
                                    className={cn('rounded-full px-2.5 py-1 text-xs font-semibold ring-1', SEVERITY[item.level].chip)}
                                >
                                    {item.value} {SEVERITY[item.level].label.toLowerCase()}
                                </li>
                            ))}
                    </ul>
                )}
            </div>

            {needs.length === 0 ? (
                <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    No gap was detected among the measured indicators. This is not proof that nothing is needed: check the indicators that could not
                    be assessed below.
                </p>
            ) : (
                <ol className="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                    {needs.map(({ gap, match }, index) => {
                        const level = SEVERITY[String(gap.severity_level)] ?? SEVERITY.low;
                        const severity = reportNumber(gap.severity_score);
                        const measured = reportNumber(gap.measured_value);
                        const reference = reportNumber(gap.benchmark_value);
                        const people = reportNumber(gap.affected_population) ?? 0;
                        const barangays = reportNumber(gap.affected_barangays_count) ?? 0;
                        return (
                            <li
                                key={String(gap.indicator_key ?? index)}
                                className="grid gap-3 py-4 first:pt-0 last:pb-0 md:grid-cols-[minmax(0,1fr)_minmax(0,18rem)]"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="grid size-6 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 tabular-nums dark:bg-slate-800 dark:text-slate-300">
                                            {index + 1}
                                        </span>
                                        <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-50">{reportText(gap.label)}</h3>
                                        <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1', level.chip)}>
                                            {level.label}
                                        </span>
                                        {gap.confidence !== 'high' && (
                                            <span
                                                className="rounded-full bg-slate-50 px-2 py-0.5 text-[11px] text-slate-500 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
                                                title="Based on partial or lower-confidence data"
                                            >
                                                Provisional
                                            </span>
                                        )}
                                    </div>
                                    <div className="mt-2 flex items-center gap-3">
                                        <div
                                            className="h-1.5 w-32 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
                                            role="img"
                                            aria-label={`Severity ${explainNumber(severity)} of 100`}
                                        >
                                            <div
                                                className={cn('h-full rounded-full', level.bar)}
                                                style={{ width: `${Math.min(100, severity ?? 0)}%` }}
                                            />
                                        </div>
                                        <span className="text-xs text-slate-500 tabular-nums">Severity {explainNumber(severity)}/100</span>
                                    </div>
                                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
                                        Local{' '}
                                        <strong className="font-semibold text-slate-900 tabular-nums dark:text-slate-50">
                                            {explainNumber(measured)}
                                        </strong>{' '}
                                        vs reference <span className="tabular-nums">{explainNumber(reference)}</span>
                                        {gap.unit ? <span className="text-slate-500"> {reportText(gap.unit, '')}</span> : null}
                                    </p>
                                    {(people > 0 || barangays > 0) && (
                                        <p className="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                                            <Users className="size-3.5" aria-hidden="true" />
                                            {[
                                                people > 0 && `${people.toLocaleString('en-PH')} residents`,
                                                barangays > 0 && `${barangays} barangay${barangays === 1 ? '' : 's'} affected`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    onClick={() => onOpen(match ? 'matched_interventions' : 'gaps_barangays')}
                                    className="group flex min-w-0 items-start gap-2.5 self-start rounded-xl border border-sky-100 bg-sky-50/50 p-3 text-left transition hover:border-sky-300 focus-visible:outline-2 focus-visible:outline-[#087dcc] dark:border-slate-700 dark:bg-slate-800/60"
                                >
                                    <Sparkles className="mt-0.5 size-4 shrink-0 text-[#087dcc]" aria-hidden="true" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
                                            {match ? 'Fits this need' : 'No catalog match yet'}
                                        </span>
                                        <span className="mt-0.5 block text-sm font-medium text-slate-900 dark:text-slate-50">
                                            {match ? reportText(match.name) : 'Review the evidence with DOST'}
                                        </span>
                                        {match && (
                                            <span className="text-muted-foreground mt-0.5 block text-xs">
                                                Relevance {explainNumber(reportNumber(match.relevance_score))}/100 · candidate, needs validation
                                            </span>
                                        )}
                                    </span>
                                    <ArrowRight
                                        className="mt-0.5 size-3.5 shrink-0 text-[#087dcc] transition group-hover:translate-x-0.5"
                                        aria-hidden="true"
                                    />
                                </button>
                            </li>
                        );
                    })}
                </ol>
            )}

            <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800">
                <span>
                    {unassessed.length > 0 ? (
                        <>
                            <span className="font-medium text-slate-600 dark:text-slate-300">Not assessed (no data):</span> {unassessed.join(', ')}
                        </>
                    ) : (
                        'Every indicator had data for this location.'
                    )}
                </span>
                {total > SHOWN && (
                    <button type="button" onClick={() => onOpen('gaps_barangays')} className="font-semibold text-[#087dcc] hover:underline">
                        See all {total} gaps
                    </button>
                )}
            </div>
        </section>
    );
}
