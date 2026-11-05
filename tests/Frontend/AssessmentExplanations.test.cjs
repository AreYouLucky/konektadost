const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const mod = { exports: {} };
const source = fs.readFileSync(path.resolve(__dirname, '../../resources/js/lib/assessment-explanations.ts'), 'utf8');
new Function('module', 'exports', ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText)(mod, mod.exports);
const { indicatorMethod, workedRate, comparisonMeaning, interventionMethod } = mod.exports;

test('worked rates use recorded inputs without deriving counts from rounded rates', () => {
    assert.equal(workedRate({ numerator: 2, denominator: 10000, multiplier: 100000 }, 20), '2 ÷ 10,000 × 100,000 ≈ 20');
    assert.equal(workedRate({ numerator: 10000, denominator: 20, multiplier: 1 }, 500), '10,000 ÷ 20 ≈ 500');
    assert.equal(workedRate({ numerator: null, denominator: 10000, multiplier: 100000 }, 20), null);
    assert.equal(workedRate({ numerator: 2, denominator: 0, multiplier: 100000 }, null), null);
});

test('density pressure, facility shortfalls and missing references have different explanations', () => {
    assert.match(comparisonMeaning('health_facilities_per_100k', 5, 10, -50), /50% below.*possible shortfall/);
    assert.match(comparisonMeaning('population_density', 500, 100, 400), /400% above.*service pressure/);
    assert.match(comparisonMeaning('population_density', 50, 100, -50), /does not flag/);
    assert.match(comparisonMeaning('schools_per_100k', null, 10, null), /Missing data cannot establish/);
    assert.match(comparisonMeaning('schools_per_100k', 0, null, null), /cannot be confirmed/);
    assert.match(comparisonMeaning('schools_per_100k', 1, 0, null), /reference is zero/);
});

test('source formulas preserve population units and catalog-specific scoring rules', () => {
    assert.match(indicatorMethod('laboratories_per_million').formula, /1,000,000/);
    assert.match(indicatorMethod('telecom_5g_share').formula, /total recorded sites × 100/);
    assert.match(indicatorMethod('population_density').formula, /land area/);
    assert.equal(interventionMethod('technologies').threshold, 40);
    assert.equal(interventionMethod('dost_projects').threshold, 45);
    assert.match(interventionMethod('dost_projects').formula, /15 starting points.*30 for sector.*up to 20/);
    assert.match(interventionMethod('dost_techs').assets, /agriculture/);
});
