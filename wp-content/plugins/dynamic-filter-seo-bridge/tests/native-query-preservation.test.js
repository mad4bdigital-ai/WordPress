'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Run: node tests/native-query-preservation.test.js [source-file]
// The optional source file is used only for verifying that the regression
// test fails against the known-bad previous source.
const sourceFile = process.argv[2] || path.join(__dirname, '../assets/js/ajax-taxonomy-reconcile.js');
const code = fs.readFileSync(sourceFile, 'utf8');
const key = 'jet-engine/tours_query_archive';
const events = [];
const callbacks = {};
const listeners = {};
const original = { '_tax_query_tour-types': '205' };
const group = { currentQuery: original };
const doc = {
  addEventListener(name, callback) { listeners[name] = callback; },
  dispatchEvent(event) { events.push(event); }
};
function CustomEvent(name, init) { this.type = name; this.detail = init.detail; }
const browser = {
  location: { pathname: '/es/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-types:excursiones-de-un-dia/' },
  JetSmartFilters: {
    filterGroups: { [key]: group },
    events: { subscribe(name, callback) { callbacks[name] = callback; } }
  },
  setTimeout(callback) { callback(); return 1; }
};
vm.runInNewContext(code, { window: browser, document: doc, CustomEvent }, { timeout: 1200 });
assert.equal(typeof callbacks['ajaxFilters/updated'], 'function', 'Native JSF event should remain subscribed');
assert.strictEqual(group.currentQuery, original, 'Hydration must not replace the native query object');
assert.equal(group.currentQuery['_tax_query_tour-types'], '205', 'Hydration must preserve the numeric term ID');
assert.ok(events.some(x=>x.type==='etg-dfsb/jsf-taxonomy-reconciled' && x.detail.native_query_preserved === true),
  'The compatibility event should still announce the URL snapshot without mutating JSF');

const multi = { '_tax_query_tour-types': ['205', '2661', 'operator_AND'], '_tax_query_destinations': '123' };
group.currentQuery = multi;
browser.location.pathname = '/es/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-types:excursiones-de-un-dia,medio-dia,operator_AND;destinations:cairo/';
callbacks['ajaxFilters/updated']('jet-engine', 'tours_query_archive');
assert.strictEqual(group.currentQuery, multi, 'AND/IN and other native facets must retain their object identity');
assert.equal(group.currentQuery['_tax_query_tour-types'][2], 'operator_AND');
assert.equal(group.currentQuery['_tax_query_destinations'], '123');

const cleared = {};
group.currentQuery = cleared;
browser.location.pathname = '/es/tours-and-activities/';
callbacks['ajaxFilters/updated']('jet-engine', 'tours_query_archive');
assert.strictEqual(group.currentQuery, cleared, 'Unselect must leave the native empty query untouched');
assert.equal(Object.keys(group.currentQuery).length, 0);

console.log('PASS: hydration, multi-term AND, other facets, reset, and compatibility event');
