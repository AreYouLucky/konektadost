import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { evidenceState, type ProjectCatalogData } from '@/lib/assessment-planning';
import { AgricultureSummaryView } from '@/pages/admin/search-map/partials/province-agriculture-card';
import ProvinceResourceOverview from '@/pages/admin/search-map/partials/province-resource-overview';
import type { AgricultureSummary } from '@/pages/admin/search-map/use-province-agriculture';
import type { ProvinceResourceData } from '@/pages/admin/search-map/use-province-resources';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    BarChart3,
    Building2,
    CheckCircle2,
    Clock,
    Coins,
    Cpu,
    FileText,
    FlaskConical,
    Info,
    Layers,
    MapPin,
    Navigation,
    Radio,
    Search,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    Users,
    Wrench,
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
    priority_score?: number | null;
    nearest_facility_km?: number | null;
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

interface DatasetAuditItem {
    key: string;
    label: string;
    table: string;
    status: 'available' | 'partial' | 'empty' | 'unavailable';
    local_records: number | null;
    national_records: number | null;
    barangays_represented?: number | null;
    total_barangays?: number | null;
    unmapped_local_records?: number | null;
    reference_year: string | number | null;
    notes: string;
}

interface LocalDostProjectItem {
    id: number;
    agency: string | null;
    program: string | null;
    project_title: string | null;
    sector: string | null;
    location: string | null;
    raw_status: string | null;
    normalized_status: string;
    budget_php: number | null;
}

interface FunctionToolsBundle {
    dataset_audit?: {
        summary: {
            available_count: number;
            partial_count: number;
            empty_count?: number;
            unavailable_count: number;
            total_datasets: number;
        };
        datasets: Record<string, DatasetAuditItem>;
    };
    local_assets?: {
        facility_counts?: { health?: number; education?: number; safety?: number; total?: number };
        facilities?: any[];
        laboratory_count?: number;
        telecom_site_count?: number;
    };
    telecom_infrastructure?: {
        total_sites: number;
        technologies_present?: string[];
        operators?: string[];
    };
    geographic_data_availability?: {
        has_geometry: boolean;
        area_km2?: number | null;
        local_road_records?: number;
    };
    sector_analysis?: {
        health?: any;
        education?: any;
        connectivity?: any;
        road_accessibility?: {
            total_segments: number;
            total_length_km: number;
            road_density_km_per_km2: number | null;
            paved_ratio_pct: number | null;
            surface_distribution?: Record<string, number>;
            condition_distribution?: Record<string, number>;
        };
        agriculture?: any;
        sti_laboratory?: any;
        safety_and_drrm?: any;
    };
    detected_gaps?: {
        total_gaps_detected: number;
        critical_severity_count: number;
        gaps: any[];
    };
    underserved_areas?: any;
    priority_scores?: any;
    local_dost_projects?: {
        total_matched_projects: number;
        total_recorded_budget_php: number;
        by_agency: Record<string, number>;
        projects: LocalDostProjectItem[];
    };
    dost_technologies?: {
        total_matches?: number;
        results?: any[];
    };
    matched_interventions?: any;
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
    functionToolsBundle?: FunctionToolsBundle | null;
    layerEResult?: any;
    /** Collection year every population figure on this page belongs to. */
    populationYear?: number | null;
    populationReason?: string | null;
    assessmentParams?: Record<string, string | number>;
    mapReturnUrl?: string;
    resourceSummary?: ProvinceResourceData;
    agricultureSummary?: AgricultureSummary;
    projectCatalog?: ProjectCatalogData;
}

type ActiveSection = 'profile_assets' | 'sectors_roads' | 'gaps_barangays' | 'dost_catalog' | 'matched_interventions';

export default function LocationAssessment({
    location,
    results,
    barangayAssessment,
    localLaboratories = [],
    dostInterventions = [],
    functionToolsBundle = null,
    layerEResult = null,
    populationYear = null,
    populationReason = null,
    mapReturnUrl,
    resourceSummary,
    agricultureSummary,
}: AssessmentPageProps) {
    const [activeSection, setActiveSection] = useState<ActiveSection>('profile_assets');

    const shortfalls = results.filter((r) => evidenceState(r) === 'priority');
    const measuredCount = results.filter((r) => evidenceState(r) !== 'missing').length;

    const backParams = location.province_psg_code ? { province: location.province_psg_code, city: location.psg_code } : { city: location.psg_code };

    const barangayCount = barangayAssessment?.barangays.length ?? 0;
    const highPriorityBarangayCount = barangayAssessment?.summary.high_priority_count ?? 0;
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
                                    Deterministic Location &amp; Sector Assessment
                                </p>
                                <h1 className="mt-1 text-2xl font-semibold tracking-tight text-[#123575] sm:text-3xl dark:text-blue-200">
                                    {location.name} Assessment
                                </h1>
                                <p className="mt-1 max-w-3xl text-sm leading-relaxed text-slate-500">
                                    Computed indicators, dynamic national benchmark comparisons, barangay priority breakdowns, and local resource inventories.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Unified Location Overview Bar */}
                <LocationOverviewBar
                    location={location}
                    populationYear={populationYear}
                />
                {populationReason && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{populationReason}</p>}

                {/* 5-Tab Workflow Strip (Layers A - E) */}
                <nav
                    aria-label="Assessment workflow steps"
                    className="grid grid-cols-2 gap-2.5 sm:grid-cols-5"
                >
                    {/* Tab 1: Layer A */}
                    <button
                        type="button"
                        onClick={() => setActiveSection('profile_assets')}
                        className={`flex flex-col gap-1 rounded-xl border p-3.5 text-left transition-all ${
                            activeSection === 'profile_assets'
                                ? 'border-[#123575] bg-[#123575] text-white shadow-md'
                                : 'border-blue-100 bg-white hover:border-blue-300 dark:border-slate-800 dark:bg-slate-950 text-slate-800 dark:text-slate-200'
                        }`}
                    >
                        <div className="flex items-center justify-between text-[10px] font-semibold tracking-wider uppercase opacity-80">
                            <span>Layer A</span>
                            <Building2 className="size-3.5" />
                        </div>
                        <div className="mt-0.5 font-semibold text-sm">Profile &amp; Assets</div>
                        <p className="text-[11px] opacity-75">Demographics, counts &amp; audit</p>
                    </button>

                    {/* Tab 2: Layer B */}
                    <button
                        type="button"
                        onClick={() => setActiveSection('sectors_roads')}
                        className={`flex flex-col gap-1 rounded-xl border p-3.5 text-left transition-all ${
                            activeSection === 'sectors_roads'
                                ? 'border-[#123575] bg-[#123575] text-white shadow-md'
                                : 'border-blue-100 bg-white hover:border-blue-300 dark:border-slate-800 dark:bg-slate-950 text-slate-800 dark:text-slate-200'
                        }`}
                    >
                        <div className="flex items-center justify-between text-[10px] font-semibold tracking-wider uppercase opacity-80">
                            <span>Layer B</span>
                            <Activity className="size-3.5" />
                        </div>
                        <div className="mt-0.5 font-semibold text-sm">Sectors &amp; Roads</div>
                        <p className="text-[11px] opacity-75">Indicators, roads &amp; telecom</p>
                    </button>

                    {/* Tab 3: Layer C */}
                    <button
                        type="button"
                        onClick={() => setActiveSection('gaps_barangays')}
                        className={`flex flex-col gap-1 rounded-xl border p-3.5 text-left transition-all ${
                            activeSection === 'gaps_barangays'
                                ? 'border-[#123575] bg-[#123575] text-white shadow-md'
                                : 'border-blue-100 bg-white hover:border-blue-300 dark:border-slate-800 dark:bg-slate-950 text-slate-800 dark:text-slate-200'
                        }`}
                    >
                        <div className="flex items-center justify-between text-[10px] font-semibold tracking-wider uppercase opacity-80">
                            <span>Layer C</span>
                            <MapPin className="size-3.5" />
                        </div>
                        <div className="mt-0.5 font-semibold text-sm">Gaps &amp; Priority</div>
                        <p className="text-[11px] opacity-75">{highPriorityBarangayCount} priority of {barangayCount} brgys</p>
                    </button>

                    {/* Tab 4: Layer D */}
                    <button
                        type="button"
                        onClick={() => setActiveSection('dost_catalog')}
                        className={`flex flex-col gap-1 rounded-xl border p-3.5 text-left transition-all ${
                            activeSection === 'dost_catalog'
                                ? 'border-[#123575] bg-[#123575] text-white shadow-md'
                                : 'border-blue-100 bg-white hover:border-blue-300 dark:border-slate-800 dark:bg-slate-950 text-slate-800 dark:text-slate-200'
                        }`}
                    >
                        <div className="flex items-center justify-between text-[10px] font-semibold tracking-wider uppercase opacity-80">
                            <span>Layer D</span>
                            <FlaskConical className="size-3.5" />
                        </div>
                        <div className="mt-0.5 font-semibold text-sm">DOST Track &amp; Catalog</div>
                        <p className="text-[11px] opacity-75">Projects history &amp; tech catalog</p>
                    </button>

                    {/* Tab 5: Layer E */}
                    <button
                        type="button"
                        onClick={() => setActiveSection('matched_interventions')}
                        className={`flex flex-col gap-1 rounded-xl border p-3.5 text-left transition-all ${
                            activeSection === 'matched_interventions'
                                ? 'border-[#123575] bg-[#123575] text-white shadow-md'
                                : 'border-blue-100 bg-white hover:border-blue-300 dark:border-slate-800 dark:bg-slate-950 text-slate-800 dark:text-slate-200'
                        }`}
                    >
                        <div className="flex items-center justify-between text-[10px] font-semibold tracking-wider uppercase opacity-80">
                            <span>Layer E</span>
                            <Sparkles className="size-3.5" />
                        </div>
                        <div className="mt-0.5 font-semibold text-sm">Interventions</div>
                        <p className="text-[11px] opacity-75">Matching, partners &amp; budgets</p>
                    </button>
                </nav>

                {/* TAB 1: Profile & Local Assets (Layer A) */}
                {activeSection === 'profile_assets' && (
                    <div className="flex flex-col gap-6">
                        <LayerAAssetsSummary
                            location={location}
                            localAssets={functionToolsBundle?.local_assets}
                            geoData={functionToolsBundle?.geographic_data_availability}
                        />

                        {functionToolsBundle?.dataset_audit && (
                            <DatasetAuditSection
                                cityName={location.name}
                                audit={functionToolsBundle.dataset_audit}
                            />
                        )}
                    </div>
                )}

                {/* TAB 2: Sectors, Dynamic Benchmarks & Roads (Layer B) */}
                {activeSection === 'sectors_roads' && (
                    <div className="flex flex-col gap-6">
                        {/* Road Accessibility and Telecom Cards */}
                        <div className="grid gap-6 md:grid-cols-2">
                            <RoadAccessibilityCard
                                roadAccessibility={functionToolsBundle?.sector_analysis?.road_accessibility}
                            />
                            <TelecomConnectivityCard
                                telecom={functionToolsBundle?.telecom_infrastructure}
                            />
                        </div>

                        {/* Benchmark Comparisons */}
                        <section
                            aria-labelledby="location-indicators-heading"
                            className="overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-[0_8px_32px_-12px_#1e3a8a29] dark:border-blue-900/60 dark:bg-slate-950"
                        >
                            <header className="flex items-start gap-3 border-b border-blue-100 bg-blue-50 p-5 sm:gap-4 sm:p-6 dark:border-blue-900/60 dark:bg-blue-950/50">
                                <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-[#123575] text-white sm:size-12">
                                    <Activity className="size-6" aria-hidden="true" />
                                </span>
                                <div className="min-w-0 space-y-1">
                                    <Badge className="rounded-full bg-blue-100 px-2.5 text-[10px] font-semibold tracking-wider text-blue-800 uppercase dark:bg-blue-900/60 dark:text-blue-200">
                                        Layer B · Computed Indicators
                                    </Badge>
                                    <h2
                                        id="location-indicators-heading"
                                        className="text-xl leading-snug font-semibold tracking-tight text-slate-900 sm:text-2xl dark:text-slate-100"
                                    >
                                        Indicators &amp; Dynamic Benchmark Comparison
                                    </h2>
                                    <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
                                        Compares {location.name}’s measured rates against dynamically computed national reference rates across all live domain tables.
                                    </p>
                                </div>
                            </header>

                            <div className="p-4 sm:p-6">
                                <div className="flex flex-col gap-5">
                                    {shortfalls.length > 0 && (
                                        <Card className="rounded-xl border-amber-200 bg-amber-50/70 shadow-sm dark:bg-amber-950/20">
                                            <CardHeader className="pb-2">
                                                <CardTitle className="flex items-center gap-2 text-sm text-amber-900 dark:text-amber-200">
                                                    <AlertTriangle className="size-4 shrink-0 text-amber-600" />
                                                    <span>Detected Indicator Shortfalls &amp; Pressures ({shortfalls.length})</span>
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="grid gap-2 sm:grid-cols-2">
                                                {shortfalls.map((result) => (
                                                    <div
                                                        key={result.key}
                                                        className="bg-background/90 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200/70 px-3 py-2.5 text-xs"
                                                    >
                                                        <span className="font-medium">{result.label}</span>
                                                        <span className="text-muted-foreground font-mono">
                                                            {format(result.value)} vs {format(result.benchmark)} ({result.unit})
                                                        </span>
                                                    </div>
                                                ))}
                                            </CardContent>
                                        </Card>
                                    )}

                                    <UnifiedIndicatorsTable results={results} />
                                </div>
                            </div>
                        </section>

                        <LocalLaboratoriesSection cityName={location.name} laboratories={localLaboratories} />

                        {resourceSummary && (
                            <section className="space-y-4">
                                <ProvinceResourceOverview data={resourceSummary} />
                                {agricultureSummary && <AgricultureSummaryView data={agricultureSummary} />}
                            </section>
                        )}
                    </div>
                )}

                {/* TAB 3: Gaps & Priority Barangays (Layer C) */}
                {activeSection === 'gaps_barangays' && (
                    <div className="flex flex-col gap-6">
                        {functionToolsBundle?.detected_gaps && (
                            <DetectedGapsCard
                                detectedGaps={functionToolsBundle.detected_gaps}
                                cityName={location.name}
                            />
                        )}

                        {barangayAssessment && barangayAssessment.barangays.length > 0 && (
                            <BarangayPrioritySection
                                cityName={location.name}
                                summary={barangayAssessment.summary}
                                barangays={barangayAssessment.barangays}
                                populationYear={populationYear}
                            />
                        )}
                    </div>
                )}

                {/* TAB 4: DOST Track Record & Catalog (Layer D) */}
                {activeSection === 'dost_catalog' && (
                    <div className="flex flex-col gap-6">
                        <LocalDostProjectsSection
                            cityName={location.name}
                            data={functionToolsBundle?.local_dost_projects}
                        />

                        <DostTechnologiesCatalogSection
                            dostInterventions={dostInterventions}
                            catalogTechnologies={functionToolsBundle?.dost_technologies?.results}
                            projectCatalog={projectCatalog}
                        />
                    </div>
                )}

                {/* TAB 5: Matched Interventions & Feasibility (Layer E) */}
                {activeSection === 'matched_interventions' && (
                    <LayerEInterventionsSection
                        cityName={location.name}
                        matchedInterventions={layerEResult?.matched_interventions ?? functionToolsBundle?.matched_interventions}
                        suitability={layerEResult?.suitability_assessment}
                        feasibility={layerEResult?.feasibility_assessment}
                        partners={layerEResult?.implementation_partners}
                        requirements={layerEResult?.requirements_estimate}
                    />
                )}

                <Card className="bg-muted/30">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Methodology &amp; Data Notes</CardTitle>
                    </CardHeader>
                    <CardContent className="text-muted-foreground flex flex-col gap-1.5 text-xs">
                        <p>
                            <strong>Comparisons:</strong> National reference rates are descriptive benchmarks computed dynamically from source tables,
                            not official service standards. Density above its reference signals service pressure; other indicators flag rates below
                            their reference.
                        </p>
                        <p>
                            <strong>Evidence limits:</strong> A recorded zero is not proof of absence. Unknown population and missing benchmarks
                            remain distinct from zero. Modeled agriculture is planning context outside scored indicators.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </Layout>
    );
}

function UnifiedIndicatorsTable({ results }: { results: AssessmentResult[] }) {
    return (
        <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
            <table className="w-full min-w-[740px] border-collapse text-left text-sm">
                <thead>
                    <tr className="bg-slate-50 text-xs tracking-wider text-slate-500 uppercase dark:bg-slate-900">
                        <th className="px-4 py-3 font-medium">Indicator &amp; Source</th>
                        <th className="px-4 py-3 text-right font-medium">Local Rate</th>
                        <th className="px-4 py-3 text-right font-medium">National Rate</th>
                        <th className="w-48 px-4 py-3 font-medium">Comparison</th>
                        <th className="px-4 py-3 font-medium">Status &amp; Notes</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {results.map((row) => {
                        const maxVal = Math.max(row.value ?? 0, row.benchmark ?? 0, 1);
                        const localPct = row.value !== null ? Math.min(100, Math.round((row.value / maxVal) * 100)) : 0;
                        const benchPct = row.benchmark !== null ? Math.min(100, Math.round((row.benchmark / maxVal) * 100)) : 0;

                        return (
                            <tr key={row.key} className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-900/40">
                                <td className="px-4 py-3.5">
                                    <div className="font-semibold text-slate-900 dark:text-slate-100">{row.label}</div>
                                    <div className="text-muted-foreground mt-0.5 text-xs">{row.description}</div>
                                    <div className="text-muted-foreground mt-1 text-[11px]">
                                        Unit: <span className="font-medium">{row.unit}</span> · Source: {row.source}
                                    </div>
                                </td>
                                <td className="px-4 py-3.5 text-right font-mono text-base font-semibold tabular-nums">
                                    {row.value === null ? (
                                        <span className="text-muted-foreground text-xs font-normal">Unavailable</span>
                                    ) : (
                                        format(row.value)
                                    )}
                                </td>
                                <td className="px-4 py-3.5 text-right font-mono text-sm text-slate-600 tabular-nums dark:text-slate-400">
                                    {row.benchmark === null ? (
                                        <span className="text-muted-foreground text-xs">No reference</span>
                                    ) : (
                                        format(row.benchmark)
                                    )}
                                </td>
                                <td className="px-4 py-3.5">
                                    {row.value !== null && row.benchmark !== null ? (
                                        <div className="flex flex-col gap-1.5">
                                            <div className="flex items-center justify-between text-xs">
                                                <span className="text-muted-foreground">Gap</span>
                                                <span
                                                    className={`font-mono font-semibold ${
                                                        row.is_shortfall
                                                            ? 'text-amber-700 dark:text-amber-400'
                                                            : 'text-[#123575] dark:text-blue-300'
                                                    }`}
                                                >
                                                    {row.gap !== null ? `${row.gap > 0 ? '+' : ''}${row.gap.toFixed(1)}%` : '—'}
                                                </span>
                                            </div>
                                            <div className="space-y-1">
                                                <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full" title="Local rate">
                                                    <div
                                                        className={`h-full rounded-full ${
                                                            row.is_shortfall ? 'bg-amber-500' : 'bg-[#123575]'
                                                        }`}
                                                        style={{ width: `${localPct}%` }}
                                                    />
                                                </div>
                                                <div className="bg-muted h-1 w-full overflow-hidden rounded-full" title="National reference rate">
                                                    <div className="h-full rounded-full bg-slate-400" style={{ width: `${benchPct}%` }} />
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <span className="text-muted-foreground text-xs">—</span>
                                    )}
                                </td>
                                <td className="max-w-xs px-4 py-3.5">
                                    <StatusBadge result={row} />
                                    {row.reason && (
                                        <p className="text-muted-foreground mt-1.5 text-xs leading-relaxed">{row.reason}</p>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function LocationOverviewBar({
    location,
    populationYear,
}: {
    location: AssessmentPageProps['location'];
    populationYear: number | null;
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
            <CardContent className="p-4 sm:p-5">
                <div className="grid min-w-0 grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                    {items.map(([label, value, subtext]) => (
                        <div key={label} className="flex flex-col gap-0.5">
                            <span className="text-muted-foreground text-[11px] tracking-wide uppercase">{label}</span>
                            <span className="text-sm font-semibold break-words">{value}</span>
                            {subtext && <span className="text-muted-foreground text-[11px]">{subtext}</span>}
                        </div>
                    ))}
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
    const [sortBy, setSortBy] = useState<'priority_score_desc' | 'population_desc' | 'population_asc' | 'density_desc' | 'name_asc'>('priority_score_desc');
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
            if (sortBy === 'priority_score_desc') {
                return (b.priority_score ?? -1) - (a.priority_score ?? -1) || (b.population ?? -1) - (a.population ?? -1);
            }
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
                            <option value="priority_score_desc">Sort: Priority Score (High → Low)</option>
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
                                    <th className="px-4 py-3 text-right font-medium">Priority Score</th>
                                    <th className="px-4 py-3 font-medium">Coverage Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {visibleBarangays.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="text-muted-foreground px-4 py-8 text-center text-sm">
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
                                                <td className="px-4 py-3 text-right font-mono font-semibold tabular-nums">
                                                    {brgy.priority_score !== null && brgy.priority_score !== undefined
                                                        ? `${brgy.priority_score.toFixed(1)} / 100`
                                                        : '—'}
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

function LocalLaboratoriesSection({ cityName, laboratories }: { cityName: string; laboratories?: LocalLaboratoryItem[] }) {
    const labs = laboratories ?? [];

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        Layer B · analyze_sti_laboratory_capacity
                    </Badge>
                    <h2 className="text-lg font-semibold tracking-tight">DOST / OneLab Accredited Laboratories in {cityName}</h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    Existing testing and calibration laboratories located in {cityName} and their registered testing services.
                </p>
            </div>

            {labs.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground p-6 text-sm">
                        No accredited DOST / OneLab laboratories are currently recorded inside {cityName}. Residents and MSMEs can be referred to the
                        nearest regional OneLab facility.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {labs.map((lab) => {
                        const services = Array.isArray(lab.services) ? lab.services : [];

                        return (
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
                                    {services.length > 0 ? (
                                        <div className="flex flex-wrap gap-1.5">
                                            {services.map((svc: any, idx) => (
                                                <Badge key={idx} variant="outline" className="font-normal">
                                                    {svc.test_name || svc.service_name || 'Laboratory Test'}
                                                    {svc.method || svc.description ? ` (${svc.method || svc.description})` : ''}
                                                </Badge>
                                            ))}
                                            {lab.total_services !== null && lab.total_services > services.length && (
                                                <Badge variant="secondary" className="font-normal">
                                                    +{lab.total_services - services.length} more
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
                        );
                    })}
                </div>
            )}
        </section>
    );
}

function DatasetAuditSection({
    cityName,
    audit,
}: {
    cityName: string;
    audit: NonNullable<FunctionToolsBundle['dataset_audit']>;
}) {
    const datasets = Object.values(audit.datasets);

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        get_available_datasets
                    </Badge>
                    <h2 className="text-lg font-semibold tracking-tight">Dataset Availability Audit ({cityName})</h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    Live domain table coverage ({audit.summary.available_count} available · {audit.summary.partial_count} partial ·{' '}
                    {audit.summary.empty_count ?? 0} empty · {audit.summary.unavailable_count} unavailable).
                </p>
            </div>

            <Card>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left text-xs">
                            <thead>
                                <tr className="bg-muted/40 text-muted-foreground border-b tracking-wider uppercase">
                                    <th className="px-4 py-2.5 font-medium">Dataset &amp; Table</th>
                                    <th className="px-4 py-2.5 font-medium">Status</th>
                                    <th className="px-4 py-2.5 text-right font-medium">Local Records</th>
                                    <th className="px-4 py-2.5 text-right font-medium">National Records</th>
                                    <th className="px-4 py-2.5 font-medium">Reference / Notes</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {datasets.map((ds) => (
                                    <tr key={ds.key} className="hover:bg-muted/30">
                                        <td className="px-4 py-2.5">
                                            <div className="font-medium">{ds.label}</div>
                                            <div className="text-muted-foreground font-mono text-[11px]">{ds.table}</div>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <Badge
                                                variant={ds.status === 'available' ? 'default' : ds.status === 'partial' ? 'outline' : 'secondary'}
                                                className={ds.status === 'available' ? 'bg-[#123575]' : ''}
                                            >
                                                {ds.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-2.5 text-right font-mono tabular-nums">
                                            {ds.local_records !== null ? ds.local_records.toLocaleString() : '—'}
                                        </td>
                                        <td className="px-4 py-2.5 text-right font-mono tabular-nums">
                                            {ds.national_records !== null ? ds.national_records.toLocaleString() : '—'}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-2.5">
                                            {ds.reference_year ? `[${ds.reference_year}] ` : ''}
                                            {ds.notes}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </section>
    );
}

function LocalDostProjectsSection({
    cityName,
    data,
}: {
    cityName: string;
    data?: any;
}) {
    if (!data) {
        return (
            <Card>
                <CardHeader className="pb-2">
                    <div className="flex items-center gap-2">
                        <Badge variant="outline" className="font-mono text-xs">
                            Layer D · get_local_dost_projects
                        </Badge>
                        <CardTitle className="text-sm font-semibold">Recorded DOST Agency Projects ({cityName})</CardTitle>
                    </div>
                </CardHeader>
                <CardContent className="text-muted-foreground p-5 text-sm">
                    No historical or ongoing DOST projects data recorded in <code>dost_projects</code> for this locality.
                </CardContent>
            </Card>
        );
    }

    const projects: LocalDostProjectItem[] = Array.isArray(data?.projects) ? data.projects : [];
    const totalMatched = Number(data?.total_matched_projects ?? projects.length ?? 0);
    const totalBudget = Number(data?.total_recorded_budget_php ?? 0);

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        Layer D · get_local_dost_projects
                    </Badge>
                    <h2 className="text-lg font-semibold tracking-tight">Recorded DOST Agency Projects ({cityName} / Province)</h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    {totalMatched} historical/ongoing DOST project records matched from <code>dost_projects</code>
                    {totalBudget > 0
                        ? ` · Total recorded budget: ₱${totalBudget.toLocaleString(undefined, { maximumFractionDigits: 2 })}`
                        : ''}
                    .
                </p>
            </div>

            {projects.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground p-5 text-sm">
                        No location-matched project rows found in <code>dost_projects</code> for {cityName}.
                    </CardContent>
                </Card>
            ) : (
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left text-xs">
                                <thead>
                                    <tr className="bg-muted/40 text-muted-foreground border-b tracking-wider uppercase">
                                        <th className="px-4 py-2.5 font-medium">Agency</th>
                                        <th className="px-4 py-2.5 font-medium">Program &amp; Project Title</th>
                                        <th className="px-4 py-2.5 font-medium">Location</th>
                                        <th className="px-4 py-2.5 font-medium">Status</th>
                                        <th className="px-4 py-2.5 text-right font-medium">Budget (PHP)</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {projects.slice(0, 15).map((proj, idx) => (
                                        <tr key={proj.id ?? idx} className="hover:bg-muted/30">
                                            <td className="px-4 py-2.5 font-semibold">{proj.agency ?? '—'}</td>
                                            <td className="px-4 py-2.5">
                                                <div className="font-medium">{proj.project_title || 'Untitled Project'}</div>
                                                {proj.program && <div className="text-muted-foreground text-[11px]">{proj.program}</div>}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5">{proj.location ?? '—'}</td>
                                            <td className="px-4 py-2.5">
                                                <Badge variant="outline">{proj.normalized_status || proj.raw_status || 'Recorded'}</Badge>
                                            </td>
                                            <td className="px-4 py-2.5 text-right font-mono tabular-nums">
                                                {proj.budget_php !== null && proj.budget_php !== undefined && Number(proj.budget_php) > 0
                                                    ? `₱${Number(proj.budget_php).toLocaleString(undefined, { maximumFractionDigits: 2 })}`
                                                    : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
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

/* -------------------------------------------------------------------------- */
/* Layer A: Demographics and Local Assets Summary Component                    */
/* -------------------------------------------------------------------------- */

function LayerAAssetsSummary({
    location,
    localAssets,
    geoData,
}: {
    location: AssessmentPageProps['location'];
    localAssets?: FunctionToolsBundle['local_assets'];
    geoData?: FunctionToolsBundle['geographic_data_availability'];
}) {
    const popStats = location.population_statistics;
    const counts = localAssets?.facility_counts;
    const healthCount = counts?.health ?? 0;
    const schoolCount = counts?.education ?? 0;
    const safetyCount = counts?.safety ?? 0;
    const telecomCount = localAssets?.telecom_site_count ?? 0;
    const roadCount = geoData?.local_road_records ?? 0;

    return (
        <section className="flex flex-col gap-4">
            <div className="flex items-center gap-2">
                <Badge variant="outline" className="font-mono text-xs">
                    Layer A · get_local_assets
                </Badge>
                <h2 className="text-lg font-semibold tracking-tight text-[#123575] dark:text-blue-200">
                    Local Physical Assets &amp; Geographic Profiling
                </h2>
            </div>

            {/* Asset Cards Grid */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <CardContent className="flex flex-col gap-1 p-4">
                        <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                            <Building2 className="size-3.5 text-emerald-600" />
                            Health Facilities
                        </span>
                        <span className="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">
                            {healthCount}
                        </span>
                        <span className="text-muted-foreground text-[10px]">Hospitals, RHUs &amp; BHS</span>
                    </CardContent>
                </Card>

                <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <CardContent className="flex flex-col gap-1 p-4">
                        <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                            <Building2 className="size-3.5 text-blue-600" />
                            Schools &amp; Learning
                        </span>
                        <span className="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">
                            {schoolCount}
                        </span>
                        <span className="text-muted-foreground text-[10px]">DepEd &amp; HEI campuses</span>
                    </CardContent>
                </Card>

                <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <CardContent className="flex flex-col gap-1 p-4">
                        <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                            <ShieldCheck className="size-3.5 text-red-600" />
                            Safety &amp; DRRM
                        </span>
                        <span className="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">
                            {safetyCount}
                        </span>
                        <span className="text-muted-foreground text-[10px]">Police, Fire &amp; Rescue</span>
                    </CardContent>
                </Card>

                <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <CardContent className="flex flex-col gap-1 p-4">
                        <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                            <Radio className="size-3.5 text-orange-600" />
                            Telecom Towers
                        </span>
                        <span className="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">
                            {telecomCount}
                        </span>
                        <span className="text-muted-foreground text-[10px]">Cellular &amp; Tower sites</span>
                    </CardContent>
                </Card>

                <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <CardContent className="flex flex-col gap-1 p-4">
                        <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                            <Navigation className="size-3.5 text-teal-600" />
                            Road Segments
                        </span>
                        <span className="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">
                            {roadCount.toLocaleString()}
                        </span>
                        <span className="text-muted-foreground text-[10px]">Mapped road network</span>
                    </CardContent>
                </Card>
            </div>

            {/* Demographics Summary Card */}
            {popStats && (
                <Card className="border-blue-100 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                            Multi-Year Demographic Profile (2020 Baseline vs 2024 Census)
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-4 text-xs">
                        <div>
                            <span className="text-muted-foreground text-[11px] uppercase tracking-wider">Current Population (2024)</span>
                            <div className="text-base font-bold font-mono text-slate-900 dark:text-slate-100">
                                {popStats.population_current?.toLocaleString() ?? '—'}
                            </div>
                            <span className="text-muted-foreground text-[10px]">Active Census benchmark</span>
                        </div>
                        <div>
                            <span className="text-muted-foreground text-[11px] uppercase tracking-wider">Baseline Population (2020)</span>
                            <div className="text-base font-bold font-mono text-slate-900 dark:text-slate-100">
                                {popStats.population_baseline?.toLocaleString() ?? '—'}
                            </div>
                            <span className="text-muted-foreground text-[10px]">PSA 2020 Census figure</span>
                        </div>
                        <div>
                            <span className="text-muted-foreground text-[11px] uppercase tracking-wider">Population Change &amp; CAGR</span>
                            <div className="text-base font-bold font-mono text-emerald-700 dark:text-emerald-400">
                                {popStats.growth_rate_pct !== null ? `${popStats.growth_rate_pct > 0 ? '+' : ''}${popStats.growth_rate_pct.toFixed(2)}%` : '—'}
                            </div>
                            <span className="text-muted-foreground text-[10px]">
                                {popStats.annual_growth_rate_pct !== null ? `${popStats.annual_growth_rate_pct.toFixed(2)}% annual CAGR` : 'Annualized rate'}
                            </span>
                        </div>
                        <div>
                            <span className="text-muted-foreground text-[11px] uppercase tracking-wider">Density &amp; Classification</span>
                            <div className="text-base font-bold font-mono text-slate-900 dark:text-slate-100">
                                {location.area_km2 && popStats.population_current
                                    ? `${Math.round(popStats.population_current / location.area_km2).toLocaleString()} / km²`
                                    : '—'}
                            </div>
                            <span className="text-muted-foreground text-[10px]">
                                {location.income_class ?? 'Unclassified'} Class · {location.urban_rural ?? 'Rural'}
                            </span>
                        </div>
                    </CardContent>
                </Card>
            )}
        </section>
    );
}

/* -------------------------------------------------------------------------- */
/* Layer B: Road Accessibility Component                                      */
/* -------------------------------------------------------------------------- */

function RoadAccessibilityCard({
    roadAccessibility,
}: {
    roadAccessibility?: any;
}) {
    if (!roadAccessibility) {
        return (
            <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                <CardHeader className="pb-3">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Navigation className="size-4 text-teal-600" />
                            <CardTitle className="text-sm font-semibold">Road Network &amp; Logistics Accessibility</CardTitle>
                        </div>
                        <Badge variant="outline" className="font-mono text-[10px]">
                            Layer B · analyze_road_accessibility
                        </Badge>
                    </div>
                </CardHeader>
                <CardContent className="p-4 text-xs text-muted-foreground">
                    Road accessibility analysis data is not available.
                </CardContent>
            </Card>
        );
    }

    const summary = roadAccessibility?.summary ?? {};
    const totalSegments = Number(summary.total_road_segments ?? roadAccessibility?.total_segments ?? 0);
    const totalKm = Number(summary.total_road_length_km ?? roadAccessibility?.total_length_km ?? 0);
    const roadDensity = summary.road_density_km_per_km2 ?? roadAccessibility?.road_density_km_per_km2 ?? null;
    const coverage = roadAccessibility?.barangay_coverage;
    const reason = roadAccessibility?.reason;

    const bySurface: any[] = Array.isArray(roadAccessibility?.by_surface_type)
        ? roadAccessibility.by_surface_type
        : [];

    const pavedSegments = bySurface
        .filter((s: any) => ['concrete', 'asphalt', 'paved'].includes(String(s.surface_type || '').toLowerCase()))
        .reduce((sum: number, s: any) => sum + Number(s.segments || 0), 0);

    const pavedPct = totalSegments > 0 ? Math.round((pavedSegments / totalSegments) * 100) : 0;
    const unpavedPct = totalSegments > 0 ? Math.max(0, 100 - pavedPct) : 0;

    return (
        <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <Navigation className="size-4 text-teal-600" />
                        <CardTitle className="text-sm font-semibold">Road Network &amp; Logistics Accessibility</CardTitle>
                    </div>
                    <Badge variant="outline" className="font-mono text-[10px]">
                        Layer B · analyze_road_accessibility
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4 text-xs">
                {totalSegments === 0 ? (
                    <div className="rounded-lg bg-amber-50 p-3 text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                        <p className="font-medium">0 Road Segments Recorded</p>
                        <p className="text-[11px] mt-1 opacity-90">
                            {reason || 'No road network segments recorded for this locality in the database.'}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="grid grid-cols-3 gap-3 text-center border-b pb-3 dark:border-slate-800">
                            <div>
                                <div className="text-muted-foreground text-[10px] uppercase">Total Segments</div>
                                <div className="text-base font-bold font-mono text-slate-900 dark:text-white">
                                    {totalSegments.toLocaleString()}
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground text-[10px] uppercase">Network Length</div>
                                <div className="text-base font-bold font-mono text-slate-900 dark:text-white">
                                    {totalKm.toFixed(1)} km
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground text-[10px] uppercase">Road Density</div>
                                <div className="text-base font-bold font-mono text-slate-900 dark:text-white">
                                    {roadDensity !== null ? `${Number(roadDensity).toFixed(2)} km/km²` : '—'}
                                </div>
                            </div>
                        </div>

                        {/* Paved vs Unpaved Visual Bar */}
                        <div className="flex flex-col gap-1.5">
                            <div className="flex justify-between text-[11px] font-medium">
                                <span className="text-teal-700 dark:text-teal-400">Paved (Concrete/Asphalt): {pavedPct}%</span>
                                <span className="text-amber-700 dark:text-amber-400">Unpaved (Gravel/Earth): {unpavedPct}%</span>
                            </div>
                            <div className="bg-amber-200 h-2 w-full overflow-hidden rounded-full dark:bg-amber-900/40">
                                <div
                                    className="bg-teal-600 h-full rounded-full transition-all"
                                    style={{ width: `${pavedPct}%` }}
                                />
                            </div>
                            <p className="text-muted-foreground text-[10px]">
                                {pavedPct >= 50
                                    ? 'Adequate paved road connectivity supports physical DOST equipment hauling.'
                                    : 'Substantial unpaved roads may require 4x4 transport or dry-season scheduling.'}
                            </p>
                        </div>

                        {/* Surface Breakdown Chips */}
                        {bySurface.length > 0 && (
                            <div className="flex flex-wrap gap-1.5 pt-1">
                                {bySurface.map((s: any, idx: number) => (
                                    <Badge key={idx} variant="secondary" className="text-[10px] font-normal capitalize">
                                        {s.surface_type || 'Unclassified'}: {s.segments ?? 0} segments ({s.length_km ?? 0} km)
                                    </Badge>
                                ))}
                            </div>
                        )}

                        {/* Barangay Coverage if available */}
                        {coverage && (
                            <div className="border-t pt-2 text-[11px] text-muted-foreground dark:border-slate-800">
                                Barangay Accessibility: <strong className="text-slate-800 dark:text-slate-200">{coverage.coverage_pct ?? 0}%</strong> ({coverage.barangays_with_roads ?? 0} of {coverage.total_barangays ?? 0} barangays with road access)
                            </div>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

/* -------------------------------------------------------------------------- */
/* Layer B: Telecom Connectivity Component                                    */
/* -------------------------------------------------------------------------- */

function TelecomConnectivityCard({
    telecom,
}: {
    telecom?: any;
}) {
    if (!telecom) {
        return (
            <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                <CardHeader className="pb-3">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Radio className="size-4 text-orange-600" />
                            <CardTitle className="text-sm font-semibold">Digital Telecom &amp; Cellular Infrastructure</CardTitle>
                        </div>
                        <Badge variant="outline" className="font-mono text-[10px]">
                            Layer B · analyze_digital_connectivity
                        </Badge>
                    </div>
                </CardHeader>
                <CardContent className="p-4 text-xs text-muted-foreground">
                    Telecom connectivity analysis data is not available.
                </CardContent>
            </Card>
        );
    }

    const summary = telecom?.summary ?? {};
    const sitesCount = Number(summary.total_sites ?? telecom?.total_sites ?? 0);
    const with5G = Number(summary.sites_with_5g ?? 0);
    const techs: string[] = Array.isArray(telecom?.technologies_present) ? telecom.technologies_present : [];
    const has5G = with5G > 0 || techs.includes('5G');

    const byOp = telecom?.by_operator ?? telecom?.operators ?? {};
    const operators: string[] = Array.isArray(byOp)
        ? byOp.map((o: any) => (typeof o === 'string' ? o : `${o.name || o.operator} (${o.count || 1})`))
        : typeof byOp === 'object' && byOp !== null
        ? Object.entries(byOp).map(([k, v]) => `${k} (${v})`)
        : [];

    return (
        <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <Radio className="size-4 text-orange-600" />
                        <CardTitle className="text-sm font-semibold">Digital Telecom &amp; Cellular Infrastructure</CardTitle>
                    </div>
                    <Badge variant="outline" className="font-mono text-[10px]">
                        Layer B · analyze_digital_connectivity
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 text-xs">
                <div className="grid grid-cols-2 gap-3 border-b pb-3 dark:border-slate-800">
                    <div>
                        <div className="text-muted-foreground text-[10px] uppercase">Recorded Cell Sites</div>
                        <div className="text-lg font-bold font-mono text-slate-900 dark:text-white">
                            {sitesCount} tower(s)
                        </div>
                    </div>
                    <div>
                        <div className="text-muted-foreground text-[10px] uppercase">5G Network Deployment</div>
                        <div className="mt-0.5">
                            <Badge variant={has5G ? 'default' : 'outline'} className={has5G ? 'bg-emerald-600' : 'text-slate-600'}>
                                {has5G ? '5G Available' : '4G / LTE Baseline'}
                            </Badge>
                        </div>
                    </div>
                </div>

                {operators.length > 0 && (
                    <div>
                        <div className="text-muted-foreground text-[11px] font-medium mb-1.5">Tower Operators &amp; Carriers:</div>
                        <div className="flex flex-wrap gap-1.5">
                            {operators.map((op, idx) => (
                                <Badge key={idx} variant="outline" className="text-[10px]">
                                    {op}
                                </Badge>
                            ))}
                        </div>
                    </div>
                )}

                <p className="text-muted-foreground text-[10px]">
                    {sitesCount > 0
                        ? 'Active cellular signal supports cloud sync for digital STI tools (STARBOOKS, RxBox, IoT weather sensors).'
                        : 'Zero cell sites recorded in database; offline mode or satellite broadband kit recommended.'}
                </p>
            </CardContent>
        </Card>
    );
}

/* -------------------------------------------------------------------------- */
/* Layer C: Detected Development Gaps Component                               */
/* -------------------------------------------------------------------------- */

function DetectedGapsCard({
    detectedGaps,
    cityName,
}: {
    detectedGaps: NonNullable<FunctionToolsBundle['detected_gaps']>;
    cityName: string;
}) {
    const gaps = detectedGaps.gaps ?? [];

    return (
        <section className="flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        Layer C · detect_development_gaps
                    </Badge>
                    <h2 className="text-lg font-semibold tracking-tight text-[#123575] dark:text-blue-200">
                        Identified Development Gaps &amp; Shortfalls ({detectedGaps.total_gaps_detected})
                    </h2>
                </div>
                {detectedGaps.critical_severity_count > 0 && (
                    <Badge variant="destructive" className="text-xs">
                        {detectedGaps.critical_severity_count} Critical Severity
                    </Badge>
                )}
            </div>

            {gaps.length === 0 ? (
                <Card>
                    <CardContent className="p-6 text-sm text-muted-foreground">
                        No critical development gaps currently detected below national benchmarks for {cityName}.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                    {gaps.map((gap: any, idx: number) => {
                        const severity = gap.severity_score ?? 50;
                        const level = gap.severity_level ?? 'Moderate';

                        return (
                            <Card key={idx} className="border-amber-200/80 bg-white shadow-xs dark:border-amber-900/40 dark:bg-slate-900">
                                <CardHeader className="pb-2">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="font-medium text-sm text-slate-900 dark:text-slate-100">
                                            {gap.label ?? gap.indicator_key ?? 'Development Deficit'}
                                        </div>
                                        <Badge
                                            className={
                                                level === 'Critical'
                                                    ? 'bg-red-600'
                                                    : level === 'High'
                                                    ? 'bg-amber-600'
                                                    : 'bg-blue-600'
                                            }
                                        >
                                            {severity}% Severity
                                        </Badge>
                                    </div>
                                    <span className="text-muted-foreground text-[11px] capitalize">
                                        Domain: {gap.dimension ?? 'General'} · Type: {gap.gap_type?.replace('_', ' ') ?? 'Shortfall'}
                                    </span>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-2 text-xs">
                                    <div className="flex justify-between items-center font-mono border-t pt-2 dark:border-slate-800">
                                        <span className="text-muted-foreground">Measured vs Benchmark:</span>
                                        <span className="font-semibold text-slate-900 dark:text-slate-100">
                                            {format(gap.measured_value)} vs {format(gap.benchmark_value)} {gap.unit}
                                        </span>
                                    </div>

                                    {gap.percentage_gap !== null && (
                                        <div className="flex justify-between items-center text-muted-foreground">
                                            <span>Percentage Gap:</span>
                                            <span className="font-mono text-amber-700 font-semibold dark:text-amber-400">
                                                {gap.percentage_gap > 0 ? '+' : ''}{gap.percentage_gap}%
                                            </span>
                                        </div>
                                    )}

                                    {gap.affected_population && (
                                        <div className="text-muted-foreground text-[11px]">
                                            Estimated affected population: <span className="font-semibold">{gap.affected_population.toLocaleString()}</span> residents
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

/* -------------------------------------------------------------------------- */
/* Layer D: Searchable DOST Technologies Catalog Component                    */
/* -------------------------------------------------------------------------- */

function DostTechnologiesCatalogSection({
    dostInterventions,
    projectCatalog,
    catalogTechnologies,
}: {
    dostInterventions?: DostInterventionItem[];
    projectCatalog?: ProjectCatalogData;
    catalogTechnologies?: any[];
}) {
    const [search, setSearch] = useState('');

    const rawList: any[] = (Array.isArray(dostInterventions) && dostInterventions.length > 0)
        ? dostInterventions
        : (Array.isArray(catalogTechnologies) && catalogTechnologies.length > 0)
        ? catalogTechnologies
        : [];

    const filtered = rawList.filter((tech: any) => {
        if (!search.trim()) return true;
        const q = search.toLowerCase();
        const name = String(tech.name || '').toLowerCase();
        const agency = String(tech.agency || '').toLowerCase();
        const sector = String(tech.sector || '').toLowerCase();
        const mechanism = String(tech.mechanism || tech.description || tech.outcome || '').toLowerCase();
        return (
            name.includes(q) ||
            agency.includes(q) ||
            sector.includes(q) ||
            mechanism.includes(q)
        );
    });

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        Layer D · search_dost_technologies
                    </Badge>
                    <h2 className="text-lg font-semibold tracking-tight text-[#123575] dark:text-blue-200">
                        DOST Technology &amp; STI Programs Catalog
                    </h2>
                </div>
                <div className="relative w-full sm:w-64">
                    <Search className="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground" />
                    <Input
                        placeholder="Search technology, sector, agency…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="h-8 pl-8 text-xs"
                    />
                </div>
            </div>

            {filtered.length === 0 ? (
                <Card>
                    <CardContent className="p-6 text-sm text-muted-foreground text-center">
                        {search.trim()
                            ? `No DOST technologies match "${search}". Try searching another keyword or agency.`
                            : 'No specific DOST technologies catalog entries mapped to this locality yet.'}
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {filtered.map((item: any, idx: number) => (
                        <Card key={item.id ?? idx} className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                            <CardHeader className="pb-2">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="font-semibold text-sm text-slate-900 dark:text-slate-100">
                                        {item.name || 'DOST Technology'}
                                    </div>
                                    <Badge variant="outline" className="text-[10px]">
                                        {item.agency || 'DOST'}
                                    </Badge>
                                </div>
                                {item.code && <div className="text-muted-foreground text-[11px] font-mono">{item.code}</div>}
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-xs">
                                {item.sector && (
                                    <Badge variant="secondary" className="w-fit text-[10px]">
                                        {item.sector}
                                    </Badge>
                                )}
                                <p className="text-muted-foreground text-[11px] leading-relaxed line-clamp-3">
                                    {item.mechanism || item.outcome || item.description || 'Verified DOST scientific program package.'}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}

/* -------------------------------------------------------------------------- */
/* Layer E: Matched Interventions, Feasibility & Suitability Component         */
/* -------------------------------------------------------------------------- */

function LayerEInterventionsSection({
    cityName,
    matchedInterventions,
    suitability,
    feasibility,
    partners,
    requirements,
}: {
    cityName: string;
    matchedInterventions?: any;
    suitability?: any;
    feasibility?: any;
    partners?: any;
    requirements?: any;
}) {
    const gapsMatches = matchedInterventions?.gap_interventions ?? [];

    return (
        <div className="flex flex-col gap-6">
            {/* Header */}
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        Layer E · match_dost_interventions &amp; assess_feasibility
                    </Badge>
                    <h2 className="text-xl font-bold tracking-tight text-[#123575] dark:text-blue-200">
                        Deterministic STI Intervention Matching &amp; Feasibility Evaluation
                    </h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    Evidence-backed technology matching for {cityName} mapped directly to identified development shortfalls, local resource readiness, and verified program availability.
                </p>
            </div>

            {/* Feasibility & Suitability Status Banner */}
            {feasibility && suitability && (
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Implementation Feasibility Card */}
                    <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="size-4 text-emerald-600" />
                                    <CardTitle className="text-sm font-semibold">Implementation Feasibility Assessment</CardTitle>
                                </div>
                                <Badge
                                    className={
                                        feasibility.feasibility_status === 'feasible'
                                            ? 'bg-emerald-600'
                                            : feasibility.feasibility_status === 'conditionally_feasible'
                                            ? 'bg-amber-600'
                                            : 'bg-red-600'
                                    }
                                >
                                    {feasibility.feasibility_status?.replace('_', ' ').toUpperCase()}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3 text-xs">
                            <p className="text-muted-foreground">{feasibility.feasibility_summary}</p>

                            {/* Verified Prerequisites Checklist */}
                            {feasibility.verified_prerequisites && (
                                <div className="flex flex-col gap-1.5 border-t pt-2 dark:border-slate-800">
                                    <span className="font-medium text-slate-800 dark:text-slate-200 text-[11px]">Prerequisite Verification:</span>
                                    {feasibility.verified_prerequisites.slice(0, 4).map((p: any, idx: number) => (
                                        <div key={idx} className="flex items-center justify-between gap-2 text-[11px]">
                                            <span className="text-muted-foreground truncate">{p.prerequisite}</span>
                                            <Badge variant={p.status === 'passed' ? 'default' : 'outline'} className={p.status === 'passed' ? 'bg-emerald-600 text-[10px]' : 'text-[10px]'}>
                                                {p.status}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Suitability 5-Dimension Radar Card */}
                    <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Sparkles className="size-4 text-blue-600" />
                                    <CardTitle className="text-sm font-semibold">Suitability Score: {suitability.suitability_score ?? 75}/100</CardTitle>
                                </div>
                                <Badge variant="secondary" className="capitalize">
                                    {suitability.suitability_level?.replace('_', ' ')}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2.5 text-xs">
                            {suitability.dimension_scores && (
                                <div className="flex flex-col gap-1.5">
                                    {Object.entries(suitability.dimension_scores).map(([dim, score]: [string, any]) => (
                                        <div key={dim} className="flex flex-col gap-0.5">
                                            <div className="flex justify-between text-[10px] text-muted-foreground capitalize">
                                                <span>{dim.replace('_', ' ')}</span>
                                                <span className="font-mono font-semibold">{score}%</span>
                                            </div>
                                            <div className="bg-muted h-1 w-full overflow-hidden rounded-full">
                                                <div className="bg-[#123575] h-full rounded-full" style={{ width: `${score}%` }} />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {suitability.enablers && suitability.enablers.length > 0 && (
                                <div className="border-t pt-2 dark:border-slate-800">
                                    <span className="text-[10px] font-semibold text-emerald-700 dark:text-emerald-400">Key Enabler:</span>
                                    <p className="text-muted-foreground text-[11px] line-clamp-1">{suitability.enablers[0]}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            )}

            {/* Matched Interventions per Gap */}
            <div className="flex flex-col gap-4">
                <h3 className="text-base font-semibold text-slate-800 dark:text-slate-100">
                    Matched DOST STI Programs per Identified Gap
                </h3>

                {gapsMatches.length === 0 ? (
                    <Card>
                        <CardContent className="p-6 text-sm text-muted-foreground">
                            No specific gap matches computed yet.
                        </CardContent>
                    </Card>
                ) : (
                    gapsMatches.map((gm: any, idx: number) => (
                        <Card key={idx} className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                            <CardHeader className="pb-3 border-b dark:border-slate-800">
                                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <Badge variant="outline" className="text-[10px] font-mono uppercase">
                                            Gap Domain: {gm.domain}
                                        </Badge>
                                        <CardTitle className="text-sm font-semibold mt-1 text-slate-900 dark:text-slate-100">
                                            {gm.gap_name}
                                        </CardTitle>
                                    </div>
                                    <span className="text-xs text-muted-foreground">
                                        Severity: <span className="font-bold text-amber-700 dark:text-amber-400">{gm.severity}%</span>
                                    </span>
                                </div>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3 pt-3 text-xs">
                                {gm.matched_interventions && gm.matched_interventions.length > 0 ? (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {gm.matched_interventions.map((mi: any, miIdx: number) => (
                                            <div
                                                key={miIdx}
                                                className="flex flex-col gap-2 rounded-lg border border-slate-100 bg-slate-50/60 p-3 dark:border-slate-800 dark:bg-slate-950"
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="font-semibold text-slate-900 dark:text-slate-100">
                                                        {mi.name}
                                                    </div>
                                                    <Badge className="bg-[#123575] shrink-0 text-[10px]">
                                                        {mi.relevance_score}% Relevance
                                                    </Badge>
                                                </div>

                                                <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                                                    <span>Agency: <strong>{mi.agency}</strong></span>
                                                    <span>·</span>
                                                    <span>Status: <strong className="capitalize">{mi.availability_status}</strong></span>
                                                </div>

                                                {mi.matching_criteria && (
                                                    <div className="flex flex-col gap-1 border-t pt-2 dark:border-slate-800">
                                                        <span className="text-[10px] font-semibold text-slate-600 dark:text-slate-400">Matching Basis:</span>
                                                        <ul className="list-disc pl-4 text-[10px] text-muted-foreground space-y-0.5">
                                                            {mi.matching_criteria.map((crit: string, cIdx: number) => (
                                                                <li key={cIdx}>{crit}</li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}

                                                <div className="text-[10px] text-muted-foreground mt-auto">
                                                    Target Beneficiaries: <em>{mi.intended_beneficiaries}</em>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground text-xs italic">
                                        {gm.gap_unmatched_note ?? 'No verified DOST catalog intervention currently recorded for this specific gap domain.'}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>

            {/* Implementation Partners & Requirements Grid */}
            <div className="grid gap-4 md:grid-cols-2">
                {/* Verified Partners */}
                {partners?.partners && (
                    <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Users className="size-4 text-blue-600" />
                                <CardTitle className="text-sm font-semibold">Verified Implementation Partners</CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2 text-xs">
                            {partners.partners.slice(0, 4).map((p: any, idx: number) => (
                                <div key={idx} className="border-b pb-2 last:border-b-0 dark:border-slate-800">
                                    <div className="font-semibold text-slate-900 dark:text-slate-100">{p.partner_name}</div>
                                    <div className="text-[10px] text-muted-foreground">{p.partner_type} · {p.jurisdiction}</div>
                                    <p className="text-[11px] text-muted-foreground mt-0.5">{p.suggested_role}</p>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {/* Resource & Funding Profile */}
                {requirements?.requirements && (
                    <Card className="border-blue-100 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Coins className="size-4 text-emerald-600" />
                                <CardTitle className="text-sm font-semibold">Resource Requirements &amp; Historical Budget</CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3 text-xs">
                            {requirements.requirements.equipment_and_hardware && (
                                <div>
                                    <span className="text-[11px] font-semibold text-slate-800 dark:text-slate-200">Hardware Package:</span>
                                    <p className="text-[11px] text-muted-foreground">
                                        {requirements.requirements.equipment_and_hardware.primary_hardware}
                                    </p>
                                </div>
                            )}

                            {requirements.requirements.funding_profile && (
                                <div className="border-t pt-2 dark:border-slate-800">
                                    <span className="text-[11px] font-semibold text-slate-800 dark:text-slate-200">Historical Funding Benchmark:</span>
                                    {requirements.requirements.funding_profile.historical_benchmark_budget ? (
                                        <div className="mt-1 flex items-baseline gap-2 font-mono">
                                            <span className="text-base font-bold text-emerald-700 dark:text-emerald-400">
                                                ₱{requirements.requirements.funding_profile.historical_benchmark_budget.median_php?.toLocaleString()}
                                            </span>
                                            <span className="text-[10px] text-muted-foreground">
                                                (Median of {requirements.requirements.funding_profile.historical_benchmark_budget.sample_projects_count} verified projects)
                                            </span>
                                        </div>
                                    ) : (
                                        <p className="text-[11px] text-amber-700 dark:text-amber-400 italic mt-0.5">
                                            Pending detailed proposal costing.
                                        </p>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </div>
    );
}
