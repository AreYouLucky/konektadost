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

function mockServer({ acceptFresh }) {
    const meta = { content: 'stale-page-token', setAttribute(name, value) { if (name === 'content') this.content = value; } };
    global.document = { cookie: '', querySelector: () => meta };
    global.route = (name) => `/${name}`;
    const calls = [];
    global.fetch = async (url, init = {}) => {
        calls.push({ url, headers: init.headers ?? {} });
        if (url === '/csrf-token') return { ok: true, redirected: false, status: 200, json: async () => ({ token: 'fresh-token' }) };
        return { status: acceptFresh && init.headers['X-CSRF-TOKEN'] === 'fresh-token' ? 200 : 419, redirected: false };
    };
    return { meta, calls };
}

test('a stale page token is refreshed and the request is retried once', async () => {
    const { meta, calls } = mockServer({ acceptFresh: true });
    const response = await mod.exports.fetchWithCsrf('/planning-chat', { method: 'POST', headers: { Accept: 'application/json' } });
    assert.equal(response.status, 200);
    assert.deepEqual(calls.map((call) => call.url), ['/planning-chat', '/csrf-token', '/planning-chat']);
    assert.equal(calls[0].headers['X-CSRF-TOKEN'], 'stale-page-token');
    assert.equal(calls[2].headers.Accept, 'application/json');
    assert.equal(meta.content, 'fresh-token');
    assert.equal(mod.exports.isSessionEnded(response), false);
});

test('a session that really ended is retried only once and reported as ended', async () => {
    const { calls } = mockServer({ acceptFresh: false });
    const response = await mod.exports.fetchWithCsrf('/planning-chat', { method: 'POST' });
    assert.equal(response.status, 419);
    assert.equal(calls.length, 3);
    assert.equal(mod.exports.isSessionEnded(response), true);
});
