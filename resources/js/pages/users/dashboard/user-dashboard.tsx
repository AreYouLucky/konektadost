import { SERIES } from '@/components/assessment-method/charts';
import { usePlanningChat } from '@/components/planning-chat';
import { useInitials } from '@/hooks/use-initials';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Cpu,
    FileText,
    FlaskConical,
    Globe2,
    Info,
    LoaderCircle,
    MapPin,
    MessageCircle,
    MessagesSquare,
    Radio,
    Search,
    Sparkles,
    Target,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface CountRow {
    label: string | null;
    count: number;
}

interface TechRecord {
    id: number;
    code: string | null;
    name: string;
    agency: string | null;
    sector: string | null;
    status: string | null;
    outcome: string | null;
}

interface ProgramRecord {
    id: number;
    code: string | null;
    name: string;
    agency: string | null;
    category: string | null;
    active_status: string | null;
    target_audience: string | null;
}

interface PlanRow {
    id: number;
    title: string;
    version: number;
    status: string | null;
    created_at: string | null;
    city: string;
    municipality: string;
    province: string | null;
}

interface ConversationRow {
    id: number;
    title: string;
    updated_at: string | null;
    archived_at: string | null;
    city: string;
    municipality: string;
    province: string | null;
}

interface DashboardProps {
    platform: {
        coverage: Record<
            'provinces' | 'municipalities' | 'barangays' | 'facilities' | 'laboratories' | 'lab_services' | 'telecom_sites' | 'roads',
            number | null
        >;
        facilitiesBySector: CountRow[];
        catalog: { techs: TechRecord[]; programs: ProgramRecord[] };
        projects: { total: number | null; byAgency: CountRow[]; byStatus: { key: string; label: string; count: number }[] };
    };
    planning: {
        totals: { plans: number; conversations: number; municipalities: number };
        recentPlans: PlanRow[];
        recentConversations: ConversationRow[];
    };
    dimensions: { key: string; label: string }[];
}

interface MunicipalityOption {
    id: number;
    psg_code: string;
    name: string;
    province_name: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];
const numberFormat = new Intl.NumberFormat('en-PH');
const formatCount = (value: number | null | undefined) => (value === null || value === undefined ? 'Not loaded' : numberFormat.format(value));

function formatDate(value: string | null) {
    if (!value) return 'Date not recorded';
    const date = new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

export default function UserDashboard({ platform, planning, dimensions }: DashboardProps) {
    const { auth } = usePage<SharedData>().props;
    const firstName = String(auth.user.name ?? '').split(' ')[0] || 'there';
    const catalogCount = platform.catalog.techs.length + platform.catalog.programs.length;
    const catalogByAgency = useMemo(() => {
        const counts = new Map<string, number>();
        [...platform.catalog.techs, ...platform.catalog.programs].forEach((row) => {
            const agency = (row.agency ?? 'Not recorded').replace(/\s*\(.*\)$/, '');
            counts.set(agency, (counts.get(agency) ?? 0) + 1);
        });
        return [...counts].sort((a, b) => b[1] - a[1]).map(([label, count]) => ({ label, count }));
    }, [platform.catalog]);

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard | KonektaDOST" />
            <div className="viz-palette flex w-full flex-col gap-4 p-4 md:p-6">
                <WelcomeHeader name={String(auth.user.name ?? '')} firstName={firstName} planning={planning} />

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        <LocationQuickStart dimensions={dimensions} />
                    </div>
                    <WorkflowCard />
                </div>

                <section aria-label="Platform data coverage" className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <StatTile
                        icon={MapPin}
                        label="Cities & municipalities"
                        value={platform.coverage.municipalities}
                        hint={`${formatCount(platform.coverage.provinces)} provinces`}
                    />
                    <StatTile icon={Globe2} label="Barangays" value={platform.coverage.barangays} hint="Mapped" />
                    <StatTile icon={Building2} label="Facilities" value={platform.coverage.facilities} hint="Health · school · safety" />
                    <StatTile
                        icon={FlaskConical}
                        label="Accredited labs"
                        value={platform.coverage.laboratories}
                        hint={`${formatCount(platform.coverage.lab_services)} services`}
                    />
                    <StatTile icon={Cpu} label="DOST catalog" value={catalogCount} hint="Technologies & programs" />
                    <StatTile icon={ClipboardList} label="DOST projects" value={platform.projects.total} hint="Historical records" />
                </section>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel title="Facilities by sector">
                        <div className="flex flex-1 items-center justify-center py-2">
                            <Donut
                                rows={platform.facilitiesBySector.map((row) => ({ label: titleCase(row.label ?? 'Not recorded'), count: row.count }))}
                                centerLabel="facilities"
                            />
                        </div>
                        <dl className="grid grid-cols-2 gap-2">
                            <MiniStat icon={Radio} label="Cell sites" value={platform.coverage.telecom_sites} />
                            <MiniStat icon={MapPin} label="Road segments" value={platform.coverage.roads} />
                        </dl>
                    </Panel>
                    <Panel title="DOST projects by agency">
                        <BarList
                            rows={platform.projects.byAgency.map((row) => ({ label: row.label ?? 'Not recorded', count: row.count }))}
                            unit="projects"
                        />
                    </Panel>
                    <Panel title="Project status">
                        <BarList rows={platform.projects.byStatus} unit="projects" />
                    </Panel>
                </div>

                <div className="grid gap-4 xl:grid-cols-3">
                    <div className="grid gap-4 md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] xl:col-span-2">
                        <Panel title="Catalog by agency">
                            <BarList rows={catalogByAgency} unit="entries" />
                        </Panel>
                        <CatalogPanel techs={platform.catalog.techs} programs={platform.catalog.programs} />
                    </div>
                    <MyPlanning planning={planning} />
                </div>

                <p className="flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                    <Info className="size-3.5 shrink-0 text-[#087dcc]" aria-hidden="true" />
                    Recorded counts; missing data is not zero. Matches and plans are drafts until LGU/DOST validation.
                </p>
            </div>
        </AppSidebarLayout>
    );
}

function greetingFor(hour: number) {
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
}

/** Greeting, today's date, the user's own planning totals and a "pick up where you left off" row. */
function WelcomeHeader({ name, firstName, planning }: { name: string; firstName: string; planning: DashboardProps['planning'] }) {
    const chat = usePlanningChat();
    const getInitials = useInitials();
    const now = new Date();
    const today = now.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    const { totals, recentPlans, recentConversations } = planning;
    const latestPlan = recentPlans[0];
    const latestChat = recentConversations[0];
    const stats = [
        { icon: FileText, label: totals.plans === 1 ? 'Plan' : 'Plans', value: totals.plans },
        { icon: MessagesSquare, label: totals.conversations === 1 ? 'Chat' : 'Chats', value: totals.conversations },
        { icon: MapPin, label: totals.municipalities === 1 ? 'Location' : 'Locations', value: totals.municipalities },
    ];

    return (
        <header className="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-col gap-5 p-5 md:flex-row md:items-center md:justify-between md:p-6">
                <div className="flex min-w-0 items-center gap-4">
                    <span
                        className="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-linear-to-br from-[#087dcc] to-[#0a5a96] text-lg font-semibold text-white shadow-sm"
                        aria-hidden="true"
                    >
                        {getInitials(name) || <Sparkles className="size-6" />}
                    </span>
                    <div className="min-w-0">
                        <p className="text-[11px] font-semibold tracking-[0.14em] text-[#087dcc] uppercase" suppressHydrationWarning>
                            Your workspace · {today}
                        </p>
                        <h1
                            className="truncate text-2xl font-semibold tracking-tight text-slate-900 md:text-3xl dark:text-slate-50"
                            suppressHydrationWarning
                        >
                            {greetingFor(now.getHours())}, {firstName}
                        </h1>
                        <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Find the DOST technologies that fit a location.</p>
                    </div>
                </div>

                <dl className="grid shrink-0 grid-cols-3 gap-2">
                    {stats.map(({ icon: Icon, label, value }) => (
                        <div
                            key={label}
                            className="flex min-w-0 flex-col gap-1 rounded-xl border border-sky-100 bg-blue-50/60 px-3 py-2.5 md:min-w-24 dark:border-slate-800 dark:bg-slate-950"
                        >
                            <dt className="flex items-center gap-1.5 text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                <Icon className="size-3.5 text-[#087dcc]" aria-hidden="true" /> {label}
                            </dt>
                            <dd className="text-xl font-semibold text-slate-900 tabular-nums dark:text-slate-50">{numberFormat.format(value)}</dd>
                        </div>
                    ))}
                </dl>
            </div>

            <div className="flex flex-col gap-2 border-t border-slate-100 bg-slate-50/70 px-5 py-3 sm:flex-row sm:items-center md:px-6 dark:border-slate-800 dark:bg-slate-950/50">
                <span className="shrink-0 text-[11px] font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                    Pick up where you left off
                </span>
                {latestPlan || latestChat ? (
                    <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-2">
                        {latestPlan && (
                            <button
                                type="button"
                                onClick={() => chat?.openPlan(latestPlan.city, latestPlan.id)}
                                className="group flex min-w-0 items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left hover:border-[#087dcc]/40 hover:bg-blue-50/60 focus-visible:outline-2 focus-visible:outline-[#087dcc] dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800"
                            >
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-[#087dcc] dark:bg-sky-950">
                                    <FileText className="size-4" aria-hidden="true" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100" title={latestPlan.title}>
                                        {latestPlan.title}
                                    </span>
                                    <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400">
                                        Latest plan · {latestPlan.municipality} · v{latestPlan.version} · {formatDate(latestPlan.created_at)}
                                    </span>
                                </span>
                                <span className="shrink-0 text-xs font-semibold text-[#087dcc]">Open</span>
                            </button>
                        )}
                        {latestChat && (
                            <Link
                                href={route('location.assess', { city: latestChat.city })}
                                className="group flex min-w-0 items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 hover:border-[#087dcc]/40 hover:bg-blue-50/60 focus-visible:outline-2 focus-visible:outline-[#087dcc] dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800"
                            >
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-[#087dcc] dark:bg-sky-950">
                                    <Target className="size-4" aria-hidden="true" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">
                                        {latestChat.municipality} assessment
                                    </span>
                                    <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400" title={latestChat.title}>
                                        Last chat · {latestChat.title} · {formatDate(latestChat.updated_at)}
                                    </span>
                                </span>
                                <ArrowRight className="size-4 shrink-0 text-[#087dcc] transition group-hover:translate-x-0.5" aria-hidden="true" />
                            </Link>
                        )}
                    </div>
                ) : (
                    <p className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600 dark:text-slate-300">
                        Nothing yet. Search a location below, or
                        <button type="button" onClick={() => chat?.show()} className="font-semibold text-[#087dcc] hover:underline">
                            talk to GeoAgent
                        </button>
                    </p>
                )}
            </div>
        </header>
    );
}

/** Donut of a few categories (validated slots 1–3), 2px surface gaps, total in the center. */
function Donut({ rows, centerLabel }: { rows: { label: string; count: number }[]; centerLabel: string }) {
    const [active, setActive] = useState<number | null>(null);
    const shown = rows.slice(0, 3);
    const total = shown.reduce((sum, row) => sum + row.count, 0);
    const radius = 42;
    const circumference = 2 * Math.PI * radius;
    if (!total) return <p className="text-sm text-slate-500">No records loaded.</p>;
    let offset = 0;
    const arcs = shown.map((row) => {
        const length = (row.count / total) * circumference;
        const arc = { length, offset };
        offset += length;
        return arc;
    });
    return (
        <div className="flex w-full max-w-sm flex-col items-center gap-4 sm:flex-row sm:justify-center">
            <div className="relative size-36 shrink-0">
                <svg viewBox="0 0 110 110" className="size-full -rotate-90" role="img" aria-label={`${numberFormat.format(total)} ${centerLabel}`}>
                    <circle cx="55" cy="55" r={radius} fill="none" stroke="var(--viz-grid)" strokeWidth="14" />
                    {shown.map((row, index) => (
                        <circle
                            key={row.label}
                            cx="55"
                            cy="55"
                            r={radius}
                            fill="none"
                            stroke={SERIES[index]}
                            strokeWidth={active === index ? 17 : 14}
                            strokeDasharray={`${Math.max(0, arcs[index].length - 2)} ${circumference}`}
                            strokeDashoffset={-arcs[index].offset}
                            onMouseEnter={() => setActive(index)}
                            onMouseLeave={() => setActive(null)}
                        >
                            <title>{`${row.label}: ${numberFormat.format(row.count)} (${Math.round((row.count / total) * 100)}%)`}</title>
                        </circle>
                    ))}
                </svg>
                <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-lg font-semibold text-slate-900 tabular-nums dark:text-slate-50">
                        {numberFormat.format(active === null ? total : shown[active].count)}
                    </span>
                    <span className="text-[10px] text-slate-500">{active === null ? centerLabel : shown[active].label.toLowerCase()}</span>
                </div>
            </div>
            <ul className="w-full max-w-56 min-w-0 space-y-2 sm:flex-1">
                {shown.map((row, index) => (
                    <li
                        key={row.label}
                        className="flex items-center gap-2 text-xs"
                        onMouseEnter={() => setActive(index)}
                        onMouseLeave={() => setActive(null)}
                    >
                        <span className="size-2.5 shrink-0 rounded-sm" style={{ backgroundColor: SERIES[index] }} aria-hidden="true" />
                        <span className="flex-1 text-slate-600 dark:text-slate-300">{row.label}</span>
                        <span className="font-medium text-slate-800 tabular-nums dark:text-slate-100">{numberFormat.format(row.count)}</span>
                        <span className="w-9 text-right text-slate-500 tabular-nums">{Math.round((row.count / total) * 100)}%</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function titleCase(value: string) {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function Panel({
    title,
    description,
    action,
    children,
}: {
    title: string;
    description?: string;
    action?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section className="flex h-full min-w-0 flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-base font-semibold text-slate-900 dark:text-slate-50">{title}</h2>
                    {description && <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{description}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function StatTile({ icon: Icon, label, value, hint }: { icon: LucideIcon; label: string; value: number | null; hint: string }) {
    return (
        <div className="flex flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                <span className="flex size-7 items-center justify-center rounded-lg bg-blue-50 text-[#087dcc] dark:bg-sky-950">
                    <Icon className="size-4" strokeWidth={1.8} aria-hidden="true" />
                </span>
                <span className="truncate">{label}</span>
            </div>
            <p className="text-2xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-slate-50">{formatCount(value)}</p>
            <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">{hint}</p>
        </div>
    );
}

function MiniStat({ icon: Icon, label, value }: { icon: LucideIcon; label: string; value: number | null }) {
    return (
        <div className="rounded-xl border border-slate-100 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-950">
            <dt className="flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                <Icon className="size-3.5 text-[#087dcc]" aria-hidden="true" /> {label}
            </dt>
            <dd className="mt-1 text-lg font-semibold text-slate-900 tabular-nums dark:text-slate-50">{formatCount(value)}</dd>
        </div>
    );
}

/** Single-series horizontal bars: one hue, value labels in text ink, hover tooltip with share. */
function BarList({ rows, unit }: { rows: { label: string; count: number }[]; unit: string }) {
    const [active, setActive] = useState<number | null>(null);
    const max = Math.max(1, ...rows.map((row) => row.count));
    const total = rows.reduce((sum, row) => sum + row.count, 0);

    if (!rows.length) return <p className="text-sm text-slate-500">No records loaded.</p>;

    return (
        <ul className="flex flex-col gap-1" onMouseLeave={() => setActive(null)}>
            {rows.map((row, index) => {
                const share = total ? Math.round((row.count / total) * 1000) / 10 : 0;
                return (
                    <li
                        key={row.label}
                        className="relative grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_3.5rem] items-center gap-3 rounded-md px-1 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-800/60"
                        onMouseEnter={() => setActive(index)}
                        onFocus={() => setActive(index)}
                        onBlur={() => setActive(null)}
                        tabIndex={0}
                        aria-label={`${row.label}: ${numberFormat.format(row.count)} ${unit}, ${share}% of total`}
                    >
                        <span className="truncate text-xs text-slate-600 dark:text-slate-300" title={row.label}>
                            {row.label}
                        </span>
                        <span className="h-2.5 rounded-full bg-slate-100 dark:bg-slate-800">
                            <span
                                className="block h-full rounded-full bg-[#087dcc] transition-[width] dark:bg-sky-400"
                                style={{ width: `${Math.max(2, (row.count / max) * 100)}%` }}
                            />
                        </span>
                        <span className="text-right text-xs font-medium text-slate-700 tabular-nums dark:text-slate-200">
                            {numberFormat.format(row.count)}
                        </span>
                        {active === index && (
                            <span
                                role="tooltip"
                                className="pointer-events-none absolute -top-8 left-36 z-10 rounded-md bg-slate-900 px-2 py-1 text-[11px] whitespace-nowrap text-white shadow-lg dark:bg-slate-100 dark:text-slate-900"
                            >
                                {row.label} · {numberFormat.format(row.count)} {unit} · {share}%
                            </span>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

function LocationQuickStart({ dimensions }: { dimensions: DashboardProps['dimensions'] }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<MunicipalityOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const [selected, setSelected] = useState<MunicipalityOption | null>(null);
    const [focus, setFocus] = useState('general');
    const pending = useRef<AbortController | null>(null);
    const chat = usePlanningChat();

    useEffect(() => {
        const term = query.trim();
        pending.current?.abort();
        if (term.length < 2 || selected) {
            setResults([]);
            setLoading(false);
            return;
        }
        const controller = new AbortController();
        pending.current = controller;
        const timer = window.setTimeout(async () => {
            setLoading(true);
            setError(false);
            try {
                const response = await fetch(route('planning-chat.locations', { q: term }), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok || response.redirected) throw new Error('search failed');
                const payload = await response.json();
                if (!controller.signal.aborted) setResults(payload.locations ?? []);
            } catch {
                if (!controller.signal.aborted) setError(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, selected]);

    const choose = (option: MunicipalityOption) => {
        setSelected(option);
        setQuery(option.name);
        setResults([]);
    };

    return (
        <section className="relative flex h-full flex-col gap-4 overflow-hidden rounded-2xl bg-linear-to-br from-[#087dcc] to-[#0a5a96] p-5 text-white shadow-sm md:p-6">
            <div>
                <h2 className="flex items-center gap-2 text-lg font-semibold">
                    <Target className="size-5" aria-hidden="true" /> Start a location assessment
                </h2>
                <p className="mt-1 text-sm text-blue-100">Search a city or municipality.</p>
            </div>

            <div className="relative">
                <label htmlFor="dashboard-location" className="sr-only">
                    City or municipality
                </label>
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                <input
                    id="dashboard-location"
                    type="search"
                    autoComplete="off"
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setSelected(null);
                    }}
                    placeholder="e.g. Quezon City, Iloilo, Davao del Sur"
                    className="h-11 w-full rounded-xl border-0 bg-white pr-10 pl-9 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:ring-2 focus:ring-white/70 focus:outline-none"
                    aria-autocomplete="list"
                    aria-controls="dashboard-location-results"
                />
                {loading && (
                    <LoaderCircle className="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-slate-400" aria-hidden="true" />
                )}
                {selected && !loading && (
                    <button
                        type="button"
                        onClick={() => {
                            setSelected(null);
                            setQuery('');
                        }}
                        className="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Clear selected location"
                    >
                        <X className="size-4" />
                    </button>
                )}
                {(results.length > 0 || error) && (
                    <ul
                        id="dashboard-location-results"
                        role="listbox"
                        className="absolute inset-x-0 top-12 z-20 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 text-slate-800 shadow-xl"
                    >
                        {error && <li className="px-3 py-2 text-xs text-amber-700">Search is unavailable. Please retry.</li>}
                        {results.map((option) => (
                            <li key={option.id} role="option" aria-selected={false}>
                                <button
                                    type="button"
                                    onClick={() => choose(option)}
                                    className="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-blue-50"
                                >
                                    <span className="truncate font-medium">{option.name}</span>
                                    <span className="shrink-0 text-xs text-slate-500">{option.province_name}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                {query.trim().length >= 2 && !loading && !error && !selected && results.length === 0 && (
                    <p className="mt-2 text-xs text-blue-100">No matching city or municipality yet — keep typing.</p>
                )}
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                <label className="flex flex-1 flex-col gap-1 text-xs font-medium text-blue-100">
                    Assessment focus
                    <select
                        value={focus}
                        onChange={(event) => setFocus(event.target.value)}
                        className="h-10 rounded-xl border-0 bg-white/15 px-3 text-sm text-white focus:ring-2 focus:ring-white/70 focus:outline-none [&>option]:text-slate-900"
                    >
                        <option value="general">All dimensions</option>
                        {dimensions.map((dimension) => (
                            <option key={dimension.key} value={dimension.key}>
                                {dimension.label}
                            </option>
                        ))}
                    </select>
                </label>
                {selected ? (
                    <Link
                        href={route('location.assess', { city: selected.psg_code, focus })}
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-semibold text-[#087dcc] shadow-sm hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    >
                        Run assessment <ArrowRight className="size-4" aria-hidden="true" />
                    </Link>
                ) : (
                    <span className="inline-flex h-10 cursor-not-allowed items-center justify-center gap-2 rounded-xl bg-white/30 px-4 text-sm font-semibold text-white/80">
                        Run assessment <ArrowRight className="size-4" aria-hidden="true" />
                    </span>
                )}
            </div>

            <div className="flex flex-wrap gap-2 border-t border-white/20 pt-4 text-xs">
                {selected && (
                    <Link
                        href={route('user.intelligence-map.index', { level: 'municipality', location: selected.id })}
                        className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 font-medium hover:bg-white/25"
                    >
                        <Globe2 className="size-3.5" aria-hidden="true" /> View {selected.name} on the map
                    </Link>
                )}
                <button
                    type="button"
                    onClick={() => chat?.show()}
                    className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 font-medium hover:bg-white/25"
                >
                    <MessageCircle className="size-3.5" aria-hidden="true" /> Talk to GeoAgent
                </button>
                <Link
                    href={route('user.intelligence-map.index')}
                    className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 font-medium hover:bg-white/25"
                >
                    <MapPin className="size-3.5" aria-hidden="true" /> Browse the intelligence map
                </Link>
            </div>
        </section>
    );
}

function WorkflowCard() {
    const steps = [
        { title: 'Select a location', text: 'City or municipality and its barangays.' },
        { title: 'Assess the evidence', text: 'Indicators compared with dynamic national references.' },
        { title: 'Find gaps & priority areas', text: 'Severity scores and barangay priority ranking.' },
        { title: 'Match DOST technologies', text: 'Catalog fit, suitability, feasibility and partners.' },
        { title: 'Draft the action plan', text: 'Five executive outputs plus an activity matrix.' },
    ];

    return (
        <Panel title="How decisions are built">
            <ol className="flex flex-col gap-3">
                {steps.map((step, index) => (
                    <li key={step.title} className="flex gap-3">
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-semibold text-[#087dcc] dark:bg-sky-950 dark:text-sky-300">
                            {index + 1}
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium text-slate-800 dark:text-slate-100">{step.title}</span>
                            <span className="block text-xs text-slate-500 dark:text-slate-400">{step.text}</span>
                        </span>
                    </li>
                ))}
            </ol>
        </Panel>
    );
}

/** Client-side pagination; resets to the first page when `resetKey` changes. */
function usePager<T>(items: T[], size: number, resetKey: unknown) {
    const [page, setPage] = useState(0);
    useEffect(() => setPage(0), [resetKey]);
    const pages = Math.max(1, Math.ceil(items.length / size));
    const current = Math.min(page, pages - 1);
    return { rows: items.slice(current * size, current * size + size), page: current, pages, setPage, total: items.length };
}

function Pager({ page, pages, setPage, total, unit }: { page: number; pages: number; setPage: (page: number) => void; total: number; unit: string }) {
    if (pages <= 1) return null;
    const button =
        'flex size-7 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-blue-50 hover:text-[#087dcc] disabled:opacity-40 dark:border-slate-700 dark:text-slate-300';
    return (
        <nav aria-label={`${unit} pages`} className="mt-auto flex items-center justify-between gap-2 pt-1 text-[11px] text-slate-500">
            <span className="tabular-nums">
                {total} {unit}
            </span>
            <span className="flex items-center gap-1.5">
                <button type="button" className={button} disabled={page === 0} onClick={() => setPage(page - 1)} aria-label="Previous page">
                    <ChevronLeft className="size-4" />
                </button>
                <span className="min-w-12 text-center tabular-nums">
                    {page + 1} / {pages}
                </span>
                <button type="button" className={button} disabled={page >= pages - 1} onClick={() => setPage(page + 1)} aria-label="Next page">
                    <ChevronRight className="size-4" />
                </button>
            </span>
        </nav>
    );
}

function CatalogPanel({ techs, programs }: { techs: TechRecord[]; programs: ProgramRecord[] }) {
    const [tab, setTab] = useState<'techs' | 'programs'>('techs');
    const [filter, setFilter] = useState('');
    const term = filter.trim().toLowerCase();

    const items = useMemo(() => {
        const rows =
            tab === 'techs'
                ? techs.map((tech) => ({
                      id: tech.id,
                      code: tech.code,
                      name: tech.name,
                      agency: tech.agency,
                      group: tech.sector,
                      status: tech.status,
                  }))
                : programs.map((program) => ({
                      id: program.id,
                      code: program.code,
                      name: program.name,
                      agency: program.agency,
                      group: program.category,
                      status: program.active_status,
                  }));
        if (!term) return rows;
        return rows.filter((row) => [row.name, row.code, row.agency, row.group].some((value) => value?.toLowerCase().includes(term)));
    }, [tab, techs, programs, term]);
    const pager = usePager(items, 10, `${tab}:${term}`);

    const tabs = [
        { key: 'techs' as const, label: 'Technologies', count: techs.length },
        { key: 'programs' as const, label: 'Programs', count: programs.length },
    ];

    return (
        <Panel
            title="Catalog"
            action={
                <div role="tablist" aria-label="Catalog" className="flex rounded-lg bg-slate-100 p-0.5 text-xs dark:bg-slate-800">
                    {tabs.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            role="tab"
                            aria-selected={tab === item.key}
                            onClick={() => setTab(item.key)}
                            className={`rounded-md px-2.5 py-1 font-medium ${tab === item.key ? 'bg-white text-[#087dcc] shadow-xs dark:bg-slate-900 dark:text-sky-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'}`}
                        >
                            {item.label} <span className="tabular-nums opacity-70">{item.count}</span>
                        </button>
                    ))}
                </div>
            }
        >
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                <input
                    type="search"
                    value={filter}
                    onChange={(event) => setFilter(event.target.value)}
                    placeholder="Filter catalog"
                    aria-label="Filter catalog"
                    className="h-9 w-full rounded-lg border border-slate-200 bg-white pl-9 text-sm text-slate-900 placeholder:text-slate-400 focus:border-[#087dcc] focus:ring-2 focus:ring-[#087dcc]/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                />
            </div>
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {pager.rows.map((item) => (
                    <li key={`${tab}-${item.id}`} className="flex items-center gap-3 py-2.5">
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100" title={item.name}>
                                {item.name}
                            </span>
                            <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400">
                                {[item.agency, item.group].filter(Boolean).join(' · ') || 'Details not recorded'}
                            </span>
                        </span>
                        {item.status && (
                            <span className="shrink-0 rounded-full bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
                                {item.status.split('/')[0].trim()}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            {!items.length && (
                <p className="text-sm text-slate-500">{term ? 'No catalog entries match this filter.' : 'The catalog is not loaded.'}</p>
            )}
            <Pager {...pager} unit="entries" />
        </Panel>
    );
}

function MyPlanning({ planning }: { planning: DashboardProps['planning'] }) {
    const chat = usePlanningChat();
    const { totals, recentPlans, recentConversations } = planning;
    const [tab, setTab] = useState<'plans' | 'chats'>('plans');
    const plans = usePager(recentPlans, 10, 'plans');
    const chats = usePager(recentConversations, 10, 'chats');
    const active = tab === 'plans' ? plans : chats;

    return (
        <Panel
            title="My planning activity"
            action={
                <div role="tablist" aria-label="Planning activity" className="flex rounded-lg bg-slate-100 p-0.5 text-xs dark:bg-slate-800">
                    {(
                        [
                            ['plans', 'Plans', totals.plans],
                            ['chats', 'Chats', totals.conversations],
                        ] as const
                    ).map(([key, label, count]) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={tab === key}
                            onClick={() => setTab(key)}
                            className={`rounded-md px-2.5 py-1 font-medium ${tab === key ? 'bg-white text-[#087dcc] shadow-xs dark:bg-slate-900 dark:text-sky-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'}`}
                        >
                            {label} <span className="tabular-nums opacity-70">{count}</span>
                        </button>
                    ))}
                </div>
            }
        >
            <p className="text-xs text-slate-500 dark:text-slate-400">
                <span className="font-semibold text-slate-800 tabular-nums dark:text-slate-100">{numberFormat.format(totals.municipalities)}</span>{' '}
                {totals.municipalities === 1 ? 'location' : 'locations'} planned
            </p>

            {tab === 'plans' &&
                (recentPlans.length ? (
                    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                        {plans.rows.map((plan) => (
                            <li key={plan.id}>
                                <button
                                    type="button"
                                    onClick={() => chat?.openPlan(plan.city, plan.id)}
                                    className="flex w-full items-center gap-3 rounded-lg px-1 py-2.5 text-left hover:bg-blue-50 dark:hover:bg-slate-800"
                                >
                                    <FileText className="size-4 shrink-0 text-[#087dcc]" aria-hidden="true" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{plan.title}</span>
                                        <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400">
                                            {plan.municipality} · v{plan.version} · {formatDate(plan.created_at)}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <EmptyPlanning onStart={() => chat?.show()} />
                ))}

            {tab === 'chats' &&
                (recentConversations.length ? (
                    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                        {chats.rows.map((conversation) => (
                            <li key={conversation.id}>
                                <Link
                                    href={route('location.assess', { city: conversation.city })}
                                    className="flex items-center gap-3 rounded-lg px-1 py-2.5 hover:bg-blue-50 dark:hover:bg-slate-800"
                                >
                                    <MessagesSquare className="size-4 shrink-0 text-[#087dcc]" aria-hidden="true" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm text-slate-700 dark:text-slate-200">{conversation.title}</span>
                                        <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400">
                                            {conversation.municipality} · {formatDate(conversation.updated_at)}
                                        </span>
                                    </span>
                                    <ArrowRight className="size-4 shrink-0 text-slate-400" aria-hidden="true" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <EmptyPlanning onStart={() => chat?.show()} />
                ))}

            <Pager {...active} unit={tab === 'plans' ? 'recent plans' : 'recent chats'} />
        </Panel>
    );
}

function EmptyPlanning({ onStart }: { onStart: () => void }) {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-200 p-6 text-center dark:border-slate-700">
            <Sparkles className="size-5 text-[#087dcc]" aria-hidden="true" />
            <p className="text-sm text-slate-600 dark:text-slate-300">Nothing here yet.</p>
            <button type="button" onClick={onStart} className="text-xs font-semibold text-[#087dcc] hover:underline">
                Talk to GeoAgent
            </button>
        </div>
    );
}
