/* MAD4B Portable Site Shell — local-only, progressive, fail-closed. */
(() => {
  'use strict';
  const cfg = window.MAD4BSiteShell;
  if (!cfg || typeof fetch !== 'function' || !window.DOMParser || !window.history) return;

  const origin = new URL(cfg.origin).origin;
  if (window.location.origin !== origin) return;
  const parser = new DOMParser();
  window.__MAD4BSiteShellBooted = true;
  const staticWidgets = new Set([
    'heading.default', 'text-editor.default', 'image.default', 'divider.default',
    'spacer.default', 'icon.default', 'image-box.default', 'icon-box.default'
  ]);
  const hardNav = href => window.location.assign(href);
  const main = doc => {
    const roots = Array.from(doc.querySelectorAll('main')).filter(el => !el.closest('main main'));
    return roots.length === 1 ? roots[0] : null;
  };
  const siteEdge = (doc, tag, type) => {
    const candidates = Array.from(doc.querySelectorAll(tag)).filter(el => !el.closest('main'));
    return candidates.find(el => el.classList.contains('elementor-location-' + type))
      || candidates.find(el => el.matches('[data-elementor-type="' + type + '"]'))
      || candidates[0] || null;
  };
  const signature = el => {
    if (!el) return null;
    const classes = Array.from(el.classList).filter(c => !/^(current|active|focus)/i.test(c)).sort();
    return [el.tagName, el.id || '', el.getAttribute('data-elementor-id') || '',
      el.getAttribute('data-elementor-type') || '', classes.join('.')].join('|');
  };
  const siteShell = doc => [signature(siteEdge(doc, 'header', 'header')),
    signature(siteEdge(doc, 'footer', 'footer'))];
  const sameShell = (a, b) => {
    const x = siteShell(a), y = siteShell(b);
    return x.every(Boolean) && x.join('::') === y.join('::');
  };
  const resource = (input, href) => {
    try { return new URL(input, href).href; } catch (_) { return ''; }
  };
  const sameRecords = (left, right) =>
    left.length === right.length && left.every((entry, index) => entry === right[index]);
  // Use two server-rendered manifests: browser scripts are commonly augmented
  // by analytics, third-party tags and Elementor's late-loaded chunks.
  // They cannot be compared to the raw template by DOM script counts.
  const localized = Object.freeze({
    'elementor-frontend-js-before': 'elementorFrontendConfig',
    'jet-engine-frontend-js-extra': 'JetEngineSettings',
    'jet-woo-builder-js-extra': 'jetWooBuilderData',
    'jet-blog-js-extra': 'JetBlogSettings'
  });
  const cssLinks = () => Array.from(document.querySelectorAll('link')).filter(el =>
    el.relList?.contains('stylesheet') ||
    (el.relList?.contains('preload') && el.getAttribute('as')?.toLowerCase() === 'style')
  );
  const cssLabel = e => [
    (e.rel || '').toLowerCase().trim().replace(/\s+/g, ' '),
    e.id || '', e.media || ''
  ].join('|');
  const absoluteCss = (entry, href) => ({
    href: resource(entry.href || '', href),
    rel: (entry.rel || '').toLowerCase().trim().replace(/\s+/g, ' '),
    id: entry.id || '', media: entry.media || ''
  });
  const domCss = () => cssLinks().map(el => ({
    href: resource(el.getAttribute('href') || '', location.href),
    rel: (el.getAttribute('rel') || '').toLowerCase().trim().replace(/\s+/g, ' '),
    id: el.id || '', media: el.getAttribute('media') || ''
  }));
  const validManifest = p => p && Array.isArray(p.css) && Array.isArray(p.styles)
    && Array.isArray(p.scripts) && Array.isArray(p.inlineScripts);
  const recordDecision = (mode, reason) => {
    const code = typeof reason === 'string' && /^[a-z0-9_]{1,80}$/.test(reason)
      ? reason : 'unclassified';
    const receipt = { mode, reason: code, timestamp: Date.now() };
    window.__MAD4BSiteShellLastDecision = mode + '_' + code;
    // Store only a reason code, not the URL, visitor, cookies or page data.
    try { window.sessionStorage.setItem('mad4b_shell_last_decision', JSON.stringify(receipt)); } catch (_) {}
    if (window.__MAD4BSiteShellQa === true) console.info('MAD4B_SHELL_COMPAT:' + mode + ':' + code);
  };
  try {
    const previous = JSON.parse(window.sessionStorage.getItem('mad4b_shell_last_decision') || 'null');
    if (previous?.mode && previous?.reason)
      window.__MAD4BSiteShellLastDecision = previous.mode + '_' + previous.reason;
  } catch (_) {}
  const rejectCompatibility = reason => {
    recordDecision('full', reason);
    return null;
  };
  // The first loaded page may contain a local, optimized bundle of many
  // WordPress CSS resources. Retain it only when the destination's complete
  // CSS dependency set is an order-preserving subset of the original page.
  let immutableCssBase = null;
  const orderedSubset = (needed, available) => {
    let pos = 0;
    for (const item of needed) {
      while (pos < available.length && available[pos] !== item) pos++;
      if (pos === available.length) return false;
      pos++;
    }
    return true;
  };
  const cssSignature = x => x.href + '|' + cssLabel(x);
  const styleSignature = x => (x.id || '') + '|' + (x.sha256 || '');
  const containedMultiset = (needed, available) => {
    // A bundled stylesheet can contain existing inline styles in another
    // order. No target style may be new, and duplicate counts must match.
    const counts = new Map();
    available.forEach(x => counts.set(x, (counts.get(x) || 0) + 1));
    for (const key of needed) {
      const count = counts.get(key) || 0;
      if (!count) return false;
      counts.set(key, count - 1);
    }
    return true;
  };
  const isLocalCombinedCss = (rendered, raw) => rendered.length > 0 && rendered.length < raw.length
    && rendered.every(entry => {
      try {
        const url = new URL(entry.href);
        return url.origin === origin && /\/litespeed\/css\/[^/]+\.css$/i.test(url.pathname)
          && entry.rel.includes('stylesheet');
      } catch (_) { return false; }
    });
  const assetTransition = (source, target, fromUrl, toUrl) => {
    if (!validManifest(source) || !validManifest(target)) return rejectCompatibility('manifest_missing');
    const allStylesheets = domCss();
    const oldCss = source.css.map(x => absoluteCss(x, fromUrl));
    const newCss = target.css.map(x => absoluteCss(x, toUrl));
    // Analytics and embedded widgets may append their own third-party CSS
    // after the initial HTML loads. Those nodes remain mounted, but they are
    // not part of the server's WordPress stylesheet bundle authority.
    const localBundles = allStylesheets.filter(item => {
      try {
        const uri = new URL(item.href);
        return uri.origin === origin && /\/litespeed\/css\/[^/]+\.css$/i.test(uri.pathname)
          && item.rel.includes('stylesheet');
      } catch (_) { return false; }
    });
    const merged = isLocalCombinedCss(localBundles, oldCss)
      && allStylesheets.every(item => localBundles.includes(item)
        || new URL(item.href).origin !== origin);
    const existing = merged ? localBundles : allStylesheets;
    const browserRecords = existing.map(cssSignature);
    const oldRecords = oldCss.map(cssSignature);
    const newRecords = newCss.map(cssSignature);
    const oldStyles = source.styles.map(styleSignature);
    const newStyles = target.styles.map(styleSignature);
    const directlyRepresented = sameRecords(browserRecords, oldRecords);
    const swaps = [];
    let cssBase = null;
    if (immutableCssBase) {
      // The original optimized bundle remains mounted for the whole session.
      // No new page may depend on CSS absent from that original set.
      if (!sameRecords(browserRecords, immutableCssBase.browser)
        || !orderedSubset(oldRecords, immutableCssBase.raw)
        || !orderedSubset(newRecords, immutableCssBase.raw)
        || !containedMultiset(oldStyles, immutableCssBase.inline)
        || !containedMultiset(newStyles, immutableCssBase.inline)) return rejectCompatibility('css_cached_bundle_changed');
    } else if ((directlyRepresented || merged)
      && orderedSubset(newRecords, oldRecords)
      && (merged ? containedMultiset(newStyles, oldStyles)
                 : orderedSubset(newStyles, oldStyles))) {
      cssBase = { browser: browserRecords, raw: oldRecords, inline: oldStyles };
    } else if (directlyRepresented && oldCss.length === newCss.length
      && sameRecords(oldStyles, newStyles)) {
      // Non-bundled sites can load a small set of matching stylesheet changes.
      const links = cssLinks();
      for (let i = 0; i < oldCss.length; i++) {
        if (cssLabel(oldCss[i]) !== cssLabel(newCss[i])) return rejectCompatibility('css_labels_changed');
        if (oldCss[i].href === newCss[i].href) continue;
        const dest = new URL(newCss[i].href);
        if (dest.origin !== origin || !dest.pathname.toLowerCase().endsWith('.css')
          || newCss[i].rel !== 'stylesheet' || !links[i]?.closest('head')) return rejectCompatibility('css_swap_unsafe');
        swaps.push({ node: links[i], href: dest.href, id: newCss[i].id, media: newCss[i].media });
      }
      if (swaps.length > 4) return rejectCompatibility('css_swap_limit');
    } else return rejectCompatibility('css_source_destination_incompatible');
    // Scripts still have to be exactly the same server-side resources.
    // Only strictly parsed, known page-context JSON assignments may change.
    const external = (proof, href) => proof.scripts.map(x =>
      resource(x.src || '', href) + '|' + (x.type || '').toLowerCase());
    if (!sameRecords(external(source, fromUrl), external(target, toUrl))) return rejectCompatibility('script_external_mismatch');
    if (source.inlineScripts.length !== target.inlineScripts.length) return rejectCompatibility('script_inline_count');
    const configs = [];
    for (let i = 0; i < source.inlineScripts.length; i++) {
      const a = source.inlineScripts[i], b = target.inlineScripts[i];
      if (a.id !== b.id || a.type !== b.type) return rejectCompatibility('script_inline_shape');
      if (a.sha256 === b.sha256) continue;
      const name = localized[a.id];
      if (!name || a.configName !== name || b.configName !== name
        || !a.configValue || typeof a.configValue !== 'object' || Array.isArray(a.configValue)
        || !b.configValue || typeof b.configValue !== 'object' || Array.isArray(b.configValue)) return rejectCompatibility('script_inline_config');
      configs.push({ name, value: b.configValue });
    }
    return { swaps, configs, cssBase };
  };
  const fetchFragment = async (href, signal) => {
    const request = new URL(href);
    request.searchParams.set('mad4b_shell_fragment', '1');
    request.searchParams.set('mad4b_shell_v', cfg.epoch || '');
    const response = await fetch(request.href, {
      method: 'GET', credentials: 'same-origin', signal,
      headers: { Accept: 'application/vnd.mad4b.shell+json' },
      cache: 'no-store', redirect: 'follow'
    });
    if (!response.ok) {
      // The server uses the same adaptive graph to explain safe Full fallbacks.
      if (response.status === 422 && response.headers.get('content-type')?.includes('application/vnd.mad4b.shell+json')) {
        const info = await response.json().catch(() => null);
        if (typeof info?.reason === 'string' && /^[a-z0-9_]{1,80}$/.test(info.reason))
          throw new Error(info.reason);
      }
      throw new Error('fragment_unavailable');
    }
    if (new URL(response.url).origin !== origin ||
      !response.headers.get('content-type')?.includes('application/vnd.mad4b.shell+json'))
      throw new Error('fragment_unavailable');
    const payload = await response.json();
    if (payload?.navigation?.mode !== 'partial_candidate') {
      const reason = payload?.navigation?.reason || 'adaptive_navigation_unverified';
      throw new Error(/^[a-z0-9_]{1,80}$/.test(reason) ? reason : 'adaptive_navigation_unverified');
    }
    if (!payload?.eligible || !validManifest(payload.proof)) throw new Error('fragment_ineligible');
    return payload;
  };
  const stageStyles = async (swaps, signal) => {
    const staged = [];
    try {
      await Promise.all(swaps.map(item => new Promise((resolve, reject) => {
        const node = document.createElement('link');
        node.rel = 'stylesheet'; node.media = 'print'; node.href = item.href;
        node.dataset.mad4bShellStage = '1';
        let done = false;
        const finish = (error) => {
          if (done) return;
          done = true; clearTimeout(timeout);
          signal.removeEventListener('abort', abort);
          node.onload = null; node.onerror = null;
          if (error) reject(error); else resolve();
        };
        const abort = () => finish(new DOMException('Interrupted', 'AbortError'));
        const timeout = setTimeout(() => finish(new Error('stylesheet_timeout')), 12000);
        node.onload = () => finish(null);
        node.onerror = () => finish(new Error('stylesheet_unavailable'));
        signal.addEventListener('abort', abort, { once: true });
        staged.push({ ...item, target: node });
        document.head.appendChild(node);
        if (signal.aborted) abort();
      })));
      if (signal.aborted) throw new DOMException('Interrupted', 'AbortError');
      return staged;
    } catch (error) {
      staged.forEach(item => item.target.remove());
      throw error;
    }
  };
  const guardFooterReflow = () => {
    // A soft route can collapse a long main region and pull the preserved
    // footer into the visible viewport. Briefly suppress paint during the
    // layout change; do not alter the real footer position or page height.
    const footer = siteEdge(document, 'footer', 'footer');
    if (!footer || !footer.isConnected) return () => {};
    const previousOpacity = footer.style.getPropertyValue('opacity');
    const previousPriority = footer.style.getPropertyPriority('opacity');
    footer.style.setProperty('opacity', '0', 'important');
    let restored = false;
    let backupTimer;
    const restore = () => {
      if (restored) return;
      restored = true;
      window.clearTimeout(backupTimer);
      if (previousOpacity) footer.style.setProperty('opacity', previousOpacity, previousPriority);
      else footer.style.removeProperty('opacity');
    };
    backupTimer = window.setTimeout(restore, 600);
    window.requestAnimationFrame(() => window.requestAnimationFrame(restore));
    return restore;
  };
  const refreshConfigs = configs => {
    for (const { name, value } of configs) {
      const current = window[name];
      if (current && typeof current === 'object' && !Array.isArray(current))
        Object.assign(current, value);
      else window[name] = value;
    }
  };
  const safeMain = root => {
    if (!root || root.querySelector(
      'form, iframe, audio, video, script, [contenteditable], [data-elementor-open-lightbox], ' +
      '.woocommerce, .jet-listing-grid, .jet-smart-filters, .jet-form-builder, ' +
      '.elementor-widget-loop-grid, .swiper, .elementor-widget-form'
    )) return false;
    return !Array.from(root.querySelectorAll('[data-widget_type]'))
      .some(el => !staticWidgets.has(el.getAttribute('data-widget_type')));
  };
  const supportedPage = doc => {
    // Some themes incorrectly add woocommerce-page site-wide. The main region
    // and the server's is_cart/is_checkout/is_account checks are authoritative.
    if (!doc.body?.classList.contains('page') || doc.body.classList.contains('logged-in')
      || doc.body.classList.contains('page-template-elementor-canvas')) return false;
    return safeMain(main(doc));
  };
  const compatible = (sourceDoc, targetDoc, sourceProof, targetProof, url) => {
    if (!sourceDoc || !targetDoc) return rejectCompatibility('synthetic_document');
    if (!supportedPage(document)) return rejectCompatibility('current_main_unsafe');
    if (!supportedPage(sourceDoc)) return rejectCompatibility('source_main_unsafe');
    if (!supportedPage(targetDoc)) return rejectCompatibility('destination_main_unsafe');
    if (document.documentElement.lang.toLowerCase() !== targetDoc.documentElement.lang.toLowerCase())
      return rejectCompatibility('language_changed');
    if (!sameShell(document, sourceDoc)) return rejectCompatibility('source_header_footer_changed');
    if (!sameShell(sourceDoc, targetDoc)) return rejectCompatibility('destination_header_footer_changed');
    return assetTransition(sourceProof, targetProof, location.href, url);
  };
  const requestedLink = ev => {
    if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return null;
    const a = ev.target?.closest?.('a[href]');
    if (!a || a.target || a.download || a.hasAttribute('data-no-pjax')) return null;
    // WooCommerce may add a global `woocommerce` class to BODY even on a
    // simple About page. Only reject links actually inside a commerce widget.
    const blocked = a.closest('form,[data-mad4b-full-navigation],.woocommerce,.jet-smart-filters,.wpml-ls');
    if (blocked && blocked !== document.body && blocked !== document.documentElement) return null;
    const href = a.getAttribute('href') || '';
    if (!href || href[0] === '#' || href[0] === '?' || a.hasAttribute('hreflang')) return null;
    let url;
    try { url = new URL(a.href, location.href); } catch (_) { return null; }
    if (url.origin !== origin || url.search || url.hash
      || !/^https?:$/.test(url.protocol) || url.href === window.location.href
      || /\.(?:pdf|zip|jpe?g|png|webp|svg|css|js|xml)$/i.test(url.pathname)) return null;
    return url;
  };
  const syntheticDocument = data => {
    if (!data || !data.eligible || !data.main || !data.head
      || !data.htmlTag || !data.bodyTag || !data.headerTag || !data.footerTag) return null;
    const safeParts = [data.htmlTag, data.bodyTag, data.headerTag, data.footerTag]
      .every(tag => typeof tag === 'string' && tag.length < 15000 && !tag.includes('<script'));
    if (!safeParts || data.main.length > 4000000 || data.head.length > 2000000) return null;
    const html = '<!doctype html>' + data.htmlTag + '<head>' + data.head + '</head>'
      + data.bodyTag + data.headerTag + '</header>'
      + data.main + data.footerTag + '</footer>'
      + '</body></html>';
    return parser.parseFromString(html, 'text/html');
  };
  const refreshHead = next => {
    const select = 'link[rel="canonical"],link[rel="alternate"][hreflang],meta[name="description"],' +
      'meta[property^="og:"],meta[name^="twitter:"],script[type="application/ld+json"]';
    document.head.querySelectorAll(select).forEach(n => n.remove());
    next.head.querySelectorAll(select).forEach(n => document.head.appendChild(document.importNode(n, true)));
  };
  const refreshMenu = url => {
    // Standard WordPress menu classes; preserve the header/footer DOM and event listeners.
    for (const a of document.querySelectorAll('header a[href]')) {
      let isCurrent = false;
      try { isCurrent = new URL(a.href, location.href).href === url; } catch (_) {}
      if (a.hasAttribute('aria-current')) a.removeAttribute('aria-current');
      if (a.parentElement?.tagName === 'LI') a.parentElement.classList.remove('current-menu-item', 'current_page_item');
      if (isCurrent) {
        a.setAttribute('aria-current', 'page');
        if (a.parentElement?.tagName === 'LI') a.parentElement.classList.add('current-menu-item', 'current_page_item');
      }
    }
  };
  let inFlight = null;
  let currentManifest = null;
  let hasPartialNavigation = false;
  document.addEventListener('click', async event => {
    const url = requestedLink(event);
    if (!url || !supportedPage(document) || !siteShell(document).every(Boolean)) return;
    event.preventDefault();
    inFlight?.abort();
    const controller = new AbortController();
    inFlight = controller;
    const previous = main(document);
    if (!previous) return hardNav(url.href);
    previous.setAttribute('aria-busy', 'true');
    let restoreFooterPaint = null;
    try {
      // Compare two server-side templates. Do not mistake analytics scripts
      // injected at runtime for missing WordPress dependencies.
      const here = location.href;
      const [sourcePayload, targetPayload] = await Promise.all([
        currentManifest?.url === here ? Promise.resolve(currentManifest.payload)
          : fetchFragment(here, controller.signal),
        fetchFragment(url.href, controller.signal)
      ]);
      if (controller.signal.aborted) return;
      const sourceDoc = syntheticDocument(sourcePayload);
      const next = syntheticDocument(targetPayload);
      const plan = compatible(sourceDoc, next, sourcePayload.proof,
        targetPayload.proof, url.href);
      if (!plan) {
        // rejectCompatibility already recorded the exact incompatibility reason.
        return hardNav(url.href);
      }
      const staged = await stageStyles(plan.swaps, controller.signal);
      if (controller.signal.aborted) {
        staged.forEach(item => item.target.remove());
        return;
      }
      if (staged.some(item => !item.node.isConnected))
        throw new Error('stylesheet_changed_during_fetch');
      const incoming = document.importNode(main(next), true);
      restoreFooterPaint = guardFooterReflow();
      // CSS has been downloaded with media=print; activate at the exact point
      // of replacing its matching old asset, without retaining page CSS forever.
      staged.forEach(item => {
        item.target.removeAttribute('data-mad4b-shell-stage');
        if (item.id) item.target.id = item.id;
        if (item.media) item.target.media = item.media;
        else item.target.removeAttribute('media');
        item.node.replaceWith(item.target);
      });
      previous.replaceWith(incoming);
      refreshHead(next);
      document.title = next.title;
      document.body.className = next.body.className;
      refreshConfigs(plan.configs);
      // Mark the previous full-document entry as well: if the browser later
      // reloads it, back/forward must still reconcile the URL and the DOM.
      if (!hasPartialNavigation) {
        const oldState = history.state;
        const preserved = oldState && typeof oldState === 'object' && !Array.isArray(oldState)
          ? { ...oldState } : {};
        history.replaceState({ ...preserved, mad4bShellBase: true }, '', location.href);
      }
      history.pushState({ mad4bShell: true }, '', url.href);
      currentManifest = { url: url.href, payload: targetPayload };
      if (plan.cssBase && !immutableCssBase) immutableCssBase = plan.cssBase;
      refreshMenu(url.href);
      window.scrollTo({ top: 0, behavior: 'auto' });
      const heading = incoming.querySelector('h1');
      if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus({ preventScroll: true });
      }
      recordDecision('partial', 'success');
      hasPartialNavigation = true;
      document.dispatchEvent(new CustomEvent('mad4b:shell:navigated', {
        detail: { url: url.href, root: incoming }
      }));
    } catch (err) {
      restoreFooterPaint?.();
      if (err?.name !== 'AbortError') {
        recordDecision('full', err?.message || 'fallback_unknown');
        hardNav(url.href);
      }
    } finally {
      if (inFlight === controller) {
        inFlight = null;
        main(document)?.removeAttribute('aria-busy');
      }
    }
  }, true);
  // Browsers can restore the partial-navigation history entry after a separate
  // full reload of the previous entry. The new document has no in-memory
  // hasPartialNavigation flag, so check the persistent history entry marker too.
  window.addEventListener('popstate', event => {
    if (hasPartialNavigation || event.state?.mad4bShell === true
      || event.state?.mad4bShellBase === true) location.reload();
  });
})();
