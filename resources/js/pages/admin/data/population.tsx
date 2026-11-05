import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import Heading from '@/components/heading';
import { Label } from '@/components/ui/label';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface FigureRow {
    id: number;
    area_level: string;
    area_ref: string;
    area_name: string | null;
    province: string | null;
    population: number | null;
    original_population: number | null;
    match_status: string | null;
    census_year: number | null;
    vintage: string;
    is_corrected: boolean;
    edited_at: string | null;
    editor: string | null;
}

interface VintageOption {
    vintage: string;
    label: string | null;
    census_year: number | null;
    is_current: boolean;
}

interface PopulationPageProps {
    figures: {
        data: FigureRow[];
        links?: { url: string | null; label: string }[];
        from: number | null;
        to: number | null;
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: { vintage: string; level: string; status: string; q: string };
    vintages: VintageOption[];
    activeVintage: string | null;
    levels: { value: string; label: string }[];
    statuses: string[];
    summary: { total: number; matched: number; withoutPopulation: number; corrected: number };
    flash?: { success?: string };
}

export default function PopulationManager({
    figures,
    filters,
    vintages,
    activeVintage,
    levels,
    statuses,
    summary,
    flash,
}: PopulationPageProps) {
    const [editing, setEditing] = useState<number | null>(null);

    const applyFilter = (key: string, value: string) => {
        router.get(
            '/admin/data/population',
            { ...filters, [key]: value },
            { preserveState: true, replace: true },
        );
    };

    const save = (id: number, population: string) => {
        router.patch(
            `/admin/data/population/${id}`,
            { population: population === '' ? null : Number(population) },
            {
                preserveScroll: true,
                onSuccess: () => setEditing(null),
            },
        );
    };

    const selectClass =
        'border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring';

    return (
        <AppSidebarLayoutAdmin breadcrumbs={[{ title: 'Reference Data', href: '/admin/data/population' }, { title: 'Population', href: '/admin/data/population' }]}>
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Population Figures"
                    description="Browse and correct population figures. Corrections retain the previous value."
                />

                {flash?.success && (
                    <p className="rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-950/30">
                        {flash.success}
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-4">
                    <Stat label="Total rows" value={summary.total} />
                    <Stat label="Matched" value={summary.matched} />
                    <Stat label="No population" value={summary.withoutPopulation} tone="amber" />
                    <Stat label="Corrected" value={summary.corrected} />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="f-vintage">Vintage</Label>
                            <select
                                id="f-vintage"
                                className={selectClass}
                                value={filters.vintage}
                                onChange={(e) => applyFilter('vintage', e.target.value)}
                            >
                                <option value="">All vintages</option>
                                {vintages.map((v) => (
                                    <option key={v.vintage} value={v.vintage}>
                                        {v.vintage}
                                        {v.is_current ? ' (current)' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="f-level">Level</Label>
                            <select
                                id="f-level"
                                className={selectClass}
                                value={filters.level}
                                onChange={(e) => applyFilter('level', e.target.value)}
                            >
                                <option value="">All levels</option>
                                {levels.map((l) => (
                                    <option key={l.value} value={l.value}>
                                        {l.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="f-status">Match status</Label>
                            <select
                                id="f-status"
                                className={selectClass}
                                value={filters.status}
                                onChange={(e) => applyFilter('status', e.target.value)}
                            >
                                <option value="">Any status</option>
                                {statuses.map((s) => (
                                    <option key={s} value={s}>
                                        {s.replace(/_/g, ' ')}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="f-q">Search name or GADM id</Label>
                            <input
                                id="f-q"
                                className={selectClass}
                                defaultValue={filters.q}
                                placeholder="Quezon City or PHL.47.14_1"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') applyFilter('q', (e.target as HTMLInputElement).value);
                                }}
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {figures.from ?? 0}–{figures.to ?? 0} of {figures.total}
                            {activeVintage && (
                                <span className="text-muted-foreground ml-2 text-xs font-normal">
                                    active vintage: {activeVintage}
                                </span>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="p-2">Area</th>
                                    <th className="p-2">Level</th>
                                    <th className="p-2">Population</th>
                                    <th className="p-2">Status</th>
                                    <th className="p-2">Vintage</th>
                                    <th className="p-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {figures.data.map((row) => (
                                    <tr key={row.id} className="border-b last:border-0">
                                        <td className="p-2">
                                            <div className="font-medium">{row.area_name ?? row.area_ref}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {row.area_ref}
                                                {row.province ? ` · ${row.province}` : ''}
                                            </div>
                                        </td>
                                        <td className="p-2">{row.area_level}</td>
                                        <td className="p-2">
                                            {editing === row.id ? (
                                                <EditCell
                                                    value={row.population}
                                                    onSave={(v) => save(row.id, v)}
                                                    onCancel={() => setEditing(null)}
                                                />
                                            ) : (
                                                <span className="font-mono">
                                                    {row.population === null ? '—' : row.population.toLocaleString()}
                                                </span>
                                            )}
                                            {row.is_corrected && (
                                                <div className="text-muted-foreground text-xs">
                                                    was {row.original_population?.toLocaleString() ?? '—'}
                                                    {row.editor ? ` · ${row.editor}` : ''}
                                                </div>
                                            )}
                                        </td>
                                        <td className="p-2">
                                            <StatusBadge status={row.match_status} corrected={row.is_corrected} />
                                        </td>
                                        <td className="p-2 text-xs">{row.vintage}</td>
                                        <td className="p-2 text-right">
                                            {editing !== row.id && (
                                                <Button size="sm" variant="ghost" onClick={() => setEditing(row.id)}>
                                                    Correct
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {figures.last_page > 1 && (
                    <div className="flex gap-2">
                        {figures.links?.map((link) =>
                            link.url ? (
                                <Button key={link.label} size="sm" variant="outline" onClick={() => router.get(link.url!, {}, { preserveState: true })}>
                                    {link.label}
                                </Button>
                            ) : null,
                        )}
                    </div>
                )}
            </div>
        </AppSidebarLayoutAdmin>
    );
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: 'amber' }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-muted-foreground text-xs tracking-wide uppercase">{label}</div>
                <div className={`text-2xl font-semibold ${tone === 'amber' && value > 0 ? 'text-amber-600' : ''}`}>
                    {value.toLocaleString()}
                </div>
            </CardContent>
        </Card>
    );
}

function StatusBadge({ status, corrected }: { status: string | null; corrected: boolean }) {
    if (corrected) return <Badge variant="outline">Corrected</Badge>;
    if (status === 'matched') return <Badge className="bg-emerald-600">Matched</Badge>;
    return <Badge className="bg-amber-600">{status?.replace(/_/g, ' ') ?? 'unknown'}</Badge>;
}

function EditCell({
    value,
    onSave,
    onCancel,
}: {
    value: number | null;
    onSave: (value: string) => void;
    onCancel: () => void;
}) {
    const [draft, setDraft] = useState(value === null ? '' : String(value));

    return (
        <div className="flex items-center gap-1">
            <input
                className="border-input h-8 w-24 rounded-md border bg-transparent px-2 text-sm"
                value={draft}
                autoFocus
                onChange={(e) => setDraft(e.target.value)}
            />
            <Button size="sm" onClick={() => onSave(draft)}>
                Save
            </Button>
            <Button size="sm" variant="ghost" onClick={onCancel}>
                Cancel
            </Button>
        </div>
    );
}