'use strict';
const assert = require('node:assert/strict');
const { create, key, install } = require('../assets/js/indexflow-managed-routes.js');
const origin = 'https://example.test';
const native = origin + '/tours/jsf/jet-engine:Tours_01/tax/location:cairo;style:luxury/';
const clean = origin + '/tours/location/cairo/style/luxury/';
const config = { provider: 'jet-engine', query_id: 'Tours_01', archive_url: origin + '/tours/', routes: [{ native_url: native, url: clean }] };
let path = '/jsf/jet-engine:Tours_01/tax/style:luxury;location:cairo/';
const group = { provider: 'jet-engine', queryId: 'Tours_01', urlType: 'permalink', getUrl: () => path };
const destination = create(config, origin);
assert.equal(destination(group), clean);
assert.equal(destination({ ...group, queryId: 'tours_01' }), null);
assert.equal(destination({ ...group, urlType: 'plain' }), null);
const numeric = { ...config, query_id: '123', routes: [] };
assert.ok(create(numeric, origin)({ ...group, queryId: 123 }));
path = '/jsf/jet-engine:Tours_01/tax/location:giza;style:luxury/';
assert.equal(destination(group), origin + '/tours' + path);
path = '/jsf/jet-engine:Tours_01/tax/location:cairo;style:luxury/pagenum/2/';
assert.equal(destination(group), origin + '/tours' + path);
path = ''; assert.equal(destination(group), origin + '/tours/');
assert.equal(key(native + '?sort=price', origin), null);
assert.equal(key(native.replace('example.test', 'evil.test'), origin), null);
assert.equal(key(native.replace('location:cairo', 'location:cairo;location:giza'), origin), null);
assert.equal(key(native.replace('location:cairo', 'location:cairo,giza'), origin), null);
assert.throws(() => create({ ...config, routes: [{ native_url: native, url: 'https://evil.test/' }] }, origin));
const subscribers = {}, hooks = {}, listeners = {}, entries = [clean]; let reloaded = 0;
const win = { location: { origin, href: clean, reload() { reloaded++; } },
    document: { addEventListener(name, cb) { listeners[name] = cb; } }, addEventListener(name, cb) { listeners[name] = cb; },
    history: { state: { preserved: true }, replaceState(state, _, url) { entries[entries.length - 1] = url; }, pushState(state, _, url) { entries.push(url); } },
    JetSmartFilters: { events: { subscribe(name, cb) { subscribers[name] = cb; } }, filterGroups: { 'jet-engine/Tours_01': group } },
    JetPlugins: { hooks: { addFilter(name, ns, cb) { hooks[name] = cb; } } } };
install(win, config); listeners['jet-smart-filters/inited']();
path = '/jsf/jet-engine:Tours_01/tax/location:giza;style:luxury/';
win.history.replaceState(null, '', native); // exact vendor behavior before updated event
subscribers['ajaxFilters/updated']('jet-engine', 'Tours_01');
assert.deepEqual(entries, [clean, origin + '/tours' + path]);
path = '/jsf/jet-engine:Tours_01/tax/style:luxury;location:cairo/';
subscribers['ajaxFilters/updated']('jet-engine', 'Tours_01');
assert.equal(entries[entries.length - 1], clean);
assert.equal(hooks['jet-smart-filters.filter.reload-location'](native, group), clean);
listeners.popstate(); assert.equal(reloaded, 1);
console.log('PASS managed browser URL matching, native fallback, reload hook, actual history entries and vendor restoration');
