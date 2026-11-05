import type { AiResult } from '@/components/assessment-ai-analysis';
import { jsPDF } from 'jspdf';
import { autoTable } from 'jspdf-autotable';
import boldFontUrl from '../../fonts/noto-sans/NotoSans-Bold.ttf?url';
import regularFontUrl from '../../fonts/noto-sans/NotoSans-Regular.ttf?url';
import { assessmentDataSources } from './assessment-data-sources';
import { explainNumber } from './assessment-explanations';
import {
    assessmentLayers,
    buildLayerOverviews,
    reportCandidates,
    reportCatalog,
    reportGaps,
    reportLabel,
    reportLaboratoryOptions,
    reportList,
    reportNumber,
    reportRecord,
    reportTechnologyOptions,
    reportText,
    type AssessmentLayer,
    type AssessmentReportInput,
    type LayerOverview,
    type OverviewChart,
} from './assessment-report';

export interface PdfFonts {
    regular: string;
    bold: string;
}
// Keep mathematical meaning even when a PDF font/viewer lacks these less-common glyphs.
export const pdfText = (text: string) =>
    text.replaceAll('−', '-').replaceAll('≈', 'about').replaceAll('→', ' gives ').replaceAll('≥', 'at least ').replaceAll('≤', 'up to ');
let fontRequest: Promise<PdfFonts> | null = null;
export async function loadAssessmentPdfFonts(): Promise<PdfFonts> {
    if (fontRequest) return fontRequest;
    fontRequest = Promise.all(
        [regularFontUrl, boldFontUrl].map(async (url) => {
            const response = await fetch(url, { signal: AbortSignal.timeout(15000) });
            if (!response.ok) throw new Error('Report font unavailable');
            const bytes = new Uint8Array(await response.arrayBuffer());
            if (bytes[0] !== 0 || bytes[1] !== 1 || bytes[2] !== 0 || bytes[3] !== 0) throw new Error('Invalid report font');
            let binary = '';
            for (let index = 0; index < bytes.length; index += 16384) binary += String.fromCharCode(...bytes.subarray(index, index + 16384));
            return btoa(binary);
        }),
    )
        .then(([regular, bold]) => ({ regular, bold }))
        .catch((error) => {
            fontRequest = null;
            throw error;
        });
    return fontRequest;
}

export function assessmentPdfFilename(report: AssessmentReportInput, date = new Date()) {
    const locality =
        report.location.name
            .normalize('NFKD')
            .replace(/[^\p{L}\p{N}]+/gu, '-')
            .replace(/^-|-$/g, '')
            .slice(0, 80) || 'location';
    const day = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Singapore', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
    return `konektadost-assessment-${locality}-${day}.pdf`;
}

/** Vector charts and searchable Unicode text, independent of the visible tab or screen size. */
export function createAssessmentPdf(
    report: AssessmentReportInput,
    fonts: PdfFonts,
    overviews = buildLayerOverviews(report),
    analysis?: AiResult,
    date = new Date(),
) {
    const doc = new jsPDF({ unit: 'mm', format: 'a4', compress: true, putOnlyUsedFonts: true });
    doc.addFileToVFS('NotoSans-Regular.ttf', fonts.regular);
    doc.addFont('NotoSans-Regular.ttf', 'NotoSans', 'normal');
    doc.addFileToVFS('NotoSans-Bold.ttf', fonts.bold);
    doc.addFont('NotoSans-Bold.ttf', 'NotoSans', 'bold');
    doc.setFont('NotoSans', 'normal');
    doc.setProperties({
        title: `${report.location.name} — Location assessment`,
        subject: 'Computed evidence, visual summaries, sources and calculation methods',
        author: 'KonektaDOST',
        creator: 'KonektaDOST',
    });
    const margin = 16,
        width = 178,
        bottom = 277;
    let y = 18;
    let currentLayer = 'Location assessment';
    const newPage = () => {
        doc.addPage();
        y = 18;
    };
    const ensure = (height: number) => {
        if (y + height > bottom) newPage();
    };
    const paragraph = (text: string, size = 9, bold = false) => {
        doc.setFont('NotoSans', bold ? 'bold' : 'normal');
        doc.setFontSize(size);
        doc.setTextColor(51, 65, 85);
        const lines = doc.splitTextToSize(pdfText(text), width) as string[];
        for (const line of lines) {
            ensure(size * 0.48);
            doc.text(line, margin, y);
            y += size * 0.48;
        }
        y += 2;
    };
    const heading = (text: string, size = 13) => {
        ensure(16);
        paragraph(text, size, true);
    };
    const table = (head: string[], body: string[][]) => {
        if (!body.length) {
            paragraph('No returned records are available for this section. Missing output is not a measured zero.');
            return;
        }
        ensure(18);
        autoTable(doc, {
            startY: y,
            head: [head.map(pdfText)],
            body: body.map((row) => row.map(pdfText)),
            margin: { left: margin, right: margin, top: 18, bottom: 20 },
            styles: {
                font: 'NotoSans',
                fontSize: 7.8,
                cellPadding: 2,
                overflow: 'linebreak',
                valign: 'top',
                lineColor: [226, 232, 240],
                lineWidth: 0.1,
            },
            headStyles: { fillColor: [8, 125, 204], textColor: [255, 255, 255], fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [248, 250, 252] },
            rowPageBreak: 'avoid',
            showHead: 'everyPage',
            didDrawPage: () => {
                /* Footers are added after the final page count is known. */
            },
        });
        y = (doc as jsPDF & { lastAutoTable?: { finalY: number } }).lastAutoTable!.finalY + 7;
    };
    const chart = (item: OverviewChart) => {
        ensure(28);
        heading(item.title, 10);
        paragraph(item.description, 8);
        const maximum = item.maximum ?? Math.max(1, ...item.rows.map((row) => row.value ?? 0));
        if (!item.rows.length) paragraph('No recorded figures are available to chart.', 8);

        for (const row of item.rows) {
            const label = doc.splitTextToSize(pdfText(row.label), width - 35) as string[];
            const visible = label.slice(0, 2);
            if (label.length > 2) visible[1] += '…';
            const rowHeight = visible.length * 3.7 + 6;
            ensure(rowHeight);
            doc.setFont('NotoSans', 'normal');
            doc.setFontSize(8);
            doc.setTextColor(51, 65, 85);
            doc.text(visible, margin, y);
            doc.text(row.value === null ? 'Unavailable' : (row.formatted ?? explainNumber(row.value)), margin + width, y, { align: 'right' });
            y += visible.length * 3.7;
            doc.setFillColor(241, 245, 249);
            doc.rect(margin, y, width, 2.6, 'F');
            if (row.value !== null && row.value > 0) {
                doc.setFillColor(row.color ?? '#087dcc');
                doc.rect(margin, y, Math.min(1, row.value / maximum) * width, 2.6, 'F');
            }
            if (item.reference !== undefined) {
                doc.setDrawColor(51, 65, 85);
                doc.line(margin + (item.reference / maximum) * width, y - 0.4, margin + (item.reference / maximum) * width, y + 3);
            }
            y += 6;
        }
        y += 3;
    };
    const explainLayer = (overview: LayerOverview) => {
        heading('How did we arrive at these results?', 11);
        overview.steps.forEach((step, index) => paragraph(`${index + 1}. ${step}`));
        paragraph(`What this means: ${overview.interpretation}`, 9, true);
    };
    const bundle = reportRecord(report.functionToolsBundle);
    const stamp = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Singapore', dateStyle: 'medium', timeStyle: 'short' }).format(date);
    heading('KonektaDOST · Technology & laboratory fit', 18);
    heading(report.location.name, 15);
    paragraph(
        `${report.location.type ?? 'Selected location'} · ${report.location.province ?? 'Province unrecorded'} · PSGC ${report.location.psg_code ?? 'unavailable'}`,
    );
    paragraph(
        `Exported ${stamp} (UTC+08:00). Population collection year: ${report.populationYear ?? 'unavailable'}. Focus: ${report.focus.replaceAll('_', ' ')}.`,
    );
    paragraph(
        'Purpose: identify DOST technologies and laboratory support that fit local needs. All five layers and returned records are included; catalog searches may be limited.',
    );
    paragraph(
        'Confirm local demand, site readiness, staff, budget and availability with DOST. Missing data stays unknown; recorded zero does not prove absence.',
    );
    heading('Technology / program shortlist', 12);
    table(
        ['Option / agency', 'Need addressed', 'Relevance / recorded status'],
        reportTechnologyOptions(report)
            .slice(0, 3)
            .map((row) => [
                `${reportText(row.name)}\n${reportText(row.agency)}`,
                reportText(row.gap_name),
                `${explainNumber(reportNumber(row.relevance_score))}/100 · ${reportLabel(row.availability_status)}`,
            ]),
    );
    const laboratoryOptions = reportLaboratoryOptions(report);
    heading('Laboratory support', 11);
    paragraph(
        laboratoryOptions.conflict
            ? 'Validate conflicting laboratory locations before selecting a partner.'
            : laboratoryOptions.local.length
              ? `Recorded local partners: ${laboratoryOptions.local.map((row) => reportText(row.agency_name)).join('; ')}. Confirm required tests and availability.`
              : laboratoryOptions.nearby
                ? `Nearest recorded referral option: ${reportText(laboratoryOptions.nearby.agency_name)} (${explainNumber(reportNumber(laboratoryOptions.nearby.distance_km))} km straight-line). Confirm required tests and access.`
                : 'No verified laboratory partner in the returned evidence. Confirm testing demand and nearby services with DOST.',
    );
    paragraph('A new laboratory needs verified testing demand, equipment, staff and budget.', 8);
    if (report.analysisScopeNotice) paragraph(report.analysisScopeNotice);
    const conflicts = reportList(reportRecord(report.inventoryIntegrity).inventories).filter((row) => row.has_conflicts === true);
    if (conflicts.length) {
        heading('Inventory location conflicts', 11);
        conflicts.forEach((row) => paragraph(reportText(row.reason)));
    }

    for (const key of assessmentLayers) {
        const overview = overviews[key];
        newPage();
        currentLayer = overview.title;
        heading(overview.title, 15);
        paragraph(overview.summary);
        table(
            ['Overview measure', 'Value', 'Meaning'],
            overview.metrics.map((row) => [row.label, row.value, row.note]),
        );
        overview.charts.forEach(chart);
        ensure(45);
        heading('Assessment results', 12);
        if (key === 'profile_assets') {
            table(
                ['Selected-area fact', 'Recorded value'],
                [
                    ['Population', explainNumber(report.location.population)],
                    ['Land area (km²)', explainNumber(report.location.area_km2)],
                    ['Population year', String(report.populationYear ?? 'Unavailable')],
                    ['Geographic level', report.location.type ?? 'Unrecorded'],
                ],
            );
            const statistics = reportRecord(report.location.population_statistics);
            table(
                ['Population statistic', 'Value', 'Basis'],
                [
                    [
                        'Baseline population',
                        explainNumber(reportNumber(statistics.population_baseline)),
                        `Baseline year ${statistics.baseline_year ?? 'unavailable'}`,
                    ],
                    ['Population change', explainNumber(reportNumber(statistics.population_change)), 'Current population minus baseline population'],
                    ['Population growth (%)', explainNumber(reportNumber(statistics.growth_rate_pct)), '(Current − baseline) ÷ baseline × 100'],
                    [
                        'Annual growth (%)',
                        explainNumber(reportNumber(statistics.annual_growth_rate_pct)),
                        '((Current ÷ baseline)^(1 ÷ year interval) − 1) × 100',
                    ],
                ],
            );
            const datasets = Object.entries(reportRecord(reportRecord(bundle.dataset_audit).datasets));
            table(
                ['Dataset', 'Status / records', 'Source year & basis'],
                datasets.map(([name, value]) => {
                    const row = reportRecord(value);
                    return [
                        reportText(row.label, name.replaceAll('_', ' ')),
                        `${reportLabel(row.status)} · ${explainNumber(reportNumber(row.local_records))} local record(s)`,
                        `${row.reference_year ?? 'Year unrecorded'}. ${reportText(row.notes)}`,
                    ];
                }),
            );
        } else if (key === 'sectors_roads') {
            table(
                ['Indicator / unit', 'Local / reference', 'Gap / confidence', 'Source'],
                report.results.map((row) => [
                    `${row.label}\n${row.unit}`,
                    `${explainNumber(row.value)} / ${explainNumber(row.benchmark)}`,
                    `${row.gap === null ? 'Unavailable' : `${explainNumber(row.gap)}%`}\n${reportLabel(row.confidence)} · ${reportLabel(row.status)}`,
                    row.source,
                ]),
            );
            const sectors = reportRecord(bundle.sector_analysis);
            table(
                ['Sector', 'Evidence status'],
                Object.entries(sectors).map(([name, value]) => [name.replaceAll('_', ' '), reportLabel(reportRecord(value).status)]),
            );
            const labs = reportList(report.localLaboratories);
            if (labs.length)
                table(
                    ['Laboratory', 'Returned services', 'Services / methods'],
                    labs.map((row) => [
                        reportText(row.agency_name),
                        explainNumber(reportNumber(row.total_services)),
                        reportList(row.services)
                            .map((service) => `${reportText(service.test_name)}: ${reportText(service.method, 'Method unrecorded')}`)
                            .join('\n') || 'Service details unavailable',
                    ]),
                );
            if (report.agricultureSummary) {
                heading('Agriculture planning context', 10);
                paragraph('Agriculture financial and production values are modeled estimates rather than observed yields or verified income.');
            }
        } else if (key === 'gaps_barangays') {
            table(
                ['Detected gap', 'Measured / reference', 'Gap & severity', 'Source'],
                reportGaps(report).map((row) => [
                    `${reportText(row.label)}\n${reportText(row.unit)}`,
                    `${explainNumber(reportNumber(row.measured_value))} / ${explainNumber(reportNumber(row.benchmark_value))}`,
                    `${explainNumber(reportNumber(row.percentage_gap))}%\n${explainNumber(reportNumber(row.severity_score))}/100 · ${reportLabel(row.severity_level)}\n${reportLabel(row.confidence)}`,
                    reportText(row.source),
                ]),
            );
            table(
                ['Sector', 'Gap evidence / limitations'],
                reportList(reportRecord(bundle.detected_gaps).sector_diagnostics).map((row) => [reportLabel(row.sector), reportText(row.message)]),
            );
            const barangays = report.barangayAssessment?.barangays ?? [];
            if (barangays.length) {
                heading('Returned barangay priorities', 11);
                table(
                    ['Barangay', 'Population / score', 'How this result was derived'],
                    barangays.map((row) => {
                        const components = reportRecord(reportRecord(reportRecord(row).score_components).normalized_components);
                        const pointText = Object.entries(components)
                            .map(([name, value]) => `${name.replaceAll('_', ' ')} ${explainNumber(reportNumber(value))}`)
                            .join(' + ');
                        return [
                            row.name,
                            `${explainNumber(row.population)} residents\n${explainNumber(row.priority_score)}/100`,
                            `${row.priority_reason}\n${row.priority_score == null ? 'Population/score unavailable; no ranking inferred.' : pointText ? `Composite adds: ${pointText}; rounded to one decimal.` : 'See the active-weight methodology below.'}`,
                        ];
                    }),
                );
            }
        } else if (key === 'dost_catalog') {
            paragraph(
                `Catalog search: ${reportNumber(reportRecord(bundle.dost_technologies).returned_count) ?? reportCatalog(report).length} record(s) returned; ${explainNumber(reportNumber(reportRecord(bundle.dost_technologies).total_matched))} total search matches. These are reference records, not verified local installations.`,
            );
            table(
                ['Local project / agency', 'Location / status', 'Source & basis'],
                reportList(reportRecord(bundle.local_dost_projects).projects).map((row) => [
                    `${reportText(row.project_title)}\n${reportText(row.agency)}`,
                    `${reportText(row.location)}\n${reportLabel(row.normalized_status ?? row.raw_status)}`,
                    `dost_projects:${row.id}. Historical budget: ${explainNumber(reportNumber(row.budget_php))} PHP.`,
                ]),
            );
            table(
                ['Catalog entry / agency', 'Recorded sector / status', 'Source & basis'],
                reportCatalog(report).map((row) => [
                    `${reportText(row.name)}\n${reportText(row.agency)}`,
                    `${reportText(row.sector)}\n${reportLabel(row.status)}`,
                    `${reportText(row.source_catalog, 'DOST reference catalog')}:${row.code ?? row.id ?? 'ID unrecorded'}. ${reportText(row.mechanism ?? row.description, '')}`,
                ]),
            );
        } else {
            table(
                ['Gap / candidate', 'Relevance / status', 'Matching basis'],
                reportCandidates(report).map((row) => [
                    `${reportText(row.gap_name)}\n${reportText(row.name)}\n${row.source_catalog}:${row.intervention_id}`,
                    `${explainNumber(reportNumber(row.relevance_score))}/100\n${reportLabel(row.availability_status)}`,
                    Array.isArray(row.matching_criteria)
                        ? row.matching_criteria
                              .filter((item) => typeof item === 'string')
                              .slice(0, 2)
                              .join('; ')
                        : 'Basis unavailable',
                ]),
            );
            const layerE = reportRecord(report.layerEResult);
            const suitability = reportRecord(layerE.suitability_assessment),
                feasibility = reportRecord(layerE.feasibility_assessment);
            if (Object.keys(suitability).length || Object.keys(feasibility).length)
                table(
                    ['Evaluation / candidate', 'Result', 'Calculation / conditions'],
                    [
                        [
                            'Suitability · ' + reportText(reportRecord(suitability.intervention).name),
                            `${explainNumber(reportNumber(suitability.suitability_score))}/100 · ${reportLabel(suitability.suitability_level)}`,
                            `Need × 0.30 + reach × 0.20 + resources × 0.15 + infrastructure × 0.20 + institutions × 0.15, rounded. Rule-based ratings may include defaults and need local validation.\n${summarizeValues(suitability.dimension_scores)}`,
                        ],
                        [
                            'Feasibility · ' + reportText(reportRecord(feasibility.intervention).name),
                            reportLabel(feasibility.feasibility_status),
                            `Missing baseline → insufficient information; blockers → not yet feasible; conditions → conditionally feasible; otherwise feasible.\n${reportText(feasibility.reason ?? feasibility.feasibility_summary, '')}\n${summarizeValues(feasibility.conditional_requirements)}`,
                        ],
                    ],
                );
            const partners = reportList(reportRecord(layerE.implementation_partners).partners);
            if (partners.length)
                table(
                    ['Potential partner', 'Suggested role / confirmation needed'],
                    partners.map((row) => [reportText(row.partner_name), `${reportText(row.suggested_role)}\n${reportText(row.jurisdiction, '')}`]),
                );
            const requirements = reportRecord(reportRecord(layerE.requirements_estimate).requirements);
            if (Object.keys(requirements).length) {
                heading('Proposed resource requirements', 11);
                table(
                    ['Requirement', 'Planning basis'],
                    Object.entries(requirements).map(([name, value]) => [name.replaceAll('_', ' '), summarizeValues(value)]),
                );
            }
            if (!Object.keys(layerE).length)
                paragraph('Detailed implementation evaluation is unavailable for this scope. A catalog match alone does not establish feasibility.');
        }
        explainLayer(overview);
    }
    newPage();
    currentLayer = 'AI interpretation & report notes';
    heading(currentLayer, 15);
    if (analysis) {
        paragraph(`Completed AI interpretation, generated ${analysis.generated_at}. This interpretation does not alter the computed data.`);
        paragraph(analysis.analysis.summary);
        table(
            ['Sector', 'Gap explanation', 'Intervention interpretation', 'Next action'],
            analysis.analysis.sectors.map((row) => [reportLabel(row.sector), row.gap_explanation, row.intervention_analysis, row.next_action]),
        );
    } else
        paragraph(
            'No completed AI interpretation was available when this report was exported. All available deterministic layers are included above; the export does not start or wait for a new AI request.',
        );
    paragraph(
        'Evidence limits: national references are dynamically computed descriptions of the recorded tables. Imported inventories may be incomplete. Historical projects are leads, modeled agriculture remains provisional, and implementation suggestions require LGU/DOST validation.',
    );
    newPage();
    currentLayer = 'Data sources & attribution';
    heading(currentLayer, 15);
    paragraph(
        'Sources recorded by the system. Unrecorded dates/providers and incomplete inventories remain evidence limitations. AI interprets these datasets; it is not an independent data source.',
    );
    table(
        ['Data used', 'Source / provider', 'Year / reference', 'Used for'],
        assessmentDataSources(report).map((row) => [row.data, row.source, row.reference, row.use]),
    );
    const pages = doc.getNumberOfPages();
    for (let page = 1; page <= pages; page++) {
        doc.setPage(page);
        doc.setFont('NotoSans', 'normal');
        doc.setFontSize(7);
        doc.setTextColor(100, 116, 139);
        doc.text('KonektaDOST · ' + report.location.name, margin, 287);
        doc.text(`Page ${page} of ${pages}`, 194, 287, { align: 'right' });
    }
    return doc;
}

function summarizeValues(value: unknown, depth = 0): string {
    if (value === null || value === undefined) return 'Unavailable';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value !== 'object') return String(value);
    if (depth > 3) return 'Detailed records are available in the source assessment.';
    return (
        Object.entries(value)
            .filter(([key]) => !['geometry', 'records', 'sites', 'contact_information', 'coordinates'].includes(key))
            .map(([key, item]) => `${Array.isArray(value) ? '' : `${key.replaceAll('_', ' ')}: `}${summarizeValues(item, depth + 1)}`)
            .join('\n') || 'No returned values'
    );
}

export async function downloadAssessmentPdf(report: AssessmentReportInput, overviews: Record<AssessmentLayer, LayerOverview>, analysis?: AiResult) {
    const fonts = await loadAssessmentPdfFonts();
    const doc = createAssessmentPdf(report, fonts, overviews, analysis);
    await doc.save(assessmentPdfFilename(report), { returnPromise: true });
}
