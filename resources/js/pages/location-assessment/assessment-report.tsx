import AssessmentAiAnalysis, { useAssessmentAi } from '@/components/assessment-ai-analysis';
import AssessmentDataAttribution from '@/components/assessment-data-attribution';
import { AssessmentLayerExplanation, AssessmentLayerOverview } from '@/components/assessment-layer-overview';
import AssessmentPdfExport from '@/components/assessment-pdf-export';
import { GapSectorTable, InterventionSectorTable } from '@/components/assessment-sector-tables';
import { Button } from '@/components/ui/button';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { buildLayerOverviews, reportCatalog, type AssessmentLayer } from '@/lib/assessment-report';
import { AgricultureSummaryView } from '@/pages/admin/search-map/partials/province-agriculture-card';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useMemo, useState } from 'react';
import ActionPlanExport from './action-plan-export';
import AssessmentNavigation from './assessment-navigation';
import { DostTechnologiesCatalogSection, LocalDostProjectsSection } from './dost-catalog';
import LaboratorySupport from './laboratory-support';
import { LocationOverviewBar } from './location-overview';
import { BarangayPrioritySection } from './priority-areas';
import { DatasetAuditSection } from './profile-assets';
import { RoadAccessibilityCard, TelecomConnectivityCard, UnifiedIndicatorsTable } from './sector-needs';
import SourceBasisModal from './source-basis-modal';
import type { AssessmentPageProps } from './types';

export function LocationAssessmentReport(report: AssessmentPageProps) {
    const { location, focus, results, barangayAssessment, functionToolsBundle: bundle, layerEResult, populationYear = null } = report;
    const [activeSection, setActiveSection] = useState<AssessmentLayer>('matched_interventions');
    const aiEnabled = !!bundle && !!location.psg_code;
    const ai = useAssessmentAi(location.psg_code, focus, true, aiEnabled, report.assessmentParams);
    const overviews = useMemo(() => buildLayerOverviews(report), [report]);
    const Layout = report.assessmentLayout === 'admin' ? AppSidebarLayoutAdmin : AppSidebarLayout;
    const backParams = location.province_psg_code ? { province: location.province_psg_code, city: location.psg_code } : { city: location.psg_code };
    return (
        <Layout>
            <Head title={location.name + ' Assessment'} />
            <div className="location-assessment flex min-w-0 flex-1 flex-col gap-6 bg-blue-50/60 p-4 md:p-6 dark:bg-slate-950">
                <header className="space-y-2">
                    <Button variant="ghost" size="sm" asChild className="-ml-2 w-fit">
                        <Link href={report.mapReturnUrl ?? route('location.show', backParams)}>
                            <ArrowLeft className="size-4" />
                            Back to {report.mapReturnUrl ? 'Intelligence Map' : 'Service Area Map'}
                        </Link>
                    </Button>
                    <h1 className="text-2xl font-semibold text-[#087dcc] sm:text-3xl">{location.name} Assessment</h1>
                    <p className="text-muted-foreground text-sm">Find DOST technologies and laboratory support that fit local needs.</p>
                </header>
                <LocationOverviewBar location={location} populationYear={populationYear} />
                {aiEnabled && (
                    <AssessmentAiAnalysis state={ai.state} retry={ai.retry} mode={activeSection === 'gaps_barangays' ? 'gaps' : 'interventions'} />
                )}
                <AssessmentPdfExport report={report} overviews={overviews} analysis={ai.state.status === 'ready' ? ai.state.result : undefined} />
                <ActionPlanExport report={report} analysis={ai.state.status === 'ready' ? ai.state.result : undefined} />
                {report.populationReason && (
                    <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{report.populationReason}</p>
                )}
                {report.inventoryIntegrity?.has_conflicts && (
                    <div
                        role="alert"
                        className="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
                    >
                        <p>Some inventory locations conflict. Affected comparisons are withheld pending validation.</p>
                        <SourceBasisModal title="Inventory location conflicts">
                            <ul className="list-disc space-y-2 pl-5">
                                {report.inventoryIntegrity.inventories
                                    .filter((row) => row.has_conflicts)
                                    .map((row) => (
                                        <li key={row.dataset}>{row.reason}</li>
                                    ))}
                            </ul>
                        </SourceBasisModal>
                    </div>
                )}
                {report.analysisScopeNotice && <p className="text-muted-foreground text-xs">{report.analysisScopeNotice}</p>}
                <AssessmentNavigation active={activeSection} onChange={setActiveSection} />
                <AssessmentLayerOverview overview={overviews[activeSection]} />
                {activeSection === 'profile_assets' && bundle?.dataset_audit && (
                    <DatasetAuditSection cityName={location.name} audit={bundle.dataset_audit} />
                )}
                {activeSection === 'sectors_roads' && (
                    <section className="min-w-0 space-y-5" aria-label="Sector evidence">
                        <UnifiedIndicatorsTable results={results} />
                        <div className="grid min-w-0 gap-4 lg:grid-cols-2">
                            <RoadAccessibilityCard roadAccessibility={bundle?.sector_analysis?.road_accessibility} />
                            <TelecomConnectivityCard telecom={bundle?.telecom_infrastructure} />
                        </div>
                        {report.agricultureSummary && <AgricultureSummaryView data={report.agricultureSummary} />}
                    </section>
                )}
                {activeSection === 'gaps_barangays' && (
                    <div className="min-w-0 space-y-6">
                        {bundle?.detected_gaps && <GapSectorTable detectedGaps={bundle.detected_gaps} cityName={location.name} />}
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
                {activeSection === 'dost_catalog' && (
                    <div className="min-w-0 space-y-6">
                        <DostTechnologiesCatalogSection
                            dostInterventions={report.dostInterventions ?? []}
                            catalogTechnologies={reportCatalog(report)}
                        />
                        <LocalDostProjectsSection cityName={location.name} data={bundle?.local_dost_projects} />
                    </div>
                )}
                {activeSection === 'matched_interventions' && (
                    <div className="min-w-0 space-y-6">
                        <InterventionSectorTable
                            cityName={location.name}
                            diagnostics={bundle?.detected_gaps?.sector_diagnostics}
                            matchedInterventions={layerEResult?.matched_interventions ?? bundle?.matched_interventions}
                            suitability={layerEResult?.suitability_assessment}
                            feasibility={layerEResult?.feasibility_assessment}
                            partners={layerEResult?.implementation_partners}
                            requirements={layerEResult?.requirements_estimate}
                        />
                        <LaboratorySupport report={report} />
                    </div>
                )}
                <AssessmentLayerExplanation overview={overviews[activeSection]} />
                <AssessmentDataAttribution report={report} />
            </div>
        </Layout>
    );
}
