import type { MapLocation } from '@/components/philippine-map';
import { Link } from '@inertiajs/react';
import {
    Building2,
    ClipboardList,
    Cpu,
    FlaskConical,
    Info,
    LayoutDashboard,
    LoaderCircle,
    MapPin,
    PanelRightClose,
    PanelRightOpen,
    Users,
    Wheat,
} from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { LOCATION_LABELS } from '../location-scope';
import { count } from '../resource-presentation';
import type { useProvinceAgriculture } from '../use-province-agriculture';
import type { ProvinceResourceData } from '../use-province-resources';
import { AgricultureHighlight, TechnologyHighlight } from './overview-highlights';
import PopulationDetails from './population-details';
import ProvinceAgricultureCard from './province-agriculture-card';
import ProvinceResourceOverview from './province-resource-overview';
import TechnologyDetails from './technology-details';

const tabs = [
    { key: 'overview', label: 'Overview', icon: LayoutDashboard },
    { key: 'facilities', label: 'Facilities', icon: Building2 },
    { key: 'laboratories', label: 'Labs', icon: FlaskConical },
    { key: 'agriculture', label: 'Agriculture', icon: Wheat },
    { key: 'technologies', label: 'DOST tech', icon: Cpu },
] as const;

export default function ProvinceResourcesCard({
    selection,
    parents,
    agriculture,
    data,
    loading,
    error,
    retry,
    open,
    onOpenChange,
    showAssessmentLink = true,
    assessmentMap = 'admin',
}: {
    selection: MapLocation;
    parents: string[];
    agriculture: ReturnType<typeof useProvinceAgriculture>;
    data: ProvinceResourceData | null;
    loading: boolean;
    error: string | null;
    retry: () => void;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    showAssessmentLink?: boolean;
    assessmentMap?: 'user' | 'admin';
}) {
    const panelId = useId();
    const scrollArea = useRef<HTMLDivElement>(null);
    const [tab, setTab] = useState<(typeof tabs)[number]['key']>('overview');
    const openTab = (key: (typeof tabs)[number]['key']) => {
        setTab(key);
        scrollArea.current?.scrollTo({ top: 0 });
    };
    if (!open)
        return (
            <aside data-map-info-panel className="absolute top-4 right-4 z-[18] @[1100px]/philippine-map:right-6">
                <button
                    type="button"
                    onClick={() => onOpenChange(true)}
                    aria-expanded={false}
                    className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/20 bg-[#087dcc] px-4 py-2 text-xs font-semibold text-white hover:bg-[#087dcc]/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087dcc]"
                >
                    <PanelRightOpen size={17} /> Location details
                </button>
            </aside>
        );
    return (
        <aside
            data-map-info-panel
            id={panelId}
            aria-label={`Details for ${selection.name}`}
            aria-busy={loading}
            className="absolute top-4 right-4 bottom-[110px] z-[18] flex min-h-0 w-[min(390px,calc(100cqw-32px))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 text-slate-800 shadow-[0_16px_60px_#087dcc24] @[1100px]/philippine-map:top-6 @[1100px]/philippine-map:right-6 [&_button]:cursor-pointer"
        >
            <header className="flex shrink-0 items-start gap-3 border-b border-slate-100 px-4 py-4">
                <span className="mt-0.5 grid size-9 shrink-0 place-items-center rounded-xl bg-blue-50 text-[#087dcc]">
                    <MapPin size={19} aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-[10px] font-semibold tracking-wider text-slate-500 uppercase">{LOCATION_LABELS[selection.level]}</p>
                    <h2 className="mt-1 text-xl leading-tight font-semibold tracking-tight text-[#087dcc]">{selection.name}</h2>
                    {parents.length > 0 && <p className="mt-1.5 text-[11px] leading-relaxed text-slate-500">{parents.join(' / ')}</p>}
                </div>
                <button
                    type="button"
                    onClick={() => onOpenChange(false)}
                    aria-label="Collapse location details"
                    aria-expanded={true}
                    aria-controls={panelId}
                    className="flex size-9 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-blue-50 hover:text-[#087dcc] focus-visible:outline-2 focus-visible:outline-[#087dcc]"
                >
                    <PanelRightClose size={18} />
                </button>
            </header>
            <nav aria-label="Location detail sections" className="grid shrink-0 grid-cols-5 gap-1 border-b border-slate-100 bg-white p-2">
                {tabs.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        aria-current={tab === item.key ? 'page' : undefined}
                        aria-controls={`${panelId}-content`}
                        onClick={() => openTab(item.key)}
                        className={`flex min-h-12 min-w-0 flex-col items-center justify-center gap-1 rounded-lg px-1 py-2 text-[10px] font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-[#087dcc] ${tab === item.key ? 'bg-[#087dcc] text-white' : item.key === 'technologies' ? 'bg-blue-50 text-[#087dcc] hover:bg-blue-100' : 'text-slate-500 hover:bg-blue-50 hover:text-[#087dcc]'}`}
                    >
                        <item.icon size={16} aria-hidden="true" />
                        {item.label}
                    </button>
                ))}
            </nav>
            <div
                ref={scrollArea}
                id={`${panelId}-content`}
                className="px-2[scrollbar-gutter:stable] min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain bg-slate-50/60 px-2 py-4"
            >
                {loading && (
                    <p role="status" className="flex items-center gap-2 py-4 text-xs text-[#738580]">
                        <LoaderCircle size={16} className="animate-spin" /> Loading location details…
                    </p>
                )}
                {error && (
                    <div role="alert" className="rounded-xl bg-amber-50 p-3 text-xs text-amber-900">
                        <p>{error}</p>
                        <button type="button" onClick={retry} className="mt-2 font-semibold text-[#087dcc] underline">
                            Try again
                        </button>
                    </div>
                )}
                {data && (
                    <>
                        {tab === 'overview' && (
                            <>
                                <PopulationOverview data={data} />
                                <TechnologyHighlight data={data} />
                                <AgricultureHighlight agriculture={agriculture} />
                                <PopulationDetails data={data} />
                                <ProvinceResourceOverview data={data} section="overview" />
                            </>
                        )}
                        {(tab === 'facilities' || tab === 'laboratories') && <ProvinceResourceOverview data={data} section={tab} />}
                        {tab === 'technologies' && <TechnologyDetails data={data} />}
                    </>
                )}
                {tab === 'agriculture' && <ProvinceAgricultureCard agriculture={agriculture} />}
            </div>
            {showAssessmentLink && (
                <footer className="shrink-0 space-y-2 border-t border-slate-100 bg-white p-4">
                    <Link
                        href={route('location.assess', { level: selection.level, location: selection.id, focus: 'general', map: assessmentMap })}
                        className="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#087dcc] px-3 text-xs font-semibold text-white hover:bg-[#087dcc]/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087dcc]"
                    >
                        <ClipboardList size={16} aria-hidden="true" /> View location assessment
                    </Link>
                    <p className="text-center text-[10px] leading-relaxed text-slate-500">Indicators, benchmarks and local resource totals</p>
                </footer>
            )}
        </aside>
    );
}

function PopulationOverview({ data }: { data: ProvinceResourceData }) {
    const { population, population_breakdown: breakdown } = data;
    const recorded = breakdown.rows.filter((row) => row.value !== null).length;
    return (
        <section aria-label="Population" className="rounded-xl border border-blue-100 bg-white p-4">
            <div className="flex items-center gap-2.5">
                <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-blue-50 text-[#087dcc]">
                    <Users size={18} aria-hidden="true" />
                </span>
                <div>
                    <h3 className="text-sm font-semibold text-[#087dcc]">People &amp; population</h3>
                    <p className="mt-0.5 text-[11px] text-slate-500">Recorded residents in the selected area</p>
                </div>
            </div>
            <p className={`mt-4 font-semibold tracking-tight text-slate-900 tabular-nums ${population.value === null ? 'text-2xl' : 'text-4xl'}`}>
                {population.value === null ? 'Unavailable' : count.format(population.value)}
            </p>
            <dl className="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-3 text-[11px]">
                <div>
                    <dt className="text-slate-500">Active collection year</dt>
                    <dd className="mt-1 font-semibold text-slate-800">{population.year ?? 'Not configured'}</dd>
                </div>
                <div className="border-l border-slate-100 pl-3">
                    <dt className="text-slate-500">{breakdown.level === 'municipality' ? 'City / municipal figures' : 'Barangay figures'}</dt>
                    <dd className="mt-1 font-semibold text-slate-800 tabular-nums">
                        {breakdown.rows.length > 0
                            ? `${count.format(recorded)} / ${count.format(breakdown.rows.length)} recorded`
                            : 'No linked figures'}
                    </dd>
                </div>
            </dl>
            {population.reason && (
                <p className="mt-3 flex items-start gap-1.5 rounded-lg bg-slate-50 p-2 text-[10px] leading-relaxed text-slate-600">
                    <Info size={12} aria-hidden="true" className="mt-0.5 shrink-0" /> {population.reason}
                </p>
            )}
        </section>
    );
}
