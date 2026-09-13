(function (window, document) {
    'use strict';

    var CONTRACT = 'etg.dfsb.browser-acceptance-observer.v1';
    var ENDPOINT = '/wp-json/etg-dfsb/v1/ajax-presentation';
    var originalFetch = null, originalPushState = null, originalReplaceState = null;
    var jsfSubscribed = false, armed = false, currentCase = null;
    var state = freshState();

    function freshState() {
        return {
            ajaxFiltersUpdated: false,
            presentationUpdated: false,
            presentationReset: false,
            blocked: [],
            filterGroup: '',
            lastNetwork: {},
            baseline: null,
            filteredSnapshot: null,
            resetSnapshot: null,
            historyCalls: [],
            etgHistoryMutation: false
        };
    }

    function clone(value) {
        try { return JSON.parse(JSON.stringify(value)); } catch (error) { return null; }
    }

    function normalizedPath(value) {
        try { return new URL(String(value || ''), window.location.href).pathname.replace(/\/+$/, '') || '/'; }
        catch (error) { return String(value || '').split('?')[0].replace(/\/+$/, '') || '/'; }
    }

    function endpointMatches(value) {
        return normalizedPath(value) === ENDPOINT;
    }

    function text(selector, attribute) {
        var node = document.querySelector(selector);
        if (!node) { return ''; }
        return attribute ? String(node.getAttribute(attribute) || '') : String(node.textContent || '').trim();
    }

    function headState() {
        var hreflang = Array.prototype.map.call(document.querySelectorAll('link[rel="alternate"][hreflang]'), function (node) {
            return String(node.getAttribute('hreflang') || '') + '=' + String(node.getAttribute('href') || '');
        }).sort();
        return {
            canonical: text('link[rel="canonical"]', 'href'),
            robots: text('meta[name="robots"]', 'content'),
            hreflang: hreflang,
            rank_math: String(document.title || '') + '|' + text('meta[name="description"]', 'content')
        };
    }

    function domIds() {
        var selectors = [
            '.jet-listing-grid__item[data-post-id]',
            '.jet-listing-grid__item [data-post-id]',
            '[data-etg-dfsb-result-item][data-post-id]'
        ];
        var out = [];
        selectors.forEach(function (selector) {
            Array.prototype.forEach.call(document.querySelectorAll(selector), function (node) {
                var id = parseInt(node.getAttribute('data-post-id'), 10);
                if (id > 0 && out.indexOf(id) === -1 && out.length < 100) { out.push(id); }
            });
        });
        return out;
    }

    function resultCount(ids) {
        var selectors = [
            '.jet-smart-filters-results-count__value',
            '.jet-smart-filters-results-count .jet-smart-filters-results-count__value',
            '[data-etg-dfsb-result-count]'
        ];
        for (var i = 0; i < selectors.length; i += 1) {
            var node = document.querySelector(selectors[i]);
            if (!node) { continue; }
            var raw = node.getAttribute('data-etg-dfsb-result-count') || node.textContent || '';
            var match = String(raw).replace(/,/g, '').match(/\d+/);
            if (match) { return parseInt(match[0], 10); }
        }
        return ids.length;
    }

    function domState() {
        var ids = domIds();
        return { ids: ids, result_count: resultCount(ids) };
    }

    function snapshotState() {
        return { url: String(window.location.href || ''), head: headState(), dom: domState() };
    }

    function sameArray(a, b) {
        a = Array.isArray(a) ? a : []; b = Array.isArray(b) ? b : [];
        if (a.length !== b.length) { return false; }
        for (var i = 0; i < a.length; i += 1) { if (String(a[i]) !== String(b[i])) { return false; } }
        return true;
    }

    function isEtgHistoryStack(stack) {
        stack = String(stack || '').replace(/browser-acceptance-observer\.js[^\n]*/g, '');
        return /ajax-filter-state\.js|etg-dynamic-filter-seo-bridge\/assets\/js\/(?!browser-acceptance-observer)/.test(stack);
    }

    function patchHistory() {
        if (originalPushState || !window.history) { return; }
        originalPushState = window.history.pushState;
        originalReplaceState = window.history.replaceState;
        if (typeof originalPushState === 'function') {
            window.history.pushState = function () {
                var stack = (new Error()).stack || '';
                var etgSource = isEtgHistoryStack(stack);
                state.historyCalls.push({ method: 'pushState', etg_source: etgSource });
                if (etgSource) { state.etgHistoryMutation = true; }
                return originalPushState.apply(window.history, arguments);
            };
        }
        if (typeof originalReplaceState === 'function') {
            window.history.replaceState = function () {
                var stack = (new Error()).stack || '';
                var etgSource = isEtgHistoryStack(stack);
                state.historyCalls.push({ method: 'replaceState', etg_source: etgSource });
                if (etgSource) { state.etgHistoryMutation = true; }
                return originalReplaceState.apply(window.history, arguments);
            };
        }
    }

    function restoreHistory() {
        if (!window.history) { return; }
        if (originalPushState) { window.history.pushState = originalPushState; }
        if (originalReplaceState) { window.history.replaceState = originalReplaceState; }
        originalPushState = null; originalReplaceState = null;
    }

    function requestUrl(input) {
        if (typeof input === 'string') { return input; }
        if (input && typeof input.url === 'string') { return input.url; }
        return '';
    }

    function requestMethod(input, init) {
        if (init && init.method) { return String(init.method).toUpperCase(); }
        if (input && input.method) { return String(input.method).toUpperCase(); }
        return 'GET';
    }

    function patchFetch() {
        if (originalFetch || typeof window.fetch !== 'function') { return; }
        originalFetch = window.fetch;
        window.fetch = function (input, init) {
            var url = requestUrl(input), method = requestMethod(input, init);
            var promise = originalFetch.apply(window, arguments);
            if (!endpointMatches(url)) { return promise; }
            promise.then(function (response) {
                var record = { method: method, endpoint: url, http_status: Number(response.status || 0) };
                try {
                    response.clone().text().then(function (body) {
                        var data = {};
                        try { data = body ? JSON.parse(body) : {}; } catch (error) { data = {}; }
                        record.contract = String(data.contract || '');
                        record.status = String(data.status || '');
                        record.authorizing = !!data.authorizing;
                        record.url_authority = !!data.url_authority;
                        record.seo_mutation = !!data.seo_mutation;
                        record.provider = String(data.provider || '');
                        record.query_id = String(data.query_id || '');
                        state.lastNetwork = record;
                    });
                } catch (error) { state.lastNetwork = record; }
            }, function () {
                state.lastNetwork = { method: method, endpoint: url, http_status: 0 };
            });
            return promise;
        };
    }

    function restoreFetch() {
        if (originalFetch) { window.fetch = originalFetch; }
        originalFetch = null;
    }

    function subscribeJetSmartFilters() {
        if (jsfSubscribed) { return; }
        var jsf = window.JetSmartFilters;
        if (!jsf || !jsf.events || typeof jsf.events.subscribe !== 'function') { return; }
        jsf.events.subscribe('ajaxFilters/updated', function (provider, queryId) {
            if (!armed) { return; }
            state.ajaxFiltersUpdated = true;
            state.filterGroup = String(provider || '') + '/' + String(queryId || '');
        });
        jsfSubscribed = true;
    }

    function boundedSubscribe(attempt) {
        if (!armed || jsfSubscribed) { return; }
        subscribeJetSmartFilters();
        if (!jsfSubscribed && attempt < 8) { window.setTimeout(function () { boundedSubscribe(attempt + 1); }, 125); }
    }

    document.addEventListener('etg-dfsb/ajax-presentation-updated', function (event) {
        if (!armed) { return; }
        state.presentationUpdated = true;
        var detail = event && event.detail ? event.detail : {};
        if (detail.provider || detail.query_id) { state.filterGroup = String(detail.provider || '') + '/' + String(detail.query_id || ''); }
        window.setTimeout(function () { if (armed) { state.filteredSnapshot = snapshotState(); } }, 0);
    });
    document.addEventListener('etg-dfsb/ajax-presentation-reset', function () {
        if (!armed) { return; }
        state.presentationReset = true;
        window.setTimeout(function () { if (armed) { state.resetSnapshot = snapshotState(); } }, 0);
    });
    document.addEventListener('etg-dfsb/ajax-presentation-blocked', function (event) {
        if (!armed) { return; }
        state.blocked.push(clone(event && event.detail ? event.detail : {}) || {});
    });

    function validateCase(planCase) {
        if (!planCase || typeof planCase !== 'object') { return false; }
        if (!/^[a-z0-9_-]{1,80}$/i.test(String(planCase.provider || ''))) { return false; }
        if (!/^[A-Za-z0-9_-]{1,80}$/.test(String(planCase.query_id || ''))) { return false; }
        if (!/^[a-z0-9_-]{1,80}$/i.test(String(planCase.taxonomy || ''))) { return false; }
        if (!/^[a-z0-9_-]{1,200}$/i.test(String(planCase.term_slug || ''))) { return false; }
        return String(planCase.case_id || '').length > 0 && String(planCase.case_id || '').length <= 128;
    }

    function arm(planCase) {
        if (!validateCase(planCase)) { return { ok: false, reason: 'invalid_governed_case' }; }
        disarm();
        state = freshState(); currentCase = clone(planCase); armed = true;
        state.baseline = snapshotState();
        patchHistory(); patchFetch(); boundedSubscribe(0);
        return { ok: true, contract: CONTRACT, case_id: String(planCase.case_id), passive: true, authorizing: false };
    }

    function disarm() {
        armed = false; currentCase = null; restoreFetch(); restoreHistory();
    }

    function snapshot() {
        var filtered = state.filteredSnapshot || snapshotState();
        var reset = state.resetSnapshot || snapshotState();
        var baseline = state.baseline || snapshotState();
        var head = filtered.head || {};
        var baselineHead = baseline.head || {};
        var resetIds = reset.dom && Array.isArray(reset.dom.ids) ? reset.dom.ids : [];
        var baselineIds = baseline.dom && Array.isArray(baseline.dom.ids) ? baseline.dom.ids : [];
        var neutralRestored = state.presentationReset
            && normalizedPath(reset.url) === normalizedPath(baseline.url)
            && sameArray(resetIds, baselineIds);
        return {
            contract: CONTRACT,
            case_id: currentCase ? String(currentCase.case_id || '') : '',
            passive: true,
            authorizing: false,
            runtime: {
                javascript_runtime: true,
                jet_smart_filters_observed: !!(window.JetSmartFilters && window.JetSmartFilters.filterGroups),
                filter_group: state.filterGroup
            },
            events: {
                ajax_filters_updated: state.ajaxFiltersUpdated,
                presentation_updated: state.presentationUpdated,
                presentation_reset: state.presentationReset
            },
            network: clone(state.lastNetwork) || {},
            rendered: clone(filtered.dom) || { ids: [], result_count: 0 },
            url_state: {
                filter_state_observed: normalizedPath(filtered.url) !== normalizedPath(baseline.url) || state.ajaxFiltersUpdated,
                etg_history_mutation: state.etgHistoryMutation,
                filtered_url: String(filtered.url || ''),
                reset_url: String(reset.url || '')
            },
            seo: {
                canonical_unchanged: String(head.canonical || '') === String(baselineHead.canonical || ''),
                robots_unchanged: String(head.robots || '') === String(baselineHead.robots || ''),
                hreflang_unchanged: sameArray(head.hreflang, baselineHead.hreflang),
                rank_math_unchanged: String(head.rank_math || '') === String(baselineHead.rank_math || '')
            },
            reset: {
                event_observed: state.presentationReset,
                neutral_state_restored: neutralRestored
            },
            blocked_events: clone(state.blocked) || [],
            history_calls: clone(state.historyCalls) || []
        };
    }

    window.ETGDFSBBrowserAcceptanceObserver = {
        contract: CONTRACT,
        passive: true,
        authorizing: false,
        arm: arm,
        snapshot: snapshot,
        disarm: disarm
    };
}(window, document));
