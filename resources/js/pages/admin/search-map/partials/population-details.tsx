import DataBars from '@/components/data-bars';
import { Users } from 'lucide-react';
import { count } from '../resource-presentation';
import type { ProvinceResourceData } from '../use-province-resources';
import { SummarySection } from './summary-ui';

export default function PopulationDetails({ data }: { data: ProvinceResourceData }) {
    const { rows, year, level } = data.population_breakdown;
    const sorted = [...rows].sort((a, b) => (b.value ?? -1) - (a.value ?? -1));
    const measured = rows.filter((row) => row.value !== null);
    return (
        <SummarySection
            title="Population figures"
            icon={Users}
            description={`Active collection year: ${year ?? 'not configured'}. ${measured.length} of ${rows.length} locations have a figure.`}
        >
            <DataBars
                title={level === 'municipality' ? 'Most populous cities / municipalities' : 'Barangay population'}
                rows={sorted
                    .filter((row) => row.value !== null)
                    .slice(0, 10)
                    .map((row) => ({ label: row.name, value: row.value }))}
            />
            <details className="border-t pt-3">
                <summary className="cursor-pointer text-xs font-medium">All population figures ({rows.length})</summary>
                <table className="mt-3 w-full text-left text-xs">
                    <caption className="sr-only">Population by location for {year ?? 'unconfigured year'}</caption>
                    <thead>
                        <tr className="border-b">
                            <th scope="col" className="py-2">
                                Location
                            </th>
                            <th scope="col" className="py-2 text-right">
                                Residents
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {sorted.map((row) => (
                            <tr key={row.id} className="border-b border-slate-100">
                                <th scope="row" className="py-2 font-normal">
                                    {row.name}
                                </th>
                                <td className="py-2 text-right tabular-nums">{row.value === null ? 'Unavailable' : count.format(row.value)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </details>
            <p className="text-muted-foreground text-[10px]">
                PSA figures use verified PSGC links and the newest geographic release. Child figures may be incomplete and may not sum to the
                published parent total.
            </p>
        </SummarySection>
    );
}
