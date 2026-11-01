import DataBars from '@/components/data-bars';
import { Cpu, MapPin } from 'lucide-react';
import type { ProvinceResourceData } from '../use-province-resources';
import { SummarySection } from './summary-ui';

export default function TechnologyDetails({ data }: { data: ProvinceResourceData }) {
    const { technologies } = data;
    const sectors = new Map<string, number>();
    for (const item of technologies.records) sectors.set(item.sector ?? 'Unclassified', (sectors.get(item.sector ?? 'Unclassified') ?? 0) + 1);
    return (
        <div className="space-y-4">
            <SummarySection title="DOST technology locations" icon={Cpu} tone="blue" description="Catalog matches for the selected area">
                <p className="text-2xl font-semibold">{technologies.count ?? 'Unavailable'}</p>
                <p className="text-muted-foreground text-xs leading-relaxed">
                    Location names come from deployment-cluster text. Blue diamonds use stored city reference points; they are not exact deployment
                    sites. Barangay results require both city and barangay names in the catalog and do not receive an approximate pin.
                </p>
                {technologies.status === 'available' && (
                    <DataBars
                        title="Technologies by sector"
                        rows={[...sectors].sort((a, b) => b[1] - a[1]).map(([label, value]) => ({ label, value, color: '#2563eb' }))}
                    />
                )}
                {technologies.status === 'unavailable' && (
                    <p className="text-muted-foreground text-xs">The DOST technology catalog is unavailable.</p>
                )}
                {technologies.count === 0 && (
                    <p className="text-muted-foreground text-xs">
                        No catalog location matches were found. This does not establish that no technologies are deployed here.
                    </p>
                )}
            </SummarySection>
            {technologies.records.length > 0 && (
                <SummarySection title="Catalog locations" icon={MapPin} tone="blue">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <caption className="sr-only">DOST technologies and catalog location matches</caption>
                            <thead>
                                <tr className="border-b">
                                    <th scope="col" className="py-2 pr-3">
                                        Technology
                                    </th>
                                    <th scope="col" className="py-2">
                                        Location / agency
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {technologies.records.map((item) => (
                                    <tr key={item.id} className="border-b align-top">
                                        <th scope="row" className="py-3 pr-3 font-medium">
                                            {item.name}
                                            <span className="text-muted-foreground mt-1 block text-[10px] font-normal">
                                                {item.code} · {item.status ?? 'Status not recorded'}
                                            </span>
                                        </th>
                                        <td className="py-3">
                                            {item.locations.join(', ')}
                                            <span className="text-muted-foreground mt-1 block text-[10px]">
                                                {item.agency ?? 'Agency not recorded'}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </SummarySection>
            )}
        </div>
    );
}
