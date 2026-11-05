import { IndicatorCalculationDetails } from '@/components/assessment-calculation-details';
import { SectorSelect, indicatorSector } from '@/components/assessment-sector-tables';
import { Badge } from '@/components/ui/badge';
import { reportNumber, reportRecord, reportText } from '@/lib/assessment-report';
import { useState } from 'react';
import { format } from './format';
import SourceBasisModal from './source-basis-modal';
import type { AssessmentResult } from './types';

export function UnifiedIndicatorsTable({ results }: { results: AssessmentResult[] }) {
    const [sector, setSector] = useState('all');
    const rows = results
        .filter((row) => sector === 'all' || indicatorSector(row.dimension, row.key) === sector)
        .toSorted((a, b) => indicatorSector(a.dimension, a.key).localeCompare(indicatorSector(b.dimension, b.key)));
    return (
        <div className="min-w-0 space-y-3">
            <SectorSelect name="Computed indicators sector" value={sector} onChange={setSector} />
            <div
                className="max-h-[32rem] overflow-auto rounded-xl border border-slate-200 dark:border-slate-800"
                tabIndex={0}
                role="region"
                aria-label="Scrollable sector indicators"
            >
                <table className="w-full min-w-[740px] border-collapse text-left text-sm">
                    <caption className="sr-only">Computed indicators grouped by sector with dynamic national comparisons</caption>
                    <thead className="sticky top-0 z-10">
                        <tr className="bg-slate-50 text-xs tracking-wider text-slate-500 uppercase dark:bg-slate-900">
                            <th className="px-4 py-3 font-medium">Indicator &amp; Source</th>
                            <th className="px-4 py-3 text-right font-medium">Local Rate</th>
                            <th className="px-4 py-3 text-right font-medium">National Rate</th>
                            <th className="w-48 px-4 py-3 font-medium">Comparison</th>
                            <th className="px-4 py-3 font-medium">Status &amp; Notes</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {rows.map((row) => {
                            const maxVal = Math.max(row.value ?? 0, row.benchmark ?? 0, 1);
                            const localPct = row.value !== null ? Math.min(100, Math.round((row.value / maxVal) * 100)) : 0;
                            const benchPct = row.benchmark !== null ? Math.min(100, Math.round((row.benchmark / maxVal) * 100)) : 0;

                            return (
                                <tr key={row.key} className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-900/40">
                                    <td className="px-4 py-3.5">
                                        <div className="font-semibold text-slate-900 dark:text-slate-100">{row.label}</div>
                                        <div className="text-muted-foreground mt-0.5 text-[11px] capitalize">
                                            {indicatorSector(row.dimension, row.key).replaceAll('_', ' ')}
                                        </div>
                                        <SourceBasisModal title={`Source & basis: ${row.label}`}>
                                            <p className="my-1">{row.description}</p>
                                            Unit: <span className="font-medium">{row.unit}</span> · Source: {row.source}
                                            {row.caveat && <p className="mt-1">{row.caveat}</p>}
                                            {row.basis_note && <p className="mt-1">{row.basis_note}</p>}
                                            {row.reason && <p>{row.reason}</p>}
                                            <IndicatorCalculationDetails
                                                indicatorKey={row.key}
                                                value={row.value}
                                                reference={row.benchmark}
                                                gap={row.gap}
                                                status={row.status}
                                                calculation={row.calculation}
                                            />
                                        </SourceBasisModal>
                                    </td>
                                    <td className="px-4 py-3.5 text-right font-mono text-base font-semibold tabular-nums">
                                        {row.value === null ? (
                                            <span className="text-muted-foreground text-xs font-normal">Unavailable</span>
                                        ) : (
                                            format(row.value)
                                        )}
                                    </td>
                                    <td className="px-4 py-3.5 text-right font-mono text-sm text-slate-600 tabular-nums dark:text-slate-400">
                                        {row.benchmark === null ? (
                                            <span className="text-muted-foreground text-xs">No reference</span>
                                        ) : (
                                            format(row.benchmark)
                                        )}
                                    </td>
                                    <td className="px-4 py-3.5">
                                        {row.value !== null && row.benchmark !== null ? (
                                            <div className="flex flex-col gap-1.5">
                                                <div className="flex items-center justify-between text-xs">
                                                    <span className="text-muted-foreground">Gap</span>
                                                    <span
                                                        className={`font-mono font-semibold ${
                                                            row.is_shortfall
                                                                ? 'text-amber-700 dark:text-amber-400'
                                                                : 'text-[#087dcc] dark:text-blue-300'
                                                        }`}
                                                    >
                                                        {row.gap !== null ? `${row.gap > 0 ? '+' : ''}${row.gap.toFixed(1)}%` : '—'}
                                                    </span>
                                                </div>
                                                <div className="space-y-1">
                                                    <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full" title="Local rate">
                                                        <div
                                                            className={`h-full rounded-full ${row.is_shortfall ? 'bg-amber-500' : 'bg-[#087dcc]'}`}
                                                            style={{ width: `${localPct}%` }}
                                                        />
                                                    </div>
                                                    <div className="bg-muted h-1 w-full overflow-hidden rounded-full" title="National reference rate">
                                                        <div className="h-full rounded-full bg-slate-400" style={{ width: `${benchPct}%` }} />
                                                    </div>
                                                </div>
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground text-xs">—</span>
                                        )}
                                    </td>
                                    <td className="max-w-xs px-4 py-3.5">
                                        <StatusBadge result={row} />
                                    </td>
                                </tr>
                            );
                        })}
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={5} className="text-muted-foreground p-5 text-sm">
                                    No measured indicators for this sector. Review available inventories and data limitations.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export function StatusBadge({ result }: { result: AssessmentResult }) {
    if (result.status === 'no_data') {
        return <Badge variant="secondary">No data</Badge>;
    }

    if (result.status === 'no_benchmark') {
        return <Badge variant="outline">Measured</Badge>;
    }

    if (result.confidence === 'low') {
        return <Badge variant="outline">Low confidence</Badge>;
    }

    if (result.is_shortfall) {
        return <Badge className="bg-amber-600">Review priority</Badge>;
    }

    return <Badge className="bg-[#087dcc]">Within comparison</Badge>;
}

export function RoadAccessibilityCard({ roadAccessibility }: { roadAccessibility?: unknown }) {
    const road = reportRecord(roadAccessibility),
        summary = reportRecord(road.summary);
    const usable = Object.keys(road).length > 0 && road.status !== 'unavailable';
    const segments = usable ? reportNumber(summary.total_road_segments ?? road.total_segments) : null;
    const length = usable ? reportNumber(summary.total_road_length_km ?? road.total_length_km) : null;
    const density = usable ? reportNumber(summary.road_density_km_per_km2 ?? road.road_density_km_per_km2) : null;
    return (
        <section className="min-w-0 space-y-3 rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
            <h3 className="text-sm font-semibold text-[#087dcc]">Road access</h3>
            <dl className="grid grid-cols-3 gap-3 text-xs">
                {[
                    ['Recorded segments', segments],
                    ['Length (km)', length],
                    ['Density (km/km?)', density],
                ].map(([label, value]) => (
                    <div key={String(label)}>
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="mt-1 font-semibold">{format(typeof value === 'number' ? value : null)}</dd>
                    </div>
                ))}
            </dl>
            <p className="text-muted-foreground text-xs">Confirm transport and site access before equipment delivery.</p>
            <SourceBasisModal title="Road inventory basis">
                <p>{reportText(road.reason, 'Imported road inventory; missing records do not establish isolation.')}</p>
                <p>Road density = recorded road length ? land area.</p>
            </SourceBasisModal>
        </section>
    );
}

export function TelecomConnectivityCard({ telecom }: { telecom?: unknown }) {
    const record = reportRecord(telecom),
        summary = reportRecord(record.summary);
    const usable = Object.keys(record).length > 0 && record.status !== 'unavailable';
    const count = usable ? reportNumber(summary.total_sites ?? record.total_sites) : null;
    const fiveG = usable ? reportNumber(summary.sites_with_5g) : null;
    const share = usable ? reportNumber(summary.share_5g_pct) : null;
    return (
        <section className="min-w-0 space-y-3 rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
            <h3 className="text-sm font-semibold text-[#087dcc]">Connectivity inventory</h3>
            <dl className="grid grid-cols-3 gap-3 text-xs">
                {[
                    ['Recorded sites', count],
                    ['Sites listing 5G', fiveG],
                    ['5G share (%)', share],
                ].map(([label, value]) => (
                    <div key={String(label)}>
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="mt-1 font-semibold">{format(typeof value === 'number' ? value : null)}</dd>
                    </div>
                ))}
            </dl>
            <p className="text-muted-foreground text-xs">Recorded towers do not measure signal or internet reliability.</p>
            <SourceBasisModal title="Telecom inventory basis">
                <p>{reportText(record.reason, 'Imported cell-site inventory; confirm local coverage through a site survey.')}</p>
                <p>5G share = sites listing 5G ? recorded sites ? 100. No share is inferred when the denominator is zero.</p>
            </SourceBasisModal>
        </section>
    );
}
