/* MAD4B Destination Contents — Elementor source-order navigation.
 * Original Elementor TOC is the authority for sequence, labels and anchors.
 * Progressive enhancement: original stays usable if native initialization fails.
 */
(function () {
  'use strict';
  if (!document.body || !document.body.classList.contains('tax-destinations')) return;

  var SELECTOR = '[data-mad4b-destination-contents="1"]';
  var TOC = '.elementor-widget-table-of-contents';
  var LINK = '.elementor-toc__body a.elementor-toc__list-item-text[href^="#"]';
  var RULES = [
    /\b(about|overview|introduction|history|general|highlights|geography)\b|عن|نبذة|مقدمة|نظرة عامة|présentation|aperçu|geschichte|überblick|panoramica|resumen|historia/i,
    /\b(activities|excursions|attractions|things to do|tours|diving|experiences|inspiration|adventure|spots)\b|الأنشطة|التجارب|الجولات|المعالم|activit[eé]s|excursions|attivit[aà]|actividades|atracciones|ausfl[uü]ge/i,
    /\b(nearby|destinations|zones|areas|cities|government|states|regions|hierarchy|places|explore|must visit|landmarks)\b|مدن|المناطق|الأماكن|وجهات|استكشف|مجاورة|villes|r[eé]gions|orte|st[aä]dte|luoghi|citt[aà]|lugares|ciudades|regiones/i,
    /\b(plan|ready|booking|inquiry|contact|travel tips|when to visit|next trip|journey|personal|faq)\b|خطط|الحجز|استعد|التخطيط|السفر|r[eé]servation|voyage|planung|buchung|viaggio|prenotazione|reserva|viaje|planifica/i
  ];
  var mounted = new WeakMap();
  var watched = new WeakMap();
  var media = window.matchMedia('(max-width: 767px)');

  function element(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function titleOf(node) {
    return (node && node.textContent || '').replace(/\s+/g, ' ').trim();
  }
  function category(title, index) {
    if (RULES[3].test(title)) return 3;
    if (RULES[2].test(title)) return 2;
    if (RULES[1].test(title)) return 1;
    if (RULES[0].test(title)) return 0;
    return index < 3 ? 0 : index < 8 ? 1 : index < 13 ? 2 : 3;
  }
  function validHash(href) {
    return /^#[A-Za-z][A-Za-z0-9_:.-]{0,120}$/.test(href || '');
  }
  function ownerDocument(root) {
    return root.closest('.elementor[data-elementor-id]') || root.closest('.elementor') || document;
  }
  function sourceFor(root) {
    var owner = ownerDocument(root);
    var widgets = owner.querySelectorAll(TOC);
    var winner = null, largest = -1;
    widgets.forEach(function (widget) {
      if (widget.contains(root)) return;
      var count = widget.querySelectorAll(LINK).length;
      if (count > largest) { winner = widget; largest = count; }
    });
    return winner;
  }
  function candidatesFor(owner, label) {
    var result = [];
    owner.querySelectorAll('h1,h2,h3,h4,h5,h6').forEach(function (heading) {
      if (heading.closest(TOC) || heading.closest(SELECTOR)) return;
      if (titleOf(heading) !== label) return;
      result.push(heading);
    });
    // Prefer rendered headings and nodes without an ID over hidden clones.
    result.sort(function (a, b) {
      var av = !!a.getClientRects().length, bv = !!b.getClientRects().length;
      if (av !== bv) return av ? -1 : 1;
      if (!!a.id !== !!b.id) return a.id ? 1 : -1;
      return 0;
    });
    return result;
  }
  function restoreAnchor(owner, label, href, used) {
    var target = document.getElementById(href.slice(1));
    if (target) {
      // Prefer a genuine Elementor target over an earlier inserted alias
      // when a responsive re-render eventually recreates the original ID.
      if (target.hasAttribute('data-mad4b-restored-anchor')) {
        var same = owner.querySelectorAll('[id]');
        for (var i=0;i<same.length;i++) {
          if (same[i] !== target && same[i].id === target.id) {
            target.remove();
            target=same[i];
            break;
          }
        }
      }
      used.add(target.hasAttribute('data-mad4b-restored-anchor') ?
        (target.nextElementSibling || target) :
        (target.closest('h1,h2,h3,h4,h5,h6') || target));
      return true;
    }
    // Repair a missing Elementor target on responsive re-render without
    // assigning duplicate IDs to cloned headings. Restore the original
    // link by inserting an independent, zero-height anchor adjacent to a
    // corresponding heading; the original Elementor fallback also benefits.
    var headings = candidatesFor(owner, label);
    var heading = headings.find(function (candidate) {
      return !used.has(candidate) && candidate.isConnected;
    });
    if (!heading || !heading.parentNode || !validHash(href)) return false;
    var alias = element('span', 'mad4b-destination-anchor-alias');
    alias.id = href.slice(1);
    alias.setAttribute('data-mad4b-restored-anchor', '1');
    alias.setAttribute('aria-hidden', 'true');
    heading.parentNode.insertBefore(alias, heading);
    used.add(heading);
    return true;
  }
  function readItems(root, source) {
    if (!source) return null;
    var owner = ownerDocument(root), used = new Set(), results = [];
    var links = source.querySelectorAll(LINK);
    if (!links.length) return null;
    var missing = [];
    links.forEach(function (link) {
      var href = link.getAttribute('href') || '';
      var label = titleOf(link);
      if (!label || !validHash(href)) return;
      if (!restoreAnchor(owner, label, href, used)) {
        missing.push(href);
      } else {
        results.push({ text: label, href: href });
      }
    });
    root.dataset.mad4bMissingAnchors = String(missing.length);
    if (missing.length || !results.length) return null;
    return results;
  }
  function groupRuns(items, grouping) {
    if (grouping === 'topic') {
      var topics = [[],[],[],[]];
      items.forEach(function (item, index) { topics[category(item.text,index)].push(item); });
      return topics.map(function (part, i) { return {category:i, items:part}; }).filter(function (part) { return part.items.length; });
    }
    // In source mode the flattened link order is ALWAYS the same as
    // Elementor's TOC, even when the same group name appears twice.
    var runs = [];
    items.forEach(function (item, index) {
      var c = category(item.text, index);
      var previous = runs[runs.length-1];
      if (!previous || previous.category !== c) runs.push({category:c,items:[]});
      runs[runs.length-1].items.push(item);
    });
    return runs;
  }
  function setReady(root, yes) {
    root.classList.toggle('is-ready', !!yes);
    root.dataset.mad4bParity = yes ? 'complete' : 'pending';
    var found = false;
    document.querySelectorAll(SELECTOR).forEach(function (node) {
      if (node.classList.contains('is-ready')) found = true;
    });
    document.body.classList.toggle('mad4b-native-destination-toc-ready', found);
    // Hide only the Elementor TOC paired with this native widget.
    // No hard-coded Elementor template, widget ID or taxonomy term ID.
    var source = sourceFor(root);
    if (source) source.classList.toggle('mad4b-destination-source-suppressed', !!yes);
  }
  function render(root, items) {
    var labels;
    try { labels = JSON.parse(root.getAttribute('data-group-labels') || '[]'); }
    catch (e) { return false; }
    if (!Array.isArray(labels) || labels.length !== 5) return false;
    var panel = root.querySelector('[data-mad4b-destination-panel="1"]');
    if (!panel) return false;
    var grouping = root.getAttribute('data-group-mode') === 'topic' ? 'topic' : 'source';
    var signature = grouping + ':' + items.map(function (item) {
      return item.href + '\u001f' + item.text;
    }).join('\u001e');
    if (signature === root.dataset.mad4bContentSignature) return true;
    var openCategories = new Set();
    panel.querySelectorAll('details[open][data-category]').forEach(function (details) {
      openCategories.add(details.getAttribute('data-category'));
    });
    var fragment = document.createDocumentFragment();
    groupRuns(items, grouping).forEach(function (run, groupIndex) {
      var detail = element('details','mad4b-tour-toc__group');
      detail.setAttribute('data-category',String(run.category));
      detail.setAttribute('data-group-index',String(groupIndex));
      if (openCategories.has(String(run.category))) detail.open = true;
      detail.appendChild(element('summary','',labels[run.category+1]));
      var ul = element('ul');
      run.items.forEach(function (item) {
        var li = element('li'), link = element('a','',item.text);
        link.setAttribute('href',item.href);
        li.appendChild(link);ul.appendChild(li);
      });
      detail.appendChild(ul);
      fragment.appendChild(detail);
    });
    panel.replaceChildren(fragment);
    var count = root.querySelector('.mad4b-tour-toc__count');
    if (count) count.textContent = String(items.length);
    root.dataset.mad4bContentSignature = signature;
    root.dataset.mad4bCount = String(items.length);
    root.dataset.mad4bDuplicateAnchors = String(
      items.length - new Set(items.map(function (item) { return item.href; })).size
    );
    return true;
  }
  function mount(root) {
    if (mounted.has(root)) return;
    var owner = ownerDocument(root), pending = false, disposed = false;
    var drawer = root.querySelector('.mad4b-tour-toc__drawer');
    function refresh() {
      pending = false;
      if (disposed || !root.isConnected) return;
      var source = sourceFor(root);
      var items = readItems(root, source);
      setReady(root, !!items && render(root, items));
    }
    function schedule() {
      if (pending || disposed) return;
      pending = true;
      window.requestAnimationFrame(refresh);
    }
    root.addEventListener('click', function (event) {
      if (drawer && event.target.closest('a[href^="#"]')) drawer.open = false;
    });
    if (drawer) drawer.addEventListener('toggle', function () {
      var wrapper = root.closest('.elementor-widget-mad4b-destination-contents');
      if (wrapper) wrapper.classList.toggle('mad4b-destination-drawer-open', drawer.open);
      if (drawer.open) schedule();
    });
    if (media.addEventListener) media.addEventListener('change', schedule);
    else if (media.addListener) media.addListener(schedule);
    // Observe only the Elementor document; do not re-render in response
    // to our own summary, link or alias changes.
    if (!watched.has(owner)) {
      var observer = new MutationObserver(function (records) {
        var relevant = records.some(function (record) {
          var target = record.target.nodeType === 1 ? record.target : record.target.parentElement;
          if (!target) return false;
          if (target.closest && target.closest(SELECTOR)) return false;
          if (target.closest && target.closest('[data-mad4b-restored-anchor="1"]')) return false;
          if (record.type === 'attributes' && record.attributeName !== 'id') return false;
          return true;
        });
        if (!relevant) return;
        owner.querySelectorAll(SELECTOR).forEach(function (node) {
          if (!mounted.has(node)) mount(node);
          var state = mounted.get(node);
          if (state) state.schedule();
        });
      });
      observer.observe(owner, {childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['id']});
      watched.set(owner, observer);
    }
    mounted.set(root, {schedule:schedule,dispose:function () {
      disposed = true;
      if (media.removeEventListener) media.removeEventListener('change',schedule);
      else if (media.removeListener) media.removeListener(schedule);
    }});
    // Elementor may hydrate TOC links asynchronously. Observers handle
    // mutations; bounded retries handle batches that don't mutate the owner.
    [0,200,700,2000,6000].forEach(function (delay) {
      window.setTimeout(schedule,delay);
    });
  }
  function init() {
    document.querySelectorAll(SELECTOR).forEach(mount);
  }
  init();
  if (window.elementorFrontend && window.elementorFrontend.hooks) {
    window.elementorFrontend.hooks.addAction(
      'frontend/element_ready/mad4b-destination-contents.default', init
    );
  } else {
    window.addEventListener('elementor/frontend/init',function () {
      if (window.elementorFrontend && window.elementorFrontend.hooks)
        window.elementorFrontend.hooks.addAction(
          'frontend/element_ready/mad4b-destination-contents.default', init
        );
    },{once:true});
  }
})();
