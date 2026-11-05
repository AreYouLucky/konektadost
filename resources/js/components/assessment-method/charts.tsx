import { Button } from '@/components/ui/button';
import { explainNumber } from '@/lib/assessment-explanations';
import { ArrowDown, ArrowRight, HelpCircle } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import WideModal from './wide-modal';

/** Categorical slots in fixed order (see .viz-palette in app.css). Never cycled. */
export const SERIES = [
    'var(--series-1)',
    'var(--series-2)',
    'var(--series-3)',
    'var(--series-4)',
    'var(--series-5)',
    'var(--series-6)',
    'var(--series-7)',
];

/** "Explain" button that opens a popup about one chart container. */
export function ExplainButton({ title, children }: { title: string; children: ReactNode }) {
    return (
        <WideModal
            size="lg"
            title={title}
            description="What this chart shows and how its numbers were computed."
            trigger={
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 shrink-0 gap-1 px-2 text-xs text-[#087dcc] hover:bg-blue-50 dark:hover:bg-slate-800"
                    aria-label={`Explain: ${title}`}
                >
                    <HelpCircle className="size-3.5" aria-hidden="true" /> Explain
                </Button>
            }
        >
            {children}
        </WideModal>
    );
}

export function ChartCard({
    title,
    hint,
    children,
    className = '',
    explain,
    plain = false,
}: {
    title: string;
    hint?: string;
    children: ReactNode;
    className?: string;
    /** Popup content explaining this chart; adds an "Explain" button to the header. */
    explain?: ReactNode;
    /** No border or padding, for a chart that already sits inside another card. */
    plain?: boolean;
}) {
    const frame = plain ? '' : 'rounded-2xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950';
    return (
        <figure className={`viz-palette min-w-0 ${frame} ${className}`}>
            <figcaption className="mb-3 flex items-start gap-2">
                <span className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold text-slate-800 dark:text-slate-100">{title}</h3>
                    {hint && <p className="text-muted-foreground mt-0.5 text-[11px]">{hint}</p>}
                </span>
                {explain && <ExplainButton title={title}>{explain}</ExplainButton>}
            </figcaption>
            {children}
        </figure>
    );
}

export function Legend({ items }: { items: { label: string; color: string }[] }) {
    return (
        <ul className="mb-3 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-600 dark:text-slate-300">
            {items.map((item) => (
                <li key={item.label} className="flex items-center gap-1.5">
                    <span className="size-2.5 rounded-sm" style={{ backgroundColor: item.color }} aria-hidden="true" />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}

function Tooltip({ children }: { children: ReactNode }) {
    return (
        <span
            role="tooltip"
            className="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1.5 -translate-x-1/2 rounded-md bg-slate-900 px-2 py-1 text-[11px] whitespace-nowrap text-white shadow-lg dark:bg-slate-100 dark:text-slate-900"
        >
            {children}
        </span>
    );
}

export interface StackKey {
    key: string;
    label: string;
    /** Optional fixed color (status/severity); defaults to the categorical slot for its position. */
    color?: string;
}
const keyColor = (key: StackKey, index: number) => key.color ?? SERIES[index % SERIES.length];
export interface StackRow {
    label: string;
    sublabel?: string;
    total: number | null;
    values: Record<string, number | null | undefined>;
}

/** Horizontal stacked bars: one color per component (fixed order), 2px gaps, optional threshold line. */
export function StackedBars({
    keys,
    rows,
    max = 100,
    threshold,
    thresholdLabel,
    unit = 'pts',
}: {
    keys: StackKey[];
    rows: StackRow[];
    max?: number;
    threshold?: number;
    thresholdLabel?: string;
    unit?: string;
}) {
    const [hover, setHover] = useState<string | null>(null);
    if (!rows.length) return <p className="text-muted-foreground text-xs">No scored records to chart.</p>;
    return (
        <div>
            <Legend items={keys.map((key, index) => ({ label: key.label, color: keyColor(key, index) }))} />
            <ul className="space-y-2.5">
                {rows.map((row) => (
                    <li key={row.label} className="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_3.5rem] items-center gap-3">
                        <span className="min-w-0">
                            <span className="block truncate text-xs text-slate-700 dark:text-slate-200" title={row.label}>
                                {row.label}
                            </span>
                            {row.sublabel && <span className="text-muted-foreground block truncate text-[10px]">{row.sublabel}</span>}
                        </span>
                        <span className="relative h-3.5">
                            <span className="absolute inset-0 rounded-full bg-slate-100 dark:bg-slate-800" />
                            <span className="absolute inset-0 flex gap-[2px] overflow-visible">
                                {keys.map((key, index) => {
                                    const value = row.values[key.key] ?? 0;
                                    if (!value || value <= 0) return null;
                                    const id = `${row.label}:${key.key}`;
                                    return (
                                        <span
                                            key={key.key}
                                            className="relative h-full shrink-0 first:rounded-l-full last:rounded-r-full"
                                            style={{ width: `${(value / max) * 100}%`, minWidth: 3, backgroundColor: keyColor(key, index) }}
                                            onMouseEnter={() => setHover(id)}
                                            onMouseLeave={() => setHover(null)}
                                        >
                                            {hover === id && (
                                                <Tooltip>
                                                    {key.label}: +{explainNumber(value)} {unit}
                                                </Tooltip>
                                            )}
                                        </span>
                                    );
                                })}
                            </span>
                            {threshold != null && (
                                <span
                                    className="absolute -inset-y-1 w-0 border-l-2 border-dashed border-slate-500 dark:border-slate-300"
                                    style={{ left: `${(threshold / max) * 100}%` }}
                                    title={thresholdLabel}
                                />
                            )}
                        </span>
                        <span className="text-right text-xs font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                            {row.total == null ? '—' : explainNumber(row.total)}
                        </span>
                    </li>
                ))}
            </ul>
            {threshold != null && (
                <p className="text-muted-foreground mt-2 flex items-center gap-1.5 text-[11px]">
                    <span className="inline-block h-3 border-l-2 border-dashed border-slate-500" aria-hidden="true" /> {thresholdLabel}
                </p>
            )}
        </div>
    );
}

/** Running-total waterfall: each step adds points to the previous total. */
export function Waterfall({
    steps,
    max = 100,
    threshold,
    thresholdLabel,
    finalLabel = 'Final score',
    finalValue,
}: {
    steps: { label: string; value: number; note?: string }[];
    max?: number;
    threshold?: number;
    thresholdLabel?: string;
    finalLabel?: string;
    finalValue: number | null;
}) {
    let running = 0;
    const rows = steps.map((step, index) => {
        const start = running;
        running += step.value;
        return { ...step, start, end: running, color: SERIES[index % SERIES.length] };
    });
    const pct = (value: number) => `${Math.max(0, Math.min(100, (value / max) * 100))}%`;
    return (
        <div className="space-y-1.5">
            {rows.map((row) => (
                <div key={row.label} className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_3rem] items-center gap-3">
                    <span className="truncate text-xs text-slate-700 dark:text-slate-200" title={row.note ?? row.label}>
                        {row.label}
                    </span>
                    <span className="relative h-5 rounded bg-slate-50 dark:bg-slate-900">
                        {row.value > 0 && (
                            <span
                                className="absolute inset-y-0.5 rounded"
                                style={{ left: pct(row.start), width: `calc(${pct(row.end)} - ${pct(row.start)})`, backgroundColor: row.color }}
                            />
                        )}
                        {threshold != null && (
                            <span className="absolute inset-y-0 border-l-2 border-dashed border-slate-400" style={{ left: pct(threshold) }} />
                        )}
                    </span>
                    <span className="text-right font-mono text-xs text-slate-700 tabular-nums dark:text-slate-200">
                        {row.value > 0 ? `+${explainNumber(row.value)}` : '0'}
                    </span>
                </div>
            ))}
            <div className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_3rem] items-center gap-3 border-t border-slate-200 pt-2 dark:border-slate-800">
                <span className="text-xs font-semibold text-slate-900 dark:text-slate-50">{finalLabel}</span>
                <span className="relative h-5 rounded bg-slate-100 dark:bg-slate-800">
                    {finalValue != null && (
                        <span className="absolute inset-y-0.5 left-0 rounded bg-slate-800 dark:bg-slate-200" style={{ width: pct(finalValue) }} />
                    )}
                    {threshold != null && (
                        <span className="absolute inset-y-0 border-l-2 border-dashed border-amber-500" style={{ left: pct(threshold) }} />
                    )}
                </span>
                <span className="text-right font-mono text-xs font-semibold tabular-nums">{explainNumber(finalValue)}</span>
            </div>
            {threshold != null && (
                <p className="text-muted-foreground flex items-center gap-1.5 pt-1 text-[11px]">
                    <span className="inline-block h-3 border-l-2 border-dashed border-amber-500" aria-hidden="true" /> {thresholdLabel}
                </p>
            )}
        </div>
    );
}

const BANDS = [
    { label: 'Low', from: 0, to: 25, color: '#fde68a' },
    { label: 'Moderate', from: 25, to: 50, color: '#fbbf24' },
    { label: 'High', from: 50, to: 75, color: '#f59e0b' },
    { label: 'Critical', from: 75, to: 100, color: '#b45309' },
];

/** Severity 0–100 scale with bands and a marker for the computed value. */
export function SeverityScale({ value }: { value: number | null }) {
    return (
        <div className="pt-6">
            <div className="relative flex h-4 gap-[2px]">
                {BANDS.map((band) => (
                    <span
                        key={band.label}
                        className="h-full flex-1 first:rounded-l-full last:rounded-r-full"
                        style={{ backgroundColor: band.color }}
                    />
                ))}
                {value != null && (
                    <span className="absolute -top-6 flex -translate-x-1/2 flex-col items-center" style={{ left: `${Math.min(100, value)}%` }}>
                        <span className="rounded bg-slate-900 px-1.5 text-[10px] font-semibold text-white tabular-nums dark:bg-white dark:text-slate-900">
                            {explainNumber(value)}
                        </span>
                        <span className="h-6 w-0.5 bg-slate-900 dark:bg-white" />
                    </span>
                )}
            </div>
            <div className="mt-1 grid grid-cols-4 text-center text-[10px] text-slate-500">
                {BANDS.map((band) => (
                    <span key={band.label}>
                        {band.label}
                        <span className="block tabular-nums">
                            {band.from}–{band.to}
                        </span>
                    </span>
                ))}
            </div>
        </div>
    );
}

export const SEVERITY_COLORS: Record<string, string> = { low: '#fde68a', moderate: '#fbbf24', high: '#f59e0b', critical: '#b45309' };

export interface FlowLink {
    from: string;
    to: string;
    weight: number;
}

/** Two-column link diagram: needs (left) → technologies (right); line thickness = relevance. */
export function BipartiteFlow({
    left,
    right,
    links,
    leftTitle,
    rightTitle,
}: {
    left: { id: string; label: string; badge?: string }[];
    right: { id: string; label: string; badge?: string }[];
    links: FlowLink[];
    leftTitle: string;
    rightTitle: string;
}) {
    const [focus, setFocus] = useState<string | null>(null);
    const rowHeight = 40;
    const height = Math.max(left.length, right.length) * rowHeight + 30;
    const width = 640;
    const leftX = 230;
    const rightX = 410;
    const leftY = (index: number) => 30 + index * rowHeight + rowHeight / 2 + ((Math.max(left.length, right.length) - left.length) * rowHeight) / 2;
    const rightY = (index: number) => 30 + index * rowHeight + rowHeight / 2 + ((Math.max(left.length, right.length) - right.length) * rowHeight) / 2;
    const leftIndex = new Map(left.map((node, index) => [node.id, index]));
    const rightIndex = new Map(right.map((node, index) => [node.id, index]));
    const active = (link: FlowLink) => focus === null || focus === link.from || focus === link.to;
    const trim = (text: string, size: number) => (text.length > size ? `${text.slice(0, size - 1)}…` : text);

    if (!links.length) return <p className="text-muted-foreground text-xs">No need has a matched technology yet.</p>;

    return (
        <div className="overflow-x-auto">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="h-auto w-full min-w-[560px]"
                role="img"
                aria-label={`${leftTitle} linked to ${rightTitle}`}
            >
                <text x={leftX} y={14} textAnchor="end" className="fill-slate-500 text-[11px] font-semibold uppercase">
                    {leftTitle}
                </text>
                <text x={rightX} y={14} className="fill-slate-500 text-[11px] font-semibold uppercase">
                    {rightTitle}
                </text>
                {links.map((link) => {
                    const a = leftIndex.get(link.from);
                    const b = rightIndex.get(link.to);
                    if (a === undefined || b === undefined) return null;
                    const y1 = leftY(a);
                    const y2 = rightY(b);
                    const mid = (leftX + rightX) / 2;
                    return (
                        <path
                            key={`${link.from}->${link.to}`}
                            d={`M ${leftX + 8} ${y1} C ${mid} ${y1}, ${mid} ${y2}, ${rightX - 8} ${y2}`}
                            fill="none"
                            stroke="var(--series-1)"
                            strokeWidth={1 + (link.weight / 100) * 5}
                            strokeOpacity={active(link) ? 0.75 : 0.12}
                            strokeLinecap="round"
                        >
                            <title>{`Relevance ${explainNumber(link.weight)}/100`}</title>
                        </path>
                    );
                })}
                {left.map((node, index) => (
                    <g
                        key={node.id}
                        onMouseEnter={() => setFocus(node.id)}
                        onMouseLeave={() => setFocus(null)}
                        className="cursor-default"
                        opacity={focus === null || focus === node.id || links.some((l) => l.from === node.id && l.to === focus) ? 1 : 0.4}
                    >
                        <rect x={0} y={leftY(index) - 16} width={leftX + 8} height={32} fill="transparent" />
                        <circle cx={leftX} cy={leftY(index)} r={6} fill="var(--series-2)" stroke="var(--viz-surface)" strokeWidth={2} />
                        <text x={leftX - 14} y={leftY(index) - 2} textAnchor="end" className="fill-slate-800 text-[12px] dark:fill-slate-100">
                            {trim(node.label, 30)}
                        </text>
                        {node.badge && (
                            <text x={leftX - 14} y={leftY(index) + 12} textAnchor="end" className="fill-slate-500 text-[10px]">
                                {node.badge}
                            </text>
                        )}
                        <title>{node.label}</title>
                    </g>
                ))}
                {right.map((node, index) => (
                    <g
                        key={node.id}
                        onMouseEnter={() => setFocus(node.id)}
                        onMouseLeave={() => setFocus(null)}
                        opacity={focus === null || focus === node.id || links.some((l) => l.to === node.id && l.from === focus) ? 1 : 0.4}
                    >
                        <rect x={rightX - 8} y={rightY(index) - 16} width={width - rightX + 8} height={32} fill="transparent" />
                        <circle cx={rightX} cy={rightY(index)} r={6} fill="var(--series-1)" stroke="var(--viz-surface)" strokeWidth={2} />
                        <text x={rightX + 14} y={rightY(index) - 2} className="fill-slate-800 text-[12px] dark:fill-slate-100">
                            {trim(node.label, 32)}
                        </text>
                        {node.badge && (
                            <text x={rightX + 14} y={rightY(index) + 12} className="fill-slate-500 text-[10px]">
                                {node.badge}
                            </text>
                        )}
                        <title>{node.label}</title>
                    </g>
                ))}
            </svg>
            <p className="text-muted-foreground mt-1 text-[11px]">Thicker line = higher relevance score. Hover a node to isolate its links.</p>
        </div>
    );
}

export interface ScatterPoint {
    id: string;
    label: string;
    x: number;
    y: number;
    group: string;
}

/** Scatter with at most three groups (all-pairs validated slots 1–3). */
export function Scatter({
    points,
    groups,
    xLabel,
    yLabel,
    yMax = 100,
}: {
    points: ScatterPoint[];
    groups: { key: string; label: string }[];
    xLabel: string;
    yLabel: string;
    yMax?: number;
}) {
    const [hover, setHover] = useState<ScatterPoint | null>(null);
    const width = 600;
    const height = 260;
    const pad = { left: 44, right: 16, top: 12, bottom: 34 };
    const xMax = useMemo(() => Math.max(1, ...points.map((point) => point.x)), [points]);
    const sx = (x: number) => pad.left + (x / xMax) * (width - pad.left - pad.right);
    const sy = (y: number) => height - pad.bottom - (y / yMax) * (height - pad.top - pad.bottom);
    const color = (group: string) =>
        SERIES[
            Math.max(
                0,
                groups.findIndex((item) => item.key === group),
            )
        ];
    const yTicks = [0, 25, 50, 75, 100].filter((tick) => tick <= yMax);
    const xTicks = [0, 0.25, 0.5, 0.75, 1].map((share) => Math.round(xMax * share));

    if (!points.length) return <p className="text-muted-foreground text-xs">No scored barangays to plot.</p>;
    return (
        <div className="relative">
            <Legend items={groups.map((group, index) => ({ label: group.label, color: SERIES[index] }))} />
            <svg viewBox={`0 0 ${width} ${height}`} className="h-auto w-full" role="img" aria-label={`${yLabel} by ${xLabel}`}>
                {yTicks.map((tick) => (
                    <g key={tick}>
                        <line x1={pad.left} x2={width - pad.right} y1={sy(tick)} y2={sy(tick)} stroke="var(--viz-grid)" />
                        <text x={pad.left - 6} y={sy(tick) + 3} textAnchor="end" className="fill-slate-500 text-[10px]">
                            {tick}
                        </text>
                    </g>
                ))}
                {xTicks.map((tick) => (
                    <text key={tick} x={sx(tick)} y={height - pad.bottom + 14} textAnchor="middle" className="fill-slate-500 text-[10px]">
                        {tick.toLocaleString('en-PH', { notation: 'compact' })}
                    </text>
                ))}
                <text x={(width + pad.left) / 2} y={height - 4} textAnchor="middle" className="fill-slate-500 text-[11px]">
                    {xLabel}
                </text>
                <text x={12} y={height / 2} textAnchor="middle" transform={`rotate(-90 12 ${height / 2})`} className="fill-slate-500 text-[11px]">
                    {yLabel}
                </text>
                {points.map((point) => (
                    <g key={point.id} onMouseEnter={() => setHover(point)} onMouseLeave={() => setHover(null)}>
                        <circle cx={sx(point.x)} cy={sy(point.y)} r={10} fill="transparent" />
                        <circle
                            cx={sx(point.x)}
                            cy={sy(point.y)}
                            r={hover?.id === point.id ? 6 : 4.5}
                            fill={color(point.group)}
                            stroke="var(--viz-surface)"
                            strokeWidth={1.5}
                        />
                    </g>
                ))}
            </svg>
            {hover && (
                <div
                    role="tooltip"
                    className="pointer-events-none absolute z-20 rounded-md bg-slate-900 px-2 py-1 text-[11px] text-white shadow-lg dark:bg-slate-100 dark:text-slate-900"
                    style={{ left: `${(sx(hover.x) / width) * 100}%`, top: `${(sy(hover.y) / height) * 100}%`, transform: 'translate(-50%, -130%)' }}
                >
                    <strong>{hover.label}</strong> · {hover.x.toLocaleString('en-PH')} people · {explainNumber(hover.y)}/100
                </div>
            )}
        </div>
    );
}

/** Horizontal flowchart (stacks vertically on small screens). */
export function FlowSteps({ steps, activeIndex }: { steps: { title: string; value: string; caption?: string }[]; activeIndex?: number }) {
    return (
        <ol className="flex flex-col items-stretch gap-1 md:flex-row md:items-center">
            {steps.map((step, index) => (
                <li key={step.title} className="flex flex-col items-center gap-1 md:flex-1 md:flex-row">
                    <div
                        className={`w-full min-w-0 rounded-xl border p-2.5 text-center ${index === activeIndex ? 'border-[#087dcc] bg-[#087dcc] text-white' : 'border-sky-100 bg-white text-slate-800 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100'}`}
                    >
                        <p className={`text-[10px] font-semibold uppercase ${index === activeIndex ? 'text-blue-100' : 'text-slate-500'}`}>
                            {step.title}
                        </p>
                        <p className="mt-0.5 text-sm font-semibold tabular-nums">{step.value}</p>
                        {step.caption && (
                            <p className={`text-[10px] ${index === activeIndex ? 'text-blue-100' : 'text-slate-500'}`}>{step.caption}</p>
                        )}
                    </div>
                    {index < steps.length - 1 && (
                        <>
                            <ArrowRight className="hidden size-4 shrink-0 text-slate-400 md:block" aria-hidden="true" />
                            <ArrowDown className="size-4 shrink-0 text-slate-400 md:hidden" aria-hidden="true" />
                        </>
                    )}
                </li>
            ))}
        </ol>
    );
}

/** One computation step: number, title, formula and worked example with real values. */
export function ComputeStep({
    index,
    title,
    formula,
    worked,
    note,
}: {
    index: number;
    title: string;
    formula?: string;
    worked?: string | null;
    note?: string;
}) {
    return (
        <li className="relative flex gap-3 pb-4 last:pb-0">
            <span className="absolute top-7 bottom-0 left-3.5 w-px bg-sky-200 last:hidden dark:bg-slate-700" aria-hidden="true" />
            <span className="relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full bg-[#087dcc] text-xs font-semibold text-white">
                {index}
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{title}</p>
                {formula && <p className="mt-0.5 font-mono text-[11px] text-slate-600 dark:text-slate-300">{formula}</p>}
                {worked && (
                    <p className="mt-1 rounded-md bg-sky-50 px-2 py-1 font-mono text-[11px] text-slate-800 dark:bg-slate-900 dark:text-slate-100">
                        {worked}
                    </p>
                )}
                {note && <p className="text-muted-foreground mt-1 text-[11px]">{note}</p>}
            </div>
        </li>
    );
}

/** Two bars on one scale: local value vs national reference. */
export function CompareBars({ local, reference, unit, flagged }: { local: number | null; reference: number | null; unit: string; flagged: boolean }) {
    const max = Math.max(local ?? 0, reference ?? 0, 1e-9);
    const rows = [
        { label: 'This location', value: local, color: flagged ? '#d97706' : 'var(--series-1)' },
        { label: 'National reference', value: reference, color: '#94a3b8' },
    ];
    return (
        <div className="viz-palette space-y-2">
            {rows.map((row) => (
                <div key={row.label} className="grid grid-cols-[7.5rem_minmax(0,1fr)_5rem] items-center gap-3">
                    <span className="text-xs text-slate-600 dark:text-slate-300">{row.label}</span>
                    <span className="h-3 rounded-full bg-slate-100 dark:bg-slate-800">
                        {row.value != null && (
                            <span
                                className="block h-full rounded-full"
                                style={{ width: `${Math.max(1, (row.value / max) * 100)}%`, backgroundColor: row.color }}
                            />
                        )}
                    </span>
                    <span className="text-right font-mono text-xs tabular-nums">{explainNumber(row.value)}</span>
                </div>
            ))}
            <p className="text-muted-foreground text-[10px]">{unit}</p>
        </div>
    );
}
