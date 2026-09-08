(function () {
    'use strict';
    var cfg = window.ETGDFSB_AJAX || {};
    if (!cfg.endpoint) { return; }

    var timers = {}, sequences = {}, controllers = {}, activeKeys = {}, eventSeenKeys = {};
    var initialized = false, runtimeBlockedSignature = '';
    var initialValues = typeof WeakMap !== 'undefined' ? new WeakMap() : null;
    var boundGroups = typeof WeakMap !== 'undefined' ? new WeakMap() : null;
    var groupRetryState = { pathGroup: '', attempts: 0, timer: null };

    function boundedPositiveInt(value, fallback, maximum) { value = Number(value); if (!isFinite(value) || value <= 0) { value = fallback; } return Math.min(Math.floor(value), maximum); }
    var requestTimeoutMs = boundedPositiveInt(cfg.timeoutMs, 8000, 8000);
    var groupRetryAttempts = boundedPositiveInt(cfg.groupRetryAttempts, 4, 8);
    var groupRetryDelayMs = boundedPositiveInt(cfg.groupRetryDelayMs, 125, 1000);

    function jsfRuntimeContract() {
        var jsf = window.JetSmartFilters, reasons = [], version = String(cfg.jsfVersion || 'unknown').trim() || 'unknown';
        var supported = Array.isArray(cfg.supportedJsfVersions) ? cfg.supportedJsfVersions.map(String) : [];
        if (!jsf || typeof jsf !== 'object') { reasons.push('jsf_global_unavailable'); }
        if (!jsf || !jsf.events || typeof jsf.events.subscribe !== 'function') { reasons.push('jsf_events_contract_unavailable'); }
        if (!jsf || !jsf.filterGroups || typeof jsf.filterGroups !== 'object') { reasons.push('jsf_filter_groups_contract_unavailable'); }
        if ('unknown' === version) { reasons.push('jsf_version_unverified'); }
        else if (supported.length && supported.indexOf(version) === -1) { reasons.push('jsf_version_unsupported'); }
        if (jsf && jsf.filterGroups && typeof jsf.filterGroups === 'object') {
            Object.keys(jsf.filterGroups).forEach(function (key) {
                var group = jsf.filterGroups[key];
                if (!group || typeof group !== 'object') { reasons.push('jsf_group_shape_invalid'); return; }
                if (typeof group.currentQuery !== 'undefined' && (!group.currentQuery || typeof group.currentQuery !== 'object')) { reasons.push('jsf_current_query_shape_invalid'); }
            });
        }
        reasons = reasons.filter(function (reason, index) { return reasons.indexOf(reason) === index; });
        return { ready: reasons.length === 0, version: version, supported_versions: supported, reasons: reasons };
    }

    function emitRuntimeContractBlocked(report) {
        report = report || jsfRuntimeContract();
        var signature = report.version + '|' + report.reasons.join(',');
        if (signature === runtimeBlockedSignature) { return; }
        runtimeBlockedSignature = signature;
        Object.keys(activeKeys).forEach(function (key) { restoreInitial('jsf_runtime_contract_unavailable', key); });
        document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-blocked', { detail: { reason: 'jsf_runtime_contract_unavailable', jsf_version: report.version, supported_jsf_versions: report.supported_versions, blocking_reasons: report.reasons } }));
    }

    function groupKey(provider, queryId) { return String(provider || '') + '/' + String(queryId || ''); }
    function groupKeys() { var jsf = window.JetSmartFilters; return jsf && jsf.filterGroups ? Object.keys(jsf.filterGroups) : []; }
    function decodePathPart(value) { try { return decodeURIComponent(String(value || '')); } catch (error) { return ''; } }

    function prettyPathState() {
        var path = String(window.location.pathname || '/');
        if (path.length > 4096) { return { group: '', query: {}, valid: false, reason: 'pretty_path_too_large' }; }
        var jsfMarker = path.indexOf('/jsf/');
        if (jsfMarker === -1) { return { group: '', query: {}, valid: true, reason: 'no_pretty_jsf_path' }; }
        var providerStart = jsfMarker + '/jsf/'.length, taxMarker = path.indexOf('/tax/', providerStart);
        var providerEnd = taxMarker === -1 ? path.indexOf('/', providerStart) : taxMarker; if (providerEnd === -1) { providerEnd = path.length; }
        var providerRaw = decodePathPart(path.slice(providerStart, providerEnd).replace(/^\/+|\/+$/g, '')), colon = providerRaw.indexOf(':');
        if (colon <= 0 || colon === providerRaw.length - 1) { return { group: '', query: {}, valid: false, reason: 'pretty_path_group_malformed' }; }
        var provider = providerRaw.slice(0, colon).trim(), queryId = providerRaw.slice(colon + 1).trim();
        if (!/^[A-Za-z0-9_-]+$/.test(provider) || !/^[A-Za-z0-9_-]+$/.test(queryId)) { return { group: '', query: {}, valid: false, reason: 'pretty_path_group_malformed' }; }
        var key = groupKey(provider, queryId);
        if (taxMarker === -1) { return { group: key, query: {}, valid: true, reason: 'pretty_url_group' }; }
        var encodedTax = path.slice(taxMarker + '/tax/'.length).replace(/^\/+|\/+$/g, ''), taxRaw = decodePathPart(encodedTax);
        if (!taxRaw && encodedTax) { return { group: key, query: {}, valid: false, reason: 'pretty_path_tax_decode_failed' }; }
        var pairs = taxRaw ? taxRaw.split(';') : [];
        if (pairs.length > 30) { return { group: key, query: {}, valid: false, reason: 'pretty_path_filter_limit_exceeded' }; }
        var query = {}, seen = {};
        for (var i = 0; i < pairs.length; i += 1) {
            var pair = String(pairs[i] || '').trim(); if (!pair) { continue; }
            var pairColon = pair.indexOf(':'); if (pairColon <= 0 || pairColon === pair.length - 1) { return { group: key, query: {}, valid: false, reason: 'pretty_path_filter_malformed' }; }
            var taxonomy = pair.slice(0, pairColon).trim(), value = pair.slice(pairColon + 1).trim();
            if (!/^[A-Za-z0-9_-]+$/.test(taxonomy) || !value || value.length > 500 || seen[taxonomy]) { return { group: key, query: {}, valid: false, reason: seen[taxonomy] ? 'pretty_path_filter_duplicate' : 'pretty_path_filter_malformed' }; }
            seen[taxonomy] = true; query['_tax_query_' + taxonomy] = value;
        }
        return { group: key, query: query, valid: true, reason: 'pretty_url_group' };
    }

    function pathDeclaredGroupKey() { var state = prettyPathState(); return state.valid ? state.group : ''; }
    function queryHasSemanticFilters(query) { if (!query || typeof query !== 'object') { return false; } return Object.keys(query).some(function (key) { return key === 'tax_query' || key === 'meta_query' || key === 'date_query' || key === 's' || key.indexOf('_tax_query_') === 0 || key.indexOf('_meta_query_') === 0 || key.indexOf('_date_query_') === 0 || key.indexOf('__s_query') === 0 || key.indexOf('_alphabet_') === 0; }); }
    function activeGroupKeys() { var jsf = window.JetSmartFilters; if (!jsf || !jsf.filterGroups) { return []; } return groupKeys().filter(function (key) { var group = jsf.filterGroups[key]; return !!(group && queryHasSemanticFilters(group.currentQuery)); }); }

    function autoGroupResolution() {
        var keys = groupKeys(), pathState = prettyPathState();
        if (!pathState.valid) { return { key: '', reason: pathState.reason, groups: keys, path_group: pathState.group || '' }; }
        if (pathState.group) { if (keys.indexOf(pathState.group) !== -1) { return { key: pathState.group, reason: 'pretty_url_group', groups: keys, path_group: pathState.group }; } return { key: '', reason: 'url_group_unavailable', groups: keys, path_group: pathState.group }; }
        var active = activeGroupKeys(); if (active.length === 1) { return { key: active[0], reason: 'single_active_group', groups: keys, path_group: '' }; }
        if (keys.length === 1) { return { key: keys[0], reason: 'single_available_group', groups: keys, path_group: '' }; }
        return { key: '', reason: 'ambiguous_auto_group', groups: keys, path_group: '' };
    }
    function autoGroupKey() { return autoGroupResolution().key; }
    function resetGroupRetry() { if (groupRetryState.timer) { window.clearTimeout(groupRetryState.timer); } groupRetryState = { pathGroup: '', attempts: 0, timer: null }; }
    function scheduleUrlGroupRetry(resolution) {
        var pathGroup = String((resolution && resolution.path_group) || ''); if (!pathGroup) { return false; }
        if (groupRetryState.pathGroup !== pathGroup) { resetGroupRetry(); groupRetryState.pathGroup = pathGroup; }
        if (groupRetryState.attempts >= groupRetryAttempts) { return false; } if (groupRetryState.timer) { return true; }
        groupRetryState.attempts += 1; groupRetryState.timer = window.setTimeout(function () { groupRetryState.timer = null; var next = autoGroupResolution(); if (next.key) { resetGroupRetry(); syncAutoBindings(); scheduleKey(next.key); return; } emitAutoGroupStatus(); }, groupRetryDelayMs); return true;
    }
    function baseArchivePath(path) { path = String(path || '/').split('?')[0].split('#')[0] || '/'; var marker = path.indexOf('/jsf/'); if (marker !== -1) { path = path.slice(0, marker); } path = '/' + path.replace(/^\/+|\/+$/g, ''); return path === '/' ? '/' : path + '/'; }
    function effectiveCurrentQuery(key, group) { var currentQuery = group && group.currentQuery && typeof group.currentQuery === 'object' ? group.currentQuery : {}; if (queryHasSemanticFilters(currentQuery) || eventSeenKeys[key]) { return currentQuery; } var state = prettyPathState(); if (state.valid && state.group === key && queryHasSemanticFilters(state.query)) { return state.query; } return currentQuery; }

    function remember(el) {
        if (!initialValues || initialValues.has(el)) { return; }
        initialValues.set(el, { html: el.innerHTML, text: el.textContent, href: el.hasAttribute('href') ? el.getAttribute('href') : null, src: el.hasAttribute('src') ? el.getAttribute('src') : null, srcset: el.hasAttribute('srcset') ? el.getAttribute('srcset') : null, gallery: el.hasAttribute('data-etg-dfsb-gallery') ? el.getAttribute('data-etg-dfsb-gallery') : null, backgroundImage: el.style && typeof el.style.backgroundImage !== 'undefined' ? el.style.backgroundImage : null });
    }
    function restoreAttribute(el, name) { if (!initialValues) { return; } var original = initialValues.get(el); if (!original) { return; } var value = original[name]; if (value === null || typeof value === 'undefined') { el.removeAttribute(name); } else { el.setAttribute(name, value); } }
    function syncSectionVisibility(el) { if (!el || typeof el.closest !== 'function') { return; } var section = el.closest('[data-etg-dfsb-live-section]'); if (!section) { return; } var text = (section.textContent || '').replace(/\s+/g, ' ').trim(); if (text) { section.removeAttribute('hidden'); } else { section.setAttribute('hidden', 'hidden'); } }
    function restoreElement(el) { if (!initialValues) { return; } var original = initialValues.get(el); if (!original) { return; } el.innerHTML = original.html; restoreAttribute(el, 'href'); restoreAttribute(el, 'src'); restoreAttribute(el, 'srcset'); restoreAttribute(el, 'data-etg-dfsb-gallery'); if (el.style && original.backgroundImage !== null) { el.style.backgroundImage = original.backgroundImage; } if (boundGroups) { boundGroups.delete(el); } syncSectionVisibility(el); }
    function elementGroup(el) { var own = (el.getAttribute('data-etg-dfsb-group') || '').trim(); if (own) { return own; } if (typeof el.closest === 'function') { var parent = el.closest('[data-etg-dfsb-group]'); if (parent) { return (parent.getAttribute('data-etg-dfsb-group') || '').trim(); } } return 'auto'; }
    function elementsFor(selector, key) { var auto = autoGroupKey(); return Array.prototype.filter.call(document.querySelectorAll(selector), function (el) { var group = elementGroup(el); if (!group || group === 'auto') { return !!auto && auto === key; } return group === key; }); }

    function allBindingSelector() { return '[data-etg-dfsb-token],[data-etg-dfsb-slot],[data-etg-dfsb-media-slot]'; }
    function emitAutoGroupStatus() {
        var autoNodes = document.querySelectorAll('[data-etg-dfsb-group="auto"],[data-etg-dfsb-token]:not([data-etg-dfsb-group]),[data-etg-dfsb-slot]:not([data-etg-dfsb-group]),[data-etg-dfsb-media-slot]:not([data-etg-dfsb-group])');
        if (!autoNodes.length) { return; } var resolution = autoGroupResolution(); if (resolution.key) { resetGroupRetry(); return; }
        if (resolution.reason === 'url_group_unavailable' && scheduleUrlGroupRetry(resolution)) { return; } if (resolution.reason !== 'url_group_unavailable') { resetGroupRetry(); }
        document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-blocked', { detail: { reason: resolution.reason, groups: resolution.groups, path_group: resolution.path_group || '', retry_attempts: resolution.reason === 'url_group_unavailable' ? groupRetryState.attempts : 0 } }));
    }

    function bindings(key) {
        var tokens = [], slots = [];
        elementsFor('[data-etg-dfsb-token]', key).forEach(function (el) { remember(el); var token = (el.getAttribute('data-etg-dfsb-token') || '').trim(); if (token && tokens.indexOf(token) === -1 && tokens.length < (cfg.maxTokens || 100)) { tokens.push(token); } });
        elementsFor('[data-etg-dfsb-slot]', key).forEach(function (el) { remember(el); var slot = (el.getAttribute('data-etg-dfsb-slot') || '').trim().toLowerCase(); if (slot && slots.indexOf(slot) === -1 && slots.length < (cfg.maxSlots || 50)) { slots.push(slot); } });
        elementsFor('[data-etg-dfsb-media-slot]', key).forEach(function (el) { remember(el); var slot = (el.getAttribute('data-etg-dfsb-media-slot') || '').trim().toLowerCase(); if (slot && slots.indexOf(slot) === -1 && slots.length < (cfg.maxSlots || 50)) { slots.push(slot); } });
        return { tokens: tokens, slots: slots };
    }

    function restoreInitial(reason, key) { if (!initialValues) { return; } Array.prototype.forEach.call(document.querySelectorAll(allBindingSelector()), function (el) { var bound = boundGroups ? boundGroups.get(el) : ''; if (bound === key || (!bound && elementsFor(allBindingSelector(), key).indexOf(el) !== -1)) { restoreElement(el); } }); delete activeKeys[key]; document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-reset', { detail: { reason: reason || 'reset', group: key || '' } })); }
    function syncAutoBindings() { var resolved = autoGroupKey(); if (resolved) { resetGroupRetry(); } Array.prototype.forEach.call(document.querySelectorAll(allBindingSelector()), function (el) { var declared = elementGroup(el); if (declared !== 'auto') { return; } remember(el); var previous = boundGroups ? boundGroups.get(el) : ''; if (previous && previous !== resolved) { restoreElement(el); } }); emitAutoGroupStatus(); return resolved; }

    function applyValue(el, item) {
        if (!item) { return; } var type = item.type || 'text', value = item.value == null ? '' : String(item.value); var target = (el.getAttribute('data-etg-dfsb-target') || '').trim().toLowerCase();
        if (target === 'href') { if (type === 'url' && value) { el.setAttribute('href', value); } else { restoreAttribute(el, 'href'); } return; }
        if (target === 'src') { var imageUrl = item.image && item.image.url ? String(item.image.url) : (type === 'url' ? value : ''); if (imageUrl) { el.setAttribute('src', imageUrl); el.removeAttribute('srcset'); } else { restoreAttribute(el, 'src'); restoreAttribute(el, 'srcset'); } return; }
        if (!value) { var fallback = (el.getAttribute('data-etg-dfsb-fallback') || '').trim(); if (fallback) { value = fallback; type = 'text'; } }
        if (type === 'html') { el.innerHTML = value; } else { el.textContent = value; } syncSectionVisibility(el);
    }

    function applyMedia(el, item, slot, key) {
        if (!item) { return; }
        var target = (el.getAttribute('data-etg-dfsb-media-target') || 'auto').trim().toLowerCase();
        var image = item.image && typeof item.image === 'object' ? item.image : null;
        var gallery = Array.isArray(item.gallery) ? item.gallery.slice(0, 30) : [];
        if (!image && gallery.length) { image = gallery[0]; }
        var url = image && image.url ? String(image.url) : '';
        if (target === 'auto') { target = String(el.tagName || '').toLowerCase() === 'img' ? 'src' : 'background'; }
        if (target === 'src') { if (url) { el.setAttribute('src', url); el.removeAttribute('srcset'); if (image.id) { el.setAttribute('data-etg-dfsb-attachment-id', String(image.id)); } } else { restoreAttribute(el, 'src'); restoreAttribute(el, 'srcset'); } }
        else if (target === 'background') { if (el.style) { el.style.backgroundImage = url ? 'url("' + url.replace(/"/g, '%22') + '")' : ''; } }
        else if (target === 'gallery') { var json = JSON.stringify(gallery); el.setAttribute('data-etg-dfsb-gallery', json); }
        if (boundGroups) { boundGroups.set(el, key); }
        document.dispatchEvent(new CustomEvent('etg-dfsb/media-updated', { detail: { group: key, slot: slot, target: target, image: image || { id: 0, url: '' }, gallery: gallery, element: el } }));
    }

    function applyResponse(data, key) {
        if (!data || data.contract !== cfg.contract || data.authorizing !== false || data.url_authority !== false || data.seo_mutation !== false) { return; }
        if (groupKey(data.provider, data.query_id) !== key) { return; }
        if (data.status !== 'ready' || data.filtered_query_complete !== true) { if (activeKeys[key]) { restoreInitial(data.status || 'blocked', key); } document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-blocked', { detail: { reason: data.status || 'blocked', group: key, blocking_reasons: data.blocking_reasons || [] } })); return; }
        activeKeys[key] = true; var tokenValues = (data.values && data.values.tokens) || {}, slotValues = (data.values && data.values.slots) || {};
        elementsFor('[data-etg-dfsb-token]', key).forEach(function (el) { remember(el); var token = (el.getAttribute('data-etg-dfsb-token') || '').trim(); if (Object.prototype.hasOwnProperty.call(tokenValues, token)) { applyValue(el, tokenValues[token]); if (boundGroups) { boundGroups.set(el, key); } } });
        elementsFor('[data-etg-dfsb-slot]', key).forEach(function (el) { remember(el); var slot = (el.getAttribute('data-etg-dfsb-slot') || '').trim().toLowerCase(); if (Object.prototype.hasOwnProperty.call(slotValues, slot)) { applyValue(el, slotValues[slot]); if (boundGroups) { boundGroups.set(el, key); } } });
        elementsFor('[data-etg-dfsb-media-slot]', key).forEach(function (el) { remember(el); var slot = (el.getAttribute('data-etg-dfsb-media-slot') || '').trim().toLowerCase(); if (Object.prototype.hasOwnProperty.call(slotValues, slot)) { applyMedia(el, slotValues[slot], slot, key); } });
        document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-updated', { detail: data }));
    }

    function readHttpResponse(response) { return response.text().then(function (text) { var data = null, invalidJson = false; if (text) { try { data = JSON.parse(text); } catch (error) { invalidJson = true; } } return { ok: response.ok, status: response.status, data: data, invalidJson: invalidJson }; }); }
    function send(provider, queryId) {
        var contract = jsfRuntimeContract(); if (!contract.ready) { emitRuntimeContractBlocked(contract); return; } runtimeBlockedSignature = '';
        var jsf = window.JetSmartFilters, key = groupKey(provider, queryId), group = jsf.filterGroups[key]; if (!group) { return; }
        var currentQuery = effectiveCurrentQuery(key, group);
        if (!queryHasSemanticFilters(currentQuery)) { if (activeKeys[key]) { restoreInitial('filters_cleared', key); } if (controllers[key]) { controllers[key].abort(); delete controllers[key]; } var resolvedAfterClear = syncAutoBindings(); if (resolvedAfterClear && resolvedAfterClear !== key) { scheduleKey(resolvedAfterClear); } return; }
        var b = bindings(key); if (!b.tokens.length && !b.slots.length) { emitAutoGroupStatus(); return; }
        sequences[key] = (sequences[key] || 0) + 1; var requestId = sequences[key]; if (controllers[key]) { controllers[key].abort(); } controllers[key] = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var requestPath = window.location.pathname || '/';
        var options = { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ provider: String(provider || ''), query_id: String(queryId || ''), request_path: requestPath, archive_path: baseArchivePath(requestPath), current_query: currentQuery, tokens: b.tokens, slots: b.slots }) };
        if (controllers[key]) { options.signal = controllers[key].signal; }
        var timedOut = false, timeoutId = null, transport = fetch(cfg.endpoint, options).then(readHttpResponse);
        var deadline = new Promise(function (resolve, reject) { timeoutId = window.setTimeout(function () { timedOut = true; if (controllers[key]) { controllers[key].abort(); } var error = new Error('ETG AJAX presentation timeout'); error.name = 'ETGTimeoutError'; reject(error); }, requestTimeoutMs); });
        Promise.race([transport, deadline]).then(function (result) {
            if (requestId !== sequences[key]) { return; }
            if (!result.ok) { document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-blocked', { detail: { reason: 'http_error', http_status: result.status, group: key } })); return; }
            if (result.invalidJson || !result.data) { document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-blocked', { detail: { reason: 'invalid_response', http_status: result.status, group: key } })); return; }
            applyResponse(result.data, key);
        }).catch(function (error) {
            if (requestId !== sequences[key]) { return; }
            if (timedOut || (error && error.name === 'ETGTimeoutError')) { document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-blocked', { detail: { reason: 'timeout', timeout_ms: requestTimeoutMs, group: key } })); return; }
            if (error && error.name === 'AbortError') { return; }
            document.dispatchEvent(new CustomEvent('etg-dfsb/ajax-presentation-blocked', { detail: { reason: 'transport_error', group: key } }));
        }).then(function () { if (timeoutId) { window.clearTimeout(timeoutId); } if (requestId === sequences[key]) { delete controllers[key]; } });
    }

    function schedule(provider, queryId) { var key = groupKey(provider, queryId); if (timers[key]) { window.clearTimeout(timers[key]); } timers[key] = window.setTimeout(function () { delete timers[key]; send(provider, queryId); }, 25); }
    function scheduleKey(key) { var parts = String(key || '').split('/'); if (parts.length < 2) { return; } schedule(parts[0], parts.slice(1).join('/')); }
    function init() {
        if (initialized) { return; } var contract = jsfRuntimeContract(); if (!contract.ready) { emitRuntimeContractBlocked(contract); return; } runtimeBlockedSignature = '';
        var jsf = window.JetSmartFilters; initialized = true;
        jsf.events.subscribe('ajaxFilters/updated', function (provider, queryId) { var key = groupKey(provider, queryId); eventSeenKeys[key] = true; var resolved = syncAutoBindings(); schedule(provider, queryId); if (resolved && resolved !== key) { scheduleKey(resolved); } });
        var pathState = prettyPathState(); if (pathState.valid && pathState.group && groupKeys().indexOf(pathState.group) !== -1 && queryHasSemanticFilters(pathState.query)) { scheduleKey(pathState.group); }
        groupKeys().forEach(function (key) { var parts = key.split('/'), group = jsf.filterGroups[key]; if (parts.length >= 2 && group && queryHasSemanticFilters(group.currentQuery)) { schedule(parts[0], parts.slice(1).join('/')); } });
        syncAutoBindings();
    }

    document.addEventListener('jet-smart-filters/inited', init, { once: true });
    if (window.JetSmartFilters) { window.setTimeout(init, 0); }
}());
