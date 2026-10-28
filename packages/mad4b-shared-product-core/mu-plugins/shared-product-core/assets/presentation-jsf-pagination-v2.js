/* MU Presentation: scoped native JetSmartFilters Pagination URL compatibility.
 * Uses the provider's public pagination/itemsBuilt event & buildPagination API;
 * does not rewrite arbitrary anchors or vendor JS/minified files.
 */
(function () {
  'use strict';
  const config = window.SharedPresentationPaginationV2;
  if (!config || !config.provider || !config.slot) return;
  function correctedHref(pager, pageNumber) {
    const group = pager.filterGroup;
    if (!group || group.urlType !== 'permalink' ||
        group.provider !== 'jet-engine' || group.queryId !== 'tours_query_archive') return null;
    const page = Number(pageNumber);
    if (!Number.isInteger(page) || page < 1 || page > 500) return null;
    const current = String(pager.currentUrlParams || '');
    const cut = current.indexOf('?');
    const contextPath = cut >= 0 ? current.slice(0, cut) : current;
    const search = cut >= 0 ? current.slice(cut + 1) : '';
    if (contextPath && !/^jsf\/jet-engine:tours_query_archive(?:\/[a-z0-9_:;,./-]*)?\/?$/i.test(contextPath)) return null;
    const origin = new URL(group.siteUrl);
    const base = new URL(group.baseUrl, origin);
    const path = contextPath || 'jsf/jet-engine:tours_query_archive/';
    const segments = path.split('/').filter(Boolean);
    if (segments.length >= 2 && segments[segments.length - 2] === 'pagenum') segments.splice(-2);
    const pathname = base.pathname.replace(/\/?$/, '/') + segments.join('/') + '/pagenum/' + page + '/';
    const result = new URL(base.href);
    result.pathname = pathname;
    if (search) result.search = new URLSearchParams(search).toString();
    return result.href;
  }
  function install(pager) {
    if (!pager || pager.__sharedPaginationV2 ||
        typeof pager.getPageUrl !== 'function' ||
        typeof pager.buildPagination !== 'function' ||
        pager.provider !== 'jet-engine' ||
        pager.queryId !== 'tours_query_archive') return;
    const original = pager.getPageUrl;
    pager.getPageUrl = function (page) {
      try { return correctedHref(this, page) || original.call(this, page); }
      catch (_) { return original.call(this, page); }
    };
    // WordPress correctly serves direct /pagenum/N URLs, but the native JSF
    // frontend initializes pageIndex/dataValue to 1 on a hard refresh.
    // Align the original paginator state before its own render/event handlers.
    const direct = window.location.pathname.match(
      /\/jsf\/jet-engine:tours_query_archive\/(?:tax\/[^/]+\/)?pagenum\/([1-9][0-9]{0,2})\/?$/
    );
    if (direct) {
      const page = Number(direct[1]);
      if (page <= Number(pager.pagesCount) && page > 0) {
        pager.pageIndex = page;
        pager.dataValue = page;
        if (typeof pager.updateActivePagesProviderProps === 'function')
          pager.updateActivePagesProviderProps();
      }
    }
    pager.__sharedPaginationV2 = true;
    installHistory(pager.filterGroup); // Event path when JSF initializes after DOMContentLoaded.
    pager.buildPagination(); // Rebuild via the native JSF API and its original event handlers.
  }
  function installHistory(group) {
    if (!group) return;
    if (!group.__sharedPaginationHistoryV2 && typeof group.updateUrl === 'function') {
      const originalUpdateUrl = group.updateUrl;
      group.updateUrl = function (...args) {
        const result = originalUpdateUrl.apply(this, args);
        const pager = Array.isArray(this.filters)
          ? this.filters.find(f => f.__sharedPaginationV2) : null;
        const selected = Number(pager && pager.data);
        if (pager && Number.isInteger(selected) &&
            selected > 1 && selected <= Number(pager.pagesCount)) {
          try {
            const url = new URL(pager.getPageUrl(selected), window.location.href);
            if (url.origin === window.location.origin) {
              window.history.replaceState(window.history.state, '', url.href);
            }
          } catch (_) { /* Native JSF URL remains authoritative. */ }
        }
        return result;
      };
      group.__sharedPaginationHistoryV2 = true;
    }
  }
  function scan() {
    const api = window.JetSmartFilters;
    const group = api && api.filterGroups && api.filterGroups[config.provider];
    if (!group || !Array.isArray(group.filters)) return;
    group.filters.forEach(install);
    installHistory(group);
  }
  function start() {
    const api = window.JetSmartFilters;
    if (!api || !api.events || typeof api.events.subscribe !== 'function') return;
    api.events.subscribe('pagination/itemsBuilt', install);
    scan(); // JSF may have built the first page before this script was loaded.
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
