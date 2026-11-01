const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const mod = { exports: {} };
const source = fs.readFileSync(path.resolve(__dirname, '../../resources/js/lib/assessment-planning.ts'), 'utf8');
new Function('module', 'exports', ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText)(mod, mod.exports);
const { evidenceState, referenceIndex, rankedShortfalls, planningTopics, matchProjects } = mod.exports;

const indicator = (overrides = {}) => ({ key: 'health_facilities_per_100k', label: 'Health facilities',
    dimension: 'health_and_public_health', unit: 'per 100,000', value: 5, benchmark: 10, gap: -50,
    status: 'available', confidence: 'high', is_shortfall: true, ...overrides });
const project = (overrides = {}) => ({ id: 1, code: 'FIXTURE-1', name: 'Fixture telehealth device', agency: 'Fixture agency',
    sector: 'Health', mechanism: 'Diagnostic support', status: null, outcome: null, deployment_clusters: null, ...overrides });

test('unknown measurements and missing references never become confirmed gaps', () => {
    for (const value of [null, NaN, Infinity]) assert.equal(evidenceState(indicator({ value })), 'missing');
    const missingReference = indicator({ value: 0, benchmark: null, status: 'no_benchmark' });
    assert.equal(evidenceState(missingReference), 'reference');
    assert.deepEqual(rankedShortfalls([missingReference]), []);
    assert.deepEqual(planningTopics([missingReference]), []);
    assert.equal(evidenceState(indicator({ status: 'no_data', value: 0 })), 'missing');
});

test('a recorded zero remains measured while low confidence remains provisional', () => {
    assert.equal(referenceIndex(indicator({ value: 0 })), 0);
    assert.equal(evidenceState(indicator({ value: 0 })), 'priority');
    assert.equal(evidenceState(indicator({ value: 0, confidence: 'low' })), 'review');
    assert.equal(planningTopics([indicator({ confidence: 'low' })])[0].basis, 'provisional');
});

test('density keeps its opposite pressure direction and is not a facility shortage', () => {
    const density = indicator({ key: 'population_density', label: 'Population density', dimension: 'population_and_social_needs', value: 150, benchmark: 100, gap: 50 });
    assert.equal(referenceIndex(density), 150);
    assert.equal(evidenceState(density), 'priority');
    assert.equal(planningTopics([density])[0].basis, 'pressure');
    assert.equal(evidenceState({ ...density, value: 50, is_shortfall: false }), 'within');
});

test('zero and invalid references do not produce a normalized index', () => {
    for (const benchmark of [0, -1, null, Infinity, NaN]) assert.equal(referenceIndex(indicator({ benchmark })), null);
    assert.equal(referenceIndex(indicator({ status: 'no_benchmark' })), null);
});

test('review order prioritizes confidence before relative difference without mixing units', () => {
    const high = indicator({ gap: -10 });
    const low = indicator({ key: 'hospitals', confidence: 'low', gap: -100 });
    assert.deepEqual(rankedShortfalls([low, high]), [high, low]);
});

test('no supported evidence means no catalog screening options', () => {
    const topics = planningTopics([indicator({ status: 'no_data', value: null })]);
    assert.deepEqual(matchProjects([project()], topics), []);
});

test('screening preserves catalog records and exposes exact matched terms', () => {
    const input = project();
    const matches = matchProjects([input], planningTopics([indicator()]), [1]);
    assert.equal(matches.length, 1);
    assert.equal(matches[0].project, input);
    assert.equal(matches[0].locationMatch, true);
    assert.ok(matches[0].reasons[0].terms.includes('telehealth'));
    assert.equal(matches[0].project.outcome, null);
});

test('whole-word matching avoids incidental substrings and does not search agency/outcome', () => {
    const topic = { id: 'test', label: 'Testing', terms: ['lab'], basis: 'comparison', evidence: [], check: '' };
    assert.deepEqual(matchProjects([project({ name: 'Collaboration tools', sector: null, mechanism: null, agency: 'Lab agency', outcome: 'Lab outcome' })], [topic]), []);
});

test('a network-consuming sensor does not become a solution to low cell-site counts', () => {
    const topics = planningTopics([indicator({ key: 'telecom_sites_per_100k', dimension: 'infrastructure_and_connectivity' })]);
    const sensor = project({ name: 'Rain sensor', sector: 'Disaster prevention', mechanism: 'Telemetry via GSM' });
    assert.deepEqual(matchProjects([sensor], topics), []);
});

test('agriculture opportunities stay explicitly modeled and unavailable sources add no themes', () => {
    const agriculture = { status: 'available', model_year: 2025, categories: [{ code: 'fisheries', name: 'Fisheries', record_count: 2 }] };
    const topics = planningTopics([], agriculture);
    assert.equal(topics[0].basis, 'modeled');
    const fish = project({ name: 'Fixture fish smoking', sector: 'Fisheries', mechanism: 'Fish processing' });
    assert.equal(matchProjects([fish], topics).length, 1);
    assert.deepEqual(planningTopics([], { ...agriculture, status: 'unavailable' }), []);
    assert.deepEqual(planningTopics([], { ...agriculture, categories: [{ ...agriculture.categories[0], record_count: 0 }] }), []);
});

test('an option matching several evidence themes is still counted only once', () => {
    const topics = planningTopics([indicator(), indicator({ key: 'laboratories_per_million', dimension: 'science_technology_and_innovation_capacity' })]);
    const input = project({ name: 'Health laboratory testing', mechanism: 'Laboratory diagnostics' });
    const matches = matchProjects([input], topics);
    assert.equal(matches.length, 1);
    assert.equal(matches[0].reasons.length, 2);
    assert.equal(matches[0].locationMatch, false);
});
