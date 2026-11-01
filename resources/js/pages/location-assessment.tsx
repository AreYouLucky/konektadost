import AssessmentCharts from '@/components/assessment-charts';
import AssessmentPlanning from '@/components/assessment-planning';
import DataBars from '@/components/data-bars';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { evidenceState, type ProjectCatalogData } from '@/lib/assessment-planning';
import PopulationDetails from '@/pages/admin/search-map/partials/population-details';
import { AgricultureSummaryView } from '@/pages/admin/search-map/partials/province-agriculture-card';
import ProvinceResourceOverview from '@/pages/admin/search-map/partials/province-resource-overview';
import TechnologyDetails from '@/pages/admin/search-map/partials/technology-details';
import type { AgricultureSummary } from '@/pages/admin/search-map/use-province-agriculture';
import type { ProvinceResourceData } from '@/pages/admin/search-map/use-province-resources';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    Building2,
    CheckCircle2,
    Cpu,
    FlaskConical,
    Info,
    Layers,
    Lightbulb,
    MapPin,
    Search,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface AssessmentDimension {
    key: string;
    label: string;
    supported: boolean;
}

interface AssessmentResult {
    key: string;
    label: string;
    description: string;
    unit: string;
    dimension: string;
    source: string;
    caveat: string | null;
    value: number | null;
    benchmark: number | null;
    median: number | null;
    gap: number | null;
    status: 'available' | 'no_data' | 'no_benchmark';
    confidence: 'high' | 'low';
    reason: string;
    is_shortfall: boolean;
    basis_note?: string | null;
}

interface BarangayItem {
    psg_code: string;
    name: string;
    population: number | null;
    population_share: number | null;
    area_km2: number | null;
    density: number | null;
    urban_rural: 'Urban' | 'Rural' | null;
    health_facilities_count: number;
    schools_count: number;
    safety_facilities_count: number;
    telecom_sites_count: number;
    total_facilities_count: number;
    priority_level: 'high' | 'moderate' | 'covered' | 'unmeasured';
    priority_reason: string;
}

interface BarangayAssessmentSummary {
    total_barangays: number;
    total_population: number;
    average_population: number | null;
    urban_count: number;
    rural_count: number;
    high_priority_count: number;
    underserved_barangays_count: number;
    underserved_population: number;
    underserved_population_share: number | null;
    most_populous: {
        name: string;
        population: number;
        population_share: number | null;
    } | null;
    highest_density: {
        name: string;
        density: number;
        population: number | null;
    } | null;
}

interface LabServiceItem {
    test_name: string;
    method: string | null;
    reference: string | null;
    fee: number | null;
}

interface LocalLaboratoryItem {
    id: number;
    agency_name: string | null;
    code: string | null;
    city: string | null;
    website: string | null;
    contact_information: string | null;
    total_services: number | null;
    services_status?: 'available' | 'unavailable';
    services: LabServiceItem[];
}

interface DostInterventionItem {
    id: number;
    code: string;
    name: string;
    agency: string | null;
    sector: string | null;
    deployment_clusters: string | null;
    mechanism: string | null;
    status: string | null;
    outcome: string | null;
    matches_city_cluster: boolean;
    matches_focus: boolean;
}

interface PopulationStatistics {
    active_year: number | null;
    population_current: number | null;
    baseline_year: number | null;
    population_baseline: number | null;
    population_change: number | null;
    growth_rate_pct: number | null;
    annual_growth_rate_pct: number | null;
    history: { year: number; population: number }[];
}

interface BarangayClassificationCounts {
    total_barangays: number;
    urban_barangays: number;
    rural_barangays: number;
}

interface AssessmentPageProps {
    location: {
        psg_code: string | null;
        province_psg_code?: string | null;
        name: string;
        type: string | null;
        province: string | null;
        population: number | null;
        area_km2: number | null;
        income_class?: string | null;
        city_class?: string | null;
        urban_rural?: string | null;
        barangay_counts?: BarangayClassificationCounts | null;
        population_statistics?: PopulationStatistics | null;
    };
    focus: string;
    dimensions: AssessmentDimension[];
    results: AssessmentResult[];
    barangayAssessment?: {
        summary: BarangayAssessmentSummary;
        barangays: BarangayItem[];
    };
    localLaboratories?: LocalLaboratoryItem[];
    dostInterventions?: DostInterventionItem[];
    /** Collection year every population figure on this page belongs to. */
    populationYear?: number | null;
    populationReason?: string | null;
    assessmentParams?: Record<string, string | number>;
    mapReturnUrl?: string;
    resourceSummary?: ProvinceResourceData;
    agricultureSummary?: AgricultureSummary;
    projectCatalog?: ProjectCatalogData;
}

type ActiveSection = 'all' | 'indicators' | 'barangays' | 'labs' | 'techs' | 'resources';

export default function LocationAssessment({
    location,
    focus,
    dimensions,
    results,
    barangayAssessment,
    localLaboratories = [],
    dostInterventions = [],
    populationYear = null,
    populationReason = null,
    assessmentParams,
    mapReturnUrl,
    resourceSummary,
    agricultureSummary,
    projectCatalog,
}: AssessmentPageProps) {
    const [activeSection, setActiveSection] = useState<ActiveSection>('all');

    const focusLabel = dimensions.find((d) => d.key === focus)?.label ?? 'General assessment';
    const focusSupported = focus === 'general' || dimensions.find((d) => d.key === focus)?.supported;

    const shortfalls = results.filter((r) => evidenceState(r) === 'priority');
    const catalog = projectCatalog ?? {
        status: dostInterventions.length ? ('available' as const) : ('unavailable' as const),
        source: 'DOST catalog entries returned with this assessment',
        coverage: 'NCR reference catalog. Confirm applicability, eligibility, availability and local deployment with DOST.',
        records: dostInterventions,
    };

    const backParams = location.province_psg_code ? { province: location.province_psg_code, city: location.psg_code } : { city: location.psg_code };

    const barangayCount = barangayAssessment?.barangays.length ?? 0;
    const Layout = mapReturnUrl ? AppSidebarLayoutAdmin : AppSidebarLayout;

    return (
        <Layout>
            <Head title={`${location.name} Assessment`} />

            <div className="location-assessment flex min-w-0 flex-1 flex-col gap-6 bg-blue-50/60 p-4 md:p-6 dark:bg-slate-950">
                {/* Top Navigation & Header */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <Button variant="ghost" size="sm" asChild className="-ml-2 w-fit">
                            <Link href={mapReturnUrl ?? route('location.show', backParams)}>
                                <ArrowLeft className="size-4" />
                                Back to {mapReturnUrl ? 'Intelligence Map' : 'Service Area Map'}
                            </Link>
                        </Button>
                        <div className="mt-2 flex items-start gap-3">
                            <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-white text-[#123575]">
                                <MapPin size={22} aria-hidden="true" />
                            </span>
                            <div>
                                <p className="text-[10px] font-semibold tracking-wider text-slate-500 uppercase">
                                    Local evidence &amp; project planning
                                </p>
                                <h1 className="mt-1 text-2xl font-semibold tracking-tight text-[#123575] sm:text-3xl dark:text-blue-200">
                                    {location.name} Assessment
                                </h1>
                                <p className="mt-2 max-w-3xl text-sm leading-relaxed text-slate-500">
                                    Review service comparisons, data readiness and DOST catalog options for local feasibility assessment.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Unified Location Overview + Assessment Focus Bar */}
                <LocationOverviewBar
                    location={location}
                    focus={focus}
                    dimensions={dimensions}
                    populationYear={populationYear}
                    assessmentParams={assessmentParams}
                />
                {populationReason && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{populationReason}</p>}

                {/* Section Navigation Tabs */}
                <nav
                    aria-label="Assessment sections"
                    className="flex flex-wrap items-center gap-2 rounded-2xl border border-blue-100 bg-white p-3 dark:bg-slate-950"
                >
                    <Button
                        type="button"
                        size="sm"
                        variant={activeSection === 'all' ? 'default' : 'outline'}
                        onClick={() => setActiveSection('all')}
                        className="gap-1.5"
                    >
                        <Layers className="size-3.5" />
                        <span>All Sections</span>
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        variant={activeSection === 'indicators' ? 'default' : 'outline'}
                        onClick={() => setActiveSection('indicators')}
                        className="gap-1.5"
                    >
                        <Activity className="size-3.5" />
                        <span>Location indicators</span>
                        <Badge variant="secondary" className="ml-0.5 px-1.5 py-0 text-[10px]">
                            {results.length}
                        </Badge>
                    </Button>

                    {barangayCount > 0 && (
                        <Button
                            type="button"
                            size="sm"
                            variant={activeSection === 'barangays' ? 'default' : 'outline'}
                            onClick={() => setActiveSection('barangays')}
                            className="gap-1.5"
                        >
                            <MapPin className="size-3.5" />
                            <span>2. Barangay Breakdown</span>
                            <Badge variant="secondary" className="ml-0.5 px-1.5 py-0 text-[10px]">
                                {barangayCount}
                            </Badge>
                        </Button>
                    )}

                    <Button
                        type="button"
                        size="sm"
                        variant={activeSection === 'labs' ? 'default' : 'outline'}
                        onClick={() => setActiveSection('labs')}
                        className="gap-1.5"
                    >
                        <FlaskConical className="size-3.5" />
                        <span>3. DOST Laboratories</span>
                        <Badge variant="secondary" className="ml-0.5 px-1.5 py-0 text-[10px]">
                            {localLaboratories.length}
                        </Badge>
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        variant={activeSection === 'techs' ? 'default' : 'outline'}
                        onClick={() => setActiveSection('techs')}
                        className={`gap-1.5 ${activeSection === 'techs' ? 'bg-[#123575] text-white hover:bg-[#0b2454]' : 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200'}`}
                    >
                        <Cpu className="size-3.5" />
                        <span>Priorities &amp; DOST projects</span>
                        <Badge variant="secondary" className="ml-0.5 px-1.5 py-0 text-[10px]">
                            {catalog.records.length} catalog
                        </Badge>
                    </Button>
                    {resourceSummary && (
                        <Button
                            type="button"
                            variant={activeSection === 'resources' ? 'default' : 'outline'}
                            className="w-fit"
                            onClick={() => setActiveSection('resources')}
                        >
                            Population, facilities & agriculture
                        </Button>
                    )}
                </nav>

                {/* SECTION 1: City Indicators & Gaps */}
                {(activeSection === 'all' || activeSection === 'indicators') && (
                    <section
                        aria-labelledby="location-indicators-heading"
                        className="overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-[0_8px_32px_-12px_#1e3a8a29] dark:border-blue-900/60 dark:bg-slate-950"
                    >
                        <header className="flex items-start gap-3 border-b border-blue-100 bg-blue-50 p-5 sm:gap-4 sm:p-6 dark:border-blue-900/60 dark:bg-blue-950/50">
                            <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-[#123575] text-white sm:size-12">
                                <Activity className="size-6" aria-hidden="true" />
                            </span>
                            <div className="min-w-0 space-y-2">
                                <Badge className="rounded-full bg-blue-100 px-2.5 text-[10px] font-semibold tracking-wider text-blue-800 uppercase dark:bg-blue-900/60 dark:text-blue-200">
                                    Step 1
                                </Badge>
                                <h2
                                    id="location-indicators-heading"
                                    className="text-xl leading-snug font-semibold tracking-tight text-slate-900 sm:text-2xl dark:text-slate-100"
                                >
                                    Location Indicators &amp; Benchmark Comparison{' '}
                                    <span className="font-medium text-[#123575] dark:text-blue-200">({focusLabel})</span>
                                </h2>
                                <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
                                    How {location.name} compares against national reference rates across measured datasets.
                                </p>
                            </div>
                        </header>

                        <div className="p-4 sm:p-6">
                            {!focusSupported ? (
                                <Card className="rounded-xl border-amber-200 bg-amber-50 shadow-sm dark:bg-amber-950/30">
                                    <CardContent className="flex gap-3 p-4">
                                        <Info className="mt-0.5 size-4 shrink-0 text-amber-600" />
                                        <div className="text-sm">
                                            <p className="font-medium">No dataset available for this dimension</p>
                                            <p className="text-muted-foreground mt-1">
                                                The framework covers {dimensions.length} assessment dimensions, but this application currently holds
                                                data for only the {dimensions.filter((d) => d.supported).length} listed as supported. No indicators
                                                are reported here rather than guessing.
                                            </p>
                                        </div>
                                    </CardContent>
                                </Card>
                            ) : (
                                <div className="flex flex-col gap-5">
                                    <AssessmentCharts
                                        results={results}
                                        dimensions={dimensions}
                                        barangays={barangayAssessment?.barangays}
                                        populationYear={populationYear}
                                        populationBreakdown={resourceSummary?.population_breakdown}
                                    />
                                    {shortfalls.length > 0 && (
                                        <Card className="rounded-xl border-amber-200 bg-amber-50/60 shadow-sm dark:bg-amber-950/20">
                                            <CardHeader className="pb-2">
                                                <CardTitle className="flex items-center gap-2 text-sm text-amber-900 dark:text-amber-200">
                                                    <AlertTriangle className="size-4 shrink-0 text-amber-600" />
                                                    <span>High-confidence comparison flags for review</span>
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="grid gap-2 sm:grid-cols-2">
                                                {shortfalls.map((result) => (
                                                    <div
                                                        key={result.key}
                                                        className="bg-background/80 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200/60 px-3 py-2.5 text-xs"
                                                    >
                                                        <span className="font-medium">{result.label}</span>
                                                        <span className="text-muted-foreground font-mono">
                                                            {format(result.value)} vs {format(result.benchmark)} {result.unit}
                                                        </span>
                                                    </div>
                                                ))}
                                            </CardContent>
                                        </Card>
                                    )}

                                    <details className="rounded-xl border border-slate-200 p-4">
                                        <summary className="cursor-pointer text-sm font-semibold text-[#123575]">
                                            Detailed indicator evidence &amp; source notes ({results.length})
                                        </summary>
                                        <div className="mt-4 grid items-stretch gap-4 md:grid-cols-2 xl:grid-cols-3">
                                            {results.map((result) => (
                                                <IndicatorCard key={result.key} result={result} />
                                            ))}
                                        </div>
                                    </details>
                                </div>
                            )}
                        </div>
                    </section>
                )}

                {(activeSection === 'all' || activeSection === 'techs') && (
                    <AssessmentPlanning
                        locationName={location.name}
                        results={results}
                        catalog={catalog}
                        agriculture={focus === 'general' ? agricultureSummary : undefined}
                        locationMatches={dostInterventions}
                    />
                )}

                {/* SECTION 2: Barangay Breakdown */}
                {(activeSection === 'all' || activeSection === 'resources') && resourceSummary && (
                    <section className="space-y-5">
                        <h2 className="text-lg font-semibold">Population & local resource profile</h2>
                        <div className="grid items-start gap-5 lg:grid-cols-2">
                            <PopulationDetails data={resourceSummary} />
                            <ProvinceResourceOverview data={resourceSummary} />
                        </div>
                        {agricultureSummary && <AgricultureSummaryView data={agricultureSummary} />}
                    </section>
                )}
                {(activeSection === 'all' || activeSection === 'barangays') && barangayAssessment && barangayAssessment.barangays.length > 0 && (
                    <BarangayPrioritySection
                        cityName={location.name}
                        summary={barangayAssessment.summary}
                        barangays={barangayAssessment.barangays}
                        populationYear={populationYear}
                    />
                )}

                {/* SECTION 3: Accredited DOST Laboratories */}
                {(activeSection === 'all' || activeSection === 'labs') && (
                    <LocalLaboratoriesSection cityName={location.name} laboratories={localLaboratories} />
                )}

                {/* SECTION 4: Recommended DOST Technologies */}
                {(activeSection === 'all' || activeSection === 'techs') && (
                    <details className="space-y-5 rounded-2xl border border-blue-100 bg-white p-5 dark:bg-slate-950">
                        <summary className="cursor-pointer font-semibold text-[#123575]">
                            Catalog location context &amp; existing text matches ({dostInterventions.length})
                        </summary>
                        {resourceSummary && <TechnologyDetails data={resourceSummary} />}
                        <DostInterventionsSection
                            cityName={location.name}
                            interventions={dostInterventions}
                            priorityBarangays={barangayAssessment?.barangays.filter((b) => b.priority_level === 'high').slice(0, 5) ?? []}
                        />
                    </details>
                )}

                <Card className="bg-muted/30">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Methodology & Data Notes</CardTitle>
                    </CardHeader>
                    <CardContent className="text-muted-foreground flex flex-col gap-1.5 text-xs">
                        <p>
                            <strong>Comparisons:</strong> National reference rates are descriptive benchmarks, not official service standards. Density
                            above its reference signals service pressure; other indicators generally flag rates below their reference. Measurements,
                            confidence and source notes are retained for every indicator.
                        </p>
                        <p>
                            <strong>Evidence limits:</strong> A recorded zero is not proof of absence. Unknown population and missing benchmarks
                            remain distinct from zero. Inventories do not measure staffing, capacity, travel time, service quality or network
                            coverage. Modeled agriculture is planning context outside scored indicators.
                        </p>
                        <p>
                            <strong>Project screening:</strong> Options come from the existing NCR DOST reference catalog and are matched through
                            explicit words in names, sectors and mechanisms. Matching does not verify suitability, local deployment, eligibility,
                            funding or impact. Confirm these with the relevant DOST agency and LGU.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </Layout>
    );
}

function LocationOverviewBar({
    location,
    focus,
    dimensions,
    populationYear,
    assessmentParams,
}: {
    location: AssessmentPageProps['location'];
    focus: string;
    dimensions: AssessmentDimension[];
    populationYear: number | null;
    assessmentParams?: Record<string, string | number>;
}) {
    const populationLabel = populationYear === null ? 'Population' : `Population (${populationYear})`;
    const popStats = location.population_statistics;
    const brgyCounts = location.barangay_counts;

    const classificationParts = [
        location.type,
        location.city_class ? `${location.city_class} City` : null,
        location.income_class ? `${location.income_class} Income Class` : null,
        location.urban_rural,
    ].filter(Boolean);

    const growthSummary = (() => {
        if (!popStats || popStats.population_baseline === null || popStats.baseline_year === null) {
            return 'No baseline census figure';
        }
        if (popStats.growth_rate_pct === null || popStats.population_change === null) {
            return `${popStats.population_baseline.toLocaleString()} (${popStats.baseline_year})`;
        }
        const sign = popStats.growth_rate_pct > 0 ? '+' : '';
        const diffSign = popStats.population_change > 0 ? '+' : '';
        return `${sign}${popStats.growth_rate_pct.toFixed(2)}% (${diffSign}${popStats.population_change.toLocaleString()} since ${popStats.baseline_year})`;
    })();

    const barangayBreakdownLabel = (() => {
        if (!brgyCounts || brgyCounts.total_barangays === 0) return '—';
        if (brgyCounts.urban_barangays > 0 || brgyCounts.rural_barangays > 0) {
            return `${brgyCounts.total_barangays.toLocaleString()} (${brgyCounts.urban_barangays} Urban · ${brgyCounts.rural_barangays} Rural)`;
        }
        return `${brgyCounts.total_barangays.toLocaleString()} barangays`;
    })();

    const items: Array<[string, string, string?]> = [
        ['Province / Region', location.province ?? '—', location.psg_code ? `PSGC ${location.psg_code}` : 'No verified PSGC'],
        ['Classification', classificationParts.length > 0 ? classificationParts.join(' · ') : '—'],
        [
            populationLabel,
            location.population?.toLocaleString() ?? 'No matched figure',
            popStats?.baseline_year && popStats?.population_baseline !== null
                ? `${popStats.baseline_year} Census: ${popStats.population_baseline.toLocaleString()}`
                : undefined,
        ],
        [
            'Population Trend',
            growthSummary,
            popStats?.annual_growth_rate_pct !== null && popStats?.annual_growth_rate_pct !== undefined
                ? `Annualized: ${popStats.annual_growth_rate_pct > 0 ? '+' : ''}${popStats.annual_growth_rate_pct.toFixed(2)}% / yr`
                : undefined,
        ],
        [
            'Land Area & Density',
            location.area_km2 ? `${location.area_km2.toLocaleString()} km²` : '—',
            location.population !== null && location.area_km2 !== null && location.area_km2 > 0
                ? `Density: ${(location.population / location.area_km2).toLocaleString(undefined, { maximumFractionDigits: 1 })} persons/km²`
                : undefined,
        ],
        ['Barangay Coverage', barangayBreakdownLabel],
    ];

    return (
        <Card className="rounded-2xl shadow-[0_4px_20px_-8px_#153d3824]">
            <CardContent className="flex flex-col gap-4 p-4 sm:p-5">
                <div className="grid min-w-0 grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                    {items.map(([label, value, subtext]) => (
                        <div key={label} className="flex flex-col gap-0.5">
                            <span className="text-muted-foreground text-[11px] tracking-wide uppercase">{label}</span>
                            <span className="text-sm font-semibold break-words">{value}</span>
                            {subtext && <span className="text-muted-foreground text-[11px]">{subtext}</span>}
                        </div>
                    ))}
                </div>

                <div className="flex flex-col gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:gap-4">
                    <Label htmlFor="focus-select" className="text-xs">
                        Assessment Focus Sector
                    </Label>
                    <select
                        id="focus-select"
                        value={focus}
                        onChange={(event) =>
                            router.get(
                                route('location.assess'),
                                { ...(assessmentParams ?? { city: location.psg_code }), focus: event.target.value },
                                { preserveState: true },
                            )
                        }
                        className="border-input focus-visible:ring-ring h-10 w-full max-w-lg rounded-lg border bg-white px-3 py-1 text-sm outline-none focus-visible:ring-2"
                    >
                        <option value="general">General assessment (all available)</option>
                        {dimensions.map((dimension) => (
                            <option key={dimension.key} value={dimension.key} disabled={!dimension.supported}>
                                {dimension.label}
                                {dimension.supported ? '' : ' — no data'}
                            </option>
                        ))}
                    </select>
                </div>
            </CardContent>
        </Card>
    );
}

function IndicatorCard({ result }: { result: AssessmentResult }) {
    const unavailable = result.status === 'no_data' || result.value === null;
    const accent = unavailable
        ? 'border-t-slate-300 dark:border-t-slate-700'
        : result.status === 'no_benchmark'
          ? 'border-t-[#00aeef]'
          : result.confidence === 'low'
            ? 'border-t-slate-400'
            : result.is_shortfall
              ? 'border-t-amber-500'
              : 'border-t-[#123575]';

    return (
        <Card className={`flex min-w-0 flex-col rounded-xl border-t-4 shadow-[0_4px_16px_-6px_#153d3824] ${accent}`}>
            <CardHeader className="gap-3 p-4 pb-3 sm:p-5 sm:pb-3">
                <CardTitle className="text-sm leading-snug">{result.label}</CardTitle>
                <div className="flex flex-wrap gap-2">
                    <StatusBadge result={result} />
                </div>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-4 p-4 pt-0 sm:p-5 sm:pt-0">
                {unavailable ? (
                    <p className="text-muted-foreground text-sm">{result.reason || 'Not measured for this area.'}</p>
                ) : (
                    <>
                        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <span className="text-3xl font-semibold tracking-tight tabular-nums">{format(result.value)}</span>
                            <span className="text-muted-foreground text-xs">{result.unit}</span>
                        </div>

                        {result.benchmark !== null && (
                            <div className="bg-muted/50 flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2 text-xs">
                                <span className="text-muted-foreground">National rate: {format(result.benchmark)}</span>
                                {result.gap !== null && (
                                    <span
                                        className={`font-mono font-medium ${
                                            result.is_shortfall ? 'text-amber-700 dark:text-amber-400' : 'text-blue-900 dark:text-blue-200'
                                        }`}
                                    >
                                        {result.gap > 0 ? '+' : ''}
                                        {result.gap.toFixed(1)}%
                                    </span>
                                )}
                            </div>
                        )}

                        {result.benchmark !== null && (
                            <DataBars
                                title="Measured vs national reference"
                                description={result.unit}
                                rows={[
                                    {
                                        label: 'Selected area',
                                        value: result.value,
                                        color: result.confidence === 'low' ? '#8255c8' : result.is_shortfall ? '#d97706' : '#138771',
                                    },
                                    { label: 'National reference', value: result.benchmark, color: '#94a3b8' },
                                ]}
                            />
                        )}
                        {result.median !== null && (
                            <p className="text-muted-foreground text-xs">
                                National median (context): {format(result.median)} {result.unit}
                            </p>
                        )}
                    </>
                )}

                {result.reason !== '' && !unavailable && (
                    <p
                        className={`flex gap-2 text-xs ${result.confidence === 'low' ? 'text-amber-700 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400'}`}
                    >
                        {result.confidence === 'low' ? (
                            <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                        ) : (
                            <Info className="mt-0.5 size-3 shrink-0" />
                        )}
                        {result.reason}
                    </p>
                )}

                <div className="text-muted-foreground mt-auto border-t pt-3 text-[11px] leading-relaxed">
                    <p>{result.description}</p>
                    <p className="mt-1 opacity-80">Source: {result.source}</p>
                    {result.basis_note && <p className="mt-1">{result.basis_note}</p>}
                    {result.caveat && <p className="mt-1">{result.caveat}</p>}
                </div>
            </CardContent>
        </Card>
    );
}

function StatusBadge({ result }: { result: AssessmentResult }) {
    if (result.status === 'no_data') {
        return <Badge variant="secondary">No data</Badge>;
    }

    if (result.status === 'no_benchmark') {
        return <Badge variant="outline">Measured</Badge>;
    }

    if (result.confidence === 'low') {
        return <Badge variant="outline">Low confidence</Badge>;
    }

    if (result.is_shortfall) {
        return <Badge className="bg-amber-600">Review priority</Badge>;
    }

    return <Badge className="bg-[#123575]">Within comparison</Badge>;
}

function BarangayPrioritySection({
    cityName,
    summary,
    barangays,
    populationYear,
}: {
    cityName: string;
    summary: BarangayAssessmentSummary;
    barangays: BarangayItem[];
    populationYear: number | null;
}) {
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<'all' | 'high' | 'zero_facilities' | 'urban' | 'rural'>('all');
    const [sortBy, setSortBy] = useState<'population_desc' | 'population_asc' | 'density_desc' | 'name_asc'>('population_desc');
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
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        Step 2
                    </Badge>
                    <h2 className="text-lg font-semibold tracking-tight">Barangay Breakdown & Underserved Areas ({cityName})</h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    Shows every barangay’s population and recorded infrastructure (Health, Schools, Safety, Cell Towers). Barangays with above-average
                    population and 0 recorded facilities are highlighted first.
                </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardContent className="flex flex-col gap-1 p-4">
                        <div className="text-muted-foreground flex items-center justify-between text-xs tracking-wide uppercase">
                            <span>Total Barangays</span>
                            <MapPin className="size-4 text-[#123575]" />
                        </div>
                        <span className="text-2xl font-semibold">{summary.total_barangays.toLocaleString()}</span>
                        <span className="text-muted-foreground text-xs">
                            {summary.urban_count} Urban · {summary.rural_count} Rural
                            {summary.average_population ? ` · Avg ${summary.average_population.toLocaleString()} pop` : ''}
                        </span>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="flex flex-col gap-1 p-4">
                        <div className="text-muted-foreground flex items-center justify-between text-xs tracking-wide uppercase">
                            <span>Largest Barangay</span>
                            <Users className="size-4 text-indigo-600" />
                        </div>
                        <span className="truncate text-lg font-semibold">{summary.most_populous?.name ?? '—'}</span>
                        <span className="text-muted-foreground text-xs">
                            {summary.most_populous
                                ? `${summary.most_populous.population.toLocaleString()} residents (${summary.most_populous.population_share ?? 0}% of city)`
                                : 'No population figure'}
                        </span>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="flex flex-col gap-1 p-4">
                        <div className="text-muted-foreground flex items-center justify-between text-xs tracking-wide uppercase">
                            <span>Most Dense Barangay</span>
                            <Building2 className="size-4 text-violet-600" />
                        </div>
                        <span className="truncate text-lg font-semibold">{summary.highest_density?.name ?? '—'}</span>
                        <span className="text-muted-foreground text-xs">
                            {summary.highest_density ? `${summary.highest_density.density.toLocaleString()} persons/km²` : 'Land area unavailable'}
                        </span>
                    </CardContent>
                </Card>

                <Card className={summary.high_priority_count > 0 ? 'border-amber-300 bg-amber-50/50 dark:bg-amber-950/20' : ''}>
                    <CardContent className="flex flex-col gap-1 p-4">
                        <div className="text-muted-foreground flex items-center justify-between text-xs tracking-wide uppercase">
                            <span>Underserved Barangays</span>
                            <AlertTriangle className="size-4 text-amber-600" />
                        </div>
                        <span className="text-2xl font-semibold">
                            {summary.high_priority_count} <span className="text-sm font-normal">large-pop brgys w/ 0 facilities</span>
                        </span>
                        <span className="text-muted-foreground text-xs">
                            {summary.underserved_barangays_count} total brgys ({summary.underserved_population.toLocaleString()} residents) have 0
                            recorded facilities
                        </span>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="flex flex-col gap-4 pb-4 sm:flex-row sm:items-center sm:justify-between">
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

                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div className="relative w-full sm:w-56">
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
                            className="border-input focus-visible:ring-ring h-9 rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:ring-2"
                        >
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
                                    <th className="px-4 py-3 font-medium">Coverage Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {visibleBarangays.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="text-muted-foreground px-4 py-8 text-center text-sm">
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
                                                                <div className="h-full rounded-full bg-[#123575]" style={{ width: `${barWidth}%` }} />
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

function PriorityBadge({ level, reason }: { level: BarangayItem['priority_level']; reason: string }) {
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
            <Badge className="bg-[#123575]" title={reason}>
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

function LocalLaboratoriesSection({ cityName, laboratories }: { cityName: string; laboratories: LocalLaboratoryItem[] }) {
    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        Step 3
                    </Badge>
                    <h2 className="text-lg font-semibold tracking-tight">DOST / OneLab Accredited Laboratories in {cityName}</h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    Existing testing and calibration laboratories located in {cityName} and their registered testing services.
                </p>
            </div>

            {laboratories.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground p-6 text-sm">
                        No accredited DOST / OneLab laboratories are currently recorded inside {cityName}. Residents and MSMEs can be referred to the
                        nearest regional OneLab facility.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {laboratories.map((lab) => (
                        <Card key={lab.id}>
                            <CardHeader className="pb-2">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <FlaskConical className="size-4 shrink-0 text-[#123575]" />
                                        <CardTitle className="text-sm leading-snug">{lab.agency_name ?? 'Accredited Laboratory'}</CardTitle>
                                    </div>
                                    <Badge variant="secondary">
                                        {lab.total_services === null ? 'Service count unavailable' : `${lab.total_services} services`}
                                    </Badge>
                                </div>
                                {lab.code && <p className="text-muted-foreground font-mono text-xs">{lab.code}</p>}
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3 text-xs">
                                {lab.services.length > 0 ? (
                                    <div className="flex flex-wrap gap-1.5">
                                        {lab.services.map((svc, idx) => (
                                            <Badge key={idx} variant="outline" className="font-normal">
                                                {svc.test_name}
                                                {svc.method ? ` (${svc.method})` : ''}
                                            </Badge>
                                        ))}
                                        {lab.total_services !== null && lab.total_services > lab.services.length && (
                                            <Badge variant="secondary" className="font-normal">
                                                +{lab.total_services - lab.services.length} more
                                            </Badge>
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground">
                                        {lab.services_status === 'unavailable'
                                            ? 'Normalized service details are unavailable; any count shown is source-listed.'
                                            : 'No specific test rows are recorded for this laboratory.'}
                                    </p>
                                )}
                                {(lab.contact_information || lab.website) && (
                                    <div className="text-muted-foreground border-t pt-2">
                                        {lab.contact_information && <p>{lab.contact_information}</p>}
                                        {lab.website && (
                                            <a href={lab.website} target="_blank" rel="noreferrer" className="text-[#123575] underline">
                                                {lab.website}
                                            </a>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}

function DostInterventionsSection({
    cityName,
    interventions,
    priorityBarangays,
}: {
    cityName: string;
    interventions: DostInterventionItem[];
    priorityBarangays: BarangayItem[];
}) {
    const agencies = Array.from(new Set(interventions.map((t) => t.agency).filter(Boolean))) as string[];
    const priorityPop = priorityBarangays.reduce((sum, b) => sum + (b.population ?? 0), 0);

    return (
        <section className="flex flex-col gap-4 rounded-2xl border border-blue-100 bg-blue-50/60 p-4 shadow-sm sm:p-5 dark:border-blue-900 dark:bg-blue-950/20">
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        Step 4
                    </Badge>
                    <h2 className="text-lg font-semibold tracking-tight">Matched DOST Technologies & Programs ({cityName})</h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    Catalogued DOST technologies matched by location text or focus keywords, with lead agencies and catalog-stated outcomes.
                </p>
            </div>

            {interventions.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground p-6 text-sm">No catalogued DOST technologies matched this filter.</CardContent>
                </Card>
            ) : (
                <>
                    <div className="grid gap-4 md:grid-cols-2">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Catalog agencies & areas to review</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-sm">
                                <div className="flex flex-wrap gap-1.5">
                                    {agencies.map((agency) => (
                                        <Badge key={agency} variant="secondary">
                                            {agency}
                                        </Badge>
                                    ))}
                                </div>
                                {priorityBarangays.length > 0 && (
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        <strong className="text-foreground">Barangays flagged by the recorded-coverage heuristic:</strong>{' '}
                                        {priorityBarangays.map((b) => b.name).join(', ')} ({priorityPop.toLocaleString()} combined residents)
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Catalog matching notes</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-sm">
                                <p className="text-muted-foreground text-xs">
                                    Entries are matched using catalog location text and focus keywords. Confirm local deployment, suitability,
                                    eligibility and current availability with the lead agency. Resident counts do not predict program reach or impact.
                                </p>
                                <div className="flex items-center gap-2 text-xs text-blue-700 dark:text-blue-400">
                                    <CheckCircle2 className="size-4 shrink-0" />
                                    <span>{interventions.length} catalog entries matched</span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        {interventions.map((tech) => (
                            <Card key={tech.id} className={tech.matches_city_cluster ? 'border-blue-500/50' : ''}>
                                <CardHeader className="pb-2">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex items-center gap-2">
                                            <Lightbulb className="size-4 shrink-0 text-blue-600" />
                                            <CardTitle className="text-sm leading-snug">{tech.name}</CardTitle>
                                        </div>
                                        {tech.matches_city_cluster && <Badge className="shrink-0 bg-blue-600">Catalog location match</Badge>}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2 pt-1">
                                        <span className="text-muted-foreground font-mono text-xs">{tech.code}</span>
                                        {tech.agency && <Badge variant="outline">{tech.agency}</Badge>}
                                        {tech.sector && <Badge variant="secondary">{tech.sector}</Badge>}
                                        {tech.status && <span className="text-muted-foreground text-xs">· {tech.status}</span>}
                                    </div>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-2 text-xs">
                                    {tech.mechanism && (
                                        <p>
                                            <strong className="text-foreground">How it works: </strong>
                                            <span className="text-muted-foreground">{tech.mechanism}</span>
                                        </p>
                                    )}
                                    {tech.outcome && (
                                        <p className="rounded-md bg-blue-50 p-2 text-blue-900 dark:bg-blue-950/30 dark:text-blue-200">
                                            <strong>Catalog-stated outcome: </strong>
                                            {tech.outcome}
                                        </p>
                                    )}
                                    {tech.deployment_clusters && (
                                        <p className="text-muted-foreground border-t pt-2">
                                            <strong>Catalog location text: </strong>
                                            {tech.deployment_clusters}
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </>
            )}
        </section>
    );
}

function format(value: number | null): string {
    if (value === null) return '—';
    if (value === 0) return '0';
    if (Math.abs(value) < 1) return value.toFixed(3);
    return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
}
