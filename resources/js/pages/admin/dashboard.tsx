import { AdminPageHeader } from '@/components/admin-page-header';
import DataBars from '@/components/data-bars';
import { Button } from '@/components/ui/button';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { Activity, ArrowRight, Bot, ClipboardList, Database, FileText, LayoutGrid, MapPin, ShieldCheck, Users, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface DailyActivity {
    date: string;
    questions: number;
    plans: number;
}

interface Props {
    generatedAt: string;
    days: number;
    users: {
        total: number;
        byRole: { role: string; label: string; count: number }[];
        new: number;
        active: number | null;
    };
    planning: {
        conversations: number;
        questions: number;
        questionsRecent: number;
        plans: number;
        plansRecent: number;
        activeUsers: number;
        daily: DailyActivity[];
        topLocations: { name: string; province: string | null; conversations: number; plans: number }[];
        recentPlans: {
            id: number;
            title: string;
            version: number;
            createdAt: string;
            user: string | null;
            location: string | null;
            province: string | null;
        }[];
    } | null;
    data: {
        datasets: { key: string | null; label: string; group: string; count: number | null }[];
        geography: { provinces: number | null; municipalities: number | null; barangays: number | null };
        quality: { label: string; value: number; total: number; note: string | null }[];
        manualEntries: number;
        populationYear: number | null;
    };
}

const number = (value: number | null | undefined) => (value === null || value === undefined ? '—' : value.toLocaleString('en-PH'));
const day = (value: string) => new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric' }).format(new Date(`${value}T00:00:00`));
const dateTime = (value: string) =>
    new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(
        new Date(value.replace(' ', 'T')),
    );

/** Admin overview: platform usage on top, reference data underneath. */
export default function AdminDashboard({ generatedAt, days, users, planning, data }: Props) {
    return (
        <AppSidebarLayoutAdmin breadcrumbs={[{ title: 'Overview', href: route('admin.dashboard.index') }]}>
            <Head title="Admin overview" />
            <div className="flex min-w-0 flex-col gap-5 p-4 md:p-6">
                <AdminPageHeader
                    icon={LayoutGrid}
                    eyebrow={
                        <span className="inline-flex items-center gap-1.5">
                            <ShieldCheck className="size-3.5 text-[#087dcc]" aria-hidden="true" /> Administrator · updated {dateTime(generatedAt)}
                        </span>
                    }
                    title="Admin overview"
                    description="How KonektaDOST is being used, and the reference data behind every assessment and technology match."
                    actions={
                        <>
                            <Button asChild variant="outline" className="border-[#087dcc]/30 text-[#087dcc]">
                                <Link href={route('admin.data-entries.show', 'users')}>
                                    <Users className="size-4" aria-hidden="true" /> Manage users
                                </Link>
                            </Button>
                            <Button asChild className="bg-[#087dcc] text-white hover:bg-[#086bb0]">
                                <Link href={route('admin.data-entries.index')}>
                                    <Database className="size-4" aria-hidden="true" /> Data entries
                                </Link>
                            </Button>
                        </>
                    }
                />

                <section aria-label="Usage at a glance" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat icon={Users} label="Accounts" value={number(users.total)} detail={`${number(users.new)} new in the last ${days} days`} />
                    <Stat
                        icon={Activity}
                        label={`Active in ${days} days`}
                        value={number(users.active)}
                        detail={users.active === null ? 'Sign-ins are not recorded' : `of ${number(users.total)} accounts signed in`}
                    />
                    <Stat
                        icon={Bot}
                        label="GeoAgent questions"
                        value={number(planning?.questions)}
                        detail={
                            planning
                                ? `${number(planning.questionsRecent)} in ${days} days · ${number(planning.conversations)} conversations`
                                : 'Planning chat is not set up'
                        }
                    />
                    <Stat
                        icon={ClipboardList}
                        label="Action plans drafted"
                        value={number(planning?.plans)}
                        detail={
                            planning
                                ? `${number(planning.plansRecent)} in ${days} days · ${number(planning.activeUsers)} active planners`
                                : 'Planning chat is not set up'
                        }
                    />
                </section>

                <div className="grid min-w-0 gap-5 xl:grid-cols-3">
                    <Panel
                        className="xl:col-span-2"
                        title="GeoAgent activity"
                        description={`Questions asked and action plans drafted per day, last ${days} days.`}
                    >
                        {planning ? <ActivityChart daily={planning.daily} /> : <Empty>Planning chat tables are not available.</Empty>}
                    </Panel>
                    <Panel title="Accounts by role" description="Administrators reach this area; technicians and users get the user workspace.">
                        <DataBars
                            title="Accounts"
                            rows={users.byRole.map((row) => ({
                                label: row.label,
                                value: row.count,
                                color: row.role === 'admin' ? '#087dcc' : '#7cc0ec',
                            }))}
                        />
                    </Panel>
                </div>

                <div className="grid min-w-0 gap-5 xl:grid-cols-3">
                    <Panel
                        className="xl:col-span-2"
                        title="Reference data"
                        description="Records behind the map, the indicators and technology matching."
                        action={
                            <Link
                                href={route('admin.data-entries.index')}
                                className="inline-flex items-center gap-1 text-xs font-semibold text-[#087dcc] hover:underline"
                            >
                                Manage <ArrowRight className="size-3.5" aria-hidden="true" />
                            </Link>
                        }
                    >
                        <dl className="mb-4 grid grid-cols-3 gap-3 rounded-xl bg-blue-50/70 p-3 text-center dark:bg-slate-800">
                            {[
                                ['Provinces', data.geography.provinces],
                                ['Cities & municipalities', data.geography.municipalities],
                                ['Barangays', data.geography.barangays],
                            ].map(([label, value]) => (
                                <div key={label as string} className="flex flex-col">
                                    <dt className="text-[11px] text-slate-500">{label}</dt>
                                    <dd className="order-first text-lg font-semibold text-[#087dcc] tabular-nums">
                                        {number(value as number | null)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {data.datasets.map((dataset) => {
                                const body = (
                                    <>
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">
                                                {dataset.label}
                                            </span>
                                            <span className="text-[11px] text-slate-500">{dataset.group}</span>
                                        </span>
                                        <span className="text-base font-semibold text-slate-900 tabular-nums dark:text-slate-50">
                                            {number(dataset.count)}
                                        </span>
                                    </>
                                );
                                const className =
                                    'flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-2.5 dark:border-slate-700';
                                return (
                                    <li key={dataset.label}>
                                        {dataset.key ? (
                                            <Link
                                                href={route('admin.data-entries.show', dataset.key)}
                                                className={cn(className, 'hover:border-sky-300 hover:bg-sky-50/50 dark:hover:bg-slate-800')}
                                            >
                                                {body}
                                            </Link>
                                        ) : (
                                            <div className={className}>{body}</div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                        <p className="mt-3 text-xs text-slate-500">
                            {data.manualEntries > 0
                                ? `${number(data.manualEntries)} records were added through Data entries; the rest come from the imported datasets.`
                                : 'All records come from the imported datasets; none have been added through Data entries yet.'}
                        </p>
                    </Panel>

                    <Panel
                        title="Data completeness"
                        description="Shares of records the assessments can use. Gaps here are shown as unavailable, never as zero."
                    >
                        <ul className="space-y-4">
                            {data.quality.map((row) => {
                                const share = row.total ? (row.value / row.total) * 100 : 0;
                                return (
                                    <li key={row.label}>
                                        <div className="flex items-baseline justify-between gap-3 text-xs">
                                            <span className="text-slate-700 dark:text-slate-200">{row.label}</span>
                                            <span className="font-semibold text-slate-900 tabular-nums dark:text-slate-50">{share.toFixed(1)}%</span>
                                        </div>
                                        <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden="true">
                                            <div
                                                className={cn(
                                                    'h-full rounded-full',
                                                    share >= 90 ? 'bg-[#087dcc]' : share >= 60 ? 'bg-sky-400' : 'bg-amber-400',
                                                )}
                                                style={{ width: `${share}%` }}
                                            />
                                        </div>
                                        <p className="mt-1 text-[11px] text-slate-500">
                                            {number(row.value)} of {number(row.total)}
                                            {row.note ? ` · ${row.note}` : ''}
                                        </p>
                                    </li>
                                );
                            })}
                        </ul>
                    </Panel>
                </div>

                {planning && (
                    <div className="grid min-w-0 gap-5 xl:grid-cols-3">
                        <Panel className="xl:col-span-2" title="Latest action plans" description="Plan versions saved from GeoAgent conversations.">
                            {planning.recentPlans.length === 0 ? (
                                <Empty>No action plans have been generated yet.</Empty>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-130 text-left text-sm">
                                        <thead className="text-xs text-slate-500">
                                            <tr className="border-b border-slate-100 dark:border-slate-800">
                                                <th scope="col" className="py-2 pr-3 font-medium">
                                                    Plan
                                                </th>
                                                <th scope="col" className="py-2 pr-3 font-medium">
                                                    Location
                                                </th>
                                                <th scope="col" className="py-2 pr-3 font-medium">
                                                    By
                                                </th>
                                                <th scope="col" className="py-2 text-right font-medium">
                                                    Saved
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                            {planning.recentPlans.map((plan) => (
                                                <tr key={plan.id}>
                                                    <td className="py-2.5 pr-3">
                                                        <span className="flex items-center gap-2 font-medium text-slate-800 dark:text-slate-100">
                                                            <FileText className="size-4 shrink-0 text-[#087dcc]" aria-hidden="true" />
                                                            <span className="truncate">{plan.title}</span>
                                                            <span className="rounded-full bg-sky-50 px-1.5 text-[10px] font-semibold text-[#087dcc] dark:bg-slate-800">
                                                                v{plan.version}
                                                            </span>
                                                        </span>
                                                    </td>
                                                    <td className="py-2.5 pr-3 text-slate-600 dark:text-slate-300">{plan.location ?? '—'}</td>
                                                    <td className="py-2.5 pr-3 text-slate-600 dark:text-slate-300">
                                                        {plan.user ?? 'Deleted account'}
                                                    </td>
                                                    <td className="py-2.5 text-right whitespace-nowrap text-slate-500 tabular-nums">
                                                        {dateTime(plan.createdAt)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Panel>
                        <Panel title="Most planned locations" description="Where GeoAgent conversations are focused.">
                            {planning.topLocations.length === 0 ? (
                                <Empty>No conversations yet.</Empty>
                            ) : (
                                <ol className="space-y-2.5">
                                    {planning.topLocations.map((location, index) => (
                                        <li key={`${location.name}-${index}`} className="flex items-center gap-3">
                                            <span className="grid size-7 shrink-0 place-items-center rounded-lg bg-blue-50 text-xs font-semibold text-[#087dcc] tabular-nums dark:bg-slate-800">
                                                {index + 1}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="flex items-center gap-1 truncate text-sm font-medium text-slate-800 dark:text-slate-100">
                                                    <MapPin className="size-3.5 shrink-0 text-slate-400" aria-hidden="true" /> {location.name}
                                                </span>
                                                {location.province && (
                                                    <span className="block truncate text-[11px] text-slate-500">{location.province}</span>
                                                )}
                                            </span>
                                            <span className="text-right text-[11px] text-slate-500 tabular-nums">
                                                <span className="block text-sm font-semibold text-slate-900 dark:text-slate-50">
                                                    {location.conversations}
                                                </span>
                                                {location.plans} plan{location.plans === 1 ? '' : 's'}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Panel>
                    </div>
                )}
            </div>
        </AppSidebarLayoutAdmin>
    );
}

function Stat({ icon: Icon, label, value, detail }: { icon: LucideIcon; label: string; value: string; detail: string }) {
    return (
        <div className="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center gap-2 text-xs font-medium text-slate-500">
                <span className="grid size-7 place-items-center rounded-lg bg-blue-50 text-[#087dcc] dark:bg-slate-800">
                    <Icon className="size-4" aria-hidden="true" />
                </span>
                {label}
            </div>
            <p className="mt-3 text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-slate-50">{value}</p>
            <p className="mt-1 text-xs text-slate-500">{detail}</p>
        </div>
    );
}

function Panel({
    title,
    description,
    action,
    className,
    children,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            aria-label={title}
            className={cn('min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900', className)}
        >
            <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-50">{title}</h2>
                    {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="rounded-xl bg-slate-50 px-4 py-6 text-center text-sm text-slate-500 dark:bg-slate-800">{children}</p>;
}

/** Stacked daily columns: plans (dark blue) under questions (light blue). */
function ActivityChart({ daily }: { daily: DailyActivity[] }) {
    const max = Math.max(1, ...daily.map((row) => row.questions + row.plans));
    const totals = daily.reduce((sum, row) => ({ questions: sum.questions + row.questions, plans: sum.plans + row.plans }), {
        questions: 0,
        plans: 0,
    });
    return (
        <figure className="min-w-0">
            <div className="mb-3 flex flex-wrap items-center gap-4 text-xs text-slate-600 dark:text-slate-300">
                <span className="inline-flex items-center gap-1.5">
                    <span className="size-2.5 rounded-sm bg-[#7cc0ec]" aria-hidden="true" /> Questions ({totals.questions})
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="size-2.5 rounded-sm bg-[#087dcc]" aria-hidden="true" /> Action plans ({totals.plans})
                </span>
                <span className="ml-auto text-slate-400">Peak {max} per day</span>
            </div>
            <div
                className="flex h-44 items-end gap-1 border-b border-slate-200 dark:border-slate-700"
                role="img"
                aria-label={`${totals.questions} questions and ${totals.plans} action plans in the period`}
            >
                {daily.map((row) => (
                    <div
                        key={row.date}
                        className="group relative flex h-full min-w-0 flex-1 flex-col justify-end"
                        title={`${day(row.date)}: ${row.questions} questions, ${row.plans} plans`}
                    >
                        <div
                            className="rounded-t-sm bg-[#7cc0ec] transition-opacity group-hover:opacity-80"
                            style={{ height: `${(row.questions / max) * 100}%` }}
                        />
                        <div className="bg-[#087dcc] transition-opacity group-hover:opacity-80" style={{ height: `${(row.plans / max) * 100}%` }} />
                    </div>
                ))}
            </div>
            <div className="mt-1.5 flex justify-between text-[11px] text-slate-400 tabular-nums">
                <span>{day(daily[0].date)}</span>
                <span>{day(daily[Math.floor(daily.length / 2)].date)}</span>
                <span>Today</span>
            </div>
            <table className="sr-only">
                <caption>Daily GeoAgent activity</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Questions</th>
                        <th scope="col">Action plans</th>
                    </tr>
                </thead>
                <tbody>
                    {daily
                        .filter((row) => row.questions || row.plans)
                        .map((row) => (
                            <tr key={row.date}>
                                <th scope="row">{day(row.date)}</th>
                                <td>{row.questions}</td>
                                <td>{row.plans}</td>
                            </tr>
                        ))}
                </tbody>
            </table>
        </figure>
    );
}
