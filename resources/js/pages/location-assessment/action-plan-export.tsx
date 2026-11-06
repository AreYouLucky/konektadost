import type { AiResult } from '@/components/assessment-ai-analysis';
import { ChartCard, FlowSteps, StackedBars } from '@/components/assessment-method/charts';
import { NeedsChartExplain, OptionsChartExplain, TimelineExplain } from '@/components/assessment-method/explain';
import WideModal from '@/components/assessment-method/wide-modal';
import { DostLogo } from '@/components/dost-logo';
import { Button } from '@/components/ui/button';
import { DialogClose } from '@/components/ui/dialog';
import {
    actionPlanColumns,
    buildActionPlanSnapshot,
    type ActionPlanActivity,
    type ActionPlanSnapshot,
    type AssessmentActionPlan,
} from '@/lib/assessment-action-plan';
import { explainNumber } from '@/lib/assessment-explanations';
import { assessmentPlanScope, saveAssessmentPlan, type SavedAssessmentPlanSummary } from '@/lib/assessment-plan-store';
import type { AssessmentReportInput, OverviewChart } from '@/lib/assessment-report';
import type { TargetAreaSet } from '@/lib/assessment-targets';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, FileDown, LoaderCircle, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { TargetRankingExplanation } from './target-areas';

const PHASE_COLORS: Record<ActionPlanActivity['phase'], string> = {
    Validation: 'var(--series-1)',
    Preparation: 'var(--series-2)',
    Pilot: 'var(--series-3)',
    Review: 'var(--series-4)',
};

/** The live assessment plus how the page was opened, which the save request needs to locate it. */
export type ActionPlanReport = AssessmentReportInput & { assessmentParams?: Record<string, string | number> };

/** Downloads the PDF of a live report or a saved snapshot; `onGenerated` receives the snapshot that was exported. */
export function useActionPlanDownload(
    source: ActionPlanReport | ActionPlanSnapshot,
    analysis?: AiResult,
    onGenerated?: (snapshot: ActionPlanSnapshot) => void,
) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    async function download() {
        setBusy(true);
        setError(null);
        try {
            const snapshot = 'schema' in source ? source : buildActionPlanSnapshot(source, analysis);
            const { downloadActionPlanSnapshotPdf } = await import('@/lib/assessment-action-plan-pdf');
            await downloadActionPlanSnapshotPdf(snapshot);
            onGenerated?.(snapshot);
        } catch {
            setError('The action plan PDF could not be created. Please retry.');
        } finally {
            setBusy(false);
        }
    }
    return { busy, error, download };
}

export type ActionPlanSaveState =
    | { status: 'idle' }
    | { status: 'saving' }
    /** The page has no location the server can resolve, so nothing is saved. */
    | { status: 'unsupported' }
    | { status: 'saved'; plan: SavedAssessmentPlanSummary; created: boolean }
    | { status: 'error'; message: string };

/**
 * Saves generated plans to the account (sidebar "Generated action plans" and the dashboard).
 * The same plan is saved once; a changed plan (e.g. after the AI interpretation arrives) becomes a new version.
 */
export function useActionPlanSave(report: ActionPlanReport) {
    const { props } = usePage<SharedData>();
    const userId = props.auth.user.id;
    const scope = useMemo(() => assessmentPlanScope(report), [report]);
    const [state, setState] = useState<ActionPlanSaveState>({ status: 'idle' });
    const latest = useRef<ActionPlanSnapshot | null>(null);
    const save = useCallback(
        async (snapshot: ActionPlanSnapshot) => {
            latest.current = snapshot;
            if (!scope) {
                setState({ status: 'unsupported' });
                return;
            }
            setState({ status: 'saving' });
            try {
                const result = await saveAssessmentPlan(userId, scope, snapshot);
                // Ignore a slower response for a plan that has since changed.
                if (latest.current === snapshot) setState({ status: 'saved', plan: result.plan, created: result.created });
            } catch (error) {
                if (latest.current === snapshot)
                    setState({ status: 'error', message: error instanceof Error ? error.message : 'The action plan could not be saved.' });
            }
        },
        [scope, userId],
    );
    const retry = useCallback(() => {
        if (latest.current) void save(latest.current);
    }, [save]);
    return { state, save, retry };
}

export type ActionPlanSaver = ReturnType<typeof useActionPlanSave>;

/** One-line save status for the modal footer and the page header. */
export function ActionPlanSaveStatus({ saver, className = '' }: { saver: ActionPlanSaver; className?: string }) {
    const { state } = saver;
    if (state.status === 'idle' || state.status === 'unsupported') return null;
    if (state.status === 'saving')
        return (
            <p role="status" className={`flex items-center gap-1.5 text-xs text-slate-500 ${className}`}>
                <LoaderCircle className="size-3.5 animate-spin" aria-hidden="true" /> Saving to your plans…
            </p>
        );
    if (state.status === 'error')
        return (
            <p role="alert" className={`flex flex-wrap items-center gap-1.5 text-xs text-amber-800 dark:text-amber-300 ${className}`}>
                <AlertTriangle className="size-3.5 shrink-0" aria-hidden="true" /> Not saved: {state.message}
                <button type="button" onClick={saver.retry} className="inline-flex items-center gap-1 font-medium text-[#087dcc] hover:underline">
                    <RotateCcw className="size-3" aria-hidden="true" /> Retry
                </button>
            </p>
        );
    return (
        <p role="status" className={`flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-300 ${className}`}>
            <CheckCircle2 className="size-3.5 shrink-0" aria-hidden="true" />
            Saved as version {state.plan.version} in Generated action plans
        </p>
    );
}

function DownloadButton({ busy, onClick, className = '' }: { busy: boolean; onClick: () => void; className?: string }) {
    return (
        <Button onClick={onClick} disabled={busy} className={`bg-[#087dcc] text-white hover:bg-[#086bb0] ${className}`}>
            {busy ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : <FileDown className="size-4" aria-hidden="true" />}
            {busy ? 'Preparing PDF…' : 'Download PDF'}
        </Button>
    );
}

function Bars({ chart, explain }: { chart: OverviewChart; explain?: ReactNode }) {
    return (
        <ChartCard title={chart.title} explain={explain}>
            {chart.rows.length ? (
                <ul className="space-y-2">
                    {chart.rows.map((row) => (
                        <li key={row.label} className="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_3rem] items-center gap-3">
                            <span className="truncate text-xs text-slate-700 dark:text-slate-200" title={row.label}>
                                {row.label}
                            </span>
                            <span className="h-2.5 rounded-full bg-slate-100 dark:bg-slate-800">
                                {row.value != null && (
                                    <span
                                        className="block h-full rounded-full"
                                        style={{
                                            width: `${Math.min(100, (row.value / (chart.maximum ?? 100)) * 100)}%`,
                                            backgroundColor: row.color ?? 'var(--series-1)',
                                        }}
                                    />
                                )}
                            </span>
                            <span className="text-right font-mono text-xs tabular-nums">{explainNumber(row.value)}</span>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-muted-foreground text-xs">No supported records.</p>
            )}
        </ChartCard>
    );
}

/** 12-week Gantt chart of the proposed activities. */
function Timeline({ activities }: { activities: ActionPlanActivity[] }) {
    const weeks = Array.from({ length: 12 }, (_, index) => index + 1);
    return (
        <ChartCard title="12-week timeline" hint="Proposed schedule; pilot depends on validation." explain={<TimelineExplain />}>
            <div className="overflow-x-auto">
                <div className="min-w-[640px]">
                    <div className="grid grid-cols-[minmax(0,15rem)_repeat(12,minmax(0,1fr))] gap-y-1.5 text-[10px] text-slate-500">
                        <span />
                        {weeks.map((week) => (
                            <span key={week} className="text-center">
                                W{week}
                            </span>
                        ))}
                        {activities.map((row) => (
                            <div key={row.activity} className="contents">
                                <span className="truncate pr-2 text-xs text-slate-700 dark:text-slate-200" title={row.activity}>
                                    {row.activity}
                                </span>
                                <span
                                    className="h-4 self-center rounded"
                                    style={{ gridColumn: `${row.startWeek + 1} / ${row.endWeek + 2}`, backgroundColor: PHASE_COLORS[row.phase] }}
                                    title={`${row.phase} · weeks ${row.startWeek}–${row.endWeek}`}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            </div>
            <ul className="mt-3 flex flex-wrap gap-3 text-[11px] text-slate-600 dark:text-slate-300">
                {Object.entries(PHASE_COLORS).map(([phase, color]) => (
                    <li key={phase} className="flex items-center gap-1.5">
                        <span className="size-2.5 rounded-sm" style={{ backgroundColor: color }} aria-hidden="true" /> {phase}
                    </li>
                ))}
            </ul>
        </ChartCard>
    );
}

function Section({ n, title, children }: { n: number; title: string; children: ReactNode }) {
    return (
        <section className="flex min-w-0 flex-col gap-3 [&>figure]:flex-1">
            <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-slate-100">
                <span className="flex size-6 items-center justify-center rounded-full bg-[#087dcc] text-xs text-white">{n}</span>
                {title}
            </h3>
            {children}
        </section>
    );
}

/** The plan body (graphs, timeline, matrix), rendered from a live or saved snapshot. */
export function ActionPlanView({ plan, targets }: { plan: AssessmentActionPlan; targets: TargetAreaSet | null }) {
    const levelLabel = plan.targetLevel === 'municipality' ? 'cities / municipalities' : 'barangays';
    return (
        <div className="viz-palette min-w-0 space-y-6">
            <FlowSteps
                steps={[
                    { title: 'Validate', value: 'Weeks 1–2', caption: 'needs & sites' },
                    { title: 'Prepare', value: 'Weeks 3–4', caption: 'fit, cost, partners' },
                    { title: 'Pilot', value: 'Weeks 5–8', caption: 'if validated' },
                    { title: 'Review', value: 'Weeks 9–12', caption: 'results & next steps' },
                ]}
            />

            <Section n={1} title="Priority areas">
                {targets && plan.targets.length ? (
                    <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                        <ChartCard
                            title={`Top ${plan.targets.length} target ${levelLabel}`}
                            hint="Points per factor (0–100)."
                            explain={<TargetRankingExplanation set={targets} />}
                        >
                            <StackedBars
                                keys={targets.factors}
                                rows={targets.areas.map((area) => ({
                                    label: `${area.rank}. ${area.name}`,
                                    total: area.score,
                                    values: area.components,
                                }))}
                            />
                        </ChartCard>
                        <div className="overflow-x-auto rounded-xl border border-sky-100 dark:border-slate-800">
                            <table className="w-full min-w-[480px] text-left text-xs">
                                <thead className="bg-blue-50 dark:bg-slate-900">
                                    <tr>
                                        {['#', 'Area', 'Why', 'Suggested technology'].map((title) => (
                                            <th key={title} className="p-2">
                                                {title}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {plan.targets.map((row) => (
                                        <tr key={row.rank} className="align-top">
                                            <td className="p-2 font-semibold">{row.rank}</td>
                                            <td className="p-2 font-medium">
                                                {row.name}
                                                <span className="text-muted-foreground block font-mono">{explainNumber(row.score)}/100</span>
                                            </td>
                                            <td className="text-muted-foreground p-2">{row.why}</td>
                                            <td className="p-2 text-[#087dcc]">{row.technologies}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                ) : (
                    <ul className="space-y-1 text-sm">
                        {plan.priorities.map((row) => (
                            <li key={row.area}>
                                <strong>{row.area}</strong> — <span className="text-muted-foreground">{row.basis}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <div className="grid gap-4 lg:grid-cols-2">
                <Section n={2} title="Critical needs">
                    <Bars chart={plan.charts[0]} explain={<NeedsChartExplain />} />
                </Section>
                <Section n={3} title="Recommended STI options">
                    <Bars chart={plan.charts[1]} explain={<OptionsChartExplain />} />
                </Section>
            </div>

            {plan.options.length > 0 && (
                <ul className="grid gap-2 sm:grid-cols-2">
                    {plan.options.map((option) => (
                        <li
                            key={option.ref}
                            className="rounded-xl border border-sky-100 bg-white p-3 text-xs dark:border-slate-800 dark:bg-slate-950"
                        >
                            <p className="text-sm font-semibold text-slate-900 dark:text-slate-100">{option.name}</p>
                            <p className="text-muted-foreground">
                                {option.agency} · for {option.need} · {explainNumber(option.relevance)}/100
                            </p>
                            {option.aiNote && <p className="mt-1 text-slate-700 dark:text-slate-300">{option.aiNote}</p>}
                        </li>
                    ))}
                </ul>
            )}

            <Section n={4} title="Activities, resources and responsibilities">
                <Timeline activities={plan.activities} />
                <div className="overflow-x-auto rounded-xl border border-sky-100 dark:border-slate-800">
                    <table className="w-full min-w-[880px] text-left text-xs">
                        <thead className="bg-[#087dcc] text-white">
                            <tr>
                                {actionPlanColumns.map((title) => (
                                    <th key={title} className="p-2 font-semibold">
                                        {title}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {plan.activities.map((row) => (
                                <tr key={row.activity} className="align-top">
                                    <td className="p-2 font-medium">{row.activity}</td>
                                    <td className="p-2">{row.objective}</td>
                                    <td className="p-2">{row.result}</td>
                                    <td className="p-2 whitespace-nowrap">
                                        Weeks {row.startWeek}–{row.endWeek}
                                    </td>
                                    <td className="p-2">{row.resources}</td>
                                    <td className="p-2">{row.responsible}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="rounded-lg bg-sky-50 p-3 text-xs text-slate-700 dark:bg-slate-900 dark:text-slate-200">
                    <strong>Laboratory pathway:</strong> {plan.laboratory}
                </p>
            </Section>

            <Section n={5} title="Expected outcomes & limits">
                <ul className="text-muted-foreground list-disc space-y-1 pl-5 text-xs">
                    {plan.limitations.map((text) => (
                        <li key={text}>{text}</li>
                    ))}
                </ul>
            </Section>
        </div>
    );
}

/**
 * The action plan modal (graphs, timeline, matrix) with Download PDF in its footer.
 * Opening it generates the plan, which is saved to the account through `saver`.
 */
export function ActionPlanModal({
    report,
    analysis,
    trigger,
    saver,
}: {
    report: ActionPlanReport;
    analysis?: AiResult;
    trigger: ReactNode;
    saver: ActionPlanSaver;
}) {
    const [open, setOpen] = useState(false);
    const snapshot = useMemo(() => buildActionPlanSnapshot(report, analysis), [report, analysis]);
    const { busy, error, download } = useActionPlanDownload(snapshot);
    const { save } = saver;
    useEffect(() => {
        if (open) void save(snapshot);
    }, [open, snapshot, save]);
    return (
        <WideModal
            size="xl"
            open={open}
            onOpenChange={setOpen}
            title={`${report.location.name} · DOST Action Plan`}
            description="Draft for LGU/DOST validation. Scores are computed; schedule and costs are proposed."
            leading={<DostLogo className="h-9 sm:h-10" />}
            footer={
                <>
                    <div className="mr-auto flex min-w-0 flex-col gap-1">
                        <ActionPlanSaveStatus saver={saver} />
                        {error && (
                            <p role="alert" className="text-xs text-amber-800 dark:text-amber-300">
                                {error}
                            </p>
                        )}
                    </div>
                    <DialogClose asChild>
                        <Button variant="ghost">Close</Button>
                    </DialogClose>
                    <DownloadButton busy={busy} onClick={download} />
                </>
            }
            trigger={trigger}
        >
            <ActionPlanView plan={snapshot.plan} targets={snapshot.targets} />
        </WideModal>
    );
}
