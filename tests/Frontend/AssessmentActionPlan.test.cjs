const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const modules = new Map();
function load(file) {
    file = path.resolve(file);
    if (modules.has(file)) return modules.get(file).exports;
    const mod = { exports: {} }; modules.set(file, mod);
    const code = ts.transpileModule(fs.readFileSync(file, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    const localRequire = id => id.endsWith('?url') ? { default: id.slice(0, -4) } : id.startsWith('.') ? load(path.resolve(path.dirname(file), id + '.ts')) : require(id);
    new Function('module', 'exports', 'require', code)(mod, mod.exports, localRequire);
    return mod.exports;
}
const root = path.resolve(__dirname, '../..');
const { buildAssessmentActionPlan, actionPlanColumns, actionPlanFilename } = load(path.join(root, 'resources/js/lib/assessment-action-plan.ts'));
const { createActionPlanPdf } = load(path.join(root, 'resources/js/lib/assessment-action-plan-pdf.ts'));
const option = (score, source = 'technologies') => ({ intervention_id: 'TEST-1', source_catalog: source, name: 'Recorded testing program', agency: 'Recorded DOST agency', relevance_score: score, availability_status: 'unknown', matching_criteria: ['Recorded sector match'] });
const fixture = () => ({
    location: { name: 'Sample area', type: 'Barangay', province: 'Sample province', population: null, area_km2: null, psg_code: '0701232001' },
    focus: 'general', results: [], populationYear: 2024,
    functionToolsBundle: {
        dataset_audit: { datasets: { facilities: { status: 'empty' } } },
        detected_gaps: { gaps: [{ label: 'Local laboratory capacity', measured_value: 0, benchmark_value: 10, unit: 'per million', severity_score: 65, confidence: 'low', source: 'Recorded inventory' }] },
        matched_interventions: { gap_interventions: [
            { gap_id: 'sti_1', gap_name: 'Local laboratory capacity', domain: 'sti_capacity', matched_interventions: [option(90), option(99, 'dost_projects')] },
            { gap_id: 'sti_2', gap_name: 'Testing access', domain: 'sti_capacity', matched_interventions: [option(70)] },
        ] },
    },
});
const ai = () => ({ status: 'ready', generated_at: '2026-11-04T09:00:00+08:00', analysis: { summary: 'AI summary', sectors: [
    { sector: 'sti_capacity', intervention_refs: ['technologies:TEST-1'], intervention_analysis: 'Review the recorded program as a service referral option.', gap_explanation: 'Provisional need.', next_action: 'Confirm required tests.', indicator_keys: [] },
] }, interventions: [{ ref: 'technologies:TEST-1', name: 'Recorded testing program', sector: 'sti_capacity', indicator_key: 'sti_1' }] });

test('plan uses distinct technology evidence, retains provisional baselines and never treats historical projects as deployments', () => {
    const report = fixture(), before = JSON.stringify(report), plan = buildAssessmentActionPlan(report);
    assert.equal(plan.options.length, 1);
    assert.equal(plan.options[0].relevance, 90);
    assert.equal(plan.options[0].status, 'unknown');
    assert.match(plan.needs[0].baseline, /^0 per million/);
    assert.equal(plan.needs[0].confidence, 'low');
    assert.equal(plan.priorities[0].area, report.location.name);
    assert.ok(plan.activities.every(row => row.responsible.startsWith('Proposed:')));
    assert.ok(plan.activities.every(row => row.startWeek >= 1 && row.endWeek <= 12));
    assert.equal(JSON.stringify(report), before);
});

test('missing matches produce a validation plan with no unsupported technology pilot', () => {
    const report = fixture(); report.functionToolsBundle = null;
    const plan = buildAssessmentActionPlan(report);
    assert.deepEqual(plan.options, []);
    assert.deepEqual(plan.needs, []);
    assert.equal(plan.activities.some(row => row.phase === 'Pilot'), false);
    assert.match(plan.laboratory, /No verified service partner/);
    assert.equal(plan.aiGeneratedAt, null);
});

test('AI wording is included only for the current recorded option name, reference and sector', () => {
    const report = fixture(), analysis = ai();
    assert.equal(buildAssessmentActionPlan(report, analysis).options[0].aiNote, analysis.analysis.sectors[0].intervention_analysis);
    analysis.interventions[0].name = 'Unrelated option';
    assert.equal(buildAssessmentActionPlan(report, analysis).aiGeneratedAt, null);
    analysis.interventions[0].name = 'Recorded testing program'; analysis.analysis.sectors[0].sector = 'health';
    assert.equal(buildAssessmentActionPlan(report, analysis).options[0].aiNote, null);
});

test('conflicting laboratory locations are withheld from partner assignments', () => {
    const report = fixture();
    report.localLaboratories = [{ agency_name: 'Conflicting laboratory name' }];
    report.inventoryIntegrity = { inventories: [{ dataset: 'laboratories', has_conflicts: true }] };
    const plan = buildAssessmentActionPlan(report);
    assert.match(plan.laboratory, /locations conflict/);
    assert.ok(plan.activities.every(row => !JSON.stringify(row).includes('Conflicting laboratory name')));
});

test('PDF retains the sample matrix columns, landscape format, fonts and Singapore date', () => {
    assert.deepEqual(actionPlanColumns, ['ACTIVITIES', 'OBJECTIVES', 'RESULTS', 'TIMELINE', 'BUDGET/RESOURCES', 'RESPONSIBLE (PERSON/OFFICE)']);
    const fonts = { regular: fs.readFileSync(path.join(root, 'resources/fonts/noto-sans/NotoSans-Regular.ttf')).toString('base64'), bold: fs.readFileSync(path.join(root, 'resources/fonts/noto-sans/NotoSans-Bold.ttf')).toString('base64') };
    const doc = createActionPlanPdf(fixture(), fonts, ai());
    assert.equal(Buffer.from(doc.output('arraybuffer')).subarray(0,5).toString(), '%PDF-');
    assert.ok(doc.internal.pageSize.getWidth() > doc.internal.pageSize.getHeight());
    assert.ok(doc.getNumberOfPages() >= 4);
    assert.equal(doc.getFont().fontName, 'NotoSans');
    assert.match(actionPlanFilename(fixture(), new Date('2026-11-03T17:30:00Z')), /2026-11-04\.pdf$/);
});
