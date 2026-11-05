import { jsPDF } from 'jspdf';
import { autoTable, type RowInput } from 'jspdf-autotable';
import { loadAssessmentPdfFonts, pdfText, type PdfFonts } from './assessment-pdf';
import { actionPlanColumns, parsePlanningPlan, planningPlanFilename, planningPlanSources, type PlanningPlanSnapshot } from './planning-plan';

/** Export the selected chat/saved version itself, including its stored evidence, without regenerating it. */
export function createPlanningPlanPdf(snapshot: PlanningPlanSnapshot, fonts: PdfFonts, date = new Date()) {
    const plan = parsePlanningPlan(snapshot);
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4', compress: true, putOnlyUsedFonts: true });
    doc.addFileToVFS('NotoSans-Regular.ttf', fonts.regular);
    doc.addFont('NotoSans-Regular.ttf', 'NotoSans', 'normal');
    doc.addFileToVFS('NotoSans-Bold.ttf', fonts.bold);
    doc.addFont('NotoSans-Bold.ttf', 'NotoSans', 'bold');
    doc.setFont('NotoSans', 'normal');
    doc.setProperties({
        title: plan.title,
        author: 'KonektaDOST',
        subject: 'Selected chatbot action plan version, activity matrix and stored evidence visuals',
    });
    const margin = 14,
        width = 269,
        bottom = 190;
    let y = 18;
    const newPage = () => {
        doc.addPage();
        y = 18;
    };
    const ensure = (height: number) => {
        if (y + height > bottom) newPage();
    };
    function paragraph(text: string, size = 9, bold = false) {
        doc.setFont('NotoSans', bold ? 'bold' : 'normal');
        doc.setFontSize(size);
        doc.setTextColor(51, 65, 85);
        const lines = doc.splitTextToSize(pdfText(text), width) as string[];
        for (const line of lines) {
            ensure(size * 0.45 + 2);
            doc.text(line, margin, y);
            y += size * 0.45;
        }
        y += 3;
    }
    function table(headers: readonly string[], rows: RowInput[], widths?: number[]) {
        ensure(25);
        autoTable(doc, {
            startY: y,
            margin: { left: margin, right: margin, top: 18, bottom: 20 },
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
                valign: 'top',
                overflow: 'linebreak',
            },
            headStyles: { fillColor: [8, 125, 204], textColor: 255, fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [248, 250, 252] },
            columnStyles: widths ? Object.fromEntries(widths.map((cellWidth, index) => [index, { cellWidth }])) : {},
            didParseCell: (data) => {
                data.cell.text = data.cell.text.map(pdfText);
            },
        });
        y = (doc as jsPDF & { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 7;
    }
    paragraph(plan.title, 17, true);
    paragraph(
        `${snapshot.location}${snapshot.province ? ` · ${snapshot.province}` : ''}${snapshot.version ? ` · Version ${snapshot.version}` : ''} · Draft pending local validation`,
        8,
    );
    if (snapshot.created_at) paragraph(`Saved/generated timestamp (as recorded): ${snapshot.created_at}`, 8);
    paragraph(
        `Exported ${new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Singapore', dateStyle: 'medium', timeStyle: 'short' }).format(date)} (UTC+08:00). This PDF preserves the selected plan's narrative, constraints and stored evidence; it does not recompute the plan.`,
        8,
    );
    if (!plan.hasMatrix)
        paragraph(
            'This version has no six-column activity matrix. The original text is retained; request a complete revision in chat to add the current format.',
            8,
        );
    for (const part of plan.parts) {
        if (part.type === 'matrix') {
            table(
                actionPlanColumns,
                part.rows.map((row) => row.map((cell) => cell || 'Not specified')),
                [49, 47, 48, 23, 51, 51],
            );
        } else {
            for (const text of part.content.split(/\n\s*\n/)) {
                const lines = text.split('\n');
                for (const line of lines) {
                    const heading = /^\s*(?:#{1,6}\s*)?\d+[.)]\s/.test(line);
                    if (heading) ensure(25);
                    paragraph(line.replace(/^#{1,6}\s+/, ''), heading ? 11 : 9, heading);
                }
            }
        }
    }
    if (snapshot.visuals?.length) {
        newPage();
        paragraph('Evidence graphs and assignment flowcharts', 15, true);
        for (const visual of snapshot.visuals) {
            ensure(65);
            paragraph(visual.title, 11, true);
            paragraph(visual.caption, 8);
            if (visual.type === 'bar') {
                const maximum = Number.isFinite(visual.maximum) && visual.maximum > 0 ? visual.maximum : 100;
                const items = visual.items.filter((row) => Number.isFinite(row.value) && row.value >= 0 && row.value <= maximum).slice(0, 6);
                if (!items.length) paragraph('No valid quantitative scores are stored in this chart.', 8);
                for (const row of items) {
                    doc.setFont('NotoSans', 'normal');
                    doc.setFontSize(8);
                    const label = doc.splitTextToSize(pdfText(row.label), width - 35) as string[];
                    ensure(label.length * 3.5 + 10);
                    doc.setTextColor(51, 65, 85);
                    doc.text(label, margin, y);
                    doc.text(`${row.value} ${visual.unit}`, 283, y, { align: 'right' });
                    y += label.length * 3.5 + 2;
                    doc.setFillColor(239, 246, 255);
                    doc.roundedRect(margin, y, width, 3, 1, 1, 'F');
                    if (row.value > 0) {
                        doc.setFillColor(8, 125, 204);
                        doc.roundedRect(margin, y, (width * row.value) / maximum, 3, 1, 1, 'F');
                    }
                    y += 8;
                }
            } else if (visual.type === 'assignment_diagram') {
                for (const assignment of visual.assignments.slice(0, 4)) {
                    const nodes = assignment.nodes.slice(0, 6);
                    if (!nodes.length) continue;
                    doc.setFont('NotoSans', 'normal');
                    doc.setFontSize(8);
                    const gap = 7,
                        nodeWidth = (width - gap * (nodes.length - 1)) / nodes.length;
                    const labels = nodes.map((node) => doc.splitTextToSize(pdfText(node.label), nodeWidth - 6) as string[]);
                    const height = Math.max(22, ...labels.map((lines) => lines.length * 3.5 + 8));
                    ensure(height + 10);
                    const positions = new Map<string, { left: number; right: number; middle: number }>();
                    nodes.forEach((node, index) => {
                        const x = margin + index * (nodeWidth + gap);
                        positions.set(node.id, { left: x, right: x + nodeWidth, middle: y + height / 2 });
                        doc.setDrawColor(8, 125, 204);
                        doc.setFillColor(239, 246, 255);
                        doc.roundedRect(x, y, nodeWidth, height, 2, 2, 'FD');
                        doc.setTextColor(30, 64, 95);
                        doc.text(labels[index], x + nodeWidth / 2, y + 6, { align: 'center' });
                    });
                    for (const edge of assignment.edges) {
                        const from = positions.get(edge.from),
                            to = positions.get(edge.to);
                        if (!from || !to) continue;
                        doc.setDrawColor(8, 125, 204);
                        doc.line(from.right, from.middle, to.left, to.middle);
                        doc.line(to.left, to.middle, to.left - 2, to.middle - 1.2);
                        doc.line(to.left, to.middle, to.left - 2, to.middle + 1.2);
                    }
                    y += height + 8;
                    if (assignment.reference) paragraph(`Recorded catalog reference: ${assignment.reference}`, 7);
                }
            }
            paragraph(`Stored source: ${visual.source_tool}`, 7);
        }
    } else {
        ensure(30);
        paragraph('Stored evidence visuals', 11, true);
        paragraph('No graph or assignment diagram was saved with this plan version. No current or unrelated evidence has been substituted.', 8);
    }
    ensure(40);
    paragraph('Source & basis', 11, true);
    const sources = planningPlanSources(snapshot);
    if (sources.length)
        table(
            ['Recorded tool / source', 'Location context'],
            sources.map((source) => [source.name, source.location]),
        );
    else paragraph('Detailed tool/source references were not stored with this version. Any citations in the original narrative remain above.', 8);
    paragraph(
        'Narrative: AI-assisted planning draft. Charts and diagrams: stored deterministic tool outputs and their saved captions. User budgets and timeframes are constraints; targets, responsibilities, funding and deployment require local verification.',
        8,
    );
    paragraph(
        'Matrix format reference: supplied Region I BDRRM Action PLan.xlsx (six-column structure). Historical sample dates, budgets and offices are not added to this export.',
        7,
    );
    for (let index = 1; index <= doc.getNumberOfPages(); index++) {
        doc.setPage(index);
        doc.setFont('NotoSans', 'normal');
        doc.setFontSize(7);
        doc.setTextColor(100, 116, 139);
        doc.text(pdfText(`KonektaDOST · ${snapshot.location}${snapshot.version ? ` · v${snapshot.version}` : ''} · Draft Action Plan`), margin, 202);
        doc.text(`Page ${index} of ${doc.getNumberOfPages()}`, 283, 202, { align: 'right' });
    }
    return doc;
}

export async function downloadPlanningPlanPdf(snapshot: PlanningPlanSnapshot) {
    const fonts = await loadAssessmentPdfFonts();
    await createPlanningPlanPdf(snapshot, fonts).save(planningPlanFilename(snapshot), { returnPromise: true });
}
