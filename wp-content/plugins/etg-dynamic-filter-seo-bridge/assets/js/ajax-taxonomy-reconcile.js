(function () {
    'use strict';

    var initialized = false;

    function decode(value) {
        try { return decodeURIComponent(String(value || '')); } catch (error) { return ''; }
    }

    function pathState() {
        var path = String(window.location.pathname || '/');
        var marker = path.indexOf('/jsf/');
        if (marker === -1 || path.length > 4096) { return { valid: marker === -1, group: '', query: {} }; }
        var start = marker + 5;
        var first = path.length;
        ['/tax/','/meta/','/date/','/sort/','/alphabet/','/_s/','/search/','/pagenum/'].forEach(function (part) {
            var at = path.indexOf(part, start); if (at !== -1 && at < first) { first = at; }
        });
        var rawGroup = decode(path.slice(start, first).replace(/^\/+|\/+$/g, ''));
        var colon = rawGroup.indexOf(':');
        if (colon <= 0 || colon === rawGroup.length - 1) { return { valid: false, group: '', query: {} }; }
        var provider = rawGroup.slice(0, colon).trim();
        var queryId = rawGroup.slice(colon + 1).trim();
        if (!/^[A-Za-z0-9_-]+$/.test(provider) || !/^[A-Za-z0-9_-]+$/.test(queryId)) { return { valid: false, group: '', query: {} }; }
        var group = provider + '/' + queryId;
        var taxMarker = path.indexOf('/tax/', start);
        if (taxMarker === -1) { return { valid: true, group: group, query: {} }; }
        var taxStart = taxMarker + 5, taxEnd = path.length;
        ['/meta/','/date/','/sort/','/alphabet/','/_s/','/search/','/pagenum/'].forEach(function (part) {
            var at = path.indexOf(part, taxStart); if (at !== -1 && at < taxEnd) { taxEnd = at; }
        });
        var raw = decode(path.slice(taxStart, taxEnd).replace(/^\/+|\/+$/g, ''));
        var query = {}, seen = {};
        if (!raw) { return { valid: true, group: group, query: query }; }
        var pairs = raw.split(';');
        if (pairs.length > 30) { return { valid: false, group: group, query: {} }; }
        for (var i = 0; i < pairs.length; i += 1) {
            var pair = String(pairs[i] || '').trim(); if (!pair) { continue; }
            var split = pair.indexOf(':'); if (split <= 0 || split === pair.length - 1) { return { valid: false, group: group, query: {} }; }
            var taxonomy = pair.slice(0, split).trim(), value = pair.slice(split + 1).trim();
            if (!/^[A-Za-z0-9_-]+$/.test(taxonomy) || !value || value.length > 500 || seen[taxonomy]) { return { valid: false, group: group, query: {} }; }
            seen[taxonomy] = true;
            query['_tax_query_' + taxonomy] = value;
        }
        return { valid: true, group: group, query: query };
    }

    function reconcile(provider, queryId) {
        var jsf = window.JetSmartFilters;
        if (!jsf || !jsf.filterGroups) { return; }
        var key = String(provider || '') + '/' + String(queryId || '');
        var group = jsf.filterGroups[key];
        if (!group || !group.currentQuery || typeof group.currentQuery !== 'object') { return; }
        var state = pathState();
        if (!state.valid || !state.group || state.group !== key) { return; }
        var next = {};
        Object.keys(group.currentQuery).forEach(function (name) {
            if (name === 'tax_query' || name.indexOf('_tax_query_') === 0) { return; }
            next[name] = group.currentQuery[name];
        });
        Object.keys(state.query).forEach(function (name) { next[name] = state.query[name]; });
        group.currentQuery = next;
        document.dispatchEvent(new CustomEvent('etg-dfsb/jsf-taxonomy-reconciled', { detail: { group: key, taxonomy_query: state.query } }));
    }

    function reconcileKey(key) {
        var parts = String(key || '').split('/');
        if (parts.length < 2) { return; }
        reconcile(parts[0], parts.slice(1).join('/'));
    }

    function init() {
        if (initialized) { return; }
        var jsf = window.JetSmartFilters;
        if (!jsf || !jsf.events || typeof jsf.events.subscribe !== 'function' || !jsf.filterGroups) { return; }
        initialized = true;
        Object.keys(jsf.filterGroups).forEach(reconcileKey);
        jsf.events.subscribe('ajaxFilters/updated', function (provider, queryId) {
            reconcile(provider, queryId);
            window.setTimeout(function () { reconcile(provider, queryId); }, 0);
            window.setTimeout(function () { reconcile(provider, queryId); }, 10);
        });
    }

    document.addEventListener('jet-smart-filters/inited', init, { once: true });
    if (window.JetSmartFilters) { window.setTimeout(init, 0); }
}());
