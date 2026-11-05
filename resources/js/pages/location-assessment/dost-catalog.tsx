import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Search } from 'lucide-react';
import { useState } from 'react';
import SourceBasisModal from './source-basis-modal';
import type { DostInterventionItem, LocalDostProjectItem } from './types';

export function LocalDostProjectsSection({ cityName, data }: { cityName: string; data?: any }) {
    if (!data) {
        return <p className="text-muted-foreground text-sm">No past or ongoing DOST project records mention {cityName}.</p>;
    }

    const projects: LocalDostProjectItem[] = Array.isArray(data?.projects) ? data.projects : [];
    const totalMatched = Number(data?.total_matched_projects ?? projects.length ?? 0);

    return (
        <section className="flex flex-col gap-3">
            <p className="text-muted-foreground text-xs">
                {totalMatched} past and ongoing project records mention this place. They show local experience, not current offers.
            </p>

            {projects.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground p-5 text-sm">
                        No location-matched project rows found in <code>dost_projects</code> for {cityName}.
                    </CardContent>
                </Card>
            ) : (
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left text-xs">
                                <thead>
                                    <tr className="bg-muted/40 text-muted-foreground border-b tracking-wider uppercase">
                                        <th className="px-4 py-2.5 font-medium">Agency</th>
                                        <th className="px-4 py-2.5 font-medium">Program &amp; Project Title</th>
                                        <th className="px-4 py-2.5 font-medium">Location</th>
                                        <th className="px-4 py-2.5 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {projects.slice(0, 15).map((proj, idx) => (
                                        <tr key={proj.id ?? idx} className="hover:bg-muted/30">
                                            <td className="px-4 py-2.5 font-semibold">{proj.agency ?? '—'}</td>
                                            <td className="px-4 py-2.5">
                                                <div className="font-medium">{proj.project_title || 'Untitled Project'}</div>
                                                {proj.program && <div className="text-muted-foreground text-[11px]">{proj.program}</div>}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5">{proj.location ?? '—'}</td>
                                            <td className="px-4 py-2.5">
                                                <Badge variant="outline">{proj.normalized_status || proj.raw_status || 'Recorded'}</Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            )}
        </section>
    );
}

export function DostTechnologiesCatalogSection({
    dostInterventions,
    catalogTechnologies,
}: {
    dostInterventions?: DostInterventionItem[];
    catalogTechnologies?: any[];
}) {
    const [search, setSearch] = useState('');

    const rawList: any[] =
        Array.isArray(catalogTechnologies) && catalogTechnologies.length > 0
            ? catalogTechnologies
            : Array.isArray(dostInterventions) && dostInterventions.length > 0
              ? dostInterventions
              : [];

    const filtered = rawList.filter((tech: any) => {
        if (!search.trim()) return true;
        const q = search.toLowerCase();
        const name = String(tech.name || '').toLowerCase();
        const agency = String(tech.agency || '').toLowerCase();
        const sector = String(tech.sector || '').toLowerCase();
        const mechanism = String(tech.mechanism || tech.description || tech.outcome || '').toLowerCase();
        return name.includes(q) || agency.includes(q) || sector.includes(q) || mechanism.includes(q);
    });

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h2 className="text-base font-semibold tracking-tight text-slate-900 dark:text-slate-100">DOST technologies &amp; STI programs</h2>
                <div className="relative w-full sm:w-64">
                    <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-3.5" />
                    <Input
                        placeholder="Search technology, sector, agency…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="h-8 pl-8 text-xs"
                    />
                </div>
            </div>

            {filtered.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground p-6 text-center text-sm">
                        {search.trim()
                            ? `No DOST technologies match "${search}". Try searching another keyword or agency.`
                            : 'No specific DOST technologies catalog entries mapped to this locality yet.'}
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {filtered.map((item: any, idx: number) => (
                        <Card key={item.id ?? idx} className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                            <CardHeader className="pb-2">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="text-sm font-semibold text-slate-900 dark:text-slate-100">{item.name || 'DOST Technology'}</div>
                                    <Badge variant="outline" className="text-[10px]">
                                        {item.agency || 'DOST'}
                                    </Badge>
                                </div>
                                {item.code && <div className="text-muted-foreground font-mono text-[11px]">{item.code}</div>}
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-xs">
                                <SourceBasisModal title={`Source & basis: ${item.name || 'DOST catalog record'}`}>
                                    <p className="mt-2">
                                        {item.source_catalog || 'DOST reference catalog'}. Match against a local need before proposing deployment.
                                    </p>
                                </SourceBasisModal>
                                {item.sector && (
                                    <Badge variant="secondary" className="w-fit text-[10px]">
                                        {item.sector}
                                    </Badge>
                                )}
                                <p className="text-muted-foreground line-clamp-3 text-[11px] leading-relaxed">
                                    {item.mechanism || item.outcome || item.description || 'Description unavailable.'}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}
