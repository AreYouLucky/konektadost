import { AdminPageHeader } from '@/components/admin-page-header';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Database } from 'lucide-react';
import { sectionIcon, type SectionMeta } from './types';

interface Section extends SectionMeta {
    available: boolean;
    count: number | null;
}

/** Overview of every data-entry sector, grouped, with record counts. */
export default function DataEntriesIndex({ sections }: { sections: Section[] }) {
    const groups = [...new Set(sections.map((section) => section.group))];
    return (
        <AppSidebarLayoutAdmin breadcrumbs={[{ title: 'Data entries', href: route('admin.data-entries.index') }]}>
            <Head title="Data entries" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <AdminPageHeader
                    icon={Database}
                    title="Data entries"
                    description="Add, correct or remove the reference records behind the map, assessments and technology matching. Changes apply to the next assessment immediately."
                />

                {groups.map((group) => (
                    <section key={group} aria-labelledby={`group-${group}`} className="space-y-3">
                        <h2 id={`group-${group}`} className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                            {group}
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {sections
                                .filter((section) => section.group === group)
                                .map((section) => {
                                    const Icon = sectionIcon(section.icon);
                                    const card = (
                                        <>
                                            <span className="flex items-start justify-between gap-3">
                                                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-[#087dcc] dark:bg-slate-800">
                                                    <Icon className="size-5" aria-hidden="true" />
                                                </span>
                                                <span className="text-right">
                                                    <span className="block text-2xl font-semibold text-slate-900 tabular-nums dark:text-slate-50">
                                                        {section.count === null ? '—' : section.count.toLocaleString('en-PH')}
                                                    </span>
                                                    <span className="text-[11px] text-slate-500">records</span>
                                                </span>
                                            </span>
                                            <span className="mt-3 block text-sm font-semibold text-slate-900 dark:text-slate-50">
                                                {section.label}
                                            </span>
                                            <span className="mt-1 block text-xs leading-relaxed text-slate-500">{section.description}</span>
                                            <span className="mt-auto flex items-center gap-1 pt-4 text-xs font-semibold text-[#087dcc]">
                                                {section.available ? 'Manage' : 'Table not available'}
                                                {section.available && (
                                                    <ArrowRight className="size-3.5 transition group-hover:translate-x-0.5" aria-hidden="true" />
                                                )}
                                            </span>
                                        </>
                                    );
                                    const className =
                                        'group flex h-full flex-col rounded-2xl border border-sky-100 bg-white p-4 text-left shadow-xs transition dark:border-slate-800 dark:bg-slate-900';
                                    return section.available ? (
                                        <Link
                                            key={section.key}
                                            href={route('admin.data-entries.show', section.key)}
                                            className={`${className} hover:border-sky-300 hover:shadow-sm focus-visible:outline-2 focus-visible:outline-[#087dcc]`}
                                        >
                                            {card}
                                        </Link>
                                    ) : (
                                        <div key={section.key} className={`${className} opacity-60`}>
                                            {card}
                                        </div>
                                    );
                                })}
                        </div>
                    </section>
                ))}
            </div>
        </AppSidebarLayoutAdmin>
    );
}
