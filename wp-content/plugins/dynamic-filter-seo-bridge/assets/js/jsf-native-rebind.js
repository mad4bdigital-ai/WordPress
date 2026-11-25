(function () {
    'use strict';
    // Repair native JSF controls following a Listing/Elementor
    // DOM replacement. Do not implement a parallel filter, URL or AJAX path.
    var SELECTOR = '.jet-filter[jsf-filter]';
    var running = false;
    var scheduled = false;
    var bound = false;
    var observer = null;
    var lastReported = '';
    var lastCompositeReport = '[]';
    var ownedListeners = new WeakMap();
    var ownedNodes = [];
    var ownedIdentities = new WeakMap();
    var detachedSince = new WeakMap();
    var detachedSweepTimer = null;

    function runtime() {
        var cfg = window.DFSB_AJAX || window.ETGDFSB_AJAX || {};
        var supported = Array.isArray(cfg.supportedJsfVersions) ? cfg.supportedJsfVersions.map(String) : [];
        if (supported.indexOf(String(cfg.jsfVersion || '')) === -1) return null;
        var j = window.JetSmartFilters;
        return j && typeof j.initFilter === 'function' && j.filterGroups &&
            j.events && typeof j.events.subscribe === 'function' && j.events.channels && typeof j.events.channels === 'object' &&
            window.jQuery && typeof window.jQuery === 'function' ? j : null;
    }

    function compositeKind(el) {
        var hierarchy = !!(el.matches && el.matches('.jet-smart-filters-hierarchy'));
        var additional = !!((el.matches && el.matches('[data-additional-providers]')) || el.querySelector('[data-additional-providers]'));
        return hierarchy || additional ? { hierarchy: hierarchy, additional: additional } : null;
    }
    function observedIdentity(el) {
        var data = el.querySelector('[data-filter-id][data-content-provider][data-query-id]');
        if (!data) return null;
        var id = Number(data.getAttribute('data-filter-id'));
        var provider = String(data.getAttribute('data-content-provider') || '');
        var queryId = String(data.getAttribute('data-query-id') || '');
        if (!Number.isSafeInteger(id) || id <= 0 || !/^[a-z0-9_-]+$/i.test(queryId) || !/^[a-z0-9_-]+$/i.test(provider)) return null;
        return { id: id, key: provider + '/' + queryId, queryId: queryId };
    }
    function identity(el) {
        // Composite filters are observed separately, never partially reinitialized.
        return compositeKind(el) ? null : observedIdentity(el);
    }
    function compositeObservation(jsf, el, kind) {
        var ident = observedIdentity(el);
        var row = { group: ident ? ident.key : '', filter_id: ident ? ident.id : 0,
            hierarchy: kind.hierarchy, additional_providers: kind.additional, groups: [],
            status: 'deferred', reason: 'identity_unverified', authorizing: false, read_only: true };
        if (!ident) return row;
        var primary = jsf.filterGroups[ident.key];
        if (!primary || !Array.isArray(primary.filters)) { row.reason = 'primary_group_unavailable'; return row; }
        var matching = primary.filters.filter(function (f) {
            return f && !f.isAdditional && Number(f.filterId) === ident.id && !!f.isHierarchy === kind.hierarchy && f.$container && f.$container[0];
        });
        var live = matching.filter(function (f) { return f.$container[0] === el; });
        var stale = matching.filter(function (f) { return !f.$container[0].isConnected; });
        var refs = live.length ? live : stale;
        row.groups.push(ident.key);
        row.primary_live = live.length; row.primary_detached = stale.length;
        if (primary.isAjaxLoading) { row.reason = 'native_group_loading'; return row; }
        if (!refs.length) { row.reason = 'native_identity_unavailable'; return row; }
        if (refs.some(function (f) { return typeof f.path !== 'string' || !f.path; })) { row.reason = 'native_path_unavailable'; return row; }
        // Distinguish copies using the native hierarchy instance, not a filter ID alone.
        if (kind.hierarchy) {
            var instance = refs[0].hierarchicalInstance;
            var levels = instance && Array.isArray(instance.filters) ? instance.filters : [];
            var depths = refs.map(function (f) { return f.depth; });
            if (!levels.length || refs.length !== levels.length || refs.some(function (f) { return f.hierarchicalInstance !== instance || levels.indexOf(f) === -1; }) ||
                depths.some(function (d) { return !Number.isInteger(d) || d < 0 || d >= levels.length; }) || new Set(depths).size !== levels.length) {
                row.reason = 'hierarchy_membership_unverified'; return row;
            }
            row.hierarchy_levels = levels.length;
        } else if (refs.length !== 1) { row.reason = 'native_owner_ambiguous'; return row; }
        if (kind.additional) {
            var data = window.jQuery(el);
            var declared = data && typeof data.data === 'function' ? data.data('additional-providers') : null;
            if (!declared && data && typeof data.find === 'function') { var child = data.find('[data-additional-providers]'); declared = child && typeof child.data === 'function' ? child.data('additional-providers') : null; }
            if (!Array.isArray(declared) || !declared.length) { row.reason = 'additional_provider_definition_unverified'; return row; }
            var keys = [];
            for (var i=0;i<declared.length;i++) {
                var parts = typeof declared[i] === 'string' ? declared[i].split('/') : [];
                var q = parts[1] || ident.queryId;
                if (!parts.length || parts.length > 2 || !/^[a-z0-9_-]+$/i.test(parts[0]) || !/^[a-z0-9_-]+$/i.test(q)) { row.reason = 'additional_provider_definition_unverified'; return row; }
                var key = parts[0]+'/'+q;
                if (key === ident.key || keys.indexOf(key) !== -1) { row.reason = 'additional_provider_identity_ambiguous'; return row; }
                keys.push(key);
                var group = jsf.filterGroups[key];
                if (!group || !Array.isArray(group.filters)) { row.reason = 'additional_group_unavailable'; return row; }
                row.groups.push(key);
                if (group.isAjaxLoading) { row.reason = 'native_group_loading'; return row; }
                if (!refs.every(function (f) {
                    return group.filters.filter(function (proxy) { return proxy && proxy.isAdditional === true && Number(proxy.filterId) === ident.id && proxy.path === f.path && proxy.provider === parts[0] && proxy.queryId === q && proxy.queryKey === f.queryKey && !!proxy.isHierarchy === kind.hierarchy && (!kind.hierarchy || proxy.depth === f.depth); }).length === 1;
                })) { row.reason = 'additional_proxy_membership_unverified'; return row; }
            }
        }
        row.status = live.length ? 'observed_live' : 'deferred';
        row.reason = live.length ? 'native_membership_observed' : 'native_disposal_not_qualified';
        return row;
    }
    function observeComposites(jsf, elements) {
        var rows = [];
        Array.prototype.forEach.call(elements,function (el) {
            if (!el.isConnected) return;
            var kind = compositeKind(el);
            if (kind) rows.push(compositeObservation(jsf,el,kind));
        });
        var signature = JSON.stringify(rows);
        if (signature === lastCompositeReport) return;
        lastCompositeReport = signature;
        if (rows.length || signature === '[]') document.dispatchEvent(new CustomEvent('dfsb/jsf-composite-rebind-observation',{detail:{contract:'dfsb.jsf-composite-rebind-observation.v1',read_only:true,authorizing:false,rows:rows}}));
    }

    function sameFilter(filter, id) {
        return filter && !filter.isHierarchy && !filter.isAdditional && Number(filter.filterId) === id && filter.$container &&
            filter.$container[0] && filter.$container[0].nodeType === 1;
    }

    function snapshotListeners(jsf) {
        var channels = jsf.events && jsf.events.channels;
        if (!channels || typeof channels !== 'object') { return null; }
        var snapshot = Object.create(null);
        Object.keys(channels).forEach(function (name) {
            if (Array.isArray(channels[name])) { snapshot[name] = channels[name].slice(); }
        });
        return snapshot;
    }
    function appendedListeners(jsf, before) {
        var additions = [];
        if (!before) { return additions; }
        Object.keys(jsf.events.channels).forEach(function (name) {
            var handlers = jsf.events.channels[name];
            if (!Array.isArray(handlers)) { return; }
            var old = (before[name] || []).slice();
            handlers.forEach(function (fn) {
                var i = old.indexOf(fn);
                if (i >= 0) { old.splice(i, 1); }
                else { additions.push({channel:name, fn:fn}); }
            });
        });
        return additions;
    }
    function unbindOwned(jsf, element) {
        var owned = ownedListeners.get(element);
        if (!owned) { return; }
        var channels = jsf.events.channels;
        owned.forEach(function (entry) {
            var handlers = channels && channels[entry.channel];
            if (!Array.isArray(handlers)) { return; }
            var index = handlers.indexOf(entry.fn);
            if (index >= 0) { handlers.splice(index, 1); }
        });
        ownedListeners.delete(element);
    }

    function sweepDetachedSubscriptions(jsf) {
        // JSF may evict stale filter objects before the adapter sees them.
        // Owned event callbacks are tied to the old DOM node, not the registry.
        var now = Date.now();
        for (var i = ownedNodes.length - 1; i >= 0; i -= 1) {
            var element = ownedNodes[i];
            if (element.isConnected) {
                detachedSince.delete(element);
                continue;
            }
            if (!detachedSince.has(element)) {
                detachedSince.set(element, now);
                if (detachedSweepTimer === null) {
                    detachedSweepTimer = window.setTimeout(function () { detachedSweepTimer = null; schedule(); }, 250);
                }
                continue;
            }
            // Permit short Elementor detach/reattach windows.
            if (now - detachedSince.get(element) < 250) { continue; }
            var ident = ownedIdentities.get(element) || identity(element);
            if (!ident) { continue; }
            var group = jsf.filterGroups[ident.key];
            if (!group || !Array.isArray(group.filters) || group.isAjaxLoading) { continue; }
            // Remove callbacks created by our own prior initFilter call, even
            // if JSF removed the corresponding group entry already.
            unbindOwned(jsf, element);
            var replacementFound = group.filters.some(function (other) {
                return sameFilter(other, ident.id) && other.$container[0].isConnected;
            });
            // Retain unmatched group references so the next newly rendered
            // control can still be identified and initialized correctly.
            if (replacementFound) {
                for (var k = group.filters.length - 1; k >= 0; k -= 1) {
                    var f = group.filters[k];
                    if (sameFilter(f, ident.id) && f.$container[0] === element) {
                        group.filters.splice(k, 1);
                    }
                }
            }
            detachedSince.delete(element);
            ownedIdentities.delete(element);
            ownedNodes.splice(i, 1);
        }
    }

    function reconcile() {
        scheduled = false;
        if (running) { return; }
        var j = runtime();
        if (!j) { return; }
        // Only existing native group/filter identities may be repaired.
        running = true;
        var rebound = 0, removed = 0;
        var changedGroups = Object.create(null);
        var contexts = Object.create(null);
        try {
            sweepDetachedSubscriptions(j);
            var elements = document.querySelectorAll(SELECTOR);
            observeComposites(j,elements);
            // Pass 1: attach every newly-rendered node before pruning. Some
            // templates display the same filter in desktop and popup markup.
            for (var n = 0; n < elements.length; n += 1) {
                var el = elements[n];
                var ident = identity(el);
                if (!ident || !el.isConnected) { continue; }
                var group = j.filterGroups[ident.key];
                if (!group || !Array.isArray(group.filters) || group.isAjaxLoading) { continue; }
                var matches = group.filters.filter(function (f) { return sameFilter(f, ident.id); });
                if (!matches.length) { continue; }
                var ctxKey = ident.key + '#' + ident.id;
                var ctx = contexts[ctxKey];
                if (!ctx) {
                    ctx = contexts[ctxKey] = { group: group, key: ident.key, id: ident.id, nodes: [], failed: false };
                }
                ctx.nodes.push(el);
                var exists = matches.some(function (f) { return f.$container[0] === el; });
                var stale = matches.some(function (f) { return !f.$container[0].isConnected; });
                if (!exists && stale) {
                    var before = snapshotListeners(j);
                    if (!before) { ctx.failed = true; continue; }
                    el.removeAttribute('jsf-filter');
                    try {
                        j.initFilter(window.jQuery(el));
                        var added = appendedListeners(j, before);
                        var nowLive = group.filters.some(function (f) {
                            return sameFilter(f, ident.id) && f.$container[0] === el;
                        });
                        if (!nowLive) {
                            added.forEach(function (entry) {
                                var channel = j.events.channels[entry.channel] || [];
                                var i = channel.indexOf(entry.fn);
                                if (i >= 0) { channel.splice(i, 1); }
                            });
                            el.setAttribute('jsf-filter', '');
                            ctx.failed = true;
                            continue;
                        }
                        ownedListeners.set(el, added);
                        ownedIdentities.set(el, ident);
                        if (ownedNodes.indexOf(el) === -1) { ownedNodes.push(el); }
                        rebound += 1;
                        changedGroups[ident.key] = true;
                    } catch (_) {
                        appendedListeners(j, before).forEach(function (entry) {
                            var channel = j.events.channels[entry.channel] || [];
                            var i = channel.indexOf(entry.fn);
                            if (i >= 0) { channel.splice(i, 1); }
                        });
                        el.setAttribute('jsf-filter', '');
                        ctx.failed = true;
                    }
                }
            }
            // Pass 2: prune only if ALL connected copies of this native
            // filter were attached successfully; otherwise leave a recovery
            // reference for the next JSF event/probe.
            Object.keys(contexts).forEach(function (key) {
                var ctx = contexts[key];
                if (ctx.failed || !ctx.nodes.every(function (el) {
                    return ctx.group.filters.some(function (f) {
                        return sameFilter(f, ctx.id) && f.$container[0] === el && el.isConnected;
                    });
                })) { return; }
                for (var i = ctx.group.filters.length - 1; i >= 0; i -= 1) {
                    var f = ctx.group.filters[i];
                    if (sameFilter(f, ctx.id) && !f.$container[0].isConnected) {
                        unbindOwned(j, f.$container[0]);
                        ctx.group.filters.splice(i, 1);
                        removed += 1;
                        changedGroups[ctx.key] = true;
                    }
                }
            });
            sweepDetachedSubscriptions(j);
            if (rebound || removed) {
                var summary = rebound + ':' + removed;
                if (summary !== lastReported || rebound) {
                    lastReported = summary;
                    document.dispatchEvent(new CustomEvent('dfsb/jsf-native-rebind', {
                        detail: {
                            rebound: rebound, detached_references_removed: removed,
                            native_jsf_only: true, groups: Object.keys(changedGroups),
                            query_id: Object.keys(changedGroups).length === 1 ? Object.keys(changedGroups)[0].split('/')[1] : ''
                        }
                    }));
                }
            }
        } finally { running = false; }
    }

    function schedule() {
        if (scheduled) { return; }
        scheduled = true;
        window.setTimeout(reconcile, 110);
    }
    // JSF/Elementor may render the replacement *after* ajaxFilters/updated.
    // Bounded settle probes catch that late swap without continuous polling.
    function settle() {
        schedule();
        window.setTimeout(schedule, 350);
        window.setTimeout(schedule, 950);
        window.setTimeout(schedule, 1800);
    }
    function includesFilter(records) {
        for (var i = 0; i < records.length; i += 1) {
            var nodes = records[i].addedNodes;
            var removed = records[i].removedNodes || [];
            for (var r = 0; r < removed.length; r += 1) {
                var gone = removed[r];
                if (gone.nodeType === 1 && ((gone.matches && gone.matches('.jet-filter')) ||
                    (gone.querySelector && gone.querySelector('.jet-filter')))) { return true; }
            }
            for (var n = 0; n < nodes.length; n += 1) {
                var el = nodes[n];
                if (el.nodeType !== 1) { continue; }
                if ((el.matches && el.matches('.jet-filter,.elementor-popup-modal')) ||
                    (el.querySelector && el.querySelector('.jet-filter'))) { return true; }
            }
        }
        return false;
    }
    function bind() {
        if (bound || !runtime()) { return; }
        bound = true;
        var j = runtime();
        j.events.subscribe('ajaxFilters/updated', settle);
        j.events.subscribe('ajaxFilters/end-loading', settle);
        document.addEventListener('dfsb/ajax-presentation-updated', settle);
        window.addEventListener('elementor/popup/show', settle);
        if (document.body && window.MutationObserver) {
            observer = new MutationObserver(function (records) {
                if (includesFilter(records)) { schedule(); }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
        settle();
    }
    document.addEventListener('jet-smart-filters/inited', bind);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind, { once: true });
    } else {
        window.setTimeout(bind, 0);
    }
    window.addEventListener('pagehide', function () {
        if (observer) { observer.disconnect(); }
    });
}());
