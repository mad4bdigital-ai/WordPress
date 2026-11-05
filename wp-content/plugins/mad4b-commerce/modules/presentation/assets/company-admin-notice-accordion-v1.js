/* MAD4B Company Admin Notices v1. Progressive enhancement only.
 * No notice text is changed, suppressed, marked dismissed, or sent to a server.
 * Keep original DOM nodes, forms, links, nonce inputs and event listeners.
 * Native <details> supplies semantic and keyboard-accessible nested disclosure.
 */
(function () {
  'use strict';
  function releaseFirstPaint(){
    document.documentElement.classList.remove('mad4b-admin-notice-boot');
  }
  function initialize(){
  if (!document.body || !document.body.classList.contains('wp-admin')){
    releaseFirstPaint();return;
  }
  const scope = document.querySelector('#wpbody-content');
  if (!scope || scope.dataset.mad4bNoticeAccordionReady === '1'){
    releaseFirstPaint();return;
  }
  scope.dataset.mad4bNoticeAccordionReady = '1';
  const labels = {
    title: 'Notifications',
    description: 'Grouped by plugin family and issue type. Original actions remain available.',
    errorLabel: 'errors', warningLabel: 'warnings',
    families: [
      ['mad4b', 'MAD4B · Companies', /mad4b|contenthub|company[\s_-]?manager|company[\s_-]?reconcile/i],
      ['jet', 'JetEngine & Crocoblock', /jet[\s_-]?engine|jet[\s_-]?smart|jet[\s_-]?filter|jet[\s_-]?form|jet[\s_-]?woo|crocoblock/i],
      ['elementor', 'Elementor', /elementor/i],
      ['wpml', 'WPML · Languages', /wpml|sitepress|wcml|icl[\s_-]/i],
      ['woo', 'WooCommerce', /woocommerce|woo[\s_-]?commerce|wc[\s_-]?admin|wc[\s_-]?notice/i],
      ['fluent', 'Fluent Suite', /fluent[\s_-]?(crm|form|board|smtp)|fluentcrm|fluentform/i],
      ['seo', 'SEO & Analytics', /rank[\s_-]?math|seo|analytics|google[\s_-]?site[\s_-]?kit/i],
      ['astra', 'Theme & Design', /astra|theme[\s_-]?builder/i],
      ['other', 'WordPress & Other', /.*/]
    ],
    topics: {
      critical: 'Errors · Action required',
      warning: 'Warnings · Review',
      update: 'Updates & Compatibility',
      security: 'Security & Access',
      content: 'Content & Translation',
      performance: 'Performance & Cache',
      success: 'Completed',
      info: 'Information & Settings'
    }
  };
  // Labels are resolved per-user by WordPress on each admin request, not
  // copied from the company source or the currently selected frontend locale.
  const localized=window.MAD4BCompanyAdminNoticeLabels||{};
  for(const key of ['title','description','errorLabel','warningLabel'])
    if(typeof localized[key]==='string'&&localized[key].length<170)labels[key]=localized[key];
  if(localized.topics && typeof localized.topics==='object')
    for(const [key,value] of Object.entries(localized.topics))
      if(Object.hasOwn(labels.topics,key)&&typeof value==='string'&&value.length<120)labels.topics[key]=value;
  if(localized.families && typeof localized.families==='object')
    for(const family of labels.families)
      if(typeof localized.families[family[0]]==='string' && localized.families[family[0]].length<120)
        family[1]=localized.families[family[0]];
  // Admin screens do not share one notice location. edit.php/post.php and
  // plugin dashboards may nest screen-level banners under .wrap, .postbox,
  // content headers or company containers. Discover the entire wpbody scope,
  // but never absorb in-form inline messages or editor-local UI alerts.
  const observedSelector = '.notice, .updated, .error, .update-nag';
  const localNoticeSelectors = [
    '.mad4b-admin-notice-hub','[hidden]','.hidden',
    '.inline-edit-row','.inside','.postbox','.components-modal__content',
    '.components-snackbar-list','.editor-post-publish-panel',
    '#poststuff','#edittag','.wp-list-table',
    '.media-modal','.media-frame','.wp-dialog',
    '[role="dialog"]','[role="alertdialog"]','form'
  ].join(',');
  const eligible = node => node instanceof HTMLElement &&
    !node.closest(localNoticeSelectors) &&
    !node.classList.contains('hidden') && !node.classList.contains('inline') &&
    !node.classList.contains('notice-inline') &&
    !node.hasAttribute('data-mad4b-notice-no-group') &&
    !node.querySelector('script,iframe') &&
    !!(node.textContent||'').trim();
  const candidates = () => [...scope.querySelectorAll(observedSelector)]
    .filter(eligible);
  const descriptor = el => {
    const identifiers = [el.id, el.className,el.dataset.plugin,el.dataset.pluginName,
      el.dataset.noticeFamily,el.dataset.mad4bFamily,el.querySelector('a[href*="page="]')?.getAttribute('href')]
      .filter(x => typeof x === 'string').join(' ');
    // Text is only a fallback for known plugin product names; never classify
    // an ordinary notification as an error solely from its text content.
    const intro = (el.textContent || '').slice(0, 200);
    const family = labels.families.find(([id, , pattern]) => id !== 'other' &&
      (pattern.test(identifiers) || pattern.test(intro))) || labels.families.at(-1);
    const c = (el.className || '').toLowerCase();
    let topic = 'info';
    if (/notice-error|\berror\b|notice-critical|is-critical/.test(c)) topic = 'critical';
    else if (/notice-warning|warning|notice-alt/.test(c)) topic = 'warning';
    else if (/notice-success|updated|success/.test(c)) topic = 'success';
    else if (/update-nag|update-message|update-plugins|upgrade/.test(c)) topic = 'update';
    else if (/security|access|permission|authentication/.test(identifiers)) topic = 'security';
    else if (/translation|wpml|content|jet-engine/.test(identifiers)) topic = 'content';
    else if (/performance|cache|speed|optimization/.test(identifiers)) topic = 'performance';
    const override = (el.dataset.mad4bNoticeGroup || '').trim();
    if (Object.hasOwn(labels.topics, override)) topic = override;
    return { family, topic, urgent: topic === 'critical' || topic === 'warning' };
  };
  const make = (tag, cls, text) => {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text) el.textContent = text;
    return el;
  };
  let hub = null;
  let scheduled = false;
  let rebuilding = false;
  let observer;
  function counts() {
    if (!hub) return;
    const allNotices=[...hub.querySelectorAll('[data-mad4b-notice-managed="1"]')]
      .filter(notice => notice.isConnected && !notice.classList.contains('hidden') && !notice.hasAttribute('hidden'));
    const errors=allNotices.filter(notice => descriptor(notice).topic==='critical').length;
    const warnings=allNotices.filter(notice => descriptor(notice).topic==='warning').length;
    const updateSummary=(cls,value) => {
      const el=hub.querySelector(':scope > summary > .'+cls);
      if(el && el.textContent!==value)el.textContent=value;
    };
    updateSummary('mad4b-notice-global-count',String(allNotices.length));
    updateSummary('mad4b-notice-global-severity',
      [errors ? errors+' '+labels.errorLabel : '',warnings ? warnings+' '+labels.warningLabel : '']
      .filter(Boolean).join(' · '));
    for (const detail of hub.querySelectorAll('details[data-mad4b-group]')) {
      let count = 0;
      for (const notice of detail.querySelectorAll('[data-mad4b-notice-managed="1"]')) {
        if (notice.isConnected && !notice.classList.contains('hidden') && !notice.hasAttribute('hidden')) count++;
      }
      const badge = detail.querySelector(':scope > summary > .mad4b-notice-count');
      if (badge && badge.textContent !== String(count)) badge.textContent = String(count);
      // Removing a notice never silently removes the overall notification hub.
    }
  }
  function rebuild() {
    scheduled = false;
    if (rebuilding) return;
    const fresh = candidates();
    if (!fresh.length) { counts(); return; }
    rebuilding = true;
    try {
      if (!hub) {
        // A single, native, keyboard-accessible master disclosure. Always
        // collapsed initially, including when there are critical notices.
        // Severity stays readable in the summary without occupying the page.
        hub = make('details', 'mad4b-admin-notice-hub');
        hub.dataset.mad4bNoticeRoot = '1';
        const first = fresh[0];
        const anchor = first.closest('.wrap') || first.parentElement || scope;
        anchor.insertBefore(hub, anchor.firstChild);
        const summary = make('summary','mad4b-admin-notice-master-summary');
        summary.append(make('span','mad4b-notice-master-title',labels.title));
        summary.append(make('span','mad4b-notice-global-count','0'));
        summary.append(make('span','mad4b-notice-global-severity',''));
        hub.append(summary);
        const content=make('div','mad4b-admin-notice-master-content');
        const heading=make('p','mad4b-admin-notice-description',labels.description);
        content.append(heading,make('div','mad4b-admin-notice-families'));
        hub.append(content);
      }
      const families = hub.querySelector('.mad4b-admin-notice-families');
      for (const notice of fresh) {
        const data = descriptor(notice);
        const fid = data.family[0];
        let group = families.querySelector(`:scope > details[data-mad4b-group="${fid}"]`);
        if (!group) {
          group = make('details','mad4b-notice-family');
          group.dataset.mad4bGroup = fid;
          const summary = make('summary', 'mad4b-notice-summary');
          summary.append(make('span','mad4b-notice-summary-label',data.family[1]));
          summary.append(make('span','mad4b-notice-count','0'));
          group.append(summary,make('div','mad4b-notice-family-body'));
          families.append(group);
        }
        if (data.urgent) group.open = true;
        const familyBody = group.querySelector(':scope > .mad4b-notice-family-body');
        let topic = familyBody.querySelector(`:scope > details[data-mad4b-group="${data.topic}"]`);
        if (!topic) {
          topic = make('details','mad4b-notice-topic');
          topic.dataset.mad4bGroup = data.topic;
          const summary = make('summary', 'mad4b-notice-topic-summary');
          summary.append(make('span','mad4b-notice-summary-label',labels.topics[data.topic]));
          summary.append(make('span','mad4b-notice-count','0'));
          topic.append(summary,make('div','mad4b-notice-topic-body'));
          familyBody.append(topic);
        }
        if (data.urgent) topic.open = true;
        notice.dataset.mad4bNoticeManaged = '1';
        topic.querySelector(':scope > .mad4b-notice-topic-body').append(notice);
      }
      counts();
    } finally { rebuilding = false; }
  }
  // Group before the first post-DOMContentLoaded paint. A requestAnimationFrame
  // here makes the native notices flash on slow WordPress admin pages.
  if (candidates().length >= 1) rebuild();
  observer = new MutationObserver(records => {
    const hasExternalNotice = records.some(r => [...r.addedNodes].some(n =>
      n instanceof HTMLElement && !n.closest('.mad4b-admin-notice-hub') &&
      (n.matches('.notice,.updated,.error,.update-nag') || n.querySelector('.notice,.updated,.error,.update-nag'))));
    // MutationObserver runs before paint: group plugin notices immediately.
    if (hasExternalNotice) rebuild();
    else if (records.some(r => r.target.closest?.('.mad4b-admin-notice-hub') &&
        [...r.removedNodes].some(n => n instanceof HTMLElement &&
          (n.matches('[data-mad4b-notice-managed="1"]') ||
           n.querySelector('[data-mad4b-notice-managed="1"]'))))) counts();
  });
  observer.observe(scope,{childList:true,subtree:true});
  scope.addEventListener('click',e => {
    if (e.target.closest?.('.mad4b-admin-notice-hub .notice-dismiss')) setTimeout(counts,150);
  });
  // Preserve the original notices. The only prepaint suppression is a
  // fail-open documentElement boot flag used until source-owned DOM grouping.
  releaseFirstPaint();
  }
  if(document.readyState==='loading')
    document.addEventListener('DOMContentLoaded',initialize,{once:true});
  else initialize();
})();
