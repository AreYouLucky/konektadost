import AssessmentAiAnalysis, { useAssessmentAi } from '@/components/assessment-ai-analysis';
import AssessmentDataAttribution from '@/components/assessment-data-attribution';
import { OverviewBars, StepHeader } from '@/components/assessment-layer-overview';
import HowComputedDialog from '@/components/assessment-method/how-computed-dialog';
import { BarangayPriorityGraphs, GapSeverityMix, SectorScorecard, TechnologyFitGraphs } from '@/components/assessment-method/layer-graphs';
import { GapSectorTable, InterventionSectorTable } from '@/components/assessment-sector-tables';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { buildLayerOverviews, reportCatalog, reportGaps, reportTechnologyOptions, type AssessmentLayer } from '@/lib/assessment-report';
import { AgricultureSummaryView } from '@/pages/admin/search-map/partials/province-agriculture-card';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BarChart3, Database, FlaskConical, History, ListChecks, MapPin, Route, Table2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { AssessmentNotices, AtAGlance, ChartGrid, Collapsible, ReportActions, StepBlock } from './assessment-layout';
import AssessmentNavigation, { ASSESSMENT_SECTIONS } from './assessment-navigation';
import { DostTechnologiesCatalogSection, LocalDostProjectsSection } from './dost-catalog';
import LaboratorySupport from './laboratory-support';
import { LocationFacts } from './location-overview';
import { NeedsSummary } from './needs-summary';
import { BarangayPrioritySection } from './priority-areas';
import { DatasetAuditSection } from './profile-assets';
import { RoadAccessibilityCard, TelecomConnectivityCard, UnifiedIndicatorsTable } from './sector-needs';
import { TargetAreasSection } from './target-areas';
import type { AssessmentPageProps } from './types';

const WIDE = 'lg:col-span-2';

/**
 * Layout: header (identity, key facts, actions) → notices → "At a glance" answers → sticky step tabs →
 * the active step, always in the same order: step header → key graphs → AI interpretation → detailed records.
 */
export function LocationAssessmentReport(report: AssessmentPageProps) {
    const { location, focus, results, barangayAssessment, functionToolsBundle: bundle, layerEResult, populationYear = null } = report;
    const [activeSection, setActiveSection] = useState<AssessmentLayer>('matched_interventions');
    const navRef = useRef<HTMLElement>(null);
    const aiEnabled = !!bundle && !!location.psg_code;
    const ai = useAssessmentAi(location.psg_code, focus, true, aiEnabled, report.assessmentParams);
    const analysis = ai.state.status === 'ready' ? ai.state.result : undefined;
    const overviews = useMemo(() => buildLayerOverviews(report), [report]);
    const counts = useMemo(
        () => ({
            profile_assets: { value: bundle?.dataset_audit?.summary.total_datasets ?? 0, label: 'datasets checked' },
            sectors_roads: { value: results.filter((row) => row.is_shortfall).length, label: 'possible needs flagged' },
            gaps_barangays: { value: reportGaps(report).length, label: 'gaps detected' },
            dost_catalog: { value: reportCatalog(report).length, label: 'catalog entries' },
            matched_interventions: { value: reportTechnologyOptions(report).length, label: 'matched technology options' },
        }),
        [report, bundle, results],
    );
    const Layout = report.assessmentLayout === 'admin' ? AppSidebarLayoutAdmin : AppSidebarLayout;
    const backParams = location.province_psg_code ? { province: location.province_psg_code, city: location.psg_code } : { city: location.psg_code };
    const overview = overviews[activeSection];
    const stepIndex = ASSESSMENT_SECTIONS.findIndex((section) => section.key === activeSection);
    const step = ASSESSMENT_SECTIONS[stepIndex];
    const barangays = barangayAssessment?.barangays ?? [];
    const hasBarangayGraphs = barangays.some((row) => row.priority_score != null && row.population != null);
    const hasTechnologyGraphs = counts.matched_interventions.value > 0;
    const openStep = (layer: AssessmentLayer) => {
        setActiveSection(layer);
        navRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    /** One overview chart of the active step by title (null when the step has no such chart). */
    const bars = (title: string, className = '') => {
        const chart = overview.charts.find((item) => item.title === title);
        return chart ? <OverviewBars chart={chart} className={className} /> : null;
    };
    const aiBlock = (mode: 'gaps' | 'interventions') =>
        aiEnabled && <AssessmentAiAnalysis key={mode} state={ai.state} retry={ai.retry} mode={mode} />;

    return (
        <Layout>
            <Head title={location.name + ' Assessment'} />
            <div className="location-assessment flex min-w-0 flex-1 flex-col gap-5 bg-blue-50/60 p-4 md:p-6 dark:bg-slate-950">
                <header className="min-w-0 rounded-2xl border border-sky-100 bg-white p-4 shadow-xs sm:p-5 dark:border-slate-800 dark:bg-slate-900">
                    <Link
                        href={report.mapReturnUrl ?? route('location.show', backParams)}
                        className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 hover:text-[#087dcc]"
                    >
                        <ArrowLeft className="size-3.5" aria-hidden="true" /> Back to {report.mapReturnUrl ? 'Intelligence Map' : 'map'}
                    </Link>
                    <div className="mt-2 flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0">
                            <h1 className="text-2xl font-semibold tracking-tight text-[#087dcc] sm:text-3xl">{location.name}</h1>
                            <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
                                <MapPin className="size-3.5" aria-hidden="true" />
                                {[location.type, location.province].filter(Boolean).join(' · ')}
                                <span className="text-slate-300 dark:text-slate-600">|</span>
                                DOST technology assessment
                            </p>
                        </div>
                        <ReportActions report={report} overviews={overviews} analysis={analysis} />
                    </div>
                    <div className="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <LocationFacts location={location} populationYear={populationYear} />
                    </div>
                </header>

                <AssessmentNotices report={report} />
                <AtAGlance report={report} onOpen={openStep} />
                <NeedsSummary report={report} onOpen={openStep} />
                <AssessmentNavigation ref={navRef} active={activeSection} onChange={setActiveSection} counts={counts} />

                <div className="min-w-0 space-y-6">
                    <StepHeader
                        overview={overview}
                        step={`${String.fromCharCode(65 + stepIndex)} of E`}
                        label={step.label}
                        icon={step.icon}
                        action={<HowComputedDialog key={activeSection} layer={activeSection} report={report} />}
                    />

                    {activeSection === 'profile_assets' && (
                        <>
                            <StepBlock title="Key graphs" hint="People, recorded assets and data coverage" icon={BarChart3}>
                                <ChartGrid>
                                    {bars('Population across census years')}
                                    {bars('Recorded local assets')}
                                    {bars('Dataset availability', WIDE)}
                                </ChartGrid>
                            </StepBlock>
                            {bundle?.dataset_audit && (
                                <StepBlock title="Detailed records" icon={Table2}>
                                    <Collapsible
                                        title="Dataset coverage"
                                        count={bundle.dataset_audit.summary.total_datasets}
                                        hint="Which data sources cover this location"
                                        icon={Database}
                                    >
                                        <DatasetAuditSection cityName={location.name} audit={bundle.dataset_audit} />
                                    </Collapsible>
                                </StepBlock>
                            )}
                        </>
                    )}

                    {activeSection === 'sectors_roads' && (
                        <>
                            <StepBlock title="Key graphs" hint="Local services against the national reference" icon={BarChart3}>
                                <ChartGrid>
                                    <SectorScorecard results={results} />
                                    {bars('Indicator evidence')}
                                    {bars('Local value relative to its reference', WIDE)}
                                </ChartGrid>
                            </StepBlock>
                            {aiBlock('gaps')}
                            <StepBlock title="Infrastructure & livelihood" hint="Roads, connectivity and modeled agriculture" icon={Route}>
                                <ChartGrid>
                                    <RoadAccessibilityCard roadAccessibility={bundle?.sector_analysis?.road_accessibility} />
                                    <TelecomConnectivityCard telecom={bundle?.telecom_infrastructure} />
                                </ChartGrid>
                                {report.agricultureSummary && <AgricultureSummaryView data={report.agricultureSummary} />}
                            </StepBlock>
                            <StepBlock title="Detailed records" icon={Table2}>
                                <Collapsible
                                    title="All indicators"
                                    count={results.length}
                                    hint="Local value vs national reference, with sources"
                                    icon={Table2}
                                >
                                    <UnifiedIndicatorsTable results={results} />
                                </Collapsible>
                            </StepBlock>
                        </>
                    )}

                    {activeSection === 'gaps_barangays' && (
                        <>
                            <StepBlock title="Key graphs" hint="Which needs are largest, and which barangays to review first" icon={BarChart3}>
                                <ChartGrid>
                                    {bars('Largest comparison gaps')}
                                    {bars('Gaps by sector')}
                                    <GapSeverityMix report={report} className={WIDE} />
                                    {hasBarangayGraphs ? (
                                        <BarangayPriorityGraphs barangays={barangays} weights={bundle?.priority_scores?.active_weights} />
                                    ) : (
                                        bars('Highest barangay review priorities', WIDE)
                                    )}
                                </ChartGrid>
                            </StepBlock>
                            {aiBlock('gaps')}
                            {(bundle?.detected_gaps || barangays.length > 0) && (
                                <StepBlock title="Detailed records" icon={Table2}>
                                    {bundle?.detected_gaps && (
                                        <Collapsible
                                            title="Development gaps"
                                            count={bundle.detected_gaps.total_gaps_detected}
                                            hint="Each gap with its local value, reference, severity and evidence"
                                            icon={ListChecks}
                                        >
                                            <GapSectorTable detectedGaps={bundle.detected_gaps} cityName={location.name} />
                                        </Collapsible>
                                    )}
                                    {barangayAssessment && barangays.length > 0 && (
                                        <Collapsible
                                            title="All barangays"
                                            count={barangays.length}
                                            hint="Search, filter and sort every barangay by priority"
                                            icon={Table2}
                                        >
                                            <BarangayPrioritySection
                                                summary={barangayAssessment.summary}
                                                barangays={barangays}
                                                populationYear={populationYear}
                                            />
                                        </Collapsible>
                                    )}
                                </StepBlock>
                            )}
                        </>
                    )}

                    {activeSection === 'dost_catalog' && (
                        <>
                            <StepBlock title="Key graphs" hint="Past local projects and catalog status" icon={BarChart3}>
                                <ChartGrid>
                                    {bars('Returned local projects by agency')}
                                    {bars('Catalog status of returned entries')}
                                </ChartGrid>
                            </StepBlock>
                            <StepBlock title="Detailed records" icon={Table2}>
                                <section className="min-w-0 rounded-2xl border border-sky-100 bg-white p-4 sm:p-5 dark:border-slate-800 dark:bg-slate-950">
                                    <DostTechnologiesCatalogSection
                                        dostInterventions={report.dostInterventions ?? []}
                                        catalogTechnologies={reportCatalog(report)}
                                    />
                                </section>
                                <Collapsible
                                    title="Past DOST projects here"
                                    count={bundle?.local_dost_projects?.total_matched_projects ?? 0}
                                    hint="Local experience, not current offers"
                                    icon={History}
                                >
                                    <LocalDostProjectsSection cityName={location.name} data={bundle?.local_dost_projects} />
                                </Collapsible>
                            </StepBlock>
                        </>
                    )}

                    {activeSection === 'matched_interventions' && (
                        <>
                            <TargetAreasSection report={report} />
                            <StepBlock title="Key graphs" hint="Which technology answers which need, and how well" icon={BarChart3}>
                                <ChartGrid>
                                    {hasTechnologyGraphs ? <TechnologyFitGraphs report={report} /> : bars('Highest candidate relevance')}
                                    {bars('Needs with a catalog option')}
                                    {bars('Recorded local laboratory capabilities', WIDE)}
                                </ChartGrid>
                            </StepBlock>
                            {aiBlock('interventions')}
                            <StepBlock title="Detailed records" icon={Table2}>
                                <Collapsible
                                    title="Technology matches by need"
                                    count={counts.matched_interventions.value}
                                    hint="Every match with its relevance, evaluation, partners and requirements"
                                    icon={ListChecks}
                                >
                                    <InterventionSectorTable
                                        cityName={location.name}
                                        diagnostics={bundle?.detected_gaps?.sector_diagnostics}
                                        matchedInterventions={layerEResult?.matched_interventions ?? bundle?.matched_interventions}
                                        suitability={layerEResult?.suitability_assessment}
                                        feasibility={layerEResult?.feasibility_assessment}
                                        partners={layerEResult?.implementation_partners}
                                        requirements={layerEResult?.requirements_estimate}
                                    />
                                </Collapsible>
                                <Collapsible title="Laboratory support" hint="Accredited labs and referral options" icon={FlaskConical}>
                                    <LaboratorySupport report={report} />
                                </Collapsible>
                            </StepBlock>
                        </>
                    )}
                </div>

                <Collapsible title="Data sources & attribution" hint="Where each dataset comes from and how it is used" icon={Database}>
                    <AssessmentDataAttribution report={report} />
                </Collapsible>
            </div>
        </Layout>
    );
}
