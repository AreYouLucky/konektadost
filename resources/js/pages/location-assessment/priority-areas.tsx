import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { barangayPriorityExplanation } from '@/lib/assessment-report';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import SourceBasisModal from './source-basis-modal';
import type { BarangayAssessmentSummary, BarangayItem } from './types';

export function BarangayPrioritySection({
    summary,
    barangays,
    populationYear,
}: {
    cityName?: string;
    summary: BarangayAssessmentSummary;
    barangays: BarangayItem[];
    populationYear: number | null;
}) {
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<'all' | 'high' | 'zero_facilities' | 'urban' | 'rural'>('all');
    const [sortBy, setSortBy] = useState<'priority_score_desc' | 'population_desc' | 'population_asc' | 'density_desc' | 'name_asc'>(
        'priority_score_desc',
    );
    const [visibleLimit, setVisibleLimit] = useState(20);

    const maxShare = useMemo(() => Math.max(...barangays.map((b) => b.population_share ?? 0), 1), [barangays]);

    const filteredBarangays = useMemo(() => {
        const query = search.trim().toLowerCase();

        const list = barangays.filter((item) => {
            if (query !== '' && !item.name.toLowerCase().includes(query) && !item.psg_code.toLowerCase().includes(query)) {
                return false;
            }
            if (filter === 'high') {
                return item.priority_level === 'high';
            }
            if (filter === 'zero_facilities') {
                return item.total_facilities_count === 0;
            }
            if (filter === 'urban') {
                return item.urban_rural === 'Urban';
            }
            if (filter === 'rural') {
                return item.urban_rural === 'Rural';
            }
            return true;
        });

        return [...list].sort((a, b) => {
            if (sortBy === 'priority_score_desc') {
                return (b.priority_score ?? -1) - (a.priority_score ?? -1) || (b.population ?? -1) - (a.population ?? -1);
            }
            if (sortBy === 'population_desc') {
                return (b.population ?? -1) - (a.population ?? -1) || a.name.localeCompare(b.name);
            }
            if (sortBy === 'population_asc') {
                return (a.population ?? Number.MAX_SAFE_INTEGER) - (b.population ?? Number.MAX_SAFE_INTEGER) || a.name.localeCompare(b.name);
            }
            if (sortBy === 'density_desc') {
                return (b.density ?? -1) - (a.density ?? -1) || (b.population ?? -1) - (a.population ?? -1);
            }
            return a.name.localeCompare(b.name);
        });
    }, [barangays, search, filter, sortBy]);

    const visibleBarangays = filteredBarangays.slice(0, visibleLimit);

    return (
        <section className="flex flex-col gap-4">
            <Card>
                <CardHeader className="flex flex-col flex-wrap gap-4 pb-4 xl:flex-row xl:items-center xl:justify-between">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" size="sm" variant={filter === 'all' ? 'default' : 'outline'} onClick={() => setFilter('all')}>
                            All ({barangays.length})
                        </Button>
                        <Button type="button" size="sm" variant={filter === 'high' ? 'default' : 'outline'} onClick={() => setFilter('high')}>
                            Underserved · High Pop ({summary.high_priority_count})
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={filter === 'zero_facilities' ? 'default' : 'outline'}
                            onClick={() => setFilter('zero_facilities')}
                        >
                            0 Facilities ({summary.underserved_barangays_count})
                        </Button>
                        {summary.urban_count > 0 && (
                            <Button type="button" size="sm" variant={filter === 'urban' ? 'default' : 'outline'} onClick={() => setFilter('urban')}>
                                Urban ({summary.urban_count})
                            </Button>
                        )}
                        {summary.rural_count > 0 && (
                            <Button type="button" size="sm" variant={filter === 'rural' ? 'default' : 'outline'} onClick={() => setFilter('rural')}>
                                Rural ({summary.rural_count})
                            </Button>
                        )}
                    </div>

                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                        <div className="relative w-full min-w-0 sm:w-56">
                            <Search className="text-muted-foreground pointer-events-none absolute top-2.5 left-2.5 size-4" />
                            <Input
                                type="search"
                                placeholder="Search barangay…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="h-9 pl-8 text-sm"
                            />
                        </div>

                        <select
                            aria-label="Sort barangays"
                            value={sortBy}
                            onChange={(e) => setSortBy(e.target.value as typeof sortBy)}
                            className="border-input focus-visible:ring-ring h-9 max-w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:ring-2"
                        >
                            <option value="priority_score_desc">Sort: Priority Score (High → Low)</option>
                            <option value="population_desc">Sort: Population (High → Low)</option>
                            <option value="population_asc">Sort: Population (Low → High)</option>
                            <option value="density_desc">Sort: Density (High → Low)</option>
                            <option value="name_asc">Sort: Name (A → Z)</option>
                        </select>
                    </div>
                </CardHeader>

                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr className="bg-muted/40 text-muted-foreground border-b text-xs tracking-wider uppercase">
                                    <th className="px-4 py-3 font-medium">#</th>
                                    <th className="px-4 py-3 font-medium">Barangay</th>
                                    <th className="px-4 py-3 font-medium">Type</th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {populationYear === null ? 'Population' : `Population (${populationYear})`}
                                    </th>
                                    <th className="w-40 px-4 py-3 font-medium">Share of City</th>
                                    <th className="px-4 py-3 text-right font-medium">Density (/km²)</th>
                                    <th className="px-4 py-3 text-center font-medium">Recorded Infrastructure</th>
                                    <th className="px-4 py-3 text-right font-medium">Priority Score</th>
                                    <th className="px-4 py-3 font-medium">Coverage Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {visibleBarangays.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="text-muted-foreground px-4 py-8 text-center text-sm">
                                            No barangays match the current search or filter.
                                        </td>
                                    </tr>
                                ) : (
                                    visibleBarangays.map((brgy, index) => {
                                        const barWidth = brgy.population_share
                                            ? Math.min(100, Math.max(4, (brgy.population_share / maxShare) * 100))
                                            : 0;

                                        return (
                                            <tr key={brgy.psg_code} className="hover:bg-muted/30 transition-colors">
                                                <td className="text-muted-foreground px-4 py-3 font-mono text-xs">{index + 1}</td>
                                                <td className="px-4 py-3">
                                                    <div className="font-medium">{brgy.name}</div>
                                                    <div className="text-muted-foreground font-mono text-xs">{brgy.psg_code}</div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    {brgy.urban_rural ? (
                                                        <Badge variant="secondary" className="text-xs">
                                                            {brgy.urban_rural}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground text-xs">—</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium tabular-nums">
                                                    {brgy.population !== null ? brgy.population.toLocaleString() : 'No data'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {brgy.population_share !== null ? (
                                                        <div className="flex flex-col gap-1">
                                                            <span className="text-xs tabular-nums">{brgy.population_share.toFixed(2)}%</span>
                                                            <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
                                                                <div className="h-full rounded-full bg-[#087dcc]" style={{ width: `${barWidth}%` }} />
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <span className="text-muted-foreground text-xs">—</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {brgy.density !== null ? brgy.density.toLocaleString() : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-center text-xs">
                                                    <span className="inline-flex flex-wrap items-center justify-center gap-1.5">
                                                        <span title="Health facilities">Health: {brgy.health_facilities_count}</span>
                                                        <span className="text-muted-foreground">·</span>
                                                        <span title="Education facilities">Schools: {brgy.schools_count}</span>
                                                        <span className="text-muted-foreground">·</span>
                                                        <span title="Safety / DRR facilities">Safety: {brgy.safety_facilities_count ?? 0}</span>
                                                        <span className="text-muted-foreground">·</span>
                                                        <span title="Telecom cell towers">Towers: {brgy.telecom_sites_count ?? 0}</span>
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono font-semibold tabular-nums">
                                                    {brgy.priority_score !== null && brgy.priority_score !== undefined
                                                        ? `${brgy.priority_score.toFixed(1)} / 100`
                                                        : '—'}
                                                    <SourceBasisModal title={`Priority score: ${brgy.name}`}>
                                                        <p className="mt-2">{barangayPriorityExplanation(brgy)}</p>
                                                        <p className="mt-2">
                                                            The population component combines relative population (70%) and relative density (30%).
                                                            Health and education add their full active weight for zero recorded facilities, 30% of
                                                            that weight for one, and zero for two or more. Road, telecom and safety use active
                                                            recorded-deficit weights. Isolation increases with distance and reaches its maximum weight
                                                            at 6 km. Inactive datasets are excluded and weights redistributed.
                                                        </p>
                                                    </SourceBasisModal>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <PriorityBadge level={brgy.priority_level} reason={brgy.priority_reason} />
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    {filteredBarangays.length > visibleLimit && (
                        <div className="flex items-center justify-between border-t px-4 py-3">
                            <span className="text-muted-foreground text-xs">
                                Showing {visibleBarangays.length} of {filteredBarangays.length} barangays
                            </span>
                            <div className="flex gap-2">
                                <Button type="button" variant="outline" size="sm" onClick={() => setVisibleLimit((prev) => prev + 50)}>
                                    Show 50 more
                                </Button>
                                <Button type="button" variant="ghost" size="sm" onClick={() => setVisibleLimit(filteredBarangays.length)}>
                                    Show all ({filteredBarangays.length})
                                </Button>
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

export function PriorityBadge({ level, reason }: { level: BarangayItem['priority_level']; reason: string }) {
    if (level === 'high') {
        return (
            <Badge className="bg-amber-600" title={reason}>
                Underserved · High Pop
            </Badge>
        );
    }

    if (level === 'moderate') {
        return (
            <Badge variant="outline" className="border-amber-500 text-amber-700 dark:text-amber-400" title={reason}>
                0 Recorded Facilities
            </Badge>
        );
    }

    if (level === 'covered') {
        return (
            <Badge className="bg-[#087dcc]" title={reason}>
                Covered
            </Badge>
        );
    }

    return (
        <Badge variant="secondary" title={reason}>
            Unmeasured
        </Badge>
    );
}
