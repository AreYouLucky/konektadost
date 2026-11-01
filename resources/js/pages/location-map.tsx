import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import type { GeoFeatureCollection, LocationMapProps, MapMarkerItem, MunicipalityProperties, ProvinceProperties } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Building2, Check, ChevronDown, ChevronLeft, ChevronUp, Copy, Cpu, FlaskConical, Layers, Loader2, Radio } from 'lucide-react';
import { lazy, Suspense, useCallback, useMemo, useState } from 'react';

// Leaflet needs a DOM, so the map is loaded only in the browser. This also
// keeps the (large) province GeoJSON from blocking the rest of the page.
const ServiceAreaMap = lazy(() => import('@/components/service-area-map'));

export default function LocationMapPage({ provinces, selectedProvince, municipalities, selectedCity, mapMarkers }: LocationMapProps) {
    const [showLabs, setShowLabs] = useState(true);
    const [showTechs, setShowTechs] = useState(true);
    const [showTelecom, setShowTelecom] = useState(false);
    const [showFacilities, setShowFacilities] = useState(false);
    const [layersOpen, setLayersOpen] = useState(true);

    const selectedProvinceFeature = useMemo(
        () => provinces?.features.find((f) => f.properties.psg_code === selectedProvince) ?? null,
        [provinces, selectedProvince],
    );

    const selectedCityFeature = useMemo(
        () => municipalities?.features.find((f) => f.properties.psg_code === selectedCity) ?? null,
        [municipalities, selectedCity],
    );

    const visibleMarkers = useMemo<MapMarkerItem[]>(() => {
        const list: MapMarkerItem[] = [];
        if (showLabs && mapMarkers?.dost_labs) {
            list.push(...mapMarkers.dost_labs);
        }
        if (showTechs && mapMarkers?.dost_techs) {
            list.push(...mapMarkers.dost_techs);
        }
        if (showFacilities && mapMarkers?.facilities) {
            list.push(...mapMarkers.facilities);
        }
        if (showTelecom && mapMarkers?.telecom_sites) {
            list.push(...mapMarkers.telecom_sites);
        }
        return list;
    }, [mapMarkers, showLabs, showTechs, showFacilities, showTelecom]);

    // Drill-down state lives in the URL, so the map is linkable and the
    // browser back button moves between levels. `only` keeps each step to a
    // single small prop instead of re-sending every province polygon.
    // A partial reload only returns the props named in `only`; every other
    // prop keeps its current client value. `selectedProvince` must therefore
    // be requested explicitly or it never updates, which leaves the city
    // dropdown disabled and the "All provinces" button hidden.
    const drillIntoProvince = useCallback((psgCode: string) => {
        router.get(
            route('location.show'),
            { province: psgCode },
            { preserveState: true, preserveScroll: true, only: ['selectedProvince', 'municipalities', 'mapMarkers'] },
        );
    }, []);

    const selectCity = useCallback(
        (psgCode: string) => {
            router.get(
                route('location.show'),
                { province: selectedProvince, city: psgCode },
                { preserveState: true, preserveScroll: true, only: ['selectedProvince', 'selectedCity', 'mapMarkers'] },
            );
        },
        [selectedProvince],
    );

    const showAllProvinces = useCallback(() => {
        router.get(
            route('location.show'),
            {},
            { preserveState: true, preserveScroll: true, only: ['selectedProvince', 'municipalities', 'selectedCity', 'mapMarkers'] },
        );
    }, []);

    // Assessment is keyed on the city, so a selected province alone is not
    // enough to run one.
    const assessSelectedCity = useCallback(
        (focus: string) => {
            if (!selectedCity) return;

            router.get(route('location.assess'), { city: selectedCity, focus });
        },
        [selectedCity],
    );

    const labsCount = mapMarkers?.dost_labs?.length ?? 0;
    const techsCount = mapMarkers?.dost_techs?.length ?? 0;
    const facilitiesCount = mapMarkers?.facilities?.length ?? 0;
    const telecomCount = mapMarkers?.telecom_sites?.length ?? 0;

    return (
        <AppSidebarLayout>
            <Head title="Service Area Map" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading title="Service Area Map" description="Select a province, then a city or municipality, to define your service area." />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <Card className="overflow-hidden">
                        <CardContent className="relative p-0">
                            <div className="h-[32rem] w-full">
                                <Suspense
                                    fallback={
                                        <div className="text-muted-foreground flex h-full items-center justify-center gap-2 text-sm">
                                            <Loader2 className="size-4 animate-spin" />
                                            Loading map…
                                        </div>
                                    }
                                >
                                    <ServiceAreaMap
                                        provinces={provinces}
                                        selectedProvince={selectedProvince}
                                        municipalities={municipalities}
                                        selectedCity={selectedCity}
                                        markers={visibleMarkers}
                                        onProvinceClick={drillIntoProvince}
                                        onCityClick={selectCity}
                                    />
                                </Suspense>
                            </div>

                            {selectedProvince && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={showAllProvinces}
                                    className="absolute top-3 left-14 z-[1000] shadow-sm"
                                >
                                    <ChevronLeft className="size-4" />
                                    All provinces
                                </Button>
                            )}

                            <div className="bg-background/95 absolute top-3 right-3 z-[1000] w-60 rounded-xl border shadow-lg backdrop-blur">
                                <button
                                    type="button"
                                    onClick={() => setLayersOpen((prev) => !prev)}
                                    className="flex w-full items-center justify-between px-3.5 py-2.5 text-left text-xs font-semibold tracking-wide uppercase"
                                >
                                    <span className="flex items-center gap-2">
                                        <Layers className="size-3.5 text-teal-600" />
                                        <span>Map Markers</span>
                                    </span>
                                    <span className="text-muted-foreground flex items-center gap-1 font-normal normal-case">
                                        <span>{visibleMarkers.length} shown</span>
                                        {layersOpen ? <ChevronUp className="size-3.5" /> : <ChevronDown className="size-3.5" />}
                                    </span>
                                </button>

                                {layersOpen && (
                                    <div className="flex flex-col gap-1.5 border-t p-2.5">
                                        <button
                                            type="button"
                                            onClick={() => setShowLabs((prev) => !prev)}
                                            className={`flex w-full items-center justify-between rounded-lg border px-2.5 py-2 text-left text-xs transition-all ${
                                                showLabs
                                                    ? 'border-[#00aeef]/50 bg-[#00aeef]/10 text-blue-950 dark:text-cyan-100'
                                                    : 'hover:bg-muted/70 text-muted-foreground border-transparent'
                                            }`}
                                        >
                                            <span className="flex items-center gap-2.5">
                                                <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-[#00aeef] text-white">
                                                    <FlaskConical className="size-3.5" />
                                                </span>
                                                <span className="flex flex-col">
                                                    <span className="font-medium">DOST Laboratories</span>
                                                    <span className="text-muted-foreground text-[10px]">Hexagon · OneLab</span>
                                                </span>
                                            </span>
                                            <Badge
                                                variant={showLabs ? 'default' : 'secondary'}
                                                className={showLabs ? 'bg-[#00aeef] text-[#0b2454]' : ''}
                                            >
                                                {labsCount}
                                            </Badge>
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => setShowTechs((prev) => !prev)}
                                            className={`flex w-full items-center justify-between rounded-lg border px-2.5 py-2 text-left text-xs transition-all ${
                                                showTechs
                                                    ? 'border-[#00aeef]/50 bg-[#00aeef]/10 text-blue-950 dark:text-blue-100'
                                                    : 'hover:bg-muted/70 text-muted-foreground border-transparent'
                                            }`}
                                        >
                                            <span className="flex items-center gap-2.5">
                                                <span className="flex size-6 shrink-0 rotate-45 items-center justify-center rounded-xs bg-[#00aeef] text-white">
                                                    <Cpu className="size-3.5 -rotate-45" />
                                                </span>
                                                <span className="flex flex-col">
                                                    <span className="font-medium">DOST Technologies</span>
                                                    <span className="text-muted-foreground text-[10px]">Diamond · Deployed</span>
                                                </span>
                                            </span>
                                            <Badge
                                                variant={showTechs ? 'default' : 'secondary'}
                                                className={showTechs ? 'bg-[#00aeef] text-[#0b2454]' : ''}
                                            >
                                                {techsCount}
                                            </Badge>
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => setShowTelecom((prev) => !prev)}
                                            className={`flex w-full items-center justify-between rounded-lg border px-2.5 py-2 text-left text-xs transition-all ${
                                                showTelecom
                                                    ? 'border-orange-500/50 bg-orange-500/10 text-orange-950 dark:text-orange-100'
                                                    : 'hover:bg-muted/70 text-muted-foreground border-transparent'
                                            }`}
                                        >
                                            <span className="flex items-center gap-2.5">
                                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-orange-600 text-white">
                                                    <Radio className="size-3.5" />
                                                </span>
                                                <span className="flex flex-col">
                                                    <span className="font-medium">Cell Sites / Towers</span>
                                                    <span className="text-muted-foreground text-[10px]">Circle · Telecom</span>
                                                </span>
                                            </span>
                                            <Badge variant={showTelecom ? 'default' : 'secondary'} className={showTelecom ? 'bg-orange-600' : ''}>
                                                {telecomCount}
                                            </Badge>
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => setShowFacilities((prev) => !prev)}
                                            className={`flex w-full items-center justify-between rounded-lg border px-2.5 py-2 text-left text-xs transition-all ${
                                                showFacilities
                                                    ? 'border-blue-500/50 bg-blue-500/10 text-blue-950 dark:text-blue-100'
                                                    : 'hover:bg-muted/70 text-muted-foreground border-transparent'
                                            }`}
                                        >
                                            <span className="flex items-center gap-2.5">
                                                <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-blue-900 text-white">
                                                    <Building2 className="size-3.5" />
                                                </span>
                                                <span className="flex flex-col">
                                                    <span className="font-medium">Local Facilities</span>
                                                    <span className="text-muted-foreground text-[10px]">
                                                        {selectedProvince ? 'Health · School · Safety' : 'Select province first'}
                                                    </span>
                                                </span>
                                            </span>
                                            <Badge variant={showFacilities ? 'default' : 'secondary'} className={showFacilities ? 'bg-blue-900' : ''}>
                                                {facilitiesCount}
                                            </Badge>
                                        </button>

                                        <div className="mt-1 flex items-center justify-between border-t pt-2 text-[11px]">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setShowLabs(true);
                                                    setShowTechs(true);
                                                    setShowTelecom(true);
                                                    setShowFacilities(true);
                                                }}
                                                className="font-medium text-blue-700 hover:underline"
                                            >
                                                Show all
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setShowLabs(false);
                                                    setShowTechs(false);
                                                    setShowTelecom(false);
                                                    setShowFacilities(false);
                                                }}
                                                className="text-muted-foreground hover:text-foreground hover:underline"
                                            >
                                                Hide all
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-6">
                        <LocationSelector
                            provinces={provinces}
                            municipalities={municipalities}
                            selectedProvince={selectedProvince}
                            selectedCity={selectedCity}
                            onProvinceChange={drillIntoProvince}
                            onCityChange={selectCity}
                            onAssess={assessSelectedCity}
                        />

                        <SelectionSummary province={selectedProvinceFeature?.properties ?? null} city={selectedCityFeature?.properties ?? null} />
                    </div>
                </div>
            </div>
        </AppSidebarLayout>
    );
}

function LocationSelector({
    provinces,
    municipalities,
    selectedProvince,
    selectedCity,
    onProvinceChange,
    onCityChange,
    onAssess,
}: {
    provinces: GeoFeatureCollection<ProvinceProperties>;
    municipalities: GeoFeatureCollection<MunicipalityProperties> | null;
    selectedProvince: string | null;
    selectedCity: string | null;
    onProvinceChange: (psgCode: string) => void;
    onCityChange: (psgCode: string) => void;
    onAssess: (focus: string) => void;
}) {
    const [focus, setFocus] = useState('general');
    const sortedProvinces = useMemo(() => [...provinces.features].sort((a, b) => a.properties.name.localeCompare(b.properties.name)), [provinces]);

    const sortedCities = useMemo(
        () => (municipalities ? [...municipalities.features].sort((a, b) => a.properties.name.localeCompare(b.properties.name)) : []),
        [municipalities],
    );

    const selectClass =
        'border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50';

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Select area</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="province-select">Province</Label>
                    <select
                        id="province-select"
                        className={selectClass}
                        value={selectedProvince ?? ''}
                        onChange={(e) => e.target.value && onProvinceChange(e.target.value)}
                    >
                        <option value="">Select a province…</option>
                        {sortedProvinces.map((f) => (
                            <option key={f.properties.psg_code} value={f.properties.psg_code}>
                                {f.properties.name}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="city-select">City / Municipality</Label>
                    <select
                        id="city-select"
                        className={selectClass}
                        value={selectedCity ?? ''}
                        disabled={!selectedProvince}
                        onChange={(e) => e.target.value && onCityChange(e.target.value)}
                    >
                        <option value="">{selectedProvince ? 'Select a city or municipality…' : 'Pick a province first'}</option>
                        {sortedCities.map((f) => (
                            <option key={f.properties.psg_code} value={f.properties.psg_code}>
                                {f.properties.name}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="focus-select">Assessment focus</Label>
                    <select id="focus-select" className={selectClass} value={focus} onChange={(e) => setFocus(e.target.value)}>
                        <option value="general">General assessment (all available)</option>
                        <option value="population_and_social_needs">Population and social needs</option>
                        <option value="health_and_public_health">Health and public health</option>
                        <option value="education_skills_and_scientific_talent">Education, skills and scientific talent</option>
                        <option value="science_technology_and_innovation_capacity">Science, technology and innovation capacity</option>
                        <option value="infrastructure_and_connectivity">Infrastructure and digital connectivity</option>
                        <option value="environment_climate_disaster">Environment, climate and disaster risk</option>
                        <option value="economy_and_livelihood" disabled>
                            Economy and livelihood opportunities — no data
                        </option>
                        <option value="agriculture_fisherries_food" disabled>
                            Agriculture, fisheries and food systems — no data
                        </option>
                        <option value="essential_resources" disabled>
                            Essential resources and environmental services — no data
                        </option>
                        <option value="institutional_capacity" disabled>
                            Institutional capacity and service delivery — no data
                        </option>
                    </select>
                </div>

                <Button onClick={() => onAssess(focus)} disabled={!selectedCity}>
                    Assess Location
                </Button>

                <p className="text-muted-foreground text-xs">
                    Select a city or municipality to run an assessment. Click an area on the map, or use the dropdowns.
                </p>
            </CardContent>
        </Card>
    );
}

function SelectionSummary({ province, city }: { province: ProvinceProperties | null; city: MunicipalityProperties | null }) {
    const [copied, setCopied] = useState(false);

    const copyPsgCode = async () => {
        if (!city) return;
        try {
            await navigator.clipboard.writeText(city.psg_code);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard is unavailable over plain http or without permission.
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Selected area</CardTitle>
            </CardHeader>
            <CardContent>
                {!province ? (
                    <p className="text-muted-foreground text-sm">Nothing selected yet. Click a province on the map to begin.</p>
                ) : (
                    <div className="flex flex-col gap-3">
                        <Row label="Province" value={province.name} badge={province.english_type} />

                        {city ? (
                            <>
                                <Row label="City / Municipality" value={city.name} />
                                <Row
                                    label="PSGC code"
                                    value={city.psg_code}
                                    mono
                                    action={
                                        <button
                                            type="button"
                                            onClick={copyPsgCode}
                                            className="text-muted-foreground hover:text-foreground transition-colors"
                                            aria-label="Copy PSGC code"
                                        >
                                            {copied ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5" />}
                                        </button>
                                    }
                                />
                                {city.center_lat !== null && city.center_lng !== null && (
                                    <Row label="Center" value={`${city.center_lat.toFixed(5)}, ${city.center_lng.toFixed(5)}`} mono />
                                )}
                            </>
                        ) : (
                            <p className="text-muted-foreground text-sm">Now pick a city or municipality within {province.name}.</p>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function Row({
    label,
    value,
    badge,
    mono,
    action,
}: {
    label: string;
    value: string;
    badge?: string | null;
    mono?: boolean;
    action?: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-muted-foreground text-xs tracking-wide uppercase">{label}</span>
            <div className="flex items-center gap-2">
                <span className={`text-sm font-medium ${mono ? 'font-mono text-xs' : ''}`}>{value}</span>
                {badge && <Badge variant="secondary">{badge}</Badge>}
                {action}
            </div>
        </div>
    );
}
