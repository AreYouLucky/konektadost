import { ArrowDown, BarChart3, GitBranch } from 'lucide-react';

export interface BarVisual {
    type: 'bar';
    title: string;
    unit: string;
    maximum: number;
    items: { label: string; value: number; reference?: string | null }[];
    caption: string;
    source_tool: string;
}

export interface AssignmentDiagram {
    type: 'assignment_diagram';
    title: string;
    caption: string;
    source_tool: string;
    assignments: {
        reference: string | null;
        nodes: { id: string; kind: 'location' | 'need' | 'technology' | 'deployment'; label: string }[];
        edges: { from: string; to: string }[];
    }[];
}

export type PlanningVisual = BarVisual | AssignmentDiagram;

export default function PlanningVisuals({ visuals, expanded = false }: { visuals?: PlanningVisual[]; expanded?: boolean }) {
    if (!visuals?.length) return null;

    return (
        <section
            aria-label="Evidence graphs and technology assignment diagrams"
            className={expanded ? 'mt-5 grid min-w-0 items-start gap-4 lg:grid-cols-2' : 'mt-4 min-w-0 space-y-2'}
        >
            <h4 className="col-span-full flex items-center gap-1.5 text-xs font-semibold text-[#087dcc]">
                <BarChart3 className="size-3.5" aria-hidden="true" /> Graphs &amp; assignment diagrams
            </h4>
            {visuals.map((visual, index) => {
                const figure = (
                    <figure className="mt-3 min-w-0">
                        {visual.type === 'bar' ? (
                            <ScoreChart visual={visual} />
                        ) : visual.type === 'assignment_diagram' ? (
                            <AssignmentFlows visual={visual} />
                        ) : null}
                        <figcaption className="mt-2 text-[10px] leading-relaxed text-slate-500">
                            {visual.caption}
                            <span className="mt-1 block break-words">Source: {visual.source_tool}</span>
                        </figcaption>
                    </figure>
                );
                return expanded ? (
                    <section
                        key={`${visual.type}:${index}`}
                        className="min-w-0 rounded-xl border border-blue-100 bg-white p-4 dark:border-slate-700 dark:bg-slate-950"
                    >
                        <h5 className="text-sm font-semibold text-slate-700 dark:text-slate-200">{visual.title}</h5>
                        {figure}
                    </section>
                ) : (
                    <details
                        key={`${visual.type}:${index}`}
                        open={index === 0}
                        className="min-w-0 rounded-xl border border-blue-100 bg-white p-2.5 dark:border-slate-700 dark:bg-slate-950"
                    >
                        <summary className="cursor-pointer text-xs font-semibold text-slate-700 dark:text-slate-200">{visual.title}</summary>
                        {figure}
                    </details>
                );
            })}
        </section>
    );
}

function ScoreChart({ visual }: { visual: BarVisual }) {
    const maximum = visual.maximum > 0 && Number.isFinite(visual.maximum) ? visual.maximum : 100;
    const items = visual.items.filter((item) => Number.isFinite(item.value) && item.value >= 0 && item.value <= maximum).slice(0, 6);
    const height = items.length * 58 + 28;
    if (!items.length) return <p className="text-xs text-slate-500">No verified scores are available for this graph.</p>;

    return (
        <svg
            role="img"
            aria-label={`${visual.title}: ${items.map((item) => `${item.label}, ${item.value} ${visual.unit}`).join('; ')}`}
            viewBox={`0 0 420 ${height}`}
            className="h-auto w-full overflow-visible"
        >
            <title>{visual.title}</title>
            <desc>{visual.caption}</desc>
            {items.map((item, index) => {
                const y = index * 58;
                const width = (item.value / maximum) * 360;
                return (
                    <g key={`${item.reference ?? item.label}:${index}`}>
                        <title>
                            {item.label}: {item.value} {visual.unit}
                        </title>
                        <text x="0" y={y + 13} fontSize="12" className="fill-slate-600 dark:fill-slate-300">
                            {item.label.length > 54 ? `${item.label.slice(0, 51)}…` : item.label}
                        </text>
                        <rect x="0" y={y + 23} width="360" height="16" rx="4" className="fill-blue-50 dark:fill-slate-800" />
                        <rect x="0" y={y + 23} width={width} height="16" rx="4" fill="#087dcc" />
                        <text x={Math.min(width + 7, 370)} y={y + 35} fontSize="12" fontWeight="600" className="fill-slate-700 dark:fill-slate-200">
                            {item.value}
                        </text>
                    </g>
                );
            })}
            {[0, 0.25, 0.5, 0.75, 1].map((fraction) => (
                <text
                    key={fraction}
                    x={fraction * 360}
                    y={height - 6}
                    textAnchor={fraction === 0 ? 'start' : 'middle'}
                    fontSize="10"
                    className="fill-slate-400"
                >
                    {fraction * maximum}
                </text>
            ))}
        </svg>
    );
}

function AssignmentFlows({ visual }: { visual: AssignmentDiagram }) {
    const colors = {
        location: 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/40',
        need: 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
        technology: 'border-cyan-200 bg-cyan-50 dark:border-cyan-900 dark:bg-cyan-950/30',
        deployment: 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30',
    };
    return (
        <div className="space-y-4">
            {visual.assignments.slice(0, 4).map((assignment, assignmentIndex) => (
                <div key={`${assignment.reference}:${assignmentIndex}`} className="rounded-lg border border-slate-100 p-2 dark:border-slate-800">
                    {assignment.reference && (
                        <p className="mb-2 flex items-center gap-1 text-[10px] break-all text-slate-500">
                            <GitBranch className="size-3 shrink-0" aria-hidden="true" />
                            {assignment.reference}
                        </p>
                    )}
                    <ol aria-label="Location to need to DOST technology to proposed deployment" className="space-y-1">
                        {assignment.nodes.map((node, index) => (
                            <li key={node.id}>
                                {index > 0 &&
                                    assignment.edges.some((edge) => edge.from === assignment.nodes[index - 1].id && edge.to === node.id) && (
                                        <ArrowDown className="mx-auto mb-1 size-4 text-slate-400" aria-hidden="true" />
                                    )}
                                <div className={`rounded-lg border px-3 py-2 ${colors[node.kind] ?? colors.deployment}`}>
                                    <p className="text-[9px] font-semibold tracking-wider text-slate-500 uppercase">
                                        {node.kind === 'deployment' ? 'Proposed deployment' : node.kind}
                                    </p>
                                    <p className="mt-0.5 text-xs font-medium break-words text-slate-700 dark:text-slate-200">{node.label}</p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </div>
            ))}
        </div>
    );
}
