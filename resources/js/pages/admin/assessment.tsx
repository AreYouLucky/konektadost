import PhilippineMap, { type MapLocation, type SearchMapProps } from '@/components/philippine-map';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, ChevronDown, ClipboardList, Info, LoaderCircle, MapPin } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { useMapMarkers } from './search-map/use-map-markers';

interface AssessmentMapProps extends SearchMapProps {
    dimensions: { key: string; label: string; supported: boolean }[];
}

export default function AssessmentMap({ dimensions, ...mapProps }: AssessmentMapProps) {
    const [path, setPath] = useState<MapLocation[]>([]);
    const [panelOpen, setPanelOpen] = useState(false);
    const { data, setData, get, processing, errors, clearErrors } = useForm({ municipality: '', focus: 'general' });
    const municipality = path.find((location) => location.level === 'municipality');
    const province = path.find((location) => location.level === 'province');
    const resources = useMapMarkers();
    const hasCode = /^\d{10}$/.test(municipality?.code ?? '');

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!municipality || !hasCode || processing) return;
        get(route('admin.assessment.start'), { preserveScroll: true, preserveState: true });
    }

    return (
        <>
            <Head title="Assessment Map">
                <meta name="description" content="Select a Philippine city or municipality and review its local needs and STI resources." />
            </Head>
            <main>
                <PhilippineMap
                    {...mapProps}
                    fullScreen
                    syncUrl
                    title="Assessment Map"
                    resourcePins={resources.pins}
                    resourceLoading={resources.loading}
                    resourceError={resources.error}
                    onRetryResources={resources.retry}
                    selectionContent={
                        resources.error ? (
                            <p role="alert" className="text-xs text-amber-900">
                                {resources.error}{' '}
                                <button type="button" onClick={resources.retry} className="underline">
                                    Retry markers
                                </button>
                            </p>
                        ) : undefined
                    }
                    onSelectionChange={(_, locations) => {
                        setPath(locations);
                        const city = locations.find((location) => location.level === 'municipality');
                        setData('municipality', city ? String(city.id) : '');
                        clearErrors();
                    }}
                />

                <Link
                    href={route('admin.dashboard.index')}
                    className="fixed top-4 left-4 z-30 inline-flex items-center gap-2 rounded-full border border-white bg-white/95 px-4 py-2.5 text-sm font-medium text-[#153d38] shadow-sm backdrop-blur focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#123575] md:top-8 md:left-8"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    <span>Dashboard</span>
                </Link>

                <aside
                    aria-label="Assessment setup"
                    className="fixed top-16 right-4 z-30 w-[min(320px,calc(100vw-32px))] overflow-hidden rounded-2xl border border-white bg-white/95 text-[#153d38] shadow-xl backdrop-blur-xl md:top-28 md:right-8"
                >
                    <div className="hidden items-center gap-3 border-b border-[#153d38]/10 p-5 md:flex">
                        <ClipboardList className="size-5 text-[#123575]" aria-hidden="true" />
                        <h2 className="font-semibold">Local assessment</h2>
                    </div>
                    <button
                        type="button"
                        className="flex w-full items-center justify-between gap-3 p-4 text-left text-sm font-semibold focus-visible:outline-2 focus-visible:outline-[#123575] md:hidden"
                        aria-expanded={panelOpen}
                        aria-controls="assessment-setup"
                        onClick={() => setPanelOpen(!panelOpen)}
                    >
                        <span className="flex min-w-0 items-center gap-2">
                            <ClipboardList className="size-4 shrink-0" aria-hidden="true" />
                            <span className="truncate">{municipality?.name ?? 'Set up assessment'}</span>
                        </span>
                        <ChevronDown className={`size-4 shrink-0 transition-transform ${panelOpen ? 'rotate-180' : ''}`} aria-hidden="true" />
                    </button>
                    <div
                        id="assessment-setup"
                        className={`${panelOpen ? 'block' : 'hidden'} max-h-[40dvh] overflow-y-auto p-5 md:block md:max-h-[calc(100dvh-220px)]`}
                    >
                        <form onSubmit={submit} className="space-y-5">
                            <div className="space-y-2" aria-live="polite">
                                <p className="text-xs font-semibold tracking-wide text-[#738580] uppercase">1. Select a location</p>
                                {municipality ? (
                                    <div className="rounded-xl bg-[#123575]/5 p-3">
                                        <p className="flex items-start gap-2 font-semibold">
                                            <MapPin className="mt-0.5 size-4 shrink-0 text-[#123575]" aria-hidden="true" />
                                            {municipality.name}
                                        </p>
                                        <p className="mt-1 pl-6 text-xs text-[#738580]">{province?.name}</p>
                                    </div>
                                ) : (
                                    <p className="text-sm leading-relaxed text-[#738580]">
                                        Search for a city or municipality, or select a province on the map to explore its locations.
                                    </p>
                                )}
                                {municipality && !hasCode && (
                                    <p className="text-sm text-amber-800">
                                        This location has no linked PSGC code for assessment. Choose another municipality.
                                    </p>
                                )}
                                {path.at(-1)?.level === 'barangay' && (
                                    <p className="text-xs text-[#738580]">The assessment covers the selected barangay's city or municipality.</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="assessment-focus" className="text-xs font-semibold tracking-wide text-[#738580] uppercase">
                                    2. Choose a focus
                                </Label>
                                <select
                                    id="assessment-focus"
                                    value={data.focus}
                                    onChange={(event) => setData('focus', event.target.value)}
                                    disabled={processing}
                                    className="w-full rounded-lg border border-[#153d38]/15 bg-white p-3 text-sm focus-visible:outline-2 focus-visible:outline-[#123575]"
                                >
                                    <option value="general">General assessment</option>
                                    {dimensions.map((dimension) => (
                                        <option key={dimension.key} value={dimension.key} disabled={!dimension.supported}>
                                            {dimension.label}
                                            {dimension.supported ? '' : ' — No data available'}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {(errors.municipality || errors.focus) && (
                                <p role="alert" className="text-sm text-red-700">
                                    {errors.municipality ?? errors.focus}
                                </p>
                            )}

                            <Button
                                type="submit"
                                disabled={!municipality || !hasCode || processing}
                                className="w-full rounded-lg bg-[#123575] text-white hover:bg-[#0b2454]"
                            >
                                {processing ? <LoaderCircle className="size-4 animate-spin" /> : <ArrowRight className="size-4" />}
                                {processing ? 'Opening assessment…' : 'View assessment'}
                            </Button>
                            <div className="flex items-start gap-2 border-t border-[#153d38]/10 pt-4 text-xs leading-relaxed text-[#738580]">
                                <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <p>
                                    Review recorded indicators, barangay priorities, laboratory services, and matching DOST interventions. Findings
                                    are advisory and depend on available data.
                                </p>
                            </div>
                        </form>
                    </div>
                </aside>
            </main>
        </>
    );
}
