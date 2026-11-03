'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/ajax-filter-state.js'), 'utf8');
const start = source.indexOf('    function coalesceNativeDirectLinkHydration(');
const end = source.indexOf('\n    function init() {', start);
assert.ok(start !== -1 && end > start, 'coalescer must exist before DFSB init');
const definition = source.slice(start, end);

function harness(gesture = false) {
  const context = {
    userFilterGesture: gesture,
    window: { setTimeout, clearTimeout },
  };
  vm.runInNewContext(definition + '\nthis.install = coalesceNativeDirectLinkHydration;', context);
  const calls = [];
  const original = function (...args) {
    calls.push({ callback: args[0], currentQuery: JSON.parse(JSON.stringify(this.currentQuery)), args });
  };
  const group = { currentQuery: {}, ajaxRequest: original };
  const jsf = { filterGroups: { 'jet-engine/tours_query_archive': group } };
  function install(query) {
    context.install(jsf, { valid: true, group: 'jet-engine/tours_query_archive', query });
  }
  return { group, original, calls, install, context };
}

test('native direct route keeps only the final full term query and callback', async () => {
  const h = harness();
  h.install({ _tax_query_destinations: 'aswan-gov,cairo,el-fayoum' });
  const early = () => 'partial', final = () => 'final';
  h.group.currentQuery = { _tax_query_destinations: ['122','123'] };
  h.group.ajaxRequest(early, h.group.currentQuery);
  h.group.currentQuery = { _tax_query_destinations: ['122','123','721'] };
  h.group.ajaxRequest(final, h.group.currentQuery);
  await new Promise((done) => setTimeout(done, 260));
  assert.equal(h.calls.length, 1);
  assert.equal(h.calls[0].callback, final);
  assert.equal(JSON.stringify(h.calls[0].currentQuery), '{"_tax_query_destinations":["122","123","721"]}');
  assert.equal(h.group.ajaxRequest, h.original);
});

test('single-term direct link does not intercept native AJAX', () => {
  const h = harness();
  h.install({ _tax_query_destinations: 'cairo' });
  assert.equal(h.group.ajaxRequest, h.original);
});

test('trusted user filter gesture flushes the latest query immediately', () => {
  const h = harness();
  h.install({ '_tax_query_tour-types': 'day-tours,half-day,operator_AND' });
  h.group.currentQuery = { '_tax_query_tour-types': ['205'] };
  h.group.ajaxRequest('partial');
  h.context.userFilterGesture = true;
  h.group.currentQuery = { '_tax_query_tour-types': ['205','2661','operator_AND'] };
  h.group.ajaxRequest('final');
  assert.equal(h.calls.length, 1);
  assert.equal(h.calls[0].callback, 'final');
  assert.equal(JSON.stringify(h.calls[0].currentQuery), '{"_tax_query_tour-types":["205","2661","operator_AND"]}');
  assert.equal(h.group.ajaxRequest, h.original);
});

test('untrusted or invalid direct-link state cannot override request methods', () => {
  const h = harness();
  h.context.install({ filterGroups: { 'jet-engine/tours_query_archive': h.group } }, { valid: false, group: 'jet-engine/tours_query_archive', query: { _tax_query_destinations: 'cairo,aswan-gov' } });
  assert.equal(h.group.ajaxRequest, h.original);
});
