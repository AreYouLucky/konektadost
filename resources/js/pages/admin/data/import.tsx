import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import Heading from '@/components/heading';
import { Label } from '@/components/ui/label';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { AlertTriangle, CheckCircle2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

interface ImportRow {
    id: number;
    filename: string;
    vintage: string;
    status: string;
    rows_total: number;
    rows_inserted: number;
    rows_updated: number;
    messages: { errors?: Record<string, string[]>; warnings?: string[] } | null;
    imported_by: string | null;
    created_at: string | null;
}

interface PreviewResult {
    valid: boolean;
    filename: string;
    vintage: string;
    census_year: number | null;
    rows_total: number;
    rows_municipality: number;
    rows_barangay: number;
    rows_with_population: number;
    errors: Record<string, string[]>;
    warnings: string[];
    inserted: number;
    updated: number;
    committed: boolean;
}

export default function PopulationImport({ imports }: { imports: ImportRow[] }) {
    const [file, setFile] = useState<File | null>(null);
    const [vintage, setVintage] = useState('');
    const [makeCurrent, setMakeCurrent] = useState(true);
    const [preview, setPreview] = useState<PreviewResult | null>(null);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const send = async (endpoint: string) => {
        if (!file) return;

        setBusy(true);
        setResult(null);

        const body = new FormData();
        body.append('file', file);
        if (endpoint.endsWith('commit')) {
            body.append('vintage', vintage || preview?.vintage || '');
            body.append('make_current', makeCurrent ? '1' : '0');
        }

        try {
            const response = await fetch(endpoint, { method: 'POST', body });
            const json = await response.json();

            if (!response.ok) {
                setResult(json.message ?? 'The server rejected the request.');
                return;
            }

            return json as PreviewResult;
        } catch {
            setResult('The upload failed. Check the file and try again.');
        } finally {
            setBusy(false);
        }
    };

    const runPreview = async () => {
        const json = await send('/admin/data/import/preview');
        if (json) setPreview(json);
    };

    const runCommit = async () => {
        const json = await send('/admin/data/import/commit');
        if (json) {
            setResult(
                `Imported: ${json.inserted} new, ${json.updated} updated of ${json.rows_total} rows.`,
            );
            setPreview(null);
            setFile(null);
            if (inputRef.current) inputRef.current.value = '';
        }
    };

    const errorCount = preview ? Object.values(preview.errors).reduce((n, list) => n + list.length, 0) : 0;

    return (
        <AppSidebarLayoutAdmin breadcrumbs={[{ title: 'Reference Data', href: '/admin/data/import' }, { title: 'Import', href: '/admin/data/import' }]}>
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Import Population Figures"
                    description="Validate a CSV first, then commit it. A new vintage is added alongside the existing one."
                />

                {result && (
                    <p className="rounded-md border p-3 text-sm" style={{ borderColor: '#8ab4f8' }}>
                        {result}
                    </p>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Upload</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="file">CSV file</Label>
                            <input
                                id="file"
                                ref={inputRef}
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(e) => {
                                    setFile(e.target.files?.[0] ?? null);
                                    setPreview(null);
                                }}
                                className="border-input rounded-md border px-3 py-2 text-sm"
                            />
                            <p className="text-muted-foreground text-xs">
                                Required columns: area_level, area_ref, population. Optional: area_name,
                                province, municipality, psgc10, psgc_corr, census_year, figure_basis,
                                match_status, city_class, income_class, urban_rural, source, source_url,
                                vintage. Max 20 MB.
                            </p>
                        </div>

                        <div className="flex flex-col gap-2 sm:max-w-xs">
                            <Label htmlFor="vintage">Vintage</Label>
                            <input
                                id="vintage"
                                className="border-input h-9 rounded-md border bg-transparent px-3 py-1 text-sm"
                                placeholder="2030_cph"
                                value={vintage}
                                onChange={(e) => setVintage(e.target.value)}
                            />
                            <p className="text-muted-foreground text-xs">
                                Leave blank to use the vintage column in the file.
                            </p>
                        </div>

                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={makeCurrent} onChange={(e) => setMakeCurrent(e.target.checked)} />
                            Make this the vintage assessments read
                        </label>

                        <div className="flex gap-2">
                            <Button onClick={runPreview} disabled={!file || busy}>
                                <Upload className="size-4" />
                                Validate
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={runCommit}
                                disabled={!preview?.valid || busy}
                            >
                                <CheckCircle2 className="size-4" />
                                Commit import
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {preview && (
                    <Card className={preview.valid ? '' : 'border-red-300'}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {preview.valid ? 'Validation passed' : `Validation failed (${errorCount} problems)`}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            <div className="grid gap-2 text-sm sm:grid-cols-4">
                                <Stat label="Rows" value={preview.rows_total} />
                                <Stat label="Municipality" value={preview.rows_municipality} />
                                <Stat label="Barangay" value={preview.rows_barangay} />
                                <Stat label="With population" value={preview.rows_with_population} />
                            </div>

                            {preview.warnings.map((warning) => (
                                <p key={warning} className="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    {warning}
                                </p>
                            ))}

                            {errorCount > 0 && (
                                <div className="flex max-h-64 flex-col gap-1 overflow-y-auto text-sm text-red-700 dark:text-red-400">
                                    {Object.entries(preview.errors).flatMap(([field, list]) =>
                                        list.slice(0, 25).map((message) => <p key={`${field}-${message}`}>{message}</p>),
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Import history</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="p-2">When</th>
                                    <th className="p-2">File</th>
                                    <th className="p-2">Vintage</th>
                                    <th className="p-2">Status</th>
                                    <th className="p-2">Rows</th>
                                    <th className="p-2">By</th>
                                </tr>
                            </thead>
                            <tbody>
                                {imports.length === 0 && (
                                    <tr>
                                        <td className="text-muted-foreground p-2" colSpan={6}>
                                            No imports yet.
                                        </td>
                                    </tr>
                                )}
                                {imports.map((row) => (
                                    <tr key={row.id} className="border-b last:border-0">
                                        <td className="p-2 text-xs">{row.created_at}</td>
                                        <td className="p-2">{row.filename}</td>
                                        <td className="p-2">{row.vintage}</td>
                                        <td className="p-2">
                                            <Badge
                                                className={
                                                    row.status === 'committed'
                                                        ? 'bg-emerald-600'
                                                        : row.status === 'failed'
                                                          ? 'bg-red-600'
                                                          : ''
                                                }
                                            >
                                                {row.status}
                                            </Badge>
                                        </td>
                                        <td className="p-2 font-mono text-xs">
                                            {row.rows_inserted} new / {row.rows_updated} upd
                                        </td>
                                        <td className="p-2 text-xs">{row.imported_by}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppSidebarLayoutAdmin>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-muted-foreground text-xs tracking-wide uppercase">{label}</span>
            <span className="text-lg font-semibold">{value.toLocaleString()}</span>
        </div>
    );
}