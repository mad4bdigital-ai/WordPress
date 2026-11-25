(function () {
    'use strict';
    var cfg = window.DFSB_AJAX || window.ETGDFSB_AJAX || {};
    // Expand phase: new consumers subscribe to dfsb/*; old saved scripts still
    // receive exactly one etg-dfsb/* event with the same payload.
    function emitDFSBEvent(name, detail) {
        if (name.indexOf('dfsb/ajax-presentation-') === 0 && detail) {
            detail = Object.assign({}, detail, { editorial_auto_group: autoGroupKey() });
        }
        document.dispatchEvent(new CustomEvent(name, { detail: detail }));
        if (name.indexOf('dfsb/') === 0) {
            document.dispatchEvent(new CustomEvent('etg-' + name, { detail: detail }));
        }
    }
    if (!cfg.endpoint) { return; }

    // Native JSF checkboxes already support data-relational-operator="AND".
    // On a direct URL with an explicit, valid operator, provide that native
    // configuration BEFORE JSF creates filter instances. This is a DOM input
    // option, not an overwrite of JSF's currentQuery, signed AJAX or counts.
    function configureNativeDirectLinkOperators() {
        var state = prettyPathState();
        if (!state.valid || !state.group || !state.query) { return; }
        var supported = Array.isArray(cfg.supportedJsfVersions) ? cfg.supportedJsfVersions.map(String) : [];
        if (supported.indexOf(String(cfg.jsfVersion || '')) === -1) { return; }
        var separator = state.group.indexOf('/');
        if (separator <= 0) { return; }
        var provider = state.group.slice(0, separator);
        var queryId = state.group.slice(separator + 1);
        Object.keys(state.query).forEach(function (key) {
            if (key.indexOf('_tax_query_') !== 0) { return; }
            var data = state.query[key];
            // Local Staging parser historically supplied comma-separated strings;
            // the canonical #733 parser supplies arrays for explicit operators.
            // Accept both validated forms without assigning native currentQuery.
            var parts = Array.isArray(data) ? data.map(String) :
                (typeof data === 'string' ? data.split(',').map(function (v) { return v.trim(); }) : []);
            if (parts.length < 3 || parts.length > 21) { return; }
            var op = parts[parts.length - 1];
            if (op !== 'operator_AND' && op !== 'operator_IN') { return; }
            var selected = parts.slice(0, -1);
            if (selected.length < 2 ||
                selected.some(function (v) { return !/^[A-Za-z0-9_-]{1,100}$/.test(v) || /^operator_/i.test(v); }) ||
                selected.filter(function (v, i) { return selected.indexOf(v) === i; }).length !== selected.length) { return; }
            var taxonomy = key.slice('_tax_query_'.length);
            Array.prototype.forEach.call(document.querySelectorAll('.jet-checkboxes-list[data-smart-filter="checkboxes"][data-query-type="tax_query"]'), function (el) {
                if (el.getAttribute('data-query-var') !== taxonomy || el.getAttribute('data-content-provider') !== provider || el.getAttribute('data-query-id') !== queryId) { return; }
                var options = Array.prototype.map.call(el.querySelectorAll('input[data-url-value]'), function (input) {
                    return String(input.getAttribute('data-url-value') || '');
                });
                if (!selected.every(function (slug) { return options.indexOf(slug) !== -1; })) { return; }
                // Native checkbox processData appends operator_AND only when
                // two or more actual inputs are selected; allow it to do so.
                if (op === 'operator_AND') { el.setAttribute('data-relational-operator', 'AND'); }
                else { el.removeAttribute('data-relational-operator'); }
            });
        });
    }
    document.addEventListener('jet-smart-filters/before-init', configureNativeDirectLinkOperators, { capture: true, once: true });

    var timers = {}, sequences = {}, controllers = {}, activeKeys = {}, eventSeenKeys = {}, initialSemanticKeys = {};
    var nativePending = Object.create(null), prepared = Object.create(null);
    var initialized = false, runtimeBlockedSignature = '';
    var userFilterGesture = false;
    // JSF can dispatch filter/apply and start-loading while hydrating a direct
    // URL, without ever dispatching updated. Distinguish user interactions
    // from initial runtime activation using trusted native DOM gestures.
    function trackUserFilterGesture(event) {
        if (!event.isTrusted || !event.target || typeof event.target.closest !== 'function') { return; }
        if (event.target.closest('[class*="jet-smart-filters"],[class*="jet-remove-filter"],[class*="jet-filters"]')) {
            userFilterGesture = true;
        }
    }
    document.addEventListener('click', trackUserFilterGesture, true);
    document.addEventListener('pointerdown', trackUserFilterGesture, true);
    document.addEventListener('keydown', trackUserFilterGesture, true);
    // A deep-linked JSF hydration may emit browser-trusted change events
    // without any user action. Do not use change alone as interaction proof.
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
        Object.keys(sequences).forEach(invalidatePresentationRequest);
        Object.keys(nativePending).forEach(discardNativePending);
        Object.keys(activeKeys).forEach(function (key) { clearTransient('jsf_runtime_contract_unavailable', key); });
        emitDFSBEvent('dfsb/ajax-presentation-blocked', { reason: 'jsf_runtime_contract_unavailable', jsf_version: report.version, supported_jsf_versions: report.supported_versions, blocking_reasons: report.reasons });
    }

    function groupKey(provider, queryId) { return String(provider || '') + '/' + String(queryId || ''); }
    function groupKeys() { var jsf = window.JetSmartFilters; return jsf && jsf.filterGroups ? Object.keys(jsf.filterGroups) : []; }
    function decodePathPart(value) { try { return decodeURIComponent(String(value || '')); } catch (error) { return ''; } }

    function prettyPathState() {
        var path = String(window.location.pathname || '/');
        if (path.length > 4096) { return { group: '', query: {}, valid: false, reason: 'pretty_path_too_large' }; }
        var jsfMarker = path.indexOf('/jsf/');
        if (jsfMarker === -1) { return { group: '', query: {}, valid: true, reason: 'no_pretty_jsf_path' }; }
        var providerStart = jsfMarker + '/jsf/'.length, providerEnd = path.length;
        ['/tax/','/meta/','/date/','/sort/','/alphabet/','/_s/','/search/','/pagenum/'].forEach(function (part) {
            var at = path.indexOf(part, providerStart); if (at !== -1 && at < providerEnd) { providerEnd = at; }
        });
        var providerRaw = decodePathPart(path.slice(providerStart, providerEnd).replace(/^\/+|\/+$/g, '')), colon = providerRaw.indexOf(':');
        if (colon <= 0 || colon === providerRaw.length - 1) { return { group: '', query: {}, valid: false, reason: 'pretty_path_group_malformed' }; }
        var provider = providerRaw.slice(0, colon).trim(), queryId = providerRaw.slice(colon + 1).trim();
        if (!/^[A-Za-z0-9_-]+$/.test(provider) || !/^[A-Za-z0-9_-]+$/.test(queryId)) { return { group: '', query: {}, valid: false, reason: 'pretty_path_group_malformed' }; }
        var key = groupKey(provider, queryId), taxMarker = path.indexOf('/tax/', providerStart);
        if (taxMarker === -1) { return { group: key, query: {}, valid: true, reason: 'pretty_url_group' }; }
        var taxStart = taxMarker + '/tax/'.length, taxEnd = path.length;
        ['/meta/','/date/','/sort/','/alphabet/','/_s/','/search/','/pagenum/'].forEach(function (part) {
            var at = path.indexOf(part, taxStart); if (at !== -1 && at < taxEnd) { taxEnd = at; }
        });
        var encodedTax = path.slice(taxStart, taxEnd).replace(/^\/+|\/+$/g, ''), taxRaw = decodePathPart(encodedTax);
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
        // A neutral filter state can expose several JSF provider groups. Use
        // the explicit Elementor binding ONLY if precisely one existing group
        // is declared; do not guess from provider order or stale filter state.
        var declared = [];
        Array.prototype.forEach.call(document.querySelectorAll('[data-etg-dfsb-group]'), function (el) {
            var group = (el.getAttribute('data-etg-dfsb-group') || '').trim();
            if (group !== 'auto' && keys.indexOf(group) !== -1 && declared.indexOf(group) === -1) { declared.push(group); }
        });
        if (declared.length === 1) { return { key: declared[0], reason: 'single_declared_group', groups: keys, path_group: '' }; }
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
    function baseArchivePath(path) {
        path = String(path || '/').split('?')[0].split('#')[0] || '/';
        var marker = path.indexOf('/jsf/'); if (marker !== -1) { path = path.slice(0, marker); }
        path = '/' + path.replace(/^\/+|\/+$/g, '');
        if (path !== '/') { return path + '/'; }
        // WordPress can render a CPT archive on /?post_type=slug rather than
        // /slug/. This is a non-authorizing presentation hint: the REST server
        // still validates archive, provider, query, taxonomy and signed state.
        var query = new URLSearchParams(window.location.search || '');
        var postType = String(query.get('post_type') || '');
        return /^[a-z0-9_-]{1,80}$/.test(postType) ? '/' + postType + '/' : '/';
    }
    function effectiveCurrentQuery(key, group) {
        var currentQuery = group && group.currentQuery && typeof group.currentQuery === 'object' ? group.currentQuery : {};
        var state = prettyPathState();
        // At direct URL hydration JetSmartFilters may initialize only one of
        // several taxonomy facets (e.g. half-day but not Cairo). Reconstruct
        // the full *presentation* state from the validated deep-link path
        // until a live filter event is seen; after that, JSF's observed query
        // replaces the URL snapshot. Backend scope checks remain authoritative.
        if (!eventSeenKeys[key] && state.valid && state.group === key && queryHasSemanticFilters(state.query)) {
            return state.query;
        }
        return currentQuery;
    }

    // The historic value wins on conflicting cached markup. Both names are
    // updated together and reset from the same initial snapshot.
    function readGalleryAttribute(el) {
        return readPresentationAttribute(el, 'gallery');
    }
    function writeGalleryAttribute(el, value) {
        writePresentationAttribute(el, 'gallery', value);
    }
    function readPresentationAttribute(el, key) {
        var legacy = 'data-etg-dfsb-' + key;
        return el.hasAttribute(legacy) ? el.getAttribute(legacy) : el.getAttribute('data-dfsb-' + key);
    }
    function writePresentationAttribute(el, key, value) {
        ['data-dfsb-' + key, 'data-etg-dfsb-' + key].forEach(function (name) {
            if (value === null) { el.removeAttribute(name); }
            else { el.setAttribute(name, value); }
        });
    }
    function isBackgroundContainer(el) {
        return readPresentationAttribute(el, 'background-source') !== null;
    }
    function templateGallery(el) {
        var raw = readPresentationAttribute(el, 'background-template-gallery');
        if (raw === null) return null;
        try { var items = JSON.parse(raw); return Array.isArray(items) ? items : []; } catch (error) { return []; }
    }
    function remember(el) {
        if (!initialValues || initialValues.has(el)) { return; }
        initialValues.set(el, { html: isBackgroundContainer(el) ? null : el.innerHTML, text: isBackgroundContainer(el) ? null : el.textContent, href: el.hasAttribute('href') ? el.getAttribute('href') : null, src: el.hasAttribute('src') ? el.getAttribute('src') : null, srcset: el.hasAttribute('srcset') ? el.getAttribute('srcset') : null, gallery: readGalleryAttribute(el), attachmentId: readPresentationAttribute(el, 'attachment-id'), backgroundImage: el.style && typeof el.style.backgroundImage !== 'undefined' ? el.style.backgroundImage : null });
    }
    function restoreAttribute(el, name) { if (!initialValues) { return; } var original = initialValues.get(el); if (!original) { return; } var value = original[name]; if (value === null || typeof value === 'undefined') { el.removeAttribute(name); } else { el.setAttribute(name, value); } }
    function syncSectionVisibility(el) { if (!el || typeof el.closest !== 'function') { return; } var section = el.closest('[data-dfsb-live-section],[data-etg-dfsb-live-section]'); if (!section) { return; } var text = (section.textContent || '').replace(/\s+/g, ' ').trim(); if (text) { section.removeAttribute('hidden'); } else { section.setAttribute('hidden', 'hidden'); } }
    function restoreElement(el) { if (!initialValues) { return; } var original = initialValues.get(el); if (!original) { return; } if (original.html !== null) { el.innerHTML = original.html; } restoreAttribute(el, 'href'); restoreAttribute(el, 'src'); restoreAttribute(el, 'srcset'); var template = templateGallery(el); var restoredGallery = template === null ? original.gallery : JSON.stringify(template); writeGalleryAttribute(el, restoredGallery); writePresentationAttribute(el, 'attachment-id', original.attachmentId); if (el.style && original.backgroundImage !== null) { el.style.backgroundImage = original.backgroundImage; } writePresentationAttribute(el, 'live-content', null); writePresentationAttribute(el, 'live-html', null); if (boundGroups) { boundGroups.delete(el); } if (isBackgroundContainer(el)) {
        el.removeAttribute('data-dfsb-media-last-applied');
        var gallery = []; try { gallery = JSON.parse(restoredGallery || '[]'); } catch (error) { gallery = []; }
        emitDFSBEvent('dfsb/media-updated', { source: 'archive_fallback', target: 'gallery', gallery: Array.isArray(gallery) ? gallery : [], element: el, slot: readPresentationAttribute(el, 'media-slot') || '' });
    } else { syncSectionVisibility(el); } }
    function elementGroup(el) { var own = (el.getAttribute('data-etg-dfsb-group') || '').trim(); if (own) { return own; } if (typeof el.closest === 'function') { var parent = el.closest('[data-etg-dfsb-group]'); if (parent) { return (parent.getAttribute('data-etg-dfsb-group') || '').trim(); } } return 'auto'; }
    function elementsFor(selector, key) { var auto = autoGroupKey(); return Array.prototype.filter.call(document.querySelectorAll(selector), function (el) { var group = elementGroup(el); if (!group || group === 'auto') { return !!auto && auto === key; } return group === key; }); }

    // A neutral DOM attribute is copied to its historic selector only when
    // the historic value is absent. Existing Elementor templates stay intact.
    function normalizeNeutralBindings() {
        var props = ['token','slot','media-slot','group','target','media-target',
            'fallback'];
        props.forEach(function (prop) {
            var modern = 'data-dfsb-' + prop, legacy = 'data-etg-dfsb-' + prop;
            document.querySelectorAll('[' + modern + ']').forEach(function (element) {
                if (!element.hasAttribute(legacy)) {
                    element.setAttribute(legacy, element.getAttribute(modern));
                }
            });
        });
    }

    function allBindingSelector() { return '[data-etg-dfsb-token],[data-etg-dfsb-slot],[data-etg-dfsb-media-slot]'; }
    function emitAutoGroupStatus() {
        var autoNodes = document.querySelectorAll('[data-etg-dfsb-group="auto"],[data-etg-dfsb-token]:not([data-etg-dfsb-group]),[data-etg-dfsb-slot]:not([data-etg-dfsb-group]),[data-etg-dfsb-media-slot]:not([data-etg-dfsb-group])');
        if (!autoNodes.length) { return; } var resolution = autoGroupResolution(); if (resolution.key) { resetGroupRetry(); return; }
        if (resolution.reason === 'url_group_unavailable' && scheduleUrlGroupRetry(resolution)) { return; } if (resolution.reason !== 'url_group_unavailable') { resetGroupRetry(); }
        emitDFSBEvent('dfsb/ajax-presentation-blocked', { reason: resolution.reason, groups: resolution.groups, path_group: resolution.path_group || '', retry_attempts: resolution.reason === 'url_group_unavailable' ? groupRetryState.attempts : 0 });
    }

    function bindings(key) {
        normalizeNeutralBindings();
        var tokens = [], slots = [];
        elementsFor('[data-etg-dfsb-token]', key).forEach(function (el) { remember(el); var token = (el.getAttribute('data-etg-dfsb-token') || '').trim(); if (token && tokens.indexOf(token) === -1 && tokens.length < (cfg.maxTokens || 100)) { tokens.push(token); } });
        elementsFor('[data-etg-dfsb-slot]', key).forEach(function (el) { remember(el); var slot = (el.getAttribute('data-etg-dfsb-slot') || '').trim().toLowerCase(); if (slot && slots.indexOf(slot) === -1 && slots.length < (cfg.maxSlots || 50)) { slots.push(slot); } });
        elementsFor('[data-etg-dfsb-media-slot]', key).forEach(function (el) { remember(el); var slot = (el.getAttribute('data-etg-dfsb-media-slot') || '').trim().toLowerCase(); if (slot && slots.indexOf(slot) === -1 && slots.length < (cfg.maxSlots || 50)) { slots.push(slot); } });
        return { tokens: tokens, slots: slots };
    }

    function restoreInitial(reason, key) {
        if (!initialValues) { return; }
        Array.prototype.forEach.call(document.querySelectorAll(allBindingSelector()), function (el) {
            var bound = boundGroups ? boundGroups.get(el) : '';
            if (bound === key || (!bound && elementsFor(allBindingSelector(), key).indexOf(el) !== -1)) { restoreElement(el); }
        });
        delete activeKeys[key];
        emitDFSBEvent('dfsb/ajax-presentation-reset', { reason: reason || 'reset', group: key || '', restored_initial: true });
    }

    function clearElement(el, key) {
        // Meta-owned galleries remain authoritative even if a separate
        // content slot is unavailable; a temporary AJAX miss is not deletion.
        if (el.getAttribute('data-etg-dfsb-background-source') === 'meta_field') { return; }
        remember(el);
        if (readPresentationAttribute(el, 'background-source') === 'registered_slot') {
            remember(el);
            var fallback = []; try { fallback = JSON.parse(readPresentationAttribute(el, 'background-fallback') || '[]'); } catch (error) { fallback = []; }
            if (!Array.isArray(fallback)) { fallback = []; }
            var template = templateGallery(el);
            if (template !== null) { fallback = template; }
            writeGalleryAttribute(el, JSON.stringify(fallback));
            if (boundGroups) { boundGroups.delete(el); }
            emitDFSBEvent('dfsb/media-updated', { group: key, target: 'gallery', gallery: fallback, element: el });
            return;
        }
        var mediaSlot = (el.getAttribute('data-etg-dfsb-media-slot') || '').trim().toLowerCase();
        if (mediaSlot) {
            var mediaTarget = (el.getAttribute('data-etg-dfsb-media-target') || 'auto').trim().toLowerCase();
            if (mediaTarget === 'auto') { mediaTarget = String(el.tagName || '').toLowerCase() === 'img' ? 'src' : 'background'; }
            if (mediaTarget === 'src') { el.removeAttribute('src'); el.removeAttribute('srcset'); writePresentationAttribute(el, 'attachment-id', null); }
            else if (mediaTarget === 'background') { if (el.style) { el.style.backgroundImage = ''; } }
            else if (mediaTarget === 'gallery') { writeGalleryAttribute(el, '[]'); }
            emitDFSBEvent('dfsb/media-updated', { group: key, slot: mediaSlot, target: mediaTarget, image: { id: 0, url: '' }, gallery: [], element: el });
        } else {
            var target = (el.getAttribute('data-etg-dfsb-target') || '').trim().toLowerCase();
            if (target === 'href') { el.removeAttribute('href'); }
            else if (target === 'src') { el.removeAttribute('src'); el.removeAttribute('srcset'); writePresentationAttribute(el, 'attachment-id', null); }
            else { el.innerHTML = ''; }
        }
        writePresentationAttribute(el, 'live-content', null);
        writePresentationAttribute(el, 'live-html', null);
        if (boundGroups) { boundGroups.delete(el); }
        syncSectionVisibility(el);
    }

    function clearTransient(reason, key) {
        Array.prototype.forEach.call(document.querySelectorAll(allBindingSelector()), function (el) {
            var bound = boundGroups ? boundGroups.get(el) : '';
            if (bound === key || (!bound && elementsFor(allBindingSelector(), key).indexOf(el) !== -1)) { clearElement(el, key); }
        });
        delete activeKeys[key];
        emitDFSBEvent('dfsb/ajax-presentation-reset', { reason: reason || 'reset', group: key || '', restored_initial: false });
    }

    function syncAutoBindings() { var resolved = autoGroupKey(); if (resolved) { resetGroupRetry(); } Array.prototype.forEach.call(document.querySelectorAll(allBindingSelector()), function (el) { var declared = elementGroup(el); if (declared !== 'auto') { return; } remember(el); var previous = boundGroups ? boundGroups.get(el) : ''; if (previous && previous !== resolved) { restoreElement(el); } }); emitAutoGroupStatus(); return resolved; }

    function applyValue(el, item) {
        if (!item) { return; } var type = item.type || 'text', value = item.value == null ? '' : String(item.value); var target = (el.getAttribute('data-etg-dfsb-target') || '').trim().toLowerCase();
        if (target === 'href') { if (type === 'url' && value) { el.setAttribute('href', value); } else { restoreAttribute(el, 'href'); } return; }
        if (target === 'src') { var imageUrl = item.image && item.image.url ? String(item.image.url) : (type === 'url' ? value : ''); if (imageUrl) { el.setAttribute('src', imageUrl); el.removeAttribute('srcset'); writePresentationAttribute(el, 'attachment-id', item.image && item.image.id ? String(item.image.id) : null); } else { restoreAttribute(el, 'src'); restoreAttribute(el, 'srcset'); var initial = initialValues && initialValues.get(el); writePresentationAttribute(el, 'attachment-id', initial ? initial.attachmentId : null); } return; }
        if (!value) { var fallback = (el.getAttribute('data-etg-dfsb-fallback') || '').trim(); if (fallback) { value = fallback; type = 'text'; } }
        if (type === 'html') { el.innerHTML = value; writePresentationAttribute(el, 'live-content', null); writePresentationAttribute(el, 'live-html', '1'); } else { el.textContent = value; writePresentationAttribute(el, 'live-html', null); writePresentationAttribute(el, 'live-content', '1'); } syncSectionVisibility(el);
    }

    function applyMedia(el, item, slot, key) {
        // An unrelated media Collection must not replace a tenant's explicit
        // gallery metadata field after a JetSmartFilters request.
        if (el.getAttribute('data-etg-dfsb-background-source') === 'meta_field') { return; }
        if (!item) { return; }
        var target = (el.getAttribute('data-etg-dfsb-media-target') || 'auto').trim().toLowerCase();
        var image = item.image && typeof item.image === 'object' ? item.image : null;
        var gallery = Array.isArray(item.gallery) ? item.gallery.slice(0, 30) : [];
        if (!image && gallery.length) { image = gallery[0]; }
        var url = image && image.url ? String(image.url) : '';
        if (target === 'auto') { target = String(el.tagName || '').toLowerCase() === 'img' ? 'src' : 'background'; }
        if (target === 'src') { if (url) { el.setAttribute('src', url); el.removeAttribute('srcset'); writePresentationAttribute(el, 'attachment-id', image && image.id ? String(image.id) : null); } else { restoreAttribute(el, 'src'); restoreAttribute(el, 'srcset'); var initial = initialValues && initialValues.get(el); writePresentationAttribute(el, 'attachment-id', initial ? initial.attachmentId : null); } }
        else if (target === 'background') { if (el.style) { el.style.backgroundImage = url ? 'url("' + url.replace(/"/g, '%22') + '")' : ''; } }
        else if (target === 'gallery') { var template = templateGallery(el); if (!gallery.length && template !== null) { gallery = template; } var json = JSON.stringify(gallery); writeGalleryAttribute(el, json); }
        if (boundGroups) { boundGroups.set(el, key); }
        emitDFSBEvent('dfsb/media-updated', { group: key, slot: slot, target: target, image: image || { id: 0, url: '' }, gallery: gallery, element: el });
    }

    function blockFailClosed(reason, key, detail) {
        clearTransient(reason, key);
        detail = detail || {}; detail.reason = reason; detail.group = key;
        emitDFSBEvent('dfsb/ajax-presentation-blocked', detail);
    }

    function applyResponse(data, key) {
        if (!data || data.contract !== cfg.contract || (cfg.canonicalContract && data.canonical_contract !== cfg.canonicalContract) || data.authorizing !== false || data.url_authority !== false || data.seo_mutation !== false) { blockFailClosed('invalid_contract', key); return; }
        if (groupKey(data.provider, data.query_id) !== key) { blockFailClosed('group_mismatch', key); return; }
        var presentationComplete = data.presentation_state_complete === true;
        if (data.status !== 'ready' || !presentationComplete) { blockFailClosed(data.status || 'blocked', key, { blocking_reasons: data.blocking_reasons || [] }); return; }
        activeKeys[key] = true; var tokenValues = (data.values && data.values.tokens) || {}, slotValues = (data.values && data.values.slots) || {};
        elementsFor('[data-etg-dfsb-token]', key).forEach(function (el) { remember(el); var token = (el.getAttribute('data-etg-dfsb-token') || '').trim(); if (Object.prototype.hasOwnProperty.call(tokenValues, token)) { applyValue(el, tokenValues[token]); if (boundGroups) { boundGroups.set(el, key); } } });
        elementsFor('[data-etg-dfsb-slot]', key).forEach(function (el) { remember(el); var slot = (el.getAttribute('data-etg-dfsb-slot') || '').trim().toLowerCase(); if (Object.prototype.hasOwnProperty.call(slotValues, slot)) { applyValue(el, slotValues[slot]); if (boundGroups) { boundGroups.set(el, key); } } });
        elementsFor('[data-etg-dfsb-media-slot]', key).forEach(function (el) { remember(el); var slot = (el.getAttribute('data-etg-dfsb-media-slot') || '').trim().toLowerCase(); if (Object.prototype.hasOwnProperty.call(slotValues, slot)) { applyMedia(el, slotValues[slot], slot, key); } });
        emitDFSBEvent('dfsb/ajax-presentation-updated', data);
    }

    function readHttpResponse(response) { return response.text().then(function (text) { var data = null, invalidJson = false; if (text) { try { data = JSON.parse(text); } catch (error) { invalidJson = true; } } return { ok: response.ok, status: response.status, data: data, invalidJson: invalidJson }; }); }
    function invalidatePresentationRequest(key) {
        sequences[key] = (sequences[key] || 0) + 1;
        delete prepared[key];
        if (controllers[key]) { controllers[key].abort(); delete controllers[key]; }
        if (timers[key]) { window.clearTimeout(timers[key]); delete timers[key]; }
    }
    function presentationPayload(provider, queryId, query, b) {
        var path = window.location.pathname || '/';
        return JSON.stringify({ provider: String(provider || ''), query_id: String(queryId || ''), request_path: path, archive_path: baseArchivePath(path), current_query: query, tokens: b.tokens, slots: b.slots });
    }
    function finishPresentation(result, key) {
        if (result.failure) { blockFailClosed(result.failure, key, result.detail || {}); return; }
        if (!result.ok) { blockFailClosed('http_error', key, { http_status: result.status }); return; }
        if (result.invalidJson || !result.data) { blockFailClosed('invalid_response', key, { http_status: result.status }); return; }
        applyResponse(result.data, key);
    }
    function settlePresentation(result, key, requestId) {
        if (requestId !== sequences[key]) return;
        var early = prepared[key];
        if (early && early.requestId === requestId && !early.committed) { early.result = result; return; }
        delete prepared[key];
        finishPresentation(result, key);
    }
    function discardNativePending(key) {
        var state = nativePending[key];
        if (state && state.deadline) window.clearTimeout(state.deadline);
        delete nativePending[key];
    }
    function nativeStart(provider, queryId) {
        var key = groupKey(provider, queryId), group = window.JetSmartFilters.filterGroups[key];
        if (!group || !jsfRuntimeContract().ready) return;
        // Preserve the certified direct-link initialization path.
        if (initialSemanticKeys[key] && !userFilterGesture && !eventSeenKeys[key]) return;
        var state = nativePending[key];
        invalidatePresentationRequest(key);
        if (state) { state.depth++; state.ambiguous = true; state.updated = false; return; }
        eventSeenKeys[key] = true;
        state = { depth: 1, ambiguous: false, updated: false };
        nativePending[key] = state;
        state.deadline = window.setTimeout(function () {
            if (nativePending[key] !== state) return;
            discardNativePending(key);
            if (!state.updated || state.ambiguous) {
                invalidatePresentationRequest(key);
                emitDFSBEvent('dfsb/ajax-presentation-blocked', { reason: 'native_jsf_lifecycle_timeout', group: key });
            }
        }, requestTimeoutMs * 2);
        schedule(provider, queryId);
    }
    function nativeUpdated(provider, queryId) {
        var key = groupKey(provider, queryId), state = nativePending[key];
        eventSeenKeys[key] = true;
        if (state && state.ambiguous) { state.updated = true; return; }
        if (state) state.updated = true;
        var resolved = syncAutoBindings(), early = prepared[key], group = window.JetSmartFilters.filterGroups[key];
        if (early && group && jsfRuntimeContract().ready && early.body === presentationPayload(provider, queryId, effectiveCurrentQuery(key, group), bindings(key))) {
            early.committed = true;
            if (early.result) { delete prepared[key]; finishPresentation(early.result, key); }
        } else {
            invalidatePresentationRequest(key);
            schedule(provider, queryId);
        }
        if (resolved && resolved !== key) scheduleKey(resolved);
    }
    function nativeEnd(provider, queryId) {
        var key = groupKey(provider, queryId), state = nativePending[key];
        if (!state) return;
        if (--state.depth > 0) { state.updated = false; return; }
        discardNativePending(key);
        if (!state.updated) {
            invalidatePresentationRequest(key);
            emitDFSBEvent('dfsb/ajax-presentation-blocked', { reason: 'native_jsf_update_missing', group: key });
        } else if (state.ambiguous) { invalidatePresentationRequest(key); syncAutoBindings(); schedule(provider, queryId); }
    }
    function send(provider, queryId) {
        var contract = jsfRuntimeContract(); if (!contract.ready) { emitRuntimeContractBlocked(contract); return; } runtimeBlockedSignature = '';
        var jsf = window.JetSmartFilters, key = groupKey(provider, queryId), group = jsf.filterGroups[key]; if (!group) { return; }
        var state = nativePending[key];
        if (state && state.ambiguous) return;
        var currentQuery = effectiveCurrentQuery(key, group);
        if (!queryHasSemanticFilters(currentQuery)) {
            if (state && !state.updated) { invalidatePresentationRequest(key); return; }
            if (initialSemanticKeys[key]) { clearTransient('filters_cleared', key); } else { restoreInitial('filters_cleared', key); }
            // Invalidate the request even in browsers without AbortController.
            sequences[key] = (sequences[key] || 0) + 1;
            if (controllers[key]) { controllers[key].abort(); delete controllers[key]; }
            var resolvedAfterClear = syncAutoBindings(); if (resolvedAfterClear && resolvedAfterClear !== key) { scheduleKey(resolvedAfterClear); }
            return;
        }
        var b = bindings(key); if (!b.tokens.length && !b.slots.length) { invalidatePresentationRequest(key); emitAutoGroupStatus(); return; }
        sequences[key] = (sequences[key] || 0) + 1; var requestId = sequences[key]; if (controllers[key]) { controllers[key].abort(); } controllers[key] = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var body = presentationPayload(provider, queryId, currentQuery, b);
        if (state && !state.updated) prepared[key] = { requestId: requestId, body: body, committed: false, result: null };
        else delete prepared[key];
        var options = { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: body };
        var controller = controllers[key];
        if (controller) { options.signal = controller.signal; }
        emitDFSBEvent('dfsb/ajax-presentation-start', { group: key, request_id: requestId });
        var timedOut = false, timeoutId = null, transport = Promise.resolve().then(function () { return fetch(cfg.endpoint, options); }).then(readHttpResponse);
        var deadline = new Promise(function (resolve, reject) { timeoutId = window.setTimeout(function () { timedOut = true; if (controller) { controller.abort(); } var error = new Error('Dynamic Filter SEO Bridge AJAX presentation timeout'); error.name = 'DFSBTimeoutError'; reject(error); }, requestTimeoutMs); });
        Promise.race([transport, deadline]).then(function (result) {
            if (requestId !== sequences[key]) { return; }
            settlePresentation(result, key, requestId);
        }).catch(function (error) {
            if (requestId !== sequences[key]) { return; }
            if (timedOut || (error && error.name === 'DFSBTimeoutError')) { settlePresentation({failure:'timeout',detail:{timeout_ms:requestTimeoutMs}}, key, requestId); return; }
            if (error && error.name === 'AbortError') { return; }
            settlePresentation({failure:'transport_error'}, key, requestId);
        }).then(function () { if (timeoutId) { window.clearTimeout(timeoutId); } if (requestId === sequences[key]) { delete controllers[key]; } });
    }

    function schedule(provider, queryId) { var key = groupKey(provider, queryId); if (timers[key]) { window.clearTimeout(timers[key]); } timers[key] = window.setTimeout(function () { delete timers[key]; send(provider, queryId); }, 25); }
    function scheduleKey(key) { var parts = String(key || '').split('/'); if (parts.length < 2) { return; } schedule(parts[0], parts.slice(1).join('/')); }
    // Native JSF may hydrate a multi-term deep link one checkbox at a time.
    // Coalesce only that initial burst (never normal user filtering) so a
    // partial taxonomy state does not launch a redundant Listing render.
    // Keep every native query value, operator, callback and render authority.
    function coalesceNativeDirectLinkHydration(jsf, pathState) {
        if (!pathState.valid || !pathState.group || userFilterGesture) { return; }
        var terms = pathState.query || {};
        var hasMultipleTerms = Object.keys(terms).some(function (key) {
            if (key.indexOf('_tax_query_') !== 0 || typeof terms[key] !== 'string') { return false; }
            return terms[key].split(',').filter(function (part) { return part && !/^operator_(?:AND|IN)$/.test(part); }).length > 1;
        });
        if (!hasMultipleTerms) { return; }
        var group = jsf.filterGroups && jsf.filterGroups[pathState.group];
        if (!group || typeof group.ajaxRequest !== 'function' || group.__dfsbHydrationCoalescing) { return; }
        var original = group.ajaxRequest, scheduled = null, latestArgs = null, finished = false;
        var hardDeadline = null;
        function flush() {
            if (finished) { return; }
            finished = true;
            if (scheduled !== null) { window.clearTimeout(scheduled); scheduled = null; }
            if (hardDeadline !== null) { window.clearTimeout(hardDeadline); hardDeadline = null; }
            if (group.ajaxRequest === wrapped) { group.ajaxRequest = original; }
            delete group.__dfsbHydrationCoalescing;
            if (latestArgs) { var args = latestArgs; latestArgs = null; original.apply(group, args); }
        }
        function wrapped() {
            latestArgs = Array.prototype.slice.call(arguments);
            if (userFilterGesture) { flush(); return; }
            if (scheduled !== null) { window.clearTimeout(scheduled); }
            scheduled = window.setTimeout(flush, 200);
        }
        group.__dfsbHydrationCoalescing = true;
        group.ajaxRequest = wrapped;
        // Bounded release even if native initialization never settles.
        hardDeadline = window.setTimeout(flush, 1500);
    }

    function init() {
        normalizeNeutralBindings();
        if (initialized) { return; } var contract = jsfRuntimeContract(); if (!contract.ready) { emitRuntimeContractBlocked(contract); return; } runtimeBlockedSignature = '';
        var jsf = window.JetSmartFilters; initialized = true;
        var pathState = prettyPathState();
        coalesceNativeDirectLinkHydration(jsf, pathState);
        if (pathState.valid && pathState.group && queryHasSemanticFilters(pathState.query)) { initialSemanticKeys[pathState.group] = true; }
        groupKeys().forEach(function (key) { var group = jsf.filterGroups[key]; if (group && queryHasSemanticFilters(group.currentQuery)) { initialSemanticKeys[key] = true; } });
        jsf.events.subscribe('ajaxFilters/start-loading', nativeStart);
        jsf.events.subscribe('ajaxFilters/updated', nativeUpdated);
        jsf.events.subscribe('ajaxFilters/end-loading', nativeEnd);
        if (pathState.valid && pathState.group && groupKeys().indexOf(pathState.group) !== -1 && queryHasSemanticFilters(pathState.query)) { scheduleKey(pathState.group); }
        groupKeys().forEach(function (key) { var parts = key.split('/'), group = jsf.filterGroups[key]; if (parts.length >= 2 && group && queryHasSemanticFilters(group.currentQuery)) { schedule(parts[0], parts.slice(1).join('/')); } });
        syncAutoBindings();
    }

    document.addEventListener('jet-smart-filters/inited', init, { once: true });
    if (window.JetSmartFilters) { window.setTimeout(init, 0); }
}());