(function () {
    'use strict';
    var cfg = window.ETGDFSB_AJAX || {};
    if (!cfg.endpoint) { return; }

    var timers = {};
    var sequences = {};
    var controllers = {};
    var activeKeys = {};
    var initialized = false;
    var initialValues = typeof WeakMap !== 'undefined' ? new WeakMap() : null;

    function groupKey(provider, queryId) {
        return String(provider || '') + '/' + String(queryId || '');
    }

    function groupKeys() {
        var jsf = window.JetSmartFilters;
        return jsf && jsf.filterGroups ? Object.keys(jsf.filterGroups) : [];
    }

    function activeGroupKeys() {
        var jsf = window.JetSmartFilters;
        if (!jsf || !jsf.filterGroups) { return []; }
        return groupKeys().filter(function (key) {
            var group = jsf.filterGroups[key];
            return !!(group && group.currentQuery && Object.keys(group.currentQuery).length);
        });
    }

    function autoGroupKey() {
        var keys = groupKeys();
        if (keys.length === 1) { return keys[0]; }
        var active = activeGroupKeys();
        return active.length === 1 ? active[0] : '';
    }

    function baseArchivePath(path) {
        path = String(path || '/').split('?')[0].split('#')[0] || '/';
        var marker = path.indexOf('/jsf/');
        if (marker !== -1) { path = path.slice(0, marker); }
        path = '/' + path.replace(/^\/+|\/+$/g, '');
        return path === '/' ? '/' : path + '/';
    }

    function remember(el) {
        if (!initialValues || initialValues.has(el)) { return; }
        initialValues.set(el, { html: el.innerHTML, text: el.textContent });
    }

    function elementGroup(el) {
        var own = (el.getAttribute('data-etg-dfsb-group') || '').trim();
        if (own) { return own; }
        if (typeof el.closest === 'function') {
            var parent = el.closest('[data-etg-dfsb-group]');
            if (parent) { return (parent.getAttribute('data-etg-dfsb-group') || '').trim(); }
        }
        return 'auto';
    }

    function elementsFor(selector, key) {
        var auto = autoGroupKey();
        return Array.prototype.filter.call(document.querySelectorAll(selector), function (el) {
            var group = elementGroup(el);
            if (!group || group === 'auto') { return !!auto && auto === key; }
            return group === key;
        });
    }

    function emitAutoGroupStatus() {
        var autoNodes = document.querySelectorAll('[data-etg-dfsb-group="auto"],[data-etg-dfsb-token]:not([data-etg-dfsb-group]),[data-etg-dfsb-slot]:not([data-etg-dfsb-group])');
        if (!autoNodes.length || autoGroupKey()) { return; }
        document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-blocked', {
            detail: { reason: 'ambiguous_auto_group', groups: groupKeys() }
        }));
    }

    function bindings(key) {
        var tokens = [], slots = [];
        elementsFor('[data-etg-dfsb-token]', key).forEach(function (el) {
            remember(el);
            var token = (el.getAttribute('data-etg-dfsb-token') || '').trim().toLowerCase();
            if (token && tokens.indexOf(token) === -1 && tokens.length < (cfg.maxTokens || 100)) { tokens.push(token); }
        });
        elementsFor('[data-etg-dfsb-slot]', key).forEach(function (el) {
            remember(el);
            var slot = (el.getAttribute('data-etg-dfsb-slot') || '').trim().toLowerCase();
            if (slot && slots.indexOf(slot) === -1 && slots.length < (cfg.maxSlots || 50)) { slots.push(slot); }
        });
        return { tokens: tokens, slots: slots };
    }

    function restoreInitial(reason, key) {
        if (!initialValues) { return; }
        elementsFor('[data-etg-dfsb-token],[data-etg-dfsb-slot]', key).forEach(function (el) {
            var original = initialValues.get(el);
            if (!original) { return; }
            el.innerHTML = original.html;
        });
        delete activeKeys[key];
        document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-reset', {
            detail: { reason: reason || 'reset', group: key || '' }
        }));
    }

    function applyValue(el, item) {
        if (!item) { return; }
        var type = item.type || 'text', value = item.value == null ? '' : String(item.value);
        var target = (el.getAttribute('data-etg-dfsb-target') || '').trim().toLowerCase();
        if (target === 'href' || target === 'src') {
            if (type === 'url' && value) { el.setAttribute(target, value); }
            return;
        }
        if (!value) {
            var fallback = (el.getAttribute('data-etg-dfsb-fallback') || '').trim();
            if (fallback) { value = fallback; type = 'text'; }
        }
        if (type === 'html') { el.innerHTML = value; }
        else { el.textContent = value; }
    }

    function applyResponse(data, key) {
        if (!data || data.contract !== cfg.contract || data.authorizing !== false || data.url_authority !== false || data.seo_mutation !== false) { return; }
        if (groupKey(data.provider, data.query_id) !== key) { return; }
        if (data.status !== 'ready' || data.filtered_query_complete !== true) {
            if (activeKeys[key]) { restoreInitial(data.status || 'blocked', key); }
            return;
        }
        activeKeys[key] = true;
        var tokenValues = (data.values && data.values.tokens) || {};
        var slotValues = (data.values && data.values.slots) || {};
        elementsFor('[data-etg-dfsb-token]', key).forEach(function (el) {
            remember(el);
            var token = (el.getAttribute('data-etg-dfsb-token') || '').trim().toLowerCase();
            if (Object.prototype.hasOwnProperty.call(tokenValues, token)) { applyValue(el, tokenValues[token]); }
        });
        elementsFor('[data-etg-dfsb-slot]', key).forEach(function (el) {
            remember(el);
            var slot = (el.getAttribute('data-etg-dfsb-slot') || '').trim().toLowerCase();
            if (Object.prototype.hasOwnProperty.call(slotValues, slot)) { applyValue(el, slotValues[slot]); }
        });
        document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-updated', { detail: data }));
    }

    function send(provider, queryId) {
        var jsf = window.JetSmartFilters;
        if (!jsf || !jsf.filterGroups) { return; }
        var key = groupKey(provider, queryId);
        var group = jsf.filterGroups[key];
        if (!group) { return; }
        var currentQuery = group.currentQuery || {};
        if (!currentQuery || !Object.keys(currentQuery).length) {
            if (activeKeys[key]) { restoreInitial('filters_cleared', key); }
            if (controllers[key]) { controllers[key].abort(); delete controllers[key]; }
            emitAutoGroupStatus();
            return;
        }
        var b = bindings(key);
        if (!b.tokens.length && !b.slots.length) { emitAutoGroupStatus(); return; }
        sequences[key] = (sequences[key] || 0) + 1;
        var requestId = sequences[key];
        if (controllers[key]) { controllers[key].abort(); }
        controllers[key] = typeof AbortController !== 'undefined' ? new AbortController() : null;

        var requestPath = window.location.pathname || '/';
        var options = {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                provider: String(provider || ''),
                query_id: String(queryId || ''),
                request_path: requestPath,
                archive_path: baseArchivePath(requestPath),
                current_query: currentQuery,
                tokens: b.tokens,
                slots: b.slots
            })
        };
        if (controllers[key]) { options.signal = controllers[key].signal; }

        fetch(cfg.endpoint, options).then(function (r) {
            return r.ok ? r.json() : null;
        }).then(function (data) {
            if (requestId !== sequences[key]) { return; }
            applyResponse(data, key);
        }).catch(function (error) {
            if (error && error.name === 'AbortError') { return; }
        }).then(function () {
            if (requestId === sequences[key]) { delete controllers[key]; }
        });
    }

    function schedule(provider, queryId) {
        var key = groupKey(provider, queryId);
        if (timers[key]) { window.clearTimeout(timers[key]); }
        timers[key] = window.setTimeout(function () {
            delete timers[key];
            send(provider, queryId);
        }, 25);
    }

    function init() {
        if (initialized) { return; }
        var jsf = window.JetSmartFilters;
        if (!jsf || !jsf.events || typeof jsf.events.subscribe !== 'function') { return; }
        initialized = true;
        jsf.events.subscribe('ajaxFilters/updated', function (provider, queryId) {
            schedule(provider, queryId);
        });
        groupKeys().forEach(function (key) {
            var parts = key.split('/');
            var group = jsf.filterGroups[key];
            if (parts.length >= 2 && group && group.currentQuery && Object.keys(group.currentQuery).length) {
                schedule(parts[0], parts.slice(1).join('/'));
            }
        });
        emitAutoGroupStatus();
    }

    document.addEventListener('jet-smart-filters/inited', init, { once: true });
    if (window.JetSmartFilters) { window.setTimeout(init, 0); }
}());
