'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const childProcess = require('child_process');

['admin-shell.js', 'dynamic-content-admin.js'].forEach((file) => {
    childProcess.execFileSync(process.execPath, ['--check', path.resolve(__dirname, '../assets/js/' + file)], { stdio: 'pipe' });
});

const source = fs.readFileSync(path.resolve(__dirname, '../assets/js/ajax-filter-state.js'), 'utf8');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function element(attrs, tag) {
    attrs = Object.assign({}, attrs);
    return {
        tagName: tag || 'DIV',
        innerHTML: 'Initial',
        textContent: 'Initial',
        style: { backgroundImage: '' },
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null; },
        setAttribute(name, value) { attrs[name] = String(value); },
        removeAttribute(name) { delete attrs[name]; },
        hasAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name); },
        closest() { return null; },
        attrs
    };
}

function response(body) {
    return {
        ok: true,
        status: 200,
        text: () => Promise.resolve(JSON.stringify(body))
    };
}

function readyTokenResponse() {
    return response({
        contract: 'etg.dfsb.ajax-presentation.v1',
        status: 'ready',
        authorizing: false,
        url_authority: false,
        seo_mutation: false,
        provider: 'jet-engine',
        query_id: 'myGrid',
        filtered_query_complete: true,
        values: {
            tokens: { 'termmeta:location:HeroImage': { value: '901', type: 'text' } },
            slots: {}
        },
        blocking_reasons: []
    });
}

function readyMediaResponse() {
    return response({
        contract: 'etg.dfsb.ajax-presentation.v1',
        status: 'ready',
        authorizing: false,
        url_authority: false,
        seo_mutation: false,
        provider: 'jet-engine',
        query_id: 'myGrid',
        filtered_query_complete: true,
        values: {
            tokens: {},
            slots: {
                hero_media: {
                    value: '901',
                    type: 'image',
                    image: { id: 901, url: 'https://example.test/901.jpg' }
                }
            }
        },
        blocking_reasons: []
    });
}

async function boot(kind) {
    const events = [];
    const requests = [];
    const group = 'jet-engine/myGrid';
    const tokenEl = kind === 'token' ? element({
        'data-etg-dfsb-token': 'termmeta:location:HeroImage',
        'data-etg-dfsb-group': group
    }) : null;
    const mediaEl = kind === 'media' ? element({
        'data-etg-dfsb-media-slot': 'hero_media',
        'data-etg-dfsb-media-target': 'src',
        'data-etg-dfsb-group': group
    }, 'IMG') : null;
    const all = [tokenEl, mediaEl].filter(Boolean);

    const document = {
        querySelectorAll(selector) {
            return all.filter((el) => {
                if (selector.indexOf('data-etg-dfsb-media-slot') !== -1 && el.hasAttribute('data-etg-dfsb-media-slot')) { return true; }
                if (selector.indexOf('data-etg-dfsb-token') !== -1 && el.hasAttribute('data-etg-dfsb-token')) { return true; }
                if (selector.indexOf('data-etg-dfsb-slot') !== -1 && el.hasAttribute('data-etg-dfsb-slot')) { return true; }
                return false;
            });
        },
        dispatchEvent(event) { events.push(event); return true; },
        addEventListener() {}
    };

    const filterGroups = {
        [group]: { currentQuery: { _tax_query_location_jet: 'cairo' } }
    };
    const window = {
        location: { pathname: '/tours-and-activities/jsf/jet-engine:myGrid/tax/location_jet:cairo/' },
        ETGDFSB_AJAX: {
            endpoint: '/ajax',
            contract: 'etg.dfsb.ajax-presentation.v1',
            maxTokens: 100,
            maxSlots: 50,
            timeoutMs: 8000,
            groupRetryAttempts: 4,
            groupRetryDelayMs: 10,
            jsfVersion: '3.8.3.1',
            supportedJsfVersions: ['3.8.3.1']
        },
        JetSmartFilters: { filterGroups, events: { subscribe() {} } },
        setTimeout,
        clearTimeout
    };

    const fetch = (url, options) => {
        const body = JSON.parse(options.body);
        requests.push(body);
        return Promise.resolve(kind === 'token' ? readyTokenResponse() : readyMediaResponse());
    };

    const context = {
        window,
        document,
        fetch,
        CustomEvent: class {
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
        setTimeout,
        clearTimeout,
        console
    };
    vm.runInNewContext(source, context, { filename: 'ajax-filter-state.js' });
    await sleep(60);
    return { events, requests, tokenEl, mediaEl };
}

(async () => {
    assert(source.includes('data-etg-dfsb-media-slot'), 'media binding selector missing');
    assert(source.includes("'etg-dfsb/media-updated'"), 'media update event missing');
    assert(!source.includes("getAttribute('data-etg-dfsb-token') || '').trim().toLowerCase()"), 'token identity must not be lower-cased in browser bridge');

    const token = await boot('token');
    assert.strictEqual(token.requests.length, 1);
    assert.deepStrictEqual(token.requests[0].tokens, ['termmeta:location:HeroImage'], 'case-sensitive token must survive request payload');
    assert.strictEqual(token.tokenEl.textContent, '901');

    const media = await boot('media');
    assert.strictEqual(media.requests.length, 1);
    assert.deepStrictEqual(media.requests[0].slots, ['hero_media']);
    assert.strictEqual(media.mediaEl.getAttribute('src'), 'https://example.test/901.jpg', 'live image slot must update explicit src target');
    assert(media.events.some((event) => event.type === 'etg-dfsb/media-updated' && event.detail.slot === 'hero_media'), 'media update event must expose slot payload');

    console.log('Alpha13 browser media, admin shell syntax and token identity smoke tests passed.');
})().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exit(1);
});