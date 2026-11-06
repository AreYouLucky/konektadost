import type { AiResult } from '@/components/assessment-ai-analysis';
import { jsPDF } from 'jspdf';
import { autoTable, type RowInput } from 'jspdf-autotable';
import {
    actionPlanColumns,
    actionPlanFilename,
    buildActionPlanSnapshot,
    type ActionPlanActivity,
    type ActionPlanSnapshot,
} from './assessment-action-plan';
import { explainNumber } from './assessment-explanations';
import { loadAssessmentPdfFonts, pdfText, type PdfFonts } from './assessment-pdf';
import type { AssessmentReportInput, OverviewChart } from './assessment-report';
import type { TargetAreaSet } from './assessment-targets';
import { drawPdfLogo, loadDostLogoDataUrl } from './dost-logo';

/** Light-mode `.viz-palette` series, in the same fixed order the on-screen charts use. */
const SERIES_HEX = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6'];
const PHASES: { phase: ActionPlanActivity['phase']; title: string; weeks: string; range: [number, number]; caption: string; color: string }[] = [
    { phase: 'Validation', title: 'Validate', weeks: 'Weeks 1-2', range: [1, 2], caption: 'needs & sites', color: SERIES_HEX[0] },
    { phase: 'Preparation', title: 'Prepare', weeks: 'Weeks 3-4', range: [3, 4], caption: 'fit, cost, partners', color: SERIES_HEX[1] },
    { phase: 'Pilot', title: 'Pilot', weeks: 'Weeks 5-8', range: [5, 8], caption: 'if validated', color: SERIES_HEX[2] },
    { phase: 'Review', title: 'Review', weeks: 'Weeks 9-12', range: [9, 12], caption: 'results & next steps', color: SERIES_HEX[3] },
];

export function createActionPlanPdf(
    report: AssessmentReportInput,
    fonts: PdfFonts,
    analysis?: AiResult,
    date = new Date(),
    logo: string | null = null,
) {
    return createActionPlanPdfFromSnapshot(buildActionPlanSnapshot(report, analysis, date), fonts, logo);
}

/** The PDF from a plan snapshot, so a saved plan exports exactly as it was generated. */
export function createActionPlanPdfFromSnapshot(snapshot: ActionPlanSnapshot, fonts: PdfFonts, logo: string | null = null) {
    const { plan } = snapshot;
    const targetSet: TargetAreaSet | null = snapshot.targets;
    const date = new Date(plan.generatedAt);
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4', compress: true, putOnlyUsedFonts: true });
    doc.addFileToVFS('NotoSans-Regular.ttf', fonts.regular);
    doc.addFont('NotoSans-Regular.ttf', 'NotoSans', 'normal');
    doc.addFileToVFS('NotoSans-Bold.ttf', fonts.bold);
    doc.addFont('NotoSans-Bold.ttf', 'NotoSans', 'bold');
    doc.setFont('NotoSans', 'normal');
    doc.setProperties({
        title: `${plan.location} - DOST Action Plan`,
        author: 'KonektaDOST',
        subject: 'Evidence-based draft activities, objectives, results, timeline, resources and responsibilities',
    });
    const margin = 14,
        width = 269,
        bottom = 192;
    let y = 17;
    const page = (title: string) => {
        doc.addPage();
        y = 17;
        heading(title, 15);
    };
    const ensure = (height: number) => {
        if (y + height > bottom) {
            doc.addPage();
            y = 17;
        }
    };
    function paragraph(text: string, size = 9, bold = false) {
        doc.setFont('NotoSans', bold ? 'bold' : 'normal');
        doc.setFontSize(size);
        const lines = doc.splitTextToSize(pdfText(text), width) as string[];
        ensure(lines.length * size * 0.43 + 3);
        doc.setTextColor(51, 65, 85);
        doc.text(lines, margin, y);
        y += lines.length * size * 0.43 + 3;
    }
    function heading(text: string, size = 11) {
        ensure(15);
        paragraph(text, size, true);
    }
    function table(headers: readonly string[], rows: RowInput[], widths?: number[]) {
        ensure(25);
        if (!rows.length) {
            paragraph('No supported records returned. Local validation is required.');
            return;
        }
        autoTable(doc, {
            startY: y,
            margin: { left: margin, right: margin, top: 17, bottom: 18 },
            head: [headers.map(pdfText)],
            body: rows,
            theme: 'grid',
            rowPageBreak: 'avoid',
            styles: {
                font: 'NotoSans',
                fontSize: 8,
                cellPadding: 2.2,
                lineColor: [226, 232, 240],
                textColor: [51, 65, 85],
                overflow: 'linebreak',
                valign: 'top',
            },
            headStyles: { fillColor: [8, 125, 204], textColor: 255, fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [248, 250, 252] },
            columnStyles: widths ? Object.fromEntries(widths.map((cellWidth, index) => [index, { cellWidth }])) : {},
            didParseCell: (data) => {
                if (typeof data.cell.text !== 'undefined') data.cell.text = data.cell.text.map(pdfText);
            },
        });
        y = (doc as jsPDF & { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 6;
    }
    function chart(item: OverviewChart, x: number, top: number, chartWidth: number) {
        let rowY = top;
        doc.setFont('NotoSans', 'bold');
        doc.setFontSize(10);
        doc.setTextColor(8, 125, 204);
        doc.text(item.title, x, rowY);
        rowY += 6;
        doc.setFont('NotoSans', 'normal');
        doc.setFontSize(7.5);
        doc.setTextColor(71, 85, 105);
        const description = doc.splitTextToSize(pdfText(item.description), chartWidth) as string[];
        doc.text(description, x, rowY);
        rowY += description.length * 3.1 + 3;
        if (!item.rows.length) {
            doc.text('No supported comparison available.', x, rowY);
            return rowY + 8;
        }
        for (const row of item.rows) {
            const label = (doc.splitTextToSize(pdfText(row.label), chartWidth - 22) as string[]).slice(0, 2);
            doc.text(label, x, rowY);
            doc.text(row.value === null ? 'Unavailable' : `${explainNumber(row.value)}/100`, x + chartWidth, rowY, { align: 'right' });
            rowY += label.length * 3.1 + 1.5;
            doc.setFillColor(241, 245, 249);
            doc.rect(x, rowY, chartWidth, 2.2, 'F');
            if (row.value !== null && row.value > 0) {
                doc.setFillColor(row.color ?? '#087dcc');
                doc.rect(x, rowY, Math.min(1, row.value / 100) * chartWidth, 2.2, 'F');
            }
            rowY += 6;
        }
        return rowY;
    }
    function chartTitle(title: string, hint: string, x: number, top: number, chartWidth: number) {
        doc.setFont('NotoSans', 'bold');
        doc.setFontSize(10);
        doc.setTextColor(8, 125, 204);
        doc.text(pdfText(title), x, top);
        doc.setFont('NotoSans', 'normal');
        doc.setFontSize(7.5);
        doc.setTextColor(71, 85, 105);
        const lines = doc.splitTextToSize(pdfText(hint), chartWidth) as string[];
        doc.text(lines, x, top + 5);
        return top + 5 + lines.length * 3.1 + 2;
    }
    /** Colour swatch + label pairs that wrap onto new lines; identity is never colour alone. */
    function legend(items: { label: string; color: string }[], x: number, top: number, maxWidth: number) {
        doc.setFont('NotoSans', 'normal');
        doc.setFontSize(7.5);
        doc.setTextColor(51, 65, 85);
        let cursor = x,
            line = top;
        for (const item of items) {
            const label = pdfText(item.label);
            const itemWidth = 5 + doc.getTextWidth(label) + 6;
            if (cursor + itemWidth > x + maxWidth && cursor > x) {
                cursor = x;
                line += 5;
            }
            doc.setFillColor(item.color);
            doc.roundedRect(cursor, line - 2.4, 3, 3, 0.6, 0.6, 'F');
            doc.text(label, cursor + 4.5, line);
            cursor += itemWidth;
        }
        return line + 5;
    }
    /** Same four phases as the on-screen strip, each with its colour, weeks and purpose. */
    function phaseStrip(top: number) {
        const gap = 4,
            boxWidth = (width - gap * 3) / 4;
        PHASES.forEach((item, index) => {
            const x = margin + index * (boxWidth + gap);
            doc.setFillColor(248, 250, 252);
            doc.setDrawColor(226, 232, 240);
            doc.setLineWidth(0.25);
            doc.roundedRect(x, top, boxWidth, 15, 2, 2, 'FD');
            doc.setFillColor(item.color);
            doc.rect(x, top + 2, 1.4, 11, 'F');
            doc.setFont('NotoSans', 'bold');
            doc.setFontSize(9);
            doc.setTextColor(15, 23, 42);
            doc.text(`${index + 1}. ${item.title}`, x + 5, top + 5.6);
            doc.setFont('NotoSans', 'normal');
            doc.setFontSize(7.5);
            doc.setTextColor(71, 85, 105);
            doc.text(pdfText(`${item.weeks} · ${item.caption}`), x + 5, top + 10.8);
            if (index < PHASES.length - 1) {
                doc.setDrawColor(148, 163, 184);
                doc.line(x + boxWidth + 0.8, top + 7.5, x + boxWidth + gap - 0.8, top + 7.5);
            }
        });
        return top + 15 + 6;
    }
    /** Points per factor for each target area, stacked to the priority score (0-100). */
    function stackedTargets(set: TargetAreaSet, x: number, top: number, chartWidth: number) {
        const level = set.level === 'municipality' ? 'cities / municipalities' : 'barangays';
        let rowY = chartTitle(
            `Top ${set.areas.length} target ${level} · score breakdown`,
            'Each bar is the priority score (0-100); each colour is the points one factor adds. Longer bars = review first.',
            x,
            top,
            chartWidth,
        );
        const colors = set.factors.map((_, index) => SERIES_HEX[index % SERIES_HEX.length]);
        rowY = legend(
            set.factors.map((factor, index) => ({ label: factor.label, color: colors[index] })),
            x,
            rowY + 1,
            chartWidth,
        );
        const labelWidth = 58,
            valueWidth = 14,
            barWidth = chartWidth - labelWidth - valueWidth;
        doc.setDrawColor(226, 232, 240);
        doc.setLineWidth(0.2);
        for (const tick of [0, 25, 50, 75, 100]) {
            const tickX = x + labelWidth + (tick / 100) * barWidth;
            doc.line(tickX, rowY - 1, tickX, rowY + set.areas.length * 7 - 2);
            doc.setFontSize(6.5);
            doc.setTextColor(148, 163, 184);
            doc.text(String(tick), tickX, rowY + set.areas.length * 7 + 1.2, { align: 'center' });
        }
        rowY += 2;
        for (const area of set.areas) {
            doc.setFont('NotoSans', 'normal');
            doc.setFontSize(7.5);
            doc.setTextColor(51, 65, 85);
            const label = (doc.splitTextToSize(pdfText(`${area.rank}. ${area.name}`), labelWidth - 3) as string[])[0];
            doc.text(label, x, rowY + 1.6);
            let cursor = x + labelWidth;
            set.factors.forEach((factor, index) => {
                const value = area.components[factor.key] ?? 0;
                if (value <= 0) return;
                const segment = (Math.min(100, value) / 100) * barWidth;
                doc.setFillColor(colors[index]);
                doc.rect(cursor, rowY - 1.4, Math.max(0.4, segment - 0.4), 3.6, 'F');
                cursor += segment;
            });
            doc.setFont('NotoSans', 'bold');
            doc.setTextColor(15, 23, 42);
            doc.text(explainNumber(area.score), x + chartWidth, rowY + 1.6, { align: 'right' });
            rowY += 7;
        }
        return rowY + 10;
    }
    /** 12-week Gantt of the proposed activities, coloured by phase. */
    function timeline(activities: ActionPlanActivity[], x: number, top: number, chartWidth: number) {
        let rowY = chartTitle('12-week timeline', 'Proposed schedule; pilot depends on validation.', x, top, chartWidth);
        rowY = legend(
            PHASES.map((item) => ({ label: item.phase, color: item.color })),
            x,
            rowY + 1,
            chartWidth,
        );
        const labelWidth = 92,
            gridWidth = chartWidth - labelWidth,
            column = gridWidth / 12,
            rowHeight = 8,
            gridTop = rowY + 1,
            gridBottom = gridTop + 6 + activities.length * rowHeight;
        PHASES.forEach((item, index) => {
            if (index % 2) return;
            const [start, end] = item.range;
            doc.setFillColor(241, 245, 249);
            doc.rect(x + labelWidth + (start - 1) * column, gridTop, (end - start + 1) * column, gridBottom - gridTop, 'F');
        });
        doc.setFont('NotoSans', 'bold');
        doc.setFontSize(7);
        doc.setTextColor(100, 116, 139);
        for (let week = 1; week <= 12; week++) {
            const columnX = x + labelWidth + (week - 1) * column;
            doc.text(`W${week}`, columnX + column / 2, gridTop + 4, { align: 'center' });
            doc.setDrawColor(226, 232, 240);
            doc.setLineWidth(0.2);
            doc.line(columnX, gridTop, columnX, gridBottom);
        }
        doc.line(x + labelWidth + gridWidth, gridTop, x + labelWidth + gridWidth, gridBottom);
        doc.line(x, gridTop + 6, x + chartWidth, gridTop + 6);
        rowY = gridTop + 6;
        for (const row of activities) {
            const color = PHASES.find((item) => item.phase === row.phase)?.color ?? SERIES_HEX[0];
            doc.setFont('NotoSans', 'normal');
            doc.setFontSize(7.5);
            doc.setTextColor(51, 65, 85);
            const label = (doc.splitTextToSize(pdfText(row.activity), labelWidth - 4) as string[]).slice(0, 2);
            doc.text(label, x, rowY + (label.length > 1 ? 3 : 4.9));
            const barX = x + labelWidth + (row.startWeek - 1) * column + 0.6;
            const barWidth = (row.endWeek - row.startWeek + 1) * column - 1.2;
            doc.setFillColor(color);
            doc.roundedRect(barX, rowY + 2, barWidth, 4, 1, 1, 'F');
            doc.setFont('NotoSans', 'bold');
            doc.setFontSize(6.5);
            doc.setTextColor(255, 255, 255);
            if (barWidth > 14) doc.text(`W${row.startWeek}-${row.endWeek}`, barX + barWidth / 2, rowY + 4.9, { align: 'center' });
            doc.setDrawColor(241, 245, 249);
            doc.line(x, rowY + rowHeight, x + chartWidth, rowY + rowHeight);
            rowY += rowHeight;
        }
        return rowY + 5;
    }
    const stamp = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Singapore', dateStyle: 'medium', timeStyle: 'short' }).format(date);
    const logoHeight = drawPdfLogo(doc, logo, margin, 10, 62);
    if (logoHeight) y = 10 + logoHeight + 9;
    heading(`KonektaDOST · ${plan.location} Action Plan`, 18);
    paragraph(
        `${plan.level} · ${snapshot.province ?? 'Province not recorded'} · Generated ${stamp} (UTC+08:00) · Population year ${plan.populationYear ?? 'unavailable'}`,
        8,
    );
    paragraph(
        'Purpose: determine appropriate DOST technology/program adoption and laboratory support, then plan local validation, resources and delivery.',
        10,
        true,
    );
    paragraph(plan.limitations[0], 8);
    if (plan.scopeNote) paragraph(plan.scopeNote, 8);
    paragraph(
        plan.aiGeneratedAt
            ? `AI-assisted rationale from completed assessment analysis (${plan.aiGeneratedAt}); all source values, selections and scores remain computed.`
            : 'Computed draft. No completed, matching AI interpretation was available; the action plan remains based on the recorded assessment.',
        8,
    );
    ensure(24);
    y = phaseStrip(y + 1);
    heading('1 · Priority areas');
    if (plan.targets.length) {
        paragraph(
            `Top ${plan.targets.length} target ${plan.targetLevel === 'municipality' ? 'cities/municipalities' : 'barangays'}, ranked by priority score (population need plus recorded service gaps). Each row lists why it ranks high and the DOST option that fits its biggest gap.`,
            8,
        );
        table(
            ['#', 'Target area', 'Score /100', 'Why it ranks high', 'Suggested DOST technology / program'],
            plan.targets.map((row) => [String(row.rank), row.name, explainNumber(row.score), row.why, row.technologies]),
            [10, 52, 20, 97, 90],
        );
        if (targetSet && targetSet.areas.length && targetSet.factors.length) {
            ensure(40 + targetSet.areas.length * 7);
            y = stackedTargets(targetSet, margin, y + 4, 200);
        } else {
            ensure(80);
            y = chart(plan.charts[2], margin, y + 4, 180) + 2;
        }
    } else
        table(
            ['Area for review', 'Basis'],
            plan.priorities.map((row) => [row.area, row.basis]),
        );
    heading('2 · Critical needs and development gaps');
    table(
        ['Need', 'Recorded local baseline / national reference', 'Severity / confidence'],
        plan.needs.map((row) => [
            row.name,
            row.baseline,
            `${explainNumber(row.severity)}/100 · ${row.confidence === 'high' ? 'High confidence' : 'Provisional / confidence unconfirmed'}`,
        ]),
    );
    heading('3 · Recommended STI options for validation');
    table(
        ['Technology / program', 'Need addressed', 'Relevance / recorded status', 'Why it is on the shortlist'],
        plan.options.map((row) => [
            `${row.name}\n${row.agency}\n${row.ref}`,
            row.need,
            `${explainNumber(row.relevance)}/100\n${row.status}`,
            row.why + (row.aiNote ? `\nAI interpretation: ${row.aiNote}` : ''),
        ]),
        [62, 55, 40, 112],
    );
    paragraph(`Laboratory pathway: ${plan.laboratory}`, 8);

    page('Why these actions · Evidence and decision path');
    paragraph(
        'Proposed flow: use the measured need, confirm practical fit and resources, then decide whether to proceed. A new laboratory needs a separate demand and feasibility assessment.',
        8,
    );
    function box(x: number, top: number, w: number, text: string) {
        doc.setDrawColor(8, 125, 204);
        doc.setFillColor(239, 246, 255);
        doc.roundedRect(x, top, w, 17, 2, 2, 'FD');
        doc.setFont('NotoSans', 'normal');
        doc.setFontSize(8);
        doc.setTextColor(30, 64, 95);
        const lines = doc.splitTextToSize(text, w - 6) as string[];
        doc.text(lines, x + w / 2, top + 7 - (lines.length - 1) * 1.6, { align: 'center' });
    }
    function arrow(x1: number, y1: number, x2: number, y2: number) {
        doc.setDrawColor(8, 125, 204);
        doc.setLineWidth(0.35);
        doc.line(x1, y1, x2, y2);
        const angle = Math.atan2(y2 - y1, x2 - x1),
            length = 2;
        doc.line(x2, y2, x2 - length * Math.cos(angle - 0.5), y2 - length * Math.sin(angle - 0.5));
        doc.line(x2, y2, x2 - length * Math.cos(angle + 0.5), y2 - length * Math.sin(angle + 0.5));
    }
    const flowTop = y + 2;
    box(14, flowTop, 58, 'Validate demand, baseline and site\nWeeks 1-2');
    box(85, flowTop, 58, 'Confirm DOST option and service requirements\nWeeks 3-4');
    doc.setFillColor(224, 242, 254);
    doc.setDrawColor(8, 125, 204);
    doc.lines(
        [
            [25, 12],
            [-25, 12],
            [-25, -12],
            [25, -12],
        ],
        185,
        flowTop - 3,
        [1, 1],
        'FD',
        true,
    );
    doc.setFontSize(7.3);
    doc.setTextColor(30, 64, 95);
    doc.text(['Need, fit and', 'resources confirmed?'], 185, flowTop + 7, { align: 'center' });
    box(225, flowTop, 58, 'Pilot agreed option / referral\nWeeks 5-8');
    arrow(72, flowTop + 8, 85, flowTop + 8);
    arrow(143, flowTop + 8, 160, flowTop + 8);
    arrow(210, flowTop + 8, 225, flowTop + 8);
    doc.setFontSize(7);
    doc.text('Yes', 216, flowTop + 5);
    box(115, flowTop + 32, 90, 'Fill evidence gaps / revise proposal');
    arrow(185, flowTop + 21, 185, flowTop + 32);
    doc.text('No', 188, flowTop + 28);
    doc.line(115, flowTop + 40, 108, flowTop + 40);
    arrow(108, flowTop + 40, 108, flowTop + 17);
    box(225, flowTop + 32, 58, 'Monitor and review\nWeeks 9-12');
    arrow(254, flowTop + 17, 254, flowTop + 32);
    const chartTop = flowTop + 64;
    y = Math.max(chart(plan.charts[0], margin, chartTop, 128), chart(plan.charts[1], 155, chartTop, 128)) + 3;
    paragraph(
        'Gap severity shows comparison size and confidence; relevance shows catalog fit. Neither graph proves unmet demand, deployment readiness or guaranteed impact.',
        8,
    );

    page('Timeline · 12-week proposed schedule');
    y = timeline(plan.activities, margin, y + 2, width);
    paragraph(
        'Weeks run from the agreed plan start. Validation comes first; preparation and pilot activities proceed only if need, fit and resources are confirmed. Review closes the cycle.',
        8,
    );

    page('Action plan matrix · Activities and responsibilities');
    paragraph(
        'Format follows the supplied workbook. Weeks run from the agreed plan start; pilot activities depend on validation, current availability, site readiness and resourcing. Roles and results below are proposed.',
        8,
    );
    const body: RowInput[] = [];
    for (const phase of ['Validation', 'Preparation', 'Pilot', 'Review'] as const) {
        const rows = plan.activities.filter((row) => row.phase === phase);
        if (!rows.length) continue;
        body.push([{ content: phase, colSpan: 6, styles: { fillColor: [224, 242, 254], fontStyle: 'bold' } }]);
        rows.forEach((row) =>
            body.push([row.activity, row.objective, row.result, `Weeks ${row.startWeek}-${row.endWeek}`, row.resources, row.responsible]),
        );
    }
    table(actionPlanColumns, body, [49, 47, 48, 23, 51, 51]);
    page('4 · Investment and resource priorities');
    table(
        ['Order', 'Resource priority', 'Decision needed'],
        [
            ['1', 'Baseline, demand and site verification', 'Resolve source gaps and identify the actual service need.'],
            [
                '2',
                'Technology/service assessment and quotations',
                'Confirm mechanism, eligibility, service scope and current costs with the named provider.',
            ],
            [
                '3',
                'Operator, site, training and recurring costs',
                'Agree operating roles, maintenance, supplies, transport and any connectivity requirements.',
            ],
            ['4', 'Pilot and monitoring resources', 'Fund only the locally validated scope; review operational results before expanding.'],
        ],
    );
    heading('5 · Expected outcomes and performance');
    table(
        ['Proposed output', 'How to check it', 'Review point'],
        [
            ['Validated local baseline and site shortlist', 'Verified records, survey findings and site-assessment notes.', 'End of Week 2'],
            [
                'Documented adoption/referral decisions',
                'One fit/availability/quotation review per selected option; agree measurable targets locally.',
                'End of Week 4',
            ],
            ...(plan.options.length
                ? [
                      [
                          'Pilot/service performance record',
                          'Track agreed usage, tests/services completed, turnaround, reliability and user feedback where relevant.',
                          'Weeks 5-8',
                      ],
                  ]
                : []),
            [
                'Reviewed plan and continuation decision',
                'Review costs and service results against the verified baseline; record limitations and next steps.',
                'Weeks 9-12',
            ],
        ],
    );
    paragraph(
        'No improvement percentage, beneficiary reach, approved budget or funding commitment is inferred. Establish operational baselines and agree numerical targets before any pilot.',
        8,
    );
    heading('Evidence limits and source attribution');
    plan.limitations.slice(1).forEach((text) => paragraph(text, 8));
    table(
        ['Data', 'Source / provider', 'Year / reference', 'Use'],
        snapshot.sources.map((row) => [row.data, row.source, row.reference, row.use]),
    );
    paragraph(
        'Format reference: Region I BDRRM Action PLan.xlsx, Sheet1, supplied sample. The six matrix headings are adapted; its historical activities, dates, budgets and responsible offices are not reused.',
        7,
    );
    for (let number = 1; number <= doc.getNumberOfPages(); number++) {
        doc.setPage(number);
        // Page 1 carries the full letterhead; later pages get a smaller logo in the top-right corner.
        if (number > 1) drawPdfLogo(doc, logo, 283 - 30, 5, 30);
        doc.setFont('NotoSans', 'normal');
        doc.setFontSize(7);
        doc.setTextColor(100, 116, 139);
        doc.text(pdfText(`KonektaDOST · ${plan.location} · Draft Action Plan`), margin, 202);
        doc.text(`Page ${number} of ${doc.getNumberOfPages()}`, 283, 202, { align: 'right' });
    }
    return doc;
}

export async function downloadActionPlanPdf(report: AssessmentReportInput, analysis?: AiResult) {
    await downloadActionPlanSnapshotPdf(buildActionPlanSnapshot(report, analysis));
}

export async function downloadActionPlanSnapshotPdf(snapshot: ActionPlanSnapshot) {
    const [fonts, logo] = await Promise.all([loadAssessmentPdfFonts(), loadDostLogoDataUrl()]);
    const doc = createActionPlanPdfFromSnapshot(snapshot, fonts, logo);
    await doc.save(actionPlanFilename(snapshot.plan.location, new Date(snapshot.plan.generatedAt)), { returnPromise: true });
}
