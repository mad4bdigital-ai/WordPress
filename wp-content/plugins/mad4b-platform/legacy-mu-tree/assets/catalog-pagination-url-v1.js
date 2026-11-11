/** Local Lab: native JetSmartFilters catalog pager URL builder compatibility. */
(() => {
  'use strict';
  const archive = /^\/(?:ar\/|de\/|fr\/|it\/|es\/)?tours-and-activities\//;
  if (!archive.test(window.location.pathname)) return;
  const pagerSelector = '.jet-smart-filters-pagination nav.jet-filters-pagination';
  const attached = new WeakSet();
  function canonicalize(anchor) {
    if (!(anchor instanceof HTMLAnchorElement) || !anchor.matches('a.jet-filters-pagination__item')) return;
    let url;
    try { url = new URL(anchor.href, location.href); } catch (_) { return; }
    if (url.origin !== location.origin || !archive.test(url.pathname)) return;
    const raw = url.searchParams.get('wcmlc');
    // JSF prepends /pagenum/N/ after the serialized URL query.
    const broken = raw && raw.match(/^([A-Za-z]{3})pagenum\/([1-9][0-9]{0,3})\/?$/);
    if (!broken) return;
    const currency=broken[1].toUpperCase(), page=Number(broken[2]);
    if (!Number.isSafeInteger(page)) return;
    url.searchParams.set('wcmlc', currency);
    let path=url.pathname.replace(/\/(?:pagenum|page)\/\d+\/?$/, '/');
    // Plain /tours-and-activities/ is a WP archive, not a JSF pagination
    // rewrite base. Promote it to the native Query Builder provider route.
    if (!path.includes('/jsf/')) path += 'jsf/jet-engine:tours_query_archive/';
    url.pathname=path + 'pagenum/' + page + '/';
    if (anchor.href !== url.href) anchor.href=url.href;
  }
  function inspect(root) {root.querySelectorAll('a.jet-filters-pagination__item[href]').forEach(canonicalize);}
  // A fresh browser navigation to a native /pagenum/N/ deep-link can render
  // the correct Nth query but the JSF pagination widget still paints '1'.
  // Synchronize its accessible marker and make page 1 navigable; never touch
  // AJAX-managed state after this one initial decoration.
  function syncInitialCurrent(nav) {
    const match = location.pathname.match(/\/pagenum\/([1-9][0-9]{0,3})\/$/);
    if (!match) return;
    const currentPage=Number(match[1]);
    const current=nav.querySelector('.jet-filters-pagination__current');
    const actual=nav.querySelector('a.jet-filters-pagination__item[data-value="'+currentPage+'"]');
    if (!current || !actual || Number(current.dataset.value)===currentPage) return;
    const previous = document.createElement('a');
    previous.className='jet-filters-pagination__item';
    previous.setAttribute('tabindex','0');
    previous.dataset.value=current.dataset.value || '1';
    const source=new URL(location.href);
    source.pathname=source.pathname.replace(/\/pagenum\/\d+\/$/,'/');
    source.pathname+='pagenum/'+previous.dataset.value+'/';
    previous.href=source.href;
    previous.append(...current.childNodes);
    current.replaceWith(previous);
    actual.classList.add('jet-filters-pagination__current');
    actual.setAttribute('aria-current','page');
  }
  function observe(nav) {
    if (attached.has(nav)) return;
    attached.add(nav);
    inspect(nav);
    syncInitialCurrent(nav);
    new MutationObserver(() => inspect(nav)).observe(nav, {subtree:true,childList:true,attributes:true,attributeFilter:['href']});
  }
  function mount() {document.querySelectorAll(pagerSelector).forEach(observe);}
  if (document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
  new MutationObserver(mutations => {
    for (const record of mutations) {
      for (const node of record.addedNodes) {
        if (!(node instanceof Element)) continue;
        if (node.matches(pagerSelector) || node.querySelector(pagerSelector)) {mount();return;}
      }
    }
  }).observe(document.documentElement,{childList:true,subtree:true});
})();
