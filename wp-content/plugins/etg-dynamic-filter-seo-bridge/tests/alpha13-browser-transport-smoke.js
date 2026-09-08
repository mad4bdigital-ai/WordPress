'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/ajax-filter-state.js'), 'utf8');

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function response(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        text: () => Promise.resolve(body == null ? '' : String(body))
    };
}

function readyBody(provider, queryId, title) {
    return JSON.stringify({
        contract: 'etg.dfsb.ajax-presentation.v1',
        status: 'ready',
        authorizing: false,
        url_authority: false,
        seo_mutation: false,
        provider,
        query_id: queryId,
        filtered_query_complete: true,
        values: { tokens: { title: { value: title, type: 'text' } }, slots: {} },
        blocking_reasons: []
    });
}

function makeElement(group) {
    const attrs = {
        'data-etg-dfsb-token': 'title',
        'data-etg-dfsb-group': group || 'auto'
    };
    return {
        innerHTML: 'Initial',
        textContent: 'Initial',
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null; },
        setAttribute(name, value) { attrs[name] = String(value); },
        removeAttribute(name) { delete attrs[name]; },
        hasAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name); },
        closest() { return null; }
    };
}

async function boot(options) {
    const events = [];
    const subscribers = {};
    const element = makeElement(options.elementGroup || 'auto');
    const filterGroups = options.filterGroups || {};
    const document = {
        querySelectorAll(selector) {
            return selector.indexOf('data-etg-dfsb-token') !== -1 ? [element] : [];
        },
        dispatchEvent(event) { events.push(event); return true; },
        addEventListener() {}
    };
    const window = {
        location: { pathname: options.pathname || '/tours-and-activities/jsf/jet-engine:myGrid/tax/location_jet:cairo/' },
        ETGDFSB_AJAX: Object.assign({
            endpoint: '/wp-json/etg-dfsb/v1/ajax-presentation',
            contract: 'etg.dfsb.ajax-presentation.v1',
            maxTokens: 100,
            maxSlots: 50,
            timeoutMs: 8000,
            groupRetryAttempts: 4,
            groupRetryDelayMs: 125,
            jsfVersion: '3.8.3.1',
            supportedJsfVersions: ['3.8.3.1']
        }, options.config || {}),
        JetSmartFilters: options.jetSmartFilters || {
            filterGroups,
            events: { subscribe(name, callback) { subscribers[name] = callback; } }
        },
        setTimeout,
        clearTimeout
    };

    const context = {
        window,
        document,
        CustomEvent: class CustomEvent {
            constructor(type, init) { this.type = type; this.detail = (init && init.detail) || {}; }
        },
        WeakMap,
        Promise,
        JSON,
        Object,
        Array,
        String,
        Number,
        Math,
        Error,
        isFinite,
        AbortController: global.AbortController,
        fetch: options.fetch,
        console,
        setTimeout,
        clearTimeout
    };
    vm.runInNewContext(source, context, { filename: 'ajax-filter-state.js' });
    await sleep(8);
    return { events, subscribers, element, filterGroups, window };
}

function blockedEvent(env, reason) {
    return env.events.find((event) => event.type === 'etg-dfsb/ajax-presentation-blocked' && event.detail.reason === reason);
}

async function testHttpDiagnostics() {
    for (const status of [403, 429, 500]) {
        const env = await boot({
            filterGroups: { 'jet-engine/myGrid': { currentQuery: { _tax_query_location_jet: 'cairo' } } },
            fetch: () => Promise.resolve(response(status, '{"code":"bounded_error"}'))
        });
        await sleep(60);
        const event = blockedEvent(env, 'http_error');
        assert(event, `${status} must emit http_error`);
        assert.strictEqual(event.detail.http_status, status, `${status} status must survive diagnostics`);
    }
}

async function testTimeout() {
    const env = await boot({
        config: { timeoutMs: 10 },
        filterGroups: { 'jet-engine/myGrid': { currentQuery: { _tax_query_location_jet: 'cairo' } } },
        fetch: () => new Promise(() => {})
    });
    await sleep(60);
    const event = blockedEvent(env, 'timeout');
    assert(event, 'hanging fetch must emit timeout');
    assert.strictEqual(event.detail.timeout_ms, 10, 'test timeout value must be reported');
}

async function testBoundedUrlGroupRetry() {
    let calls = 0;
    const env = await boot({
        config: { groupRetryAttempts: 3, groupRetryDelayMs: 10 },
        filterGroups: {},
        fetch: () => {
            calls += 1;
            return Promise.resolve(response(200, readyBody('jet-engine', 'myGrid', 'Cairo Tours')));
        }
    });
    setTimeout(() => {
        env.filterGroups['jet-engine/myGrid'] = { currentQuery: { _tax_query_location_jet: 'cairo' } };
    }, 5);
    await sleep(80);
    assert(calls > 0, 'URL-declared group appearing during retry must be scheduled');
    assert.strictEqual(env.element.textContent, 'Cairo Tours', 'retry-resolved group must update bound content');
    assert(!blockedEvent(env, 'url_group_unavailable'), 'successful bounded retry must not emit unavailable');
}

async function testStaleResponseGate() {
    const pending = [];
    const env = await boot({
        filterGroups: { 'jet-engine/myGrid': { currentQuery: { _tax_query_location_jet: 'cairo' } } },
        fetch: () => new Promise((resolve) => pending.push(resolve))
    });
    await sleep(35);
    assert.strictEqual(pending.length, 1, 'initial request expected');
    env.filterGroups['jet-engine/myGrid'].currentQuery = { _tax_query_location_jet: 'giza' };
    env.subscribers['ajaxFilters/updated']('jet-engine', 'myGrid');
    await sleep(35);
    assert.strictEqual(pending.length, 2, 'second request expected after filter update');
    pending[1](response(200, readyBody('jet-engine', 'myGrid', 'New State')));
    await sleep(8);
    pending[0](response(200, readyBody('jet-engine', 'myGrid', 'Old State')));
    await sleep(8);
    assert.strictEqual(env.element.textContent, 'New State', 'late stale response must not overwrite newer state');
}

async function testAbortIsNotTransportFailure() {
    let call = 0;
    const env = await boot({
        filterGroups: { 'jet-engine/myGrid': { currentQuery: { _tax_query_location_jet: 'cairo' } } },
        fetch: (url, options) => {
            call += 1;
            if (call === 1) {
                return new Promise((resolve, reject) => {
                    options.signal.addEventListener('abort', () => {
                        const error = new Error('aborted');
                        error.name = 'AbortError';
                        reject(error);
                    });
                });
            }
            return Promise.resolve(response(200, readyBody('jet-engine', 'myGrid', 'Latest')));
        }
    });
    await sleep(35);
    env.filterGroups['jet-engine/myGrid'].currentQuery = { _tax_query_location_jet: 'giza' };
    env.subscribers['ajaxFilters/updated']('jet-engine', 'myGrid');
    await sleep(60);
    assert.strictEqual(env.element.textContent, 'Latest', 'new request succeeds after superseding abort');
    assert(!blockedEvent(env, 'transport_error'), 'AbortController supersession must not emit transport error');
}

async function testRuntimeContractFailClosed() {
    let calls = 0;
    const env = await boot({
        config: { jsfVersion: '9.9.9' },
        filterGroups: { 'jet-engine/myGrid': { currentQuery: { _tax_query_location_jet: 'cairo' } } },
        fetch: () => { calls += 1; return Promise.resolve(response(200, readyBody('jet-engine', 'myGrid', 'Unsafe'))); }
    });
    await sleep(40);
    const event = blockedEvent(env, 'jsf_runtime_contract_unavailable');
    assert(event, 'uncertified JetSmartFilters version must fail closed');
    assert.strictEqual(event.detail.jsf_version, '9.9.9');
    assert(event.detail.blocking_reasons.includes('jsf_version_unsupported'));
    assert.strictEqual(calls, 0, 'uncertified runtime must not call presentation REST');
}

async function main() {
    assert(source.includes('boundedPositiveInt(cfg.timeoutMs, 8000, 8000)'), 'browser timeout must be absolutely capped at 8 seconds');
    assert(source.includes("reason: 'http_error'"), 'HTTP error diagnostic contract missing');
    assert(source.includes('http_status: result.status'), 'HTTP status diagnostic missing');
    assert(source.includes('scheduleUrlGroupRetry'), 'bounded URL-group retry missing');
    assert(source.includes('jsfRuntimeContract'), 'JetSmartFilters runtime contract probe missing');
    assert(source.includes("reason: 'jsf_runtime_contract_unavailable'"), 'JetSmartFilters fail-closed diagnostic missing');
    await testHttpDiagnostics();
    await testTimeout();
    await testBoundedUrlGroupRetry();
    await testStaleResponseGate();
    await testAbortIsNotTransportFailure();
    await testRuntimeContractFailClosed();
    console.log('Alpha13 browser transport smoke tests passed.');
}

main().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exit(1);
});
