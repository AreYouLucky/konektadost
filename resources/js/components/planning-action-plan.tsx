import { DostLogo } from '@/components/dost-logo';
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
                    <header className="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-3 border-b border-sky-100 px-5 py-4 sm:px-8 sm:py-5 dark:border-slate-700">
                        <DostLogo className="h-9 border-slate-200 sm:h-10 sm:border-r sm:pr-4" />
                        <div className="min-w-0 flex-1">
                            <Dialog.Title className="text-lg leading-snug font-semibold break-words text-[#087dcc] sm:text-xl">
                                {document.title}
                            </Dialog.Title>
                            <Dialog.Description className="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-xs text-slate-500">
                                <span>{snapshot.location}</span>
                                {snapshot.version ? <span>· Version {snapshot.version}</span> : null}
                                <span>· {snapshot.created_at ?? 'Timestamp not recorded'}</span>
                                <span className="rounded-full bg-amber-50 px-2 font-medium text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-800">
                                    Draft
                                </span>
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
                    <div
                        className="viz-palette min-h-0 flex-1 space-y-8 overflow-y-auto overscroll-contain px-5 py-6 sm:px-8 sm:py-8"
                        aria-label="Full action plan"
                        tabIndex={0}
                    >
                        {error && (
                            <p role="alert" className="text-sm text-amber-700">
                                {error}
                            </p>
                        )}
                        {!document.hasMatrix && (
                            <p className="rounded-xl bg-sky-50 px-4 py-3 text-xs leading-relaxed text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                                This version has no six-column activity matrix. Its original text is preserved; ask the assistant to revise it into
                                the current format.
                            </p>
                        )}
                        {document.parts.map((part, index) =>
                            part.type === 'matrix' ? (
                                <section key={index} aria-label="Action plan activity matrix" className="space-y-3">
                                    <h3 className="border-b border-sky-100 pb-2 text-base font-semibold text-[#087dcc] dark:border-slate-800">
                                        6. Action Plan Matrix
                                    </h3>
                                    <div
                                        role="region"
                                        tabIndex={0}
                                        className="overflow-x-auto rounded-xl border border-sky-100 dark:border-slate-700"
                                    >
                                        <table className="w-full min-w-[1000px] text-left text-xs leading-relaxed">
                                            <caption className="sr-only">
                                                Activities, objectives, results, timeline, budget/resources and responsible person or office
                                            </caption>
                                            <thead className="bg-[#087dcc] text-white">
                                                <tr>
                                                    {actionPlanColumns.map((title) => (
                                                        <th key={title} className="px-4 py-3 font-semibold">
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
                                                                <th key={column} scope="row" className="px-4 py-3 font-medium">
                                                                    {cell || 'Not specified'}
                                                                </th>
                                                            ) : (
                                                                <td key={column} className="px-4 py-3">
                                                                    {cell || 'Not specified'}
                                                                </td>
                                                            ),
                                                        )}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            ) : (
                                <PlanningPlanText
                                    key={index}
                                    content={
                                        document.parts[index + 1]?.type === 'matrix'
                                            ? part.content.replace(/\n?[^\n]*action plan matrix[^\n]*\s*$/i, '')
                                            : part.content
                                    }
                                />
                            ),
                        )}
                        <div className="[&>section]:mt-0">
                            {snapshot.visuals?.length ? (
                                <PlanningVisuals visuals={snapshot.visuals} expanded />
                            ) : (
                                <p className="text-xs text-slate-500">No evidence graph or assignment diagram was stored with this version.</p>
                            )}
                        </div>
                        <section className="space-y-2 border-t border-sky-100 pt-6 dark:border-slate-800">
                            <h3 className="text-sm font-semibold text-[#087dcc]">Source &amp; basis</h3>
                            <p className="max-w-4xl text-xs leading-relaxed text-slate-500">
                                This preview and PDF use this plan version’s text and stored visuals. Scores remain tool evidence; activities, targets
                                and funding statements remain draft proposals.
                            </p>
                            {sources.length > 0 && (
                                <ul className="flex flex-wrap gap-1.5 pt-1 text-[11px]">
                                    {sources.map((source) => (
                                        <li
                                            key={`${source.name}:${source.location}`}
                                            className="rounded-full bg-slate-50 px-2.5 py-0.5 text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700"
                                        >
                                            {source.name.replaceAll('_', ' ')} · {source.location}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>
                    <footer className="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-sky-100 bg-slate-50 px-5 py-3 sm:px-8 dark:border-slate-700 dark:bg-slate-900">
                        <Dialog.Close asChild>
                            <Button variant="ghost" size="sm">
                                Close
                            </Button>
                        </Dialog.Close>
                        {downloadButton}
                    </footer>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

const SECTION_HEADING =
    /^\s*(?:#{1,6}\s*)?\**\s*\d+[.)]\s*\**\s*(priority areas|critical needs|recommended sti|investment and resource|expected outcomes|action plan matrix)/i;
const BULLET = /^\s*[-*•]\s+/;
const NUMBERED = /^\s*\d+[.)]\s+/;
const clean = (line: string) =>
    line
        .replace(/\*\*/g, '')
        .replace(/^#{1,6}\s+/, '')
        .trim();

/** Plan text as titled sections, with paragraphs, bullet lists and numbered lists spaced for reading. */
function PlanningPlanText({ content }: { content: string }) {
    const sections: { heading: string | null; lines: string[] }[] = [];
    for (const line of content.split('\n').filter((item) => item.trim())) {
        if (SECTION_HEADING.test(line)) sections.push({ heading: clean(line), lines: [] });
        else {
            if (!sections.length) sections.push({ heading: null, lines: [] });
            sections[sections.length - 1].lines.push(line);
        }
    }
    return (
        <div className="max-w-5xl space-y-8 text-sm leading-relaxed break-words">
            {sections.map((section, index) => (
                <section key={index} className="space-y-3">
                    {section.heading && (
                        <h3 className="border-b border-sky-100 pb-2 text-base font-semibold text-[#087dcc] dark:border-slate-800">
                            {section.heading}
                        </h3>
                    )}
                    <PlanLines lines={section.lines} />
                </section>
            ))}
        </div>
    );
}

function PlanLines({ lines }: { lines: string[] }) {
    const blocks: { kind: 'p' | 'ul' | 'ol'; items: string[] }[] = [];
    for (const line of lines) {
        const kind = BULLET.test(line) ? 'ul' : NUMBERED.test(line) ? 'ol' : 'p';
        const text = clean(line.replace(kind === 'ul' ? BULLET : kind === 'ol' ? NUMBERED : /^$/, ''));
        const last = blocks[blocks.length - 1];
        if (kind !== 'p' && last?.kind === kind) last.items.push(text);
        else blocks.push({ kind, items: [text] });
    }
    return (
        <div className="space-y-3">
            {blocks.map((block, index) =>
                block.kind === 'p' ? (
                    <p key={index} className="whitespace-pre-wrap text-slate-700 dark:text-slate-200">
                        {block.items[0]}
                    </p>
                ) : block.kind === 'ul' ? (
                    <ul key={index} className="list-disc space-y-1.5 pl-5 text-slate-700 marker:text-[#087dcc] dark:text-slate-200">
                        {block.items.map((item, itemIndex) => (
                            <li key={itemIndex} className="pl-1">
                                {item}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <ol
                        key={index}
                        className="list-decimal space-y-1.5 pl-5 text-slate-700 marker:font-semibold marker:text-[#087dcc] dark:text-slate-200"
                    >
                        {block.items.map((item, itemIndex) => (
                            <li key={itemIndex} className="pl-1">
                                {item}
                            </li>
                        ))}
                    </ol>
                ),
            )}
        </div>
    );
}
