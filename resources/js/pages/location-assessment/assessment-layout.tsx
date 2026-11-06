import { type AiResult } from '@/components/assessment-ai-analysis';
import { useAssessmentPdfDownload } from '@/components/assessment-pdf-export';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { explainNumber } from '@/lib/assessment-explanations';
import { reportGaps, reportNumber, reportTechnologyOptions, reportText, type AssessmentLayer, type LayerOverview } from '@/lib/assessment-report';
import { buildTargetAreas } from '@/lib/assessment-targets';
import {
    AlertTriangle,
    ArrowRight,
    ChevronDown,
    ClipboardList,
    Crosshair,
    Download,
    FileText,
    Gauge,
    LoaderCircle,
    Sparkles,
    Target,
    type LucideIcon,
} from 'lucide-react';
import { useId, useMemo, useState, type ReactNode } from 'react';
import { ActionPlanModal, ActionPlanSaveStatus, useActionPlanDownload, useActionPlanSave } from './action-plan-export';
import SourceBasisModal from './source-basis-modal';
import type { AssessmentPageProps } from './types';

/** Primary actions in the page header: open the plan, or download either PDF. */
export function ReportActions({
    report,
    overviews,
    analysis,
}: {
    report: AssessmentPageProps;
    overviews: Record<AssessmentLayer, LayerOverview>;
    analysis?: AiResult;
}) {
    // Opening the plan or downloading its PDF saves it to the account's generated action plans.
    const saver = useActionPlanSave(report);
    const plan = useActionPlanDownload(report, analysis, saver.save);
    const full = useAssessmentPdfDownload(report, overviews, analysis);
    const busy = plan.busy || full.busy;
    const error = plan.error ?? full.error;
    return (
        <div className="flex flex-col items-stretch gap-1 sm:items-end">
            <div className="flex flex-wrap gap-2">
                <ActionPlanModal
                    report={report}
                    analysis={analysis}
                    saver={saver}
                    trigger={
                        <Button className="flex-1 bg-[#087dcc] text-white hover:bg-[#086bb0] sm:flex-none">
                            <ClipboardList className="size-4" aria-hidden="true" /> View action plan
                        </Button>
                    }
                />
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" disabled={busy} className="flex-1 border-[#087dcc]/30 text-[#087dcc] sm:flex-none">
                            {busy ? (
                                <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
                            ) : (
                                <Download className="size-4" aria-hidden="true" />
                            )}
                            {busy ? 'Preparing…' : 'Download'}
                            <ChevronDown className="size-3.5 opacity-70" aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-64">
                        <DropdownMenuLabel className="text-xs text-slate-500">PDF downloads</DropdownMenuLabel>
                        <DropdownMenuItem onSelect={() => void plan.download()} className="items-start gap-2">
                            <ClipboardList className="mt-0.5 size-4 text-[#087dcc]" aria-hidden="true" />
                            <span>
                                <span className="block text-sm font-medium">Action plan</span>
                                <span className="text-muted-foreground block text-[11px]">Targets, technologies, timeline, matrix</span>
                            </span>
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => void full.download()} className="items-start gap-2">
                            <FileText className="mt-0.5 size-4 text-[#087dcc]" aria-hidden="true" />
                            <span>
                                <span className="block text-sm font-medium">Full assessment report</span>
                                <span className="text-muted-foreground block text-[11px]">All steps, charts and formulas</span>
                            </span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            {error && (
                <p role="alert" className="text-xs text-amber-800 dark:text-amber-300">
                    {error}
                </p>
            )}
            <ActionPlanSaveStatus saver={saver} className="sm:justify-end" />
        </div>
    );
}

/** All data warnings in one compact strip. */
export function AssessmentNotices({ report }: { report: AssessmentPageProps }) {
    const conflicts = report.inventoryIntegrity?.has_conflicts ? report.inventoryIntegrity.inventories.filter((row) => row.has_conflicts) : [];
    if (!report.populationReason && !conflicts.length && !report.analysisScopeNotice) return null;
    return (
        <div className="flex flex-col gap-2 rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-xs text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {report.populationReason && (
                <p className="flex gap-2">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" /> {report.populationReason}
                </p>
            )}
            {conflicts.length > 0 && (
                <div role="alert" className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <AlertTriangle className="size-3.5 shrink-0" aria-hidden="true" />
                    <span>Some inventory locations conflict; affected comparisons are withheld.</span>
                    <SourceBasisModal title="Inventory location conflicts" triggerLabel="See which">
                        <ul className="list-disc space-y-2 pl-5">
                            {conflicts.map((row) => (
                                <li key={row.dataset}>{row.reason}</li>
                            ))}
                        </ul>
                    </SourceBasisModal>
                </div>
            )}
            {report.analysisScopeNotice && <p className="text-amber-900/80 dark:text-amber-100/80">{report.analysisScopeNotice}</p>}
        </div>
    );
}

function GlanceCard({
    icon: Icon,
    label,
    value,
    detail,
    tone = 'blue',
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    tone?: 'blue' | 'amber';
    onClick: () => void;
}) {
    const toneClass = tone === 'amber' ? 'bg-amber-50 text-amber-600 dark:bg-amber-950' : 'bg-blue-50 text-[#087dcc] dark:bg-slate-800';
    return (
        <button
            type="button"
            onClick={onClick}
            className="group flex h-full min-w-0 flex-col gap-2 rounded-2xl border border-sky-100 bg-white p-4 text-left shadow-xs transition hover:border-sky-300 hover:shadow-sm focus-visible:outline-2 focus-visible:outline-[#087dcc] dark:border-slate-800 dark:bg-slate-900"
        >
            <span className="flex items-center gap-2 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
                <span className={`flex size-7 items-center justify-center rounded-lg ${toneClass}`}>
                    <Icon className="size-4" aria-hidden="true" />
                </span>
                {label}
            </span>
            <span className="line-clamp-2 text-base leading-snug font-semibold text-slate-900 dark:text-slate-50" title={value}>
                {value}
            </span>
            <span className="text-muted-foreground mt-auto flex items-center justify-between gap-2 text-xs">
                <span className="truncate">{detail}</span>
                <ArrowRight className="size-3.5 shrink-0 text-[#087dcc] transition group-hover:translate-x-0.5" aria-hidden="true" />
            </span>
        </button>
    );
}

/** The four answers a decision-maker needs first; each opens the step with the evidence. */
export function AtAGlance({ report, onOpen }: { report: AssessmentPageProps; onOpen: (layer: AssessmentLayer) => void }) {
    const data = useMemo(() => {
        const gaps = [...reportGaps(report)].sort((a, b) => (reportNumber(b.severity_score) ?? -1) - (reportNumber(a.severity_score) ?? -1));
        const options = reportTechnologyOptions(report);
        const targets = buildTargetAreas(report, 10);
        const compared = report.results.filter((row) => row.status === 'available' && row.value !== null && row.benchmark !== null);
        const high = compared.filter((row) => row.confidence === 'high').length;
        return { gaps, options, targets, compared, high };
    }, [report]);
    const topGap = data.gaps[0];
    const topTech = data.options[0];
    const topArea = data.targets?.areas[0];
    const unit = data.targets?.level === 'municipality' ? 'cities / municipalities' : 'barangays';
    return (
        <section aria-label="At a glance" className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <GlanceCard
                icon={AlertTriangle}
                tone="amber"
                label="Top need"
                value={topGap ? reportText(topGap.label) : 'No gap detected'}
                detail={
                    topGap
                        ? `Severity ${explainNumber(reportNumber(topGap.severity_score))}/100 · ${data.gaps.length} gaps found`
                        : 'Check data coverage'
                }
                onClick={() => onOpen('gaps_barangays')}
            />
            <GlanceCard
                icon={Sparkles}
                label="Best-fit technology"
                value={topTech ? reportText(topTech.name) : 'No catalog match yet'}
                detail={
                    topTech
                        ? `${explainNumber(reportNumber(topTech.relevance_score))}/100 relevance · ${data.options.length} options`
                        : 'Ask DOST to review options'
                }
                onClick={() => onOpen('matched_interventions')}
            />
            <GlanceCard
                icon={Crosshair}
                label="Deploy first"
                value={topArea ? topArea.name : report.location.name}
                detail={
                    topArea
                        ? `Priority ${explainNumber(topArea.score)}/100 · of ${data.targets?.total} ${unit}`
                        : 'Site ranking needs municipality data'
                }
                onClick={() => onOpen('matched_interventions')}
            />
            <GlanceCard
                icon={Gauge}
                label="Evidence"
                value={`${data.compared.length} of ${report.results.length} indicators compared`}
                detail={`${data.high} high confidence`}
                onClick={() => onOpen('sectors_roads')}
            />
        </section>
    );
}

/** A labelled group inside a step ("Key graphs", "Detailed records"…): icon, title, hint and a divider line. */
export function StepBlock({ title, hint, icon: Icon, children }: { title: string; hint?: string; icon: LucideIcon; children: ReactNode }) {
    return (
        <section aria-label={title} className="min-w-0 space-y-3">
            <div className="flex min-w-0 items-center gap-2">
                <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-blue-50 text-[#087dcc] dark:bg-slate-800">
                    <Icon className="size-3.5" aria-hidden="true" />
                </span>
                <h3 className="shrink-0 text-sm font-semibold text-slate-800 dark:text-slate-100">{title}</h3>
                {hint && <span className="text-muted-foreground hidden truncate text-xs sm:inline">{hint}</span>}
                <span aria-hidden="true" className="h-px min-w-6 flex-1 bg-sky-200/70 dark:bg-slate-800" />
            </div>
            {children}
        </section>
    );
}

/** Two equal columns on large screens; cards in a row stretch to the same height. */
export function ChartGrid({ children }: { children: ReactNode }) {
    return <div className="grid min-w-0 gap-4 lg:grid-cols-2">{children}</div>;
}

/** A titled block that can be folded away, with an optional count. Closed by default so each step leads with its graphs. */
export function Collapsible({
    title,
    count,
    hint,
    icon: Icon = Target,
    defaultOpen = false,
    children,
}: {
    title: string;
    count?: number | string | null;
    hint?: string;
    icon?: LucideIcon;
    defaultOpen?: boolean;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const id = useId();
    return (
        <section className="min-w-0 rounded-2xl border border-sky-100 bg-white dark:border-slate-800 dark:bg-slate-950">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={id}
                onClick={() => setOpen((value) => !value)}
                className="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left hover:bg-sky-50/60 dark:hover:bg-slate-900"
            >
                <Icon className="size-4 shrink-0 text-[#087dcc]" aria-hidden="true" />
                <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {title}
                        {count != null && (
                            <span className="rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-[#087dcc] tabular-nums dark:bg-slate-800 dark:text-sky-300">
                                {count}
                            </span>
                        )}
                    </span>
                    {hint && <span className="text-muted-foreground block text-[11px]">{hint}</span>}
                </span>
                <span className="text-xs font-medium text-[#087dcc]">{open ? 'Hide' : 'Show'}</span>
                <ChevronDown className={`size-4 shrink-0 text-slate-400 transition ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
            </button>
            {open && (
                <div id={id} className="min-w-0 border-t border-sky-100 p-4 dark:border-slate-800">
                    {children}
                </div>
            )}
        </section>
    );
}
