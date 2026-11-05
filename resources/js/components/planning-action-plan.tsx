import PlanningVisuals from '@/components/planning-visuals';
import { Button } from '@/components/ui/button';
import { actionPlanColumns, parsePlanningPlan, planningPlanSources, type PlanningPlanSnapshot } from '@/lib/planning-plan';
import * as Dialog from '@radix-ui/react-dialog';
import { Download, LoaderCircle, Maximize2, X } from 'lucide-react';
import { useMemo, useState } from 'react';

export default function PlanningActionPlan({ snapshot, initiallyOpen = false }: { snapshot: PlanningPlanSnapshot; initiallyOpen?: boolean }) {
    const [open, setOpen] = useState(initiallyOpen);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const document = useMemo(() => parsePlanningPlan(snapshot), [snapshot]);
    const sources = planningPlanSources(snapshot);
    async function download() {
        setBusy(true);
        setError(null);
        try {
            const { downloadPlanningPlanPdf } = await import('@/lib/planning-plan-pdf');
            await downloadPlanningPlanPdf(snapshot);
        } catch {
            setError('This plan could not be exported. Please retry.');
        } finally {
            setBusy(false);
        }
    }
    const downloadButton = (
        <Button size="sm" onClick={download} disabled={busy} className="shrink-0 bg-[#087dcc] text-white hover:bg-[#0666a8]">
            {busy ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : <Download className="size-4" aria-hidden="true" />}
            {busy ? 'Preparing PDF…' : 'Download PDF'}
        </Button>
    );
    return (
        <Dialog.Root open={open} onOpenChange={setOpen}>
            <section aria-label="Generated action plan" className="min-w-0 space-y-2">
                <h3 className="font-semibold break-words text-[#087dcc]">{document.title}</h3>
                <p className="text-xs text-slate-500">
                    {snapshot.location}
                    {snapshot.version ? ` · Version ${snapshot.version}` : ''} · Draft for local validation
                </p>
                <p className="text-xs text-slate-600 dark:text-slate-300">Review the full plan, activity matrix and stored evidence visuals.</p>
                <div className="flex flex-wrap gap-2">
                    <Dialog.Trigger asChild>
                        <Button variant="outline" size="sm" className="text-[#087dcc]">
                            <Maximize2 className="size-4" aria-hidden="true" />
                            View large
                        </Button>
                    </Dialog.Trigger>
                    {downloadButton}
                </div>
                {error && !open && (
                    <p role="alert" className="text-xs text-amber-700">
                        {error}
                    </p>
                )}
            </section>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-[100] bg-slate-950/60" />
                <Dialog.Content
                    style={{ width: 'min(96vw, 1400px)', maxHeight: '92dvh', translate: '-50% -50%' }}
                    className="fixed top-1/2 left-1/2 z-[101] flex min-h-0 flex-col overflow-hidden rounded-2xl border border-sky-200 bg-white text-slate-900 shadow-2xl outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    onEscapeKeyDown={(event) => event.stopPropagation()}
                >
                    <header className="flex shrink-0 flex-wrap items-start justify-between gap-3 border-b border-sky-100 p-4 sm:p-5 dark:border-slate-700">
                        <div className="min-w-0 flex-1">
                            <Dialog.Title className="text-base font-semibold break-words text-[#087dcc] sm:text-xl">{document.title}</Dialog.Title>
                            <Dialog.Description className="mt-1 text-xs text-slate-500">
                                {snapshot.location}
                                {snapshot.version ? ` · Version ${snapshot.version}` : ''} · {snapshot.created_at ?? 'Timestamp not recorded'} · Draft
                            </Dialog.Description>
                        </div>
                        <div className="flex items-center gap-2">
                            {downloadButton}
                            <Dialog.Close asChild>
                                <Button variant="ghost" size="icon" aria-label="Close large action plan">
                                    <X className="size-5" />
                                </Button>
                            </Dialog.Close>
                        </div>
                    </header>
                    <div className="min-h-0 overflow-y-auto overscroll-contain p-4 sm:p-6" aria-label="Full action plan" tabIndex={0}>
                        {error && (
                            <p role="alert" className="mb-3 text-sm text-amber-700">
                                {error}
                            </p>
                        )}
                        {!document.hasMatrix && (
                            <p className="mb-4 rounded-lg bg-sky-50 p-3 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                                This version has no six-column activity matrix. Its original text is preserved; ask the assistant to revise it into
                                the current format.
                            </p>
                        )}
                        <div className="space-y-5">
                            {document.parts.map((part, index) =>
                                part.type === 'matrix' ? (
                                    <div
                                        key={index}
                                        role="region"
                                        aria-label="Action plan activity matrix"
                                        tabIndex={0}
                                        className="overflow-x-auto rounded-lg border border-sky-100 dark:border-slate-700"
                                    >
                                        <table className="w-full min-w-[1000px] text-left text-xs">
                                            <caption className="sr-only">
                                                Activities, objectives, results, timeline, budget/resources and responsible person or office
                                            </caption>
                                            <thead className="bg-[#087dcc] text-white">
                                                <tr>
                                                    {actionPlanColumns.map((title) => (
                                                        <th key={title} className="p-3">
                                                            {title}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                                                {part.rows.map((row, rowIndex) => (
                                                    <tr key={rowIndex} className="align-top even:bg-sky-50/50 dark:even:bg-slate-900">
                                                        {row.map((cell, column) =>
                                                            column === 0 ? (
                                                                <th key={column} scope="row" className="p-3 font-medium">
                                                                    {cell || 'Not specified'}
                                                                </th>
                                                            ) : (
                                                                <td key={column} className="p-3">
                                                                    {cell || 'Not specified'}
                                                                </td>
                                                            ),
                                                        )}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <PlanningPlanText key={index} content={part.content} />
                                ),
                            )}
                        </div>
                        {snapshot.visuals?.length ? (
                            <PlanningVisuals visuals={snapshot.visuals} expanded />
                        ) : (
                            <p className="mt-4 text-xs text-slate-500">No evidence graph or assignment diagram was stored with this version.</p>
                        )}
                        <section className="mt-6">
                            <h3 className="text-sm font-semibold text-[#087dcc]">Source &amp; basis</h3>
                            <p className="mt-1 text-xs text-slate-500">
                                This preview and PDF use this plan version’s text and stored visuals. Scores remain tool evidence; activities, targets
                                and funding statements remain draft proposals.
                            </p>
                            {sources.length > 0 && (
                                <ul className="mt-2 list-disc space-y-1 pl-5 text-xs">
                                    {sources.map((source) => (
                                        <li key={`${source.name}:${source.location}`}>
                                            {source.name} · {source.location}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

function PlanningPlanText({ content }: { content: string }) {
    return (
        <div className="space-y-2 text-sm leading-relaxed break-words">
            {content
                .split('\n')
                .filter((line) => line.trim())
                .map((line, index) =>
                    /^\s*(?:#{1,6}\s*)?\d+[.)]\s/.test(line) ? (
                        <h3 key={index} className="pt-3 font-semibold text-[#087dcc] first:pt-0">
                            {line.replace(/^#{1,6}\s+/, '')}
                        </h3>
                    ) : (
                        <p key={index} className="whitespace-pre-wrap">
                            {line}
                        </p>
                    ),
                )}
        </div>
    );
}
