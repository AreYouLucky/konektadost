import DataBars from '@/components/data-bars';
import { ChartNoAxesCombined, LoaderCircle, Package, Wheat } from 'lucide-react';
import { agricultureIcon, agricultureNumber } from '../agriculture-presentation';
import type { AgricultureSummary, useProvinceAgriculture } from '../use-province-agriculture';
import { SummaryRow, SummarySection } from './summary-ui';

function money(amount: string | null) {
    return amount === null ? 'Not recorded' : `₱${agricultureNumber(amount)}`;
}

export function AgricultureSummaryView({ data }: { data: AgricultureSummary }) {
    if (data.status !== 'available')
        return (
            <SummarySection title="Agriculture & livelihood" icon={Wheat}>
                <p className="text-muted-foreground text-xs">
                    {data.status === 'unavailable'
                        ? 'The agriculture dataset is unavailable.'
                        : 'No modeled agriculture estimates are linked to this location.'}
                </p>
            </SummarySection>
        );
    const types = data.categories.flatMap((category) =>
        category.types.map((type) => ({ label: `${category.name} / ${type.name}`, value: type.record_count })),
    );
    return (
        <div className="space-y-4">
            <SummarySection
                title="Agriculture & livelihood"
                icon={Wheat}
                description={`Modeled annual estimates · ${data.model_year ?? 'year not recorded'}`}
            >
                <SummaryRow label="Modeled entries" value={agricultureNumber(data.record_count)} />
                <SummaryRow label="Named commodities" value={agricultureNumber(data.commodity_count)} />
                {data.financials && (
                    <>
                        <DataBars
                            title="Annual financial estimates"
                            description="PHP totals from reported amounts; values are modeled, not verified earnings."
                            rows={(
                                [
                                    ['revenue', 'Revenue'],
                                    ['operating_cost', 'Operating cost'],
                                    ['net', 'Net income'],
                                ] as const
                            ).map(([key, label]) => ({
                                label,
                                value: data.financials![key].total === null ? null : Number(data.financials![key].total),
                                formatted: money(data.financials![key].total),
                                color: key === 'operating_cost' ? '#d97706' : undefined,
                            }))}
                        />
                        <table className="w-full text-left text-xs">
                            <caption className="sr-only">Annual financial totals and reporting coverage</caption>
                            <thead>
                                <tr className="border-b">
                                    <th scope="col" className="py-2">
                                        Annual amount
                                    </th>
                                    <th scope="col" className="py-2 text-right">
                                        PHP / reporting entries
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {(
                                    [
                                        ['revenue', 'Revenue'],
                                        ['operating_cost', 'Operating cost'],
                                        ['net', 'Net income'],
                                    ] as const
                                ).map(([key, label]) => (
                                    <tr key={key} className="border-b">
                                        <th scope="row" className="py-2 font-normal">
                                            {label}
                                        </th>
                                        <td className="py-2 text-right tabular-nums">
                                            {money(data.financials![key].total)}
                                            <span className="text-muted-foreground block text-[10px]">
                                                {data.financials![key].reported_count} / {data.record_count} reported
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </>
                )}
                {data.areas
                    .filter((row) => row.area_sqm !== null)
                    .map((row) => (
                        <SummaryRow
                            key={row.kind ?? 'unknown'}
                            label={`Recorded area · ${row.kind ?? 'unspecified'}`}
                            value={`${agricultureNumber(row.area_sqm)} m²`}
                            note="Summed entries may overlap."
                        />
                    ))}
                <p className="text-muted-foreground text-[10px] leading-relaxed">
                    Modeled estimates, not measured production.{' '}
                    {data.data_source_types.some((source) => /synthetic/i.test(source)) && 'Includes synthetic data. '}Missing values are excluded;
                    zero and negative net estimates are retained. Fisheries quantities describe trading/handling, not catch. Source:{' '}
                    {data.source_tables.join(', ') || 'Not recorded'}.
                </p>
            </SummarySection>
            <SummarySection title="Agriculture composition" icon={ChartNoAxesCombined}>
                <DataBars
                    title="Modeled entries by category"
                    description="Compares the number of model entries, not production volume or economic importance."
                    rows={data.categories.map((row) => ({ label: row.name, value: row.record_count })).sort((a, b) => b.value - a.value)}
                />
                <DataBars
                    title="Most represented agriculture types"
                    description="Top 8 types by number of entries."
                    rows={types.sort((a, b) => b.value - a.value).slice(0, 8)}
                />
            </SummarySection>
            <SummarySection
                title="Commodity totals"
                icon={Package}
                description="Grouped by commodity, variant, type, unit and quantity basis. Scroll sideways for all financial columns."
            >
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[850px] text-left text-xs">
                        <caption className="sr-only">Modeled annual commodity quantities, prices, revenue, operating costs and net income</caption>
                        <thead className="bg-slate-50 text-[#405f54]">
                            <tr>
                                {[
                                    'Commodity / basis',
                                    'Entries',
                                    'Annual quantity',
                                    'Marketable',
                                    'Sold',
                                    'Unit price (PHP)',
                                    'Revenue (PHP/year)',
                                    'Cost (PHP/year)',
                                    'Net (PHP/year)',
                                ].map((label, index) => (
                                    <th key={label} scope="col" className={`p-2 ${index ? 'text-right' : ''}`}>
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {data.commodities.map((row, index) => {
                                const Icon = agricultureIcon(row.category_code, row.type_name);
                                return (
                                    <tr key={index} className="border-t align-top">
                                        <th scope="row" className="min-w-40 p-2 font-medium">
                                            <span className="flex items-center gap-2">
                                                <Icon size={16} aria-hidden="true" className="shrink-0 text-amber-700" />
                                                {[row.name || 'Unnamed', row.variant].filter(Boolean).join(' · ')}
                                            </span>
                                            <span className="text-muted-foreground mt-1 block text-[10px] font-normal">
                                                {row.type_name} · {row.unit ?? 'unit unknown'} · {(row.basis ?? 'basis unknown').replaceAll('_', ' ')}
                                            </span>
                                        </th>
                                        <td className="p-2 text-right">{row.record_count}</td>
                                        {[row.annual_quantity, row.marketable_quantity, row.sold_quantity].map((amount, i) => (
                                            <td key={i} className="p-2 text-right tabular-nums">
                                                {agricultureNumber(amount)}
                                            </td>
                                        ))}
                                        <td className="p-2 text-right tabular-nums">
                                            {row.unit_price.min === null
                                                ? 'Not recorded'
                                                : row.unit_price.min === row.unit_price.max
                                                  ? agricultureNumber(row.unit_price.min)
                                                  : `${agricultureNumber(row.unit_price.min)}–${agricultureNumber(row.unit_price.max)}`}
                                            <span className="text-muted-foreground mt-1 block text-[10px]">
                                                {row.unit_price.reported_count}/{row.record_count} reported
                                            </span>
                                        </td>
                                        {(['revenue', 'operating_cost', 'net'] as const).map((key) => (
                                            <td key={key} className="p-2 text-right tabular-nums">
                                                {agricultureNumber(row.financials[key].total)}
                                                <span className="text-muted-foreground mt-1 block text-[10px]">
                                                    {row.financials[key].reported_count}/{row.record_count} reported
                                                </span>
                                            </td>
                                        ))}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <p className="text-muted-foreground text-[10px]">
                    Unit prices show the recorded range within each group; prices and unlike quantities are never added across units. Full decimal
                    values are retained in the table; bar lengths are approximate.
                </p>
            </SummarySection>
        </div>
    );
}

export default function ProvinceAgricultureCard({ agriculture }: { agriculture: ReturnType<typeof useProvinceAgriculture> }) {
    if (agriculture.loading)
        return (
            <p role="status" className="flex items-center gap-2 text-xs">
                <LoaderCircle size={15} className="animate-spin" /> Loading agriculture details…
            </p>
        );
    if (agriculture.error)
        return (
            <div role="alert" className="text-xs text-amber-900">
                <p>{agriculture.error}</p>
                <button type="button" onClick={agriculture.retry} className="mt-2 underline">
                    Try again
                </button>
            </div>
        );
    return agriculture.data ? <AgricultureSummaryView data={agriculture.data} /> : null;
}
