import { Card, CardContent } from '@/components/ui/card';
import { EVIDENCE_STATES, evidenceState, referenceIndex, type EvidenceState, type IndicatorEvidence } from '@/lib/assessment-planning';
import { useId } from 'react';

const surface = 'min-w-0 rounded-2xl border-blue-100 bg-white shadow-[0_4px_20px_-8px_#087dcc24] dark:bg-slate-950';

export function EvidenceVisuals({ results, dimensions }: { results: IndicatorEvidence[]; dimensions: { key: string; label: string }[] }) {
    const counts = Object.fromEntries(
        Object.keys(EVIDENCE_STATES).map((key) => [key, results.filter((row) => evidenceState(row) === key).length]),
    ) as Record<EvidenceState, number>;
    const groups = [...new Set(results.map((row) => row.dimension))];
    let offset = 0;
    return (
        <div className="grid items-stretch gap-4 xl:grid-cols-2">
            <Card className={surface}>
                <CardContent className="p-5">
                    <h3 className="font-semibold text-[#087dcc] dark:text-blue-200">Evidence status</h3>
                    <p className="mt-1 text-xs leading-relaxed text-slate-500">Indicator counts, not an overall service-adequacy score.</p>
                    <div className="mt-5 flex flex-wrap items-center justify-center gap-5">
                        <svg
                            viewBox="0 0 160 160"
                            className="size-40 shrink-0"
                            role="img"
                            aria-label={`${results.length} indicators, grouped by evidence status. Counts are listed alongside.`}
                        >
                            <circle cx="80" cy="80" r="60" fill="none" stroke="#f1f5f9" strokeWidth="16" />
                            {(Object.keys(EVIDENCE_STATES) as EvidenceState[]).map((key) => {
                                const share = results.length ? (counts[key] / results.length) * 100 : 0;
                                const start = offset;
                                offset += share;
                                return share > 0 ? (
                                    <circle
                                        key={key}
                                        cx="80"
                                        cy="80"
                                        r="60"
                                        pathLength="100"
                                        fill="none"
                                        stroke={EVIDENCE_STATES[key].color}
                                        strokeWidth="16"
                                        strokeDasharray={`${share} ${100 - share}`}
                                        strokeDashoffset={-start}
                                        transform="rotate(-90 80 80)"
                                    />
                                ) : null;
                            })}
                            <text x="80" y="78" textAnchor="middle" fill="#087dcc" fontSize="30" fontWeight="600">
                                {results.length}
                            </text>
                            <text x="80" y="99" textAnchor="middle" fill="#64748b" fontSize="11">
                                indicators
                            </text>
                        </svg>
                        <ul className="min-w-0 flex-1 basis-full space-y-3 text-xs sm:basis-0">
                            {(Object.keys(EVIDENCE_STATES) as EvidenceState[]).map((key) => (
                                <li key={key} className="flex items-center gap-2">
                                    <span
                                        aria-hidden="true"
                                        className="size-2.5 shrink-0 rounded-full"
                                        style={{ backgroundColor: EVIDENCE_STATES[key].color }}
                                    />
                                    <span className="flex-1">{EVIDENCE_STATES[key].label}</span>
                                    <span className="font-semibold tabular-nums">{counts[key]}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </CardContent>
            </Card>
            <Card className={surface}>
                <CardContent className="p-5">
                    <h3 className="font-semibold text-[#087dcc] dark:text-blue-200">Where to investigate</h3>
                    <p className="mt-1 text-xs leading-relaxed text-slate-500">
                        Evidence status within each assessment theme. Bar segments count indicators; themes have different numbers of indicators.
                    </p>
                    <div className="mt-5 space-y-4">
                        {groups.map((key) => {
                            const rows = results.filter((row) => row.dimension === key);
                            const states = (Object.keys(EVIDENCE_STATES) as EvidenceState[]).map((state) => ({
                                state,
                                total: rows.filter((row) => evidenceState(row) === state).length,
                            }));
                            return (
                                <div key={key}>
                                    <div className="mb-2 flex justify-between gap-3 text-xs">
                                        <span>{dimensions.find((row) => row.key === key)?.label ?? key.replaceAll('_', ' ')}</span>
                                        <span className="shrink-0 font-semibold">{rows.length} indicators</span>
                                    </div>
                                    <div aria-hidden="true" className="flex h-3 overflow-hidden rounded-full bg-slate-100">
                                        {states
                                            .filter((row) => row.total > 0)
                                            .map(({ state, total }) => (
                                                <span
                                                    key={state}
                                                    style={{
                                                        width: `${(total / rows.length) * 100}%`,
                                                        backgroundColor: EVIDENCE_STATES[state].color,
                                                    }}
                                                />
                                            ))}
                                    </div>
                                    <p className="mt-1.5 text-[10px] leading-relaxed text-slate-500">
                                        {states
                                            .filter((row) => row.total > 0)
                                            .map(({ state, total }) => `${total} ${EVIDENCE_STATES[state].label.toLowerCase()}`)
                                            .join(' · ')}
                                    </p>
                                </div>
                            );
                        })}
                        {!groups.length && <p className="text-xs text-slate-500">No indicators are available for this focus.</p>}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

export function ReferenceIndexChart({ results }: { results: IndicatorEvidence[] }) {
    const id = useId();
    const rows = results.flatMap((row) => {
        const index = referenceIndex(row);
        return index === null ? [] : [{ row, index }];
    });
    return (
        <Card className={surface}>
            <CardContent className="p-5">
                <figure aria-labelledby={id}>
                    <figcaption id={id}>
                        <h3 className="font-semibold text-[#087dcc] dark:text-blue-200">Local rate against a reference of 100</h3>
                        <p className="mt-1 text-xs leading-relaxed text-slate-500">
                            100 equals the national rate. Density above 100 indicates pressure; other indicators generally flag rates below 100. Bars
                            stop at 200; labels retain the actual index. This is not a score.
                        </p>
                    </figcaption>
                    <div className="mt-4 space-y-4">
                        {rows.map(({ row, index }) => (
                            <div key={row.key}>
                                <div className="mb-1.5 flex flex-wrap items-baseline justify-between gap-2 text-xs">
                                    <span>{row.label}</span>
                                    <span className="font-semibold tabular-nums">
                                        {index.toLocaleString('en-PH', { maximumFractionDigits: 1 })}
                                        {index > 200 ? ' (beyond scale)' : ''}
                                    </span>
                                </div>
                                <div aria-hidden="true" className="relative h-3 rounded-full bg-slate-100">
                                    <span
                                        className="absolute inset-y-0 left-0 rounded-full"
                                        style={{
                                            width: `${Math.min(200, Math.max(0, index)) / 2}%`,
                                            backgroundColor: EVIDENCE_STATES[evidenceState(row)].color,
                                        }}
                                    />
                                    <span className="absolute -top-1 -bottom-1 left-1/2 border-l-2 border-dashed border-slate-500" />
                                </div>
                            </div>
                        ))}
                        {rows.length > 0 ? (
                            <p className="text-[10px] text-slate-500">
                                Dashed line: national reference (100). A zero bar means a recorded zero rate.
                            </p>
                        ) : (
                            <p className="text-xs text-slate-500">
                                A measured value and a positive reference are required. Missing data are not plotted as zero.
                            </p>
                        )}
                    </div>
                </figure>
            </CardContent>
        </Card>
    );
}
