import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import SourceBasisModal from './source-basis-modal';
import type { FunctionToolsBundle } from './types';

export function DatasetAuditSection({ cityName, audit }: { cityName: string; audit: NonNullable<FunctionToolsBundle['dataset_audit']> }) {
    const datasets = Object.values(audit.datasets);

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <h2 className="text-lg font-semibold tracking-tight">Dataset coverage ({cityName})</h2>
                </div>
            </div>

            <Card>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left text-xs">
                            <thead>
                                <tr className="bg-muted/40 text-muted-foreground border-b tracking-wider uppercase">
                                    <th className="px-4 py-2.5 font-medium">Dataset</th>
                                    <th className="px-4 py-2.5 font-medium">Status</th>
                                    <th className="px-4 py-2.5 text-right font-medium">Local Records</th>
                                    <th className="px-4 py-2.5 text-right font-medium">National Records</th>
                                    <th className="px-4 py-2.5 font-medium">Evidence</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {datasets.map((ds) => (
                                    <tr key={ds.key} className="hover:bg-muted/30">
                                        <td className="px-4 py-2.5">
                                            <div className="font-medium">{ds.label}</div>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <Badge
                                                variant={ds.status === 'available' ? 'default' : ds.status === 'partial' ? 'outline' : 'secondary'}
                                                className={ds.status === 'available' ? 'bg-[#087dcc]' : ''}
                                            >
                                                {ds.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-2.5 text-right font-mono tabular-nums">
                                            {ds.local_records !== null ? ds.local_records.toLocaleString() : '—'}
                                        </td>
                                        <td className="px-4 py-2.5 text-right font-mono tabular-nums">
                                            {ds.national_records !== null ? ds.national_records.toLocaleString() : '—'}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-2.5">
                                            <SourceBasisModal title={`Source & basis: ${ds.label}`}>
                                                <p>Recorded dataset: {ds.table}</p>
                                                <p>Year / reference: {ds.reference_year ?? 'Not recorded'}</p>
                                                <p>{ds.notes}</p>
                                            </SourceBasisModal>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </section>
    );
}
