/* Source-owned bridge to JetSmartFilters events. No query or widget engine. */
(function (root) {
    'use strict';
    function key(value, origin) {
        const url = new URL(value, origin);
        if (url.origin !== new URL(origin).origin || url.username || url.password) return null;
        for (const [name] of url.searchParams) {
            if (!name.startsWith('utm_') && !['gclid', 'fbclid'].includes(name)) return null;
        }
        let path;
        try { path = decodeURIComponent(url.pathname); } catch (_) { return null; }
        const match = path.match(/^(.*\/jsf\/[^/]+\/tax\/)([^/]+)\/?$/);
        if (!match) return null;
        const pairs = match[2].split(';');
        if (pairs.some(pair => !/^[a-z0-9_-]+:[^:;,/+|]+$/.test(pair))) return null;
        const names = pairs.map(pair => pair.split(':')[0]);
        if (new Set(names).size !== names.length) return null;
        pairs.sort();
        return url.origin + match[1] + pairs.join(';') + '/';
    }
    function create(config, origin) {
        const archive = new URL(config.archive_url, origin);
        if (archive.origin !== new URL(origin).origin || archive.search || archive.hash) throw new Error('Invalid route archive');
        const routes = new Map();
        for (const row of config.routes) {
            const native = key(row.native_url, origin);
            const published = new URL(row.url, origin);
            if (!native || published.origin !== archive.origin || published.search || published.hash || routes.has(native)) throw new Error('Invalid published route map');
            routes.set(native, published.href);
        }
        return function destination(group) {
            if (group.provider !== config.provider || !['string', 'number'].includes(typeof group.queryId)
                || String(group.queryId) !== config.query_id || group.urlType !== 'permalink') return null;
            const stateUrl = group.getUrl(true);
            if (!stateUrl) return archive.href;
            // getUrl belongs to the vendor and includes every selected filter.
            // An unknown combination keeps that native URL, never a partial route.
            const native = new URL(archive.href.replace(/\/$/, '') + '/' + stateUrl.replace(/^\//, ''), origin);
            const identity = key(native.href, origin);
            return identity && routes.has(identity) ? routes.get(identity) : native.href;
        };
    }
    function install(win, config) {
        const destination = create(config, win.location.origin);
        let committed = win.location.href;
        let committedState = win.history.state;
        let initialized = false;
        function initialize() {
            const vendor = win.JetSmartFilters;
            if (initialized || !vendor || !vendor.events || !vendor.filterGroups) return;
            initialized = true;
            vendor.events.subscribe('ajaxFilters/updated', function (provider, queryId) {
                if (provider !== config.provider || String(queryId) !== config.query_id) return;
                const group = vendor.filterGroups[provider + '/' + queryId];
                if (!group) return;
                const next = destination(group);
                if (!next) return;
                // Vendor replaceState already ran. Restore the previous entry
                // before adding a real transition; do not patch history methods.
                win.history.replaceState(committedState, '', committed);
                if (next !== committed) win.history.pushState(committedState, '', next);
                committed = next;
            });
            if (win.JetPlugins && win.JetPlugins.hooks) {
                win.JetPlugins.hooks.addFilter('jet-smart-filters.filter.reload-location', 'indexflow/managed-routes', function (original, group) {
                    return destination(group) || original;
                });
            }
        }
        win.document.addEventListener('jet-smart-filters/inited', initialize);
        initialize();
        // Restore all vendor widgets and provider state through the normal
        // server/vendor lifecycle on Back/Forward; no parallel AJAX state engine.
        win.addEventListener('popstate', function () { win.location.reload(); });
    }
    const api = { key, create, install };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root && root.IndexFlowManagedRoutes) install(root, root.IndexFlowManagedRoutes);
})(typeof window === 'undefined' ? null : window);
