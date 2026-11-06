import { AdminPageHeader } from '@/components/admin-page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import type { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Info,
    LoaderCircle,
    Pencil,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { EntryFieldInput } from './fields';
import { formValues, sectionIcon, type EntryField, type EntryRow, type FormValues, type SectionMeta } from './types';

interface Props {
    resource: SectionMeta;
    fields: EntryField[];
    rows: EntryRow[];
    pagination: { page: number; lastPage: number; total: number; from: number | null; to: number | null };
    filters: { search: string; sort: string; direction: 'asc' | 'desc' };
    sortable: string[];
}

/** List, search, add, edit and delete the records of one data-entry sector. */
export default function DataEntryResourcePage({ resource, fields, rows, pagination, filters, sortable }: Props) {
    const { props } = usePage<SharedData & { flash?: { success?: string | null }; errors: Record<string, string> }>();
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<EntryRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<EntryRow | null>(null);
    const [deleteBusy, setDeleteBusy] = useState(false);
    const firstRender = useRef(true);
    const Icon = sectionIcon(resource.icon);
    const columns = fields.filter((field) => field.list);

    const visit = (params: Partial<Props['filters']> & { page?: number }) =>
        router.get(
            route('admin.data-entries.show', resource.key),
            { search: filters.search || undefined, sort: filters.sort, direction: filters.direction, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = window.setTimeout(() => visit({ search: search.trim() || undefined, page: 1 }), 300);
        return () => window.clearTimeout(timer);
        // Only the typed search term should trigger a new visit.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const sortBy = (name: string) => visit({ sort: name, direction: filters.sort === name && filters.direction === 'asc' ? 'desc' : 'asc', page: 1 });

    const confirmDelete = () => {
        if (!deleting) return;
        setDeleteBusy(true);
        router.delete(route('admin.data-entries.destroy', [resource.key, deleting.id]), {
            preserveScroll: true,
            onFinish: () => {
                setDeleteBusy(false);
                setDeleting(null);
            },
        });
    };

    return (
        <AppSidebarLayoutAdmin
            breadcrumbs={[
                { title: 'Data entries', href: route('admin.data-entries.index') },
                { title: resource.label, href: route('admin.data-entries.show', resource.key) },
            ]}
        >
            <Head title={`${resource.label} · Data entries`} />
            <div className="flex min-w-0 flex-col gap-5 p-4 md:p-6">
                <AdminPageHeader
                    icon={Icon}
                    eyebrow={
                        <Link href={route('admin.data-entries.index')} className="inline-flex items-center gap-1.5 font-medium hover:text-[#087dcc]">
                            <ArrowLeft className="size-3.5" aria-hidden="true" /> All data entries
                        </Link>
                    }
                    title={resource.label}
                    description={resource.description}
                    actions={
                        <Button onClick={() => setEditing('new')} className="bg-[#087dcc] text-white hover:bg-[#086bb0]">
                            <Plus className="size-4" aria-hidden="true" /> Add {resource.singular.toLowerCase()}
                        </Button>
                    }
                />

                {props.flash?.success && (
                    <p
                        role="status"
                        key={props.flash.success}
                        className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800"
                    >
                        <CheckCircle2 className="size-4" aria-hidden="true" /> {props.flash.success}
                    </p>
                )}
                {props.errors?.record && (
                    <p role="alert" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                        {props.errors.record}
                    </p>
                )}
                {resource.note && (
                    <p className="flex items-start gap-2 text-xs text-slate-500">
                        <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" /> {resource.note}
                    </p>
                )}

                <section className="min-w-0 rounded-2xl border border-sky-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-3 dark:border-slate-800">
                        <div className="relative w-full max-w-sm">
                            <Search
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400"
                                aria-hidden="true"
                            />
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder={`Search ${resource.label.toLowerCase()}`}
                                aria-label={`Search ${resource.label}`}
                                className="pl-9"
                            />
                        </div>
                        <p className="text-xs text-slate-500 tabular-nums" aria-live="polite">
                            {pagination.total === 0
                                ? 'No records'
                                : `${pagination.from?.toLocaleString('en-PH')}–${pagination.to?.toLocaleString('en-PH')} of ${pagination.total.toLocaleString('en-PH')}`}
                        </p>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-160 text-left text-sm">
                            <thead className="bg-slate-50 text-xs text-slate-500 dark:bg-slate-950">
                                <tr>
                                    {columns.map((field) => (
                                        <th key={field.name} scope="col" className="px-4 py-2.5 font-medium">
                                            {sortable.includes(field.name) ? (
                                                <button
                                                    type="button"
                                                    onClick={() => sortBy(field.name)}
                                                    className="inline-flex items-center gap-1 hover:text-[#087dcc]"
                                                    aria-label={`Sort by ${field.label}`}
                                                >
                                                    {field.label}
                                                    {filters.sort === field.name &&
                                                        (filters.direction === 'asc' ? (
                                                            <ArrowUp className="size-3" aria-hidden="true" />
                                                        ) : (
                                                            <ArrowDown className="size-3" aria-hidden="true" />
                                                        ))}
                                                </button>
                                            ) : (
                                                field.label
                                            )}
                                        </th>
                                    ))}
                                    <th scope="col" className="w-px px-4 py-2.5 text-right font-medium">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {rows.map((row) => (
                                    <tr key={row.id} className="align-top hover:bg-sky-50/40 dark:hover:bg-slate-800/40">
                                        {columns.map((field, index) => (
                                            <td
                                                key={field.name}
                                                className={
                                                    index === 0
                                                        ? 'px-4 py-2.5 font-medium text-slate-900 dark:text-slate-100'
                                                        : 'px-4 py-2.5 text-slate-600 dark:text-slate-300'
                                                }
                                            >
                                                <Cell field={field} row={row} />
                                            </td>
                                        ))}
                                        <td className="px-4 py-2 text-right whitespace-nowrap">
                                            <Button variant="ghost" size="sm" onClick={() => setEditing(row)} aria-label={`Edit ${row.title}`}>
                                                <Pencil className="size-3.5" aria-hidden="true" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => setDeleting(row)}
                                                aria-label={`Delete ${row.title}`}
                                                className="text-red-600 hover:bg-red-50 hover:text-red-700"
                                            >
                                                <Trash2 className="size-3.5" aria-hidden="true" />
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={columns.length + 1} className="px-4 py-10 text-center text-sm text-slate-500">
                                            {filters.search
                                                ? `No ${resource.label.toLowerCase()} match “${filters.search}”.`
                                                : `No ${resource.label.toLowerCase()} yet.`}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {pagination.lastPage > 1 && (
                        <nav
                            aria-label="Pages"
                            className="flex items-center justify-between gap-2 border-t border-slate-100 p-3 text-xs dark:border-slate-800"
                        >
                            <Button variant="outline" size="sm" disabled={pagination.page <= 1} onClick={() => visit({ page: pagination.page - 1 })}>
                                <ChevronLeft className="size-3.5" aria-hidden="true" /> Previous
                            </Button>
                            <span className="text-slate-500 tabular-nums">
                                Page {pagination.page} of {pagination.lastPage}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pagination.page >= pagination.lastPage}
                                onClick={() => visit({ page: pagination.page + 1 })}
                            >
                                Next <ChevronRight className="size-3.5" aria-hidden="true" />
                            </Button>
                        </nav>
                    )}
                </section>
            </div>

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                {editing !== null && (
                    <EntryForm
                        key={editing === 'new' ? 'new' : editing.id}
                        resource={resource}
                        fields={fields}
                        row={editing === 'new' ? null : editing}
                        onDone={() => setEditing(null)}
                    />
                )}
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && !deleteBusy && setDeleting(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete this {resource.singular.toLowerCase()}?</DialogTitle>
                        <DialogDescription>
                            “{deleting?.title}” will be removed permanently. Assessments and the map stop counting it straight away.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(null)} disabled={deleteBusy}>
                            Cancel
                        </Button>
                        <Button onClick={confirmDelete} disabled={deleteBusy} className="bg-red-600 text-white hover:bg-red-700">
                            {deleteBusy && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />} Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppSidebarLayoutAdmin>
    );
}

/** A table cell for one field. */
function Cell({ field, row }: { field: EntryField; row: EntryRow }) {
    if (field.type === 'location') {
        return row.location_label ? <span className="line-clamp-2">{row.location_label}</span> : <span className="text-slate-400">Not linked</span>;
    }
    const value = row[field.name];
    if (value === null || value === undefined || value === '') return <span className="text-slate-300">—</span>;
    if (field.type === 'boolean') return <>{value ? 'Yes' : 'No'}</>;
    if (field.type === 'list' && Array.isArray(value)) {
        return (
            <span className="flex flex-wrap gap-1">
                {value.map((item) => (
                    <span key={String(item)} className="rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-[#087dcc]">
                        {String(item)}
                    </span>
                ))}
            </span>
        );
    }
    if (field.type === 'select') {
        return <>{field.options?.find((option) => String(option.value) === String(value))?.label ?? String(value)}</>;
    }
    if (field.type === 'decimal' || field.type === 'integer') {
        return <span className="tabular-nums">{Number(value).toLocaleString('en-PH', { maximumFractionDigits: 6 })}</span>;
    }
    return (
        <span className="line-clamp-2 max-w-md" title={String(value)}>
            {String(value)}
        </span>
    );
}

/** Add/edit form generated from the sector's fields. */
function EntryForm({ resource, fields, row, onDone }: { resource: SectionMeta; fields: EntryField[]; row: EntryRow | null; onDone: () => void }) {
    const form = useForm<FormValues>(formValues(fields, row));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (row) form.put(route('admin.data-entries.update', [resource.key, row.id]), options);
        else form.post(route('admin.data-entries.store', resource.key), options);
    };
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <DialogContent className="flex max-h-[calc(100dvh-2rem)] max-w-3xl flex-col gap-0 p-0">
            <form onSubmit={submit} className="flex min-h-0 flex-col" noValidate>
                <DialogHeader className="border-b border-slate-100 px-6 py-4 dark:border-slate-800">
                    <DialogTitle>{row ? `Edit ${resource.singular.toLowerCase()}` : `Add ${resource.singular.toLowerCase()}`}</DialogTitle>
                    <DialogDescription>{row ? row.title : resource.description}</DialogDescription>
                </DialogHeader>
                <div className="grid min-h-0 flex-1 gap-4 overflow-y-auto px-6 py-5 sm:grid-cols-2">
                    {fields.map((field) => (
                        <EntryFieldInput
                            key={field.name}
                            field={field}
                            values={form.data}
                            setValue={(name, value) => form.setData(name, value)}
                            error={field.type === 'location' ? (errors.municipality_id ?? errors.barangay_id) : errors[field.name]}
                            editing={row !== null}
                        />
                    ))}
                </div>
                <DialogFooter className="border-t border-slate-100 px-6 py-4 dark:border-slate-800">
                    {Object.keys(form.errors).length > 0 && (
                        <p role="alert" className="mr-auto self-center text-xs text-red-600">
                            Please fix the highlighted fields.
                        </p>
                    )}
                    <Button type="button" variant="ghost" onClick={onDone} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={form.processing} className="bg-[#087dcc] text-white hover:bg-[#086bb0]">
                        {form.processing && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                        {row ? 'Save changes' : `Add ${resource.singular.toLowerCase()}`}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
