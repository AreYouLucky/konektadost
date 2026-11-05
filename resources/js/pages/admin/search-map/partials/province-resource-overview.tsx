import DataBars from '@/components/data-bars';
import { Building2, FlaskConical, RadioTower } from 'lucide-react';
import { count, RESOURCE_CATEGORIES, sourceLabel } from '../resource-presentation';
import type { ProvinceResourceData } from '../use-province-resources';
import { SummaryRow, SummarySection } from './summary-ui';

export default function ProvinceResourceOverview({
    data,
    section = 'overview',
}: {
    data: ProvinceResourceData;
    section?: 'overview' | 'facilities' | 'laboratories';
}) {
    const labs = data.records.filter((row) => row.category === 'laboratory');
    const serviceTotal = (key: 'catalog_service_count' | 'source_listed_service_count') => {
        const reported = labs.filter((row) => row.metadata[key] !== null);
        return reported.length ? count.format(reported.reduce((total, row) => total + row.metadata[key]!, 0)) : 'Not recorded';
    };
    const types = new Map<string, number>();
    const typeCategories = new Map<string, string>();
    const sources = new Set<string>();
    for (const record of data.records) {
        sources.add(sourceLabel(record.source));
        if (record.category !== 'laboratory') {
            const name = record.detail ?? 'Unclassified';
            types.set(name, (types.get(name) ?? 0) + 1);
            typeCategories.set(name, record.category);
        }
    }
    return (
        <div className="space-y-4">
            {section === 'overview' && (
                <SummarySection title="Cell sites" icon={RadioTower} description="Recorded inventory within this location">
                    <SummaryRow
                        label="Total cell sites"
                        value={data.telecom_sites.count === null ? 'Unavailable' : count.format(data.telecom_sites.count)}
                    />
                    {data.telecom_sites.status === 'available' && (
                        <>
                            <SummaryRow label="With map coordinates" value={count.format(data.telecom_sites.pinned_count)} />
                            <SummaryRow
                                label="Without usable coordinates"
                                value={count.format(data.telecom_sites.count! - data.telecom_sites.pinned_count)}
                            />
                        </>
                    )}
                    <p className="text-[10px] leading-relaxed text-[#738580]">
                        Orange radio-tower icons show recorded cell sites. Site counts do not measure signal strength or coverage.
                    </p>
                </SummarySection>
            )}
            {section !== 'laboratories' && (
                <SummarySection title="Facilities" icon={Building2} description="Recorded inventory within this location">
                    <SummaryRow
                        label="Total facilities"
                        value={data.facilities.count === null ? 'Unavailable' : count.format(data.facilities.count)}
                    />
                    {data.facilities.status === 'available' && (
                        <>
                            <DataBars
                                title="Facilities by sector"
                                description="Longer bars show more recorded facilities, not better service coverage."
                                rows={Object.entries(RESOURCE_CATEGORIES)
                                    .filter(([key]) => key !== 'laboratory')
                                    .map(([key, item]) => ({ label: item.label, value: data.facilities.by_category[key] ?? 0 }))
                                    .sort((a, b) => b.value - a.value)}
                            />
                            <div className="space-y-2 border-t border-[#edf2eb] pt-3">
                                {section === 'facilities' && (
                                    <DataBars
                                        title="Most recorded facility types"
                                        rows={[...types]
                                            .sort((a, b) => b[1] - a[1])
                                            .slice(0, 8)
                                            .map(([label, value]) => ({ label, value, color: '#287ac5' }))}
                                    />
                                )}
                                <table className="w-full text-left text-xs">
                                    <caption className="py-2 text-left font-semibold">Facility type totals</caption>
                                    <thead>
                                        <tr className="border-b">
                                            <th scope="col" className="py-2">
                                                Type
                                            </th>
                                            <th scope="col" className="text-right">
                                                Total
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {[...types]
                                            .sort((a, b) => b[1] - a[1])
                                            .map(([name, total]) => {
                                                const category =
                                                    RESOURCE_CATEGORIES[typeCategories.get(name) ?? 'facility'] ?? RESOURCE_CATEGORIES.facility;
                                                const Icon = category.icon;
                                                return (
                                                    <tr key={name} className="border-b border-[#edf2eb]">
                                                        <th scope="row" className="py-2 font-normal">
                                                            <span className="flex items-center gap-2">
                                                                <span
                                                                    className={`grid size-7 shrink-0 place-items-center rounded-lg bg-slate-50 ${category.color}`}
                                                                >
                                                                    <Icon size={15} aria-hidden="true" />
                                                                </span>
                                                                {name}
                                                            </span>
                                                        </th>
                                                        <td className="py-2 text-right tabular-nums">{count.format(total)}</td>
                                                    </tr>
                                                );
                                            })}
                                    </tbody>
                                </table>
                            </div>
                            <SummaryRow label="With map coordinates" value={count.format(data.facilities.pinned_count)} />
                        </>
                    )}
                </SummarySection>
            )}
            {section !== 'facilities' && (
                <SummarySection title="Laboratories" icon={FlaskConical} description="Local registered laboratories and their recorded services">
                    <SummaryRow
                        label="Total laboratories"
                        value={data.laboratories.count === null ? 'Unavailable' : count.format(data.laboratories.count)}
                    />
                    {data.laboratories.status === 'available' && (
                        <>
                            <SummaryRow label="Normalized service entries" value={serviceTotal('catalog_service_count')} />
                            <SummaryRow label="Source-listed services" value={serviceTotal('source_listed_service_count')} />
                            <SummaryRow label="With map coordinates" value={count.format(data.laboratories.pinned_count)} />
                            {section === 'laboratories' && (
                                <table className="w-full text-left text-xs">
                                    <caption className="py-2 text-left font-semibold">Laboratory service totals</caption>
                                    <thead>
                                        <tr className="border-b">
                                            <th scope="col" className="py-2">
                                                Laboratory / location
                                            </th>
                                            <th scope="col" className="text-right">
                                                Entries
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {labs.map((lab) => (
                                            <tr key={lab.key} className="border-b align-top">
                                                <th scope="row" className="py-2 pr-2 font-normal">
                                                    {lab.name}
                                                    <span className="block text-[10px] text-[#738580]">
                                                        {lab.municipality ?? 'Location not recorded'}
                                                    </span>
                                                </th>
                                                <td className="py-2 text-right">{lab.metadata.catalog_service_count ?? 'Unavailable'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                            <p className="text-[10px] leading-relaxed text-[#738580]">
                                Service entries may repeat test and method pairs. Source-listed services are a separate catalog total; missing counts
                                are excluded.
                            </p>
                        </>
                    )}
                </SummarySection>
            )}
            <p className="px-1 text-[10px] leading-relaxed text-[#738580]">
                Sources: {[...sources].join('; ') || 'Not recorded'}. Zero means none recorded; inventories do not establish service adequacy.
            </p>
        </div>
    );
}
