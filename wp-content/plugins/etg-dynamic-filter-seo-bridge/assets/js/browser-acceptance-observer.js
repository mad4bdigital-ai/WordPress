(function (window, document) {
    'use strict';

    var CONTRACT = 'etg.dfsb.browser-acceptance-observer.v1';
    var ENDPOINT = '/wp-json/etg-dfsb/v1/ajax-presentation';
    var MAX_DIAGNOSTIC_EVENTS = 32;
    var originalFetch = null, originalPushState = null, originalReplaceState = null;
    var jsfSubscribed = false, armed = false, currentCase = null, currentChallengeNonce = '';
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
            etgHistoryMutation: false,
            ajaxFilterStartedAt: 0,
            ajaxEndpointLatencyMs: null,
            filterToPresentationMs: null
        };
    }

    function clone(value) {
        try { return JSON.parse(JSON.stringify(value)); } catch (error) { return null; }
    }

    function nowMs() {
        try { if (window.performance && typeof window.performance.now === 'function') { return Number(window.performance.now()) || 0; } } catch (error) {}
        return Date.now ? Date.now() : 0;
    }

    function roundMs(value) {
        value = Number(value);
        return isFinite(value) && value >= 0 ? Math.round(value * 1000) / 1000 : null;
    }

    function navigationPerformance() {
        var out = {};
        try {
            var perf = window.performance;
            if (!perf) { return out; }
            var entries = typeof perf.getEntriesByType === 'function' ? perf.getEntriesByType('navigation') : [];
            var nav = entries && entries.length ? entries[0] : null;
            if (nav) {
                var requestStart = Number(nav.requestStart || 0), responseStart = Number(nav.responseStart || 0);
                var domEnd = Number(nav.domContentLoadedEventEnd || 0), loadEnd = Number(nav.loadEventEnd || 0);
                if (responseStart >= requestStart) { out.ttfb_ms = roundMs(responseStart - requestStart); }
                if (domEnd >= Number(nav.startTime || 0)) { out.dom_content_loaded_ms = roundMs(domEnd - Number(nav.startTime || 0)); }
                if (loadEnd >= Number(nav.startTime || 0) && loadEnd > 0) { out.load_event_ms = roundMs(loadEnd - Number(nav.startTime || 0)); }
                return out;
            }
            var timing = perf.timing;
            if (timing) {
                if (Number(timing.responseStart) >= Number(timing.requestStart)) { out.ttfb_ms = roundMs(Number(timing.responseStart) - Number(timing.requestStart)); }
                if (Number(timing.domContentLoadedEventEnd) >= Number(timing.navigationStart)) { out.dom_content_loaded_ms = roundMs(Number(timing.domContentLoadedEventEnd) - Number(timing.navigationStart)); }
                if (Number(timing.loadEventEnd) >= Number(timing.navigationStart) && Number(timing.loadEventEnd) > 0) { out.load_event_ms = roundMs(Number(timing.loadEventEnd) - Number(timing.navigationStart)); }
            }
        } catch (error) {}
        return out;
    }

    function boundedString(value, maxLength) {
        return String(value == null ? '' : value).slice(0, Math.max(0, Number(maxLength) || 0));
    }

    function boundedStringList(value, maxItems, maxLength) {
        if (!Array.isArray(value)) { return []; }
        return value.slice(0, maxItems).map(function (item) { return boundedString(item, maxLength); });
    }

    function boundedPush(list, value) {
        if (!Array.isArray(list)) { return; }
        if (list.length >= MAX_DIAGNOSTIC_EVENTS) { list.shift(); }
        list.push(value);
    }

    function boundedBlockedDetail(detail) {
        detail = detail && typeof detail === 'object' ? detail : {};
        var out = {};
        if (Object.prototype.hasOwnProperty.call(detail, 'reason')) { out.reason = boundedString(detail.reason, 160); }
        if (Object.prototype.hasOwnProperty.call(detail, 'group')) { out.group = boundedString(detail.group, 160); }
        if (Object.prototype.hasOwnProperty.call(detail, 'jsf_version')) { out.jsf_version = boundedString(detail.jsf_version, 80); }
        if (Object.prototype.hasOwnProperty.call(detail, 'supported_jsf_versions')) { out.supported_jsf_versions = boundedStringList(detail.supported_jsf_versions, 32, 80); }
        if (Object.prototype.hasOwnProperty.call(detail, 'blocking_reasons')) { out.blocking_reasons = boundedStringList(detail.blocking_reasons, 32, 160); }
        if (Object.prototype.hasOwnProperty.call(detail, 'groups')) { out.groups = boundedStringList(detail.groups, 32, 160); }
        if (Object.prototype.hasOwnProperty.call(detail, 'path_group')) { out.path_group = boundedString(detail.path_group, 160); }
        if (Object.prototype.hasOwnProperty.call(detail, 'retry_attempts')) { out.retry_attempts = Math.max(0, Math.min(100, parseInt(detail.retry_attempts, 10) || 0)); }
        if (Object.prototype.hasOwnProperty.call(detail, 'http_status')) { out.http_status = Math.max(0, Math.min(599, parseInt(detail.http_status, 10) || 0)); }
        if (Object.prototype.hasOwnProperty.call(detail, 'timeout_ms')) { out.timeout_ms = Math.max(0, Math.min(120000, parseInt(detail.timeout_ms, 10) || 0)); }
        return out;
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

    function domIds(limit) {
        limit = Math.max(1, Math.min(5000, parseInt(limit, 10) || 100));
        var selectors = [
            '.jet-listing-grid__item[data-post-id]',
            '.jet-listing-grid__item [data-post-id]',
            '[data-etg-dfsb-result-item][data-post-id]'
        ];
        var out = [];
        selectors.forEach(function (selector) {
            Array.prototype.forEach.call(document.querySelectorAll(selector), function (node) {
                var id = parseInt(node.getAttribute('data-post-id'), 10);
                if (id > 0 && out.indexOf(id) === -1 && out.length < limit) { out.push(id); }
            });
        });
        return out;
    }

    function resultCount(ids) {
        var selectors = [
            { selector: '.jet-smart-filters-results-count__value', source: 'jet_smart_filters_results_count' },
            { selector: '.jet-smart-filters-results-count .jet-smart-filters-results-count__value', source: 'jet_smart_filters_results_count' },
            { selector: '[data-etg-dfsb-result-count]', source: 'etg_data_attribute' }
        ];
        for (var i = 0; i < selectors.length; i += 1) {
            var node = document.querySelector(selectors[i].selector);
            if (!node) { continue; }
            var raw = node.getAttribute('data-etg-dfsb-result-count') || node.textContent || '';
            var match = String(raw).replace(/,/g, '').match(/\d+/);
            if (match) {
                return { count: parseInt(match[0], 10), authoritative: true, source: selectors[i].source };
            }
        }
        return { count: ids.length, authoritative: false, source: 'dom_item_count_fallback' };
    }

    function domState() {
        var proofIds = domIds(5000);
        var ids = proofIds.slice(0, 100);
        var count = resultCount(proofIds);
        return {
            ids: ids,
            ids_complete: !!count.authoritative && count.count <= 100 && proofIds.length === count.count,
            observed_id_count: proofIds.length,
            proof_ids: proofIds,
            proof_ids_complete: !!count.authoritative && count.count <= 5000 && proofIds.length === count.count,
            proof_item_count: proofIds.length,
            result_count: count.count,
            result_count_authoritative: !!count.authoritative,
            result_count_source: count.source
        };
    }

    function snapshotState() {
        return { url: boundedString(window.location.href || '', 2048), head: headState(), dom: domState() };
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
                boundedPush(state.historyCalls, { method: 'pushState', etg_source: etgSource });
                if (etgSource) { state.etgHistoryMutation = true; }
                return originalPushState.apply(window.history, arguments);
            };
        }
        if (typeof originalReplaceState === 'function') {
            window.history.replaceState = function () {
                var stack = (new Error()).stack || '';
                var etgSource = isEtgHistoryStack(stack);
                boundedPush(state.historyCalls, { method: 'replaceState', etg_source: etgSource });
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
            var url = requestUrl(input), method = requestMethod(input, init), startedAt = nowMs();
            var promise = originalFetch.apply(window, arguments);
            if (!endpointMatches(url)) { return promise; }
            promise.then(function (response) {
                var record = { method: boundedString(method, 16), endpoint: boundedString(url, 2048), http_status: Number(response.status || 0), latency_ms: roundMs(nowMs() - startedAt) };
                state.ajaxEndpointLatencyMs = record.latency_ms;
                try {
                    response.clone().text().then(function (body) {
                        var data = {};
                        try { data = body ? JSON.parse(body) : {}; } catch (error) { data = {}; }
                        record.contract = boundedString(data.contract || '', 160);
                        record.status = boundedString(data.status || '', 80);
                        record.authorizing = !!data.authorizing;
                        record.url_authority = !!data.url_authority;
                        record.seo_mutation = !!data.seo_mutation;
                        record.provider = boundedString(data.provider || '', 80);
                        record.query_id = boundedString(data.query_id || '', 128);
                        state.lastNetwork = record;
                    });
                } catch (error) { state.lastNetwork = record; }
            }, function () {
                state.lastNetwork = { method: boundedString(method, 16), endpoint: boundedString(url, 2048), http_status: 0 };
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
            state.ajaxFilterStartedAt = nowMs();
            state.filterGroup = boundedString(provider || '', 80) + '/' + boundedString(queryId || '', 79);
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
        if (state.ajaxFilterStartedAt > 0) { state.filterToPresentationMs = roundMs(nowMs() - state.ajaxFilterStartedAt); }
        var detail = event && event.detail ? event.detail : {};
        if (detail.provider || detail.query_id) { state.filterGroup = boundedString(detail.provider || '', 80) + '/' + boundedString(detail.query_id || '', 79); }
        window.setTimeout(function () { if (armed) { state.filteredSnapshot = snapshotState(); } }, 0);
    });
    document.addEventListener('etg-dfsb/ajax-presentation-reset', function () {
        if (!armed) { return; }
        state.presentationReset = true;
        window.setTimeout(function () { if (armed) { state.resetSnapshot = snapshotState(); } }, 0);
    });
    document.addEventListener('etg-dfsb/ajax-presentation-blocked', function (event) {
        if (!armed) { return; }
        boundedPush(state.blocked, boundedBlockedDetail(event && event.detail ? event.detail : {}));
    });

    function validateCase(planCase) {
        if (!planCase || typeof planCase !== 'object') { return false; }
        if (!/^[a-z0-9_-]{1,80}$/i.test(String(planCase.provider || ''))) { return false; }
        if (!/^[A-Za-z0-9_-]{1,80}$/.test(String(planCase.query_id || ''))) { return false; }
        if (!/^[a-z0-9_-]{1,80}$/i.test(String(planCase.taxonomy || ''))) { return false; }
        if (!/^[a-z0-9_-]{1,200}$/i.test(String(planCase.term_slug || ''))) { return false; }
        return String(planCase.case_id || '').length > 0 && String(planCase.case_id || '').length <= 128;
    }

    function validateChallenge(challenge) {
        return !!(challenge && typeof challenge === 'object' && /^[a-f0-9]{32}$/i.test(String(challenge.nonce || '')));
    }

    function arm(planCase, challenge) {
        if (!validateCase(planCase)) { return { ok: false, reason: 'invalid_governed_case' }; }
        if (!validateChallenge(challenge)) { return { ok: false, reason: 'invalid_freshness_challenge' }; }
        disarm();
        state = freshState(); currentCase = clone(planCase); currentChallengeNonce = String(challenge.nonce).toLowerCase(); armed = true;
        state.baseline = snapshotState();
        patchHistory(); patchFetch(); boundedSubscribe(0);
        return { ok: true, contract: CONTRACT, case_id: String(planCase.case_id), challenge_nonce: currentChallengeNonce, passive: true, authorizing: false };
    }

    function disarm() {
        armed = false; currentCase = null; currentChallengeNonce = ''; restoreFetch(); restoreHistory();
    }

    function renderedEvidence(dom) {
        dom = dom && typeof dom === 'object' ? dom : {};
        var out = {};
        ['ids','ids_complete','observed_id_count','result_count','result_count_authoritative','result_count_source'].forEach(function (key) {
            if (Object.prototype.hasOwnProperty.call(dom, key)) { out[key] = clone(dom[key]); }
        });
        return out;
    }

    function canonicalIdsJson(ids) {
        return JSON.stringify((Array.isArray(ids) ? ids : []).map(function (id) { return parseInt(id, 10) || 0; }));
    }

    function sha256Hex(text) {
        try {
            if (!window.crypto || !window.crypto.subtle || typeof window.crypto.subtle.digest !== 'function' || typeof window.TextEncoder !== 'function') {
                return Promise.resolve('');
            }
            var bytes = (new window.TextEncoder()).encode(String(text || ''));
            return window.crypto.subtle.digest('SHA-256', bytes).then(function (buffer) {
                return Array.prototype.map.call(new Uint8Array(buffer), function (b) { return ('00' + b.toString(16)).slice(-2); }).join('');
            }, function () { return ''; });
        } catch (error) { return Promise.resolve(''); }
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
            case_id: currentCase ? boundedString(currentCase.case_id || '', 128) : '',
            challenge_nonce: boundedString(currentChallengeNonce, 32),
            passive: true,
            authorizing: false,
            runtime: {
                javascript_runtime: true,
                jet_smart_filters_observed: !!(window.JetSmartFilters && window.JetSmartFilters.filterGroups),
                filter_group: boundedString(state.filterGroup, 160)
            },
            events: {
                ajax_filters_updated: state.ajaxFiltersUpdated,
                presentation_updated: state.presentationUpdated,
                presentation_reset: state.presentationReset
            },
            network: clone(state.lastNetwork) || {},
            performance: (function () {
                var perf = navigationPerformance();
                if (state.ajaxEndpointLatencyMs !== null) { perf.ajax_endpoint_latency_ms = state.ajaxEndpointLatencyMs; }
                if (state.filterToPresentationMs !== null) { perf.filter_to_presentation_ms = state.filterToPresentationMs; }
                return perf;
            }()),
            rendered: renderedEvidence(filtered.dom),
            url_state: {
                filter_state_observed: normalizedPath(filtered.url) !== normalizedPath(baseline.url) || state.ajaxFiltersUpdated,
                etg_history_mutation: state.etgHistoryMutation,
                filtered_url: boundedString(filtered.url || '', 2048),
                reset_url: boundedString(reset.url || '', 2048)
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

    function snapshotAsync() {
        var evidence = snapshot();
        var filtered = state.filteredSnapshot || snapshotState();
        var dom = filtered && filtered.dom ? filtered.dom : {};
        var proofIds = Array.isArray(dom.proof_ids) ? dom.proof_ids.slice(0, 5000) : [];
        var total = Number(evidence.rendered && evidence.rendered.result_count || 0);
        var authoritative = !!(evidence.rendered && evidence.rendered.result_count_authoritative);
        if (!authoritative || total <= 100 || total > 5000 || proofIds.length !== total) {
            return Promise.resolve(evidence);
        }
        var identity = proofIds.slice().sort(function (a, b) { return Number(a) - Number(b); });
        return Promise.all([sha256Hex(canonicalIdsJson(identity)), sha256Hex(canonicalIdsJson(proofIds))]).then(function (digests) {
            if (/^[a-f0-9]{64}$/.test(digests[0]) && /^[a-f0-9]{64}$/.test(digests[1])) {
                evidence.rendered.digest_authoritative = true;
                evidence.rendered.proof_item_count = proofIds.length;
                evidence.rendered.identity_digest = digests[0];
                evidence.rendered.order_digest = digests[1];
            }
            return evidence;
        });
    }

    window.ETGDFSBBrowserAcceptanceObserver = {
        contract: CONTRACT,
        passive: true,
        authorizing: false,
        arm: arm,
        snapshot: snapshot,
        snapshotAsync: snapshotAsync,
        disarm: disarm
    };
}(window, document));
