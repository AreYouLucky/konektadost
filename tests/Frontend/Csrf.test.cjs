const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const mod = { exports: {} };
const source = fs.readFileSync(path.resolve(__dirname, '../../resources/js/lib/csrf.ts'), 'utf8');
new Function('module', 'exports', ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText)(mod, mod.exports);

test('a rotated session cookie takes precedence over the stale page token', () => {
    global.document = { cookie: 'other=value; XSRF-TOKEN=new%2Bencrypted%3D%3D', querySelector: () => ({ content: 'old-page-token' }) };
    assert.deepEqual(mod.exports.csrfHeaders(), { 'X-XSRF-TOKEN': 'new+encrypted==' });
    document.cookie = 'XSRF-TOKEN=rotated-again';
    assert.deepEqual(mod.exports.csrfHeaders(), { 'X-XSRF-TOKEN': 'rotated-again' });
});

test('pages without an XSRF cookie use their server-rendered token', () => {
    global.document = { cookie: '', querySelector: () => ({ content: 'page-token' }) };
    assert.deepEqual(mod.exports.csrfHeaders(), { 'X-CSRF-TOKEN': 'page-token' });
});

test('a malformed cookie does not break request generation', () => {
    global.document = { cookie: 'XSRF-TOKEN=%invalid', querySelector: () => ({ content: 'page-token' }) };
    assert.deepEqual(mod.exports.csrfHeaders(), { 'X-CSRF-TOKEN': 'page-token' });
});
