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
const { assessmentLayers, buildLayerOverviews, reportCatalog, reportNumber, barangayPriorityExplanation, reportTechnologyOptions, reportLaboratoryOptions } = load(path.join(root, 'resources/js/lib/assessment-report.ts'));
const { createAssessmentPdf, assessmentPdfFilename } = load(path.join(root, 'resources/js/lib/assessment-pdf.ts'));
const candidate = score => ({ source_catalog: 'technologies', intervention_id: 'HEALTH-1', name: 'Recorded diagnostic device', relevance_score: score, availability_status: 'available' });
const fixture = () => ({
    location: { name: 'San José', type: 'Barangay', province: 'Province', psg_code: '0701232001', population: 1000, area_km2: 2 },
    populationYear: 2024, focus: 'general',
    results: [{ key: 'health_facilities_per_100k', label: 'Health facilities', dimension: 'health_and_public_health', unit: 'per 100,000 people', value: 0, benchmark: 10, gap: -100, status: 'available', confidence: 'low', is_shortfall: true, source: 'Recorded inventory', reason: 'Provisional imported inventory.', calculation: { numerator: 0, denominator: 1000, multiplier: 100000, population_year: 2024 } }],
    functionToolsBundle: {
        dataset_audit: { datasets: { facilities: { status: 'unavailable', label: 'Facilities', local_records: null }, telecom_sites: { status: 'empty', local_records: 0 } } },
        local_assets: { facilities: { by_sector: { health: 0, education: 0, safety: 0 } }, telecom_sites: { total_sites: 0 } },
        detected_gaps: { gaps: [{ indicator_key: 'health_facilities_per_100k', label: 'Health facilities', dimension: 'health_and_public_health', measured_value: 0, benchmark_value: 10, percentage_gap: -100, severity_score: 65, confidence: 'low', unit: 'per 100,000' }], sector_diagnostics: [{ sector: 'agriculture', status: 'not_assessed', message: 'No agriculture deficit model.' }] },
        dost_technologies: { total_matched: 200, returned_count: 1, records: [{ id: 1, title: 'Recorded diagnostic device', agency: 'Recorded agency', sector: 'Health', tech_status: 'available', source_catalog: 'technologies' }] },
        local_dost_projects: { total_matched_projects: 0, projects: [] },
        matched_interventions: { gap_interventions: [{ gap_id: 'health_1', gap_name: 'Health facilities', domain: 'health', matched_interventions: [candidate(80)] }, { gap_id: 'health_2', gap_name: 'Hospital access', domain: 'health', matched_interventions: [candidate(90)] }] },
    },
});

test('every layer has its own visual overview and result explanation without changing source data', () => {
    const report = fixture(), before = JSON.stringify(report), overviews = buildLayerOverviews(report);
    assert.deepEqual(Object.keys(overviews), [...assessmentLayers]);
    for (const layer of Object.values(overviews)) { assert.ok(layer.charts.length >= 2); assert.ok(layer.steps.length >= 4); assert.ok(layer.interpretation.length > 0); }
    assert.equal(JSON.stringify(report), before);
});

test('charts preserve unavailable evidence and recorded zero and suppress conflicting inventory counts', () => {
    const report = fixture(), overviews = buildLayerOverviews(report);
    const rows = overviews.profile_assets.charts.find(chart => chart.title === 'Recorded local assets').rows;
    assert.equal(rows.find(row => row.label === 'Health facilities').value, null);
    assert.equal(rows.find(row => row.label === 'Telecom sites').value, 0);
    assert.equal(overviews.gaps_barangays.charts[1].rows.find(row => row.label === 'Agriculture').value, null);
    report.inventoryIntegrity = { inventories: [{ dataset: 'telecom_sites', has_conflicts: true }] };
    assert.equal(buildLayerOverviews(report).profile_assets.charts.find(chart => chart.title === 'Recorded local assets').rows.find(row => row.label === 'Telecom sites').value, null);
    for (const invalid of [null, undefined, '', ' ', false, [], {}, NaN, Infinity]) assert.equal(reportNumber(invalid), null);
});

test('candidate counts do not double-count a catalog record and catalog metrics use returned records', () => {
    const report = fixture(), overviews = buildLayerOverviews(report);
    assert.equal(overviews.matched_interventions.metrics.find(row => row.label === 'Distinct candidates').value, '1');
    assert.equal(overviews.matched_interventions.charts[0].rows[0].value, 90);
    const healthCoverage = overviews.matched_interventions.charts.find(chart => chart.title === 'Needs with a catalog option').rows.find(row => row.label === 'Health');
    assert.equal(healthCoverage.value, 100);
    assert.equal(healthCoverage.formatted, '2 of 2 needs');
    assert.equal(overviews.dost_catalog.metrics.find(row => row.label === 'Catalog entries shown').value, '1');
    assert.equal(reportCatalog(report)[0].name, 'Recorded diagnostic device');
});

test('missing tool output does not become zero detected gaps or zero intervention need', () => {
    const report = fixture(); report.functionToolsBundle = null;
    const overviews = buildLayerOverviews(report);
    assert.equal(overviews.gaps_barangays.metrics[0].value, 'Unavailable');
    assert.equal(overviews.matched_interventions.metrics[0].value, 'Unavailable');
    assert.ok(overviews.matched_interventions.charts[1].rows.every(row => row.value === null));
    assert.match(barangayPriorityExplanation({ population: null, priority_score: null }), /Missing population does not become a zero score/);
});

test('additional charts preserve missing census observations and withhold conflicting laboratory capabilities', () => {
    const report = fixture();
    report.location.population_statistics = { history: [{ year: 2020, population: 700 }, { year: 2024, population: null }] };
    report.functionToolsBundle.sector_analysis = { sti_laboratory: { status: 'available', capability_domains: { calibration_and_metrology: 12 } } };
    report.inventoryIntegrity = { inventories: [{ dataset: 'laboratories', has_conflicts: true }] };
    const overviews = buildLayerOverviews(report);
    const history = overviews.profile_assets.charts.find(chart => chart.title === 'Population across census years');
    assert.deepEqual(history.rows, [{ label: '2020', value: 700 }, { label: '2024', value: null }]);
    const lab = overviews.matched_interventions.charts.find(chart => chart.title === 'Recorded local laboratory capabilities');
    assert.ok(lab.rows.every(row => row.value === null));
    assert.equal(overviews.matched_interventions.charts.find(chart => chart.title === 'Needs with a catalog option').rows.find(row => row.label === 'Roads').value, null);
});

test('technology shortlist deduplicates options by their best match and excludes historical project leads', () => {
    const report = fixture();
    report.functionToolsBundle.matched_interventions.gap_interventions[0].matched_interventions.push({
        ...candidate(99), source_catalog: 'dost_projects', name: 'Historical research project',
    });
    const options = reportTechnologyOptions(report);
    assert.equal(options.length, 1);
    assert.equal(options[0].relevance_score, 90);
    assert.equal(options[0].gap_name, 'Hospital access');
    report.functionToolsBundle = null;
    assert.deepEqual(reportTechnologyOptions(report), []);
});

test('laboratory referrals retain recorded proximity and are withheld for conflicting geography', () => {
    const report = fixture();
    report.functionToolsBundle.sector_analysis = { sti_laboratory: { nearest_accredited_laboratory_if_zero: {
        agency_name: 'Recorded nearby laboratory', distance_km: 75.65,
    } } };
    assert.equal(reportLaboratoryOptions(report).nearby.distance_km, 75.65);
    report.localLaboratories = [{ agency_name: 'Recorded local laboratory' }];
    assert.equal(reportLaboratoryOptions(report).local.length, 1);
    assert.equal(reportLaboratoryOptions(report).nearby, null);
    report.inventoryIntegrity = { inventories: [{ dataset: 'laboratories', has_conflicts: true }] };
    assert.deepEqual(reportLaboratoryOptions(report), { conflict: true, local: [], nearby: null });
});

test('PDF generation produces all five layers independently of UI filters with real text and embedded fonts', () => {
    const fonts = { regular: fs.readFileSync(path.join(root, 'resources/fonts/noto-sans/NotoSans-Regular.ttf')).toString('base64'), bold: fs.readFileSync(path.join(root, 'resources/fonts/noto-sans/NotoSans-Bold.ttf')).toString('base64') };
    const report = fixture(), doc = createAssessmentPdf(report, fonts);
    const bytes = Buffer.from(doc.output('arraybuffer'));
    assert.equal(bytes.subarray(0, 5).toString(), '%PDF-');
    assert.ok(doc.getNumberOfPages() >= 7, 'All five layers, cover and report notes must be present.');
    assert.ok(bytes.length > 10000, 'The generated document must contain embedded text/fonts.');
    assert.equal(doc.getFont().fontName, 'NotoSans');
    assert.match(assessmentPdfFilename(report, new Date('2026-11-02T17:30:00Z')), /2026-11-03\.pdf$/);
});
