const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

// Exercise the actual TypeScript geometry code without a browser or another test dependency.
const modules = new Map();
function load(file) {
    if (modules.has(file)) return modules.get(file).exports;
    const mod = { exports: {} };
    modules.set(file, mod);
    const js = ts.transpileModule(fs.readFileSync(file, 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const localRequire = (name) => name.startsWith('.') ? load(path.resolve(path.dirname(file), `${name}.ts`)) : require(name);
    new Function('require', 'module', 'exports', js)(localRequire, mod, mod.exports);
    return mod.exports;
}
const { geometryContainsPoint, scopeMapPins } = load(path.resolve(__dirname, '../../resources/js/components/philippine-map/marker-scope.ts'));
const { resourcePinBounds } = load(path.resolve(__dirname, '../../resources/js/components/philippine-map/types.ts'));
const square = (a, b, c, d) => [[a, b], [c, b], [c, d], [a, d], [a, b]];
const polygon = { type: 'Polygon', coordinates: [square(120, 14, 122, 16), square(120.5, 14.5, 121, 15)] };
const boundary = { type: 'Feature', geometry: polygon, properties: { key: 'province:1' } };
const selection = { id: 1, level: 'province', key: 'province:1' };

test('national view retains all supplied pins without requiring a selection', () => {
    const pins = [{ key: 'site:1', coordinates: [120.25, 14.25] }, { key: 'lab:2', coordinates: [126, 8] }];
    assert.equal(scopeMapPins(pins, null, null), pins);
});

test('selected area uses coordinates even when imported municipality IDs are stale', () => {
    const inside = { key: 'site:1', municipality_id: 999, coordinates: [120.25, 14.25] };
    const outside = { key: 'site:2', municipality_id: 1, coordinates: [126, 8] };
    assert.deepEqual(scopeMapPins([inside, outside], selection, boundary), [inside]);
});

test('polygon holes are excluded and outer boundary points are included', () => {
    assert.equal(geometryContainsPoint(polygon, [120.75, 14.75]), false);
    assert.equal(geometryContainsPoint(polygon, [120, 15]), true);
    assert.equal(geometryContainsPoint(polygon, [120.5, 14.75]), false);
    assert.equal(geometryContainsPoint(polygon, [122.01, 15]), false);
});

test('multipolygon islands remain selectable', () => {
    const geometry = { type: 'MultiPolygon', coordinates: [[square(120, 14, 121, 15)], [square(125, 8, 126, 9)]] };
    assert.equal(geometryContainsPoint(geometry, [125.5, 8.5]), true);
    assert.equal(geometryContainsPoint(geometry, [123, 12]), false);
});

test('city and barangay selections narrow pins using their own polygon', () => {
    const pins = [{ key: 'a', coordinates: [120.2, 14.2] }, { key: 'b', coordinates: [121.5, 15.5] }];
    const city = { ...boundary, geometry: { type: 'Polygon', coordinates: [square(120, 14, 120.4, 14.4)] } };
    assert.deepEqual(scopeMapPins(pins, { ...selection, level: 'municipality' }, city), [pins[0]]);
    assert.deepEqual(scopeMapPins(pins, { ...selection, level: 'barangay' }, city), [pins[0]]);
    assert.deepEqual(scopeMapPins(pins, selection, null), []);
});

test('a barangay with no polygon accepts only an exact explicit source PSGC link', () => {
    const linked = { key: 'site:1', coordinates: [121.05, 14.55], barangay_psgc: '1381500020' };
    const other = { key: 'site:2', coordinates: [121.05, 14.55], barangay_psgc: '1381500021' };
    const unlinked = { key: 'lab:3', coordinates: [121.05, 14.55] };
    assert.deepEqual(scopeMapPins([linked, other, unlinked], { ...selection, level: 'barangay', code: '1381500020' }, null), [linked]);
});

test('a location without geometry can frame its verified point records', () => {
    assert.deepEqual(resourcePinBounds([{ coordinates: [121.05, 14.55] }, { coordinates: [121.08, 14.53] }]), [121.05, 14.53, 121.08, 14.55]);
    assert.equal(resourcePinBounds([]), null);
});
