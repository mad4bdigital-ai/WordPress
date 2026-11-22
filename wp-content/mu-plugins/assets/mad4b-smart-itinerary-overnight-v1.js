/* MAD4B Smart Itinerary Overnight Editor
 * No jquery dependency; native JetEngine field values are never rewritten.
 * Eligibility changes only which new choices the editor can make.
 */
(function () {
  'use strict';
  function lookup(matrix, day, city, kind, total) {
    if (!matrix || !Number.isInteger(day) || day < 0) return false;
    if (day >= total - 1) return kind === 'none' && city === 'none';
    if (kind === 'none' || city === 'none') return false;
    if (kind === 'hotel' || kind === 'resort') {
      return Boolean(matrix.hotels && matrix.hotels[city]
        && matrix.hotels[city][kind] && matrix.hotels[city][kind].count > 0);
    }
    var special = matrix.day_specific && matrix.day_specific[String(day)];
    if (!special) return false;
    if (kind === 'cruise' && city === 'nile_cruise')
      return Boolean(special.cruise && special.cruise.editorial_eligible);
    if (kind === 'sleeper_train' && city === 'train')
      return Boolean(special.sleeper_train && special.sleeper_train.editorial_eligible);
    return false;
  }
  function choices(matrix, day, total) {
    var allowedTypes = new Set(), allowedDest = new Set();
    var dests = ['cairo', 'luxor', 'aswan', 'sharm', 'hurghada', 'nile_cruise', 'train', 'none'];
    var kinds = ['hotel', 'resort', 'cruise', 'sleeper_train', 'none'];
    for (var c of dests) for (var k of kinds) if (lookup(matrix, day, c, k, total)) {
      allowedTypes.add(k); allowedDest.add(c);
    }
    return {types: allowedTypes, destinations: allowedDest};
  }
  if (typeof module !== 'undefined' && module.exports)
    module.exports = {lookup: lookup, choices: choices};
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  var cfg = window.MAD4BSmartOvernightV1;
  if (!cfg || !cfg.action || !cfg.nonce) return;
  var lang = cfg.labels || {}, data = null, state = 'loading', lastQueryHash = '';
  var selectors = [
    'select[name*="mad4b_overnight_kind"]',
    'select[id*="mad4b_overnight_kind"]',
    '[data-field-name="mad4b_overnight_kind"] select',
    'select[data-name="mad4b_overnight_kind"]'
  ].join(',');

  function findFields() {
    var kinds = Array.from(document.querySelectorAll(selectors));
    // Gutenberg and the old WordPress editor can instantiate JetEngine in
    // different panels; preserve the relative order within the same repeater.
    var dests = Array.from(document.querySelectorAll([
      'select[name*="mad4b_overnight_destination"]',
      'select[id*="mad4b_overnight_destination"]',
      '[data-field-name="mad4b_overnight_destination"] select',
      'select[data-name="mad4b_overnight_destination"]'
    ].join(',')));
    function unique(xs) { return Array.from(new Set(xs)); }
    kinds = unique(kinds); dests = unique(dests);
    var rows = [];
    kinds.forEach(function (kind, idx) {
      var name = kind.name || kind.id || '';
      var match = name.match(/item[-_](\d+)/i);
      var rowID = match ? parseInt(match[1], 10) : idx;
      var dest = dests.find(function (candidate) {
        var m = (candidate.name || candidate.id || '').match(/item[-_](\d+)/i);
        return m && parseInt(m[1], 10) === rowID;
      }) || dests[idx];
      if (dest && !rows.some(function (r) { return r.kind === kind; }))
        rows.push({kind: kind, dest: dest, position: idx});
    });
    return rows;
  }
  function noticeEl(select) {
    var holder = select.closest('.cx-control,.cx-vui-repeater-item,.jet-engine-meta-field,.components-base-control') || select.parentElement;
    if (!holder) return null;
    var note = holder.querySelector(':scope > .mad4b-overnight-eligibility-note');
    if (note) return note;
    note = document.createElement('p');
    note.className = 'mad4b-overnight-eligibility-note';
    note.setAttribute('role', 'status');
    note.setAttribute('aria-live', 'polite');
    note.style.cssText = 'font-size:12px;margin:6px 0;color:#44546c;line-height:1.45';
    holder.appendChild(note);
    return note;
  }
  function renderOptions(select, set, current, field) {
    Array.from(select.options).forEach(function (opt) {
      var key = opt.value || '';
      var label = data && data.choice_labels && data.choice_labels[field]
        ? data.choice_labels[field][key] : null;
      if (label && opt.textContent !== label) opt.textContent = label;
      var active = !key || set.has(key) || (key === current && current !== '');
      // Never hide/disable the already saved value, even if now ineligible.
      // Its exact value remains submitted, and a warning asks for review.
      if (opt.hidden !== !active) opt.hidden = !active;
      if (opt.disabled !== !active) opt.disabled = !active;
    });
  }
  function render() {
    var fields = findFields();
    if (!fields.length) return;
    var count = fields.length;
    for (var i = 0; i < fields.length; i++) {
      var row = fields[i], kind = row.kind, dest = row.dest;
      var currentKind = kind.value || '', currentDest = dest.value || '';
      var note = noticeEl(kind);
      if (!note) continue;
      if (state !== 'ready' || !data) {
        var allowedOnFailure = new Set();
        renderOptions(kind, allowedOnFailure, currentKind, 'kind');
        renderOptions(dest, allowedOnFailure, currentDest, 'destination');
        note.textContent = state === 'loading' ? (lang.checking || 'Checking eligibility…')
           : state === 'new' ? (lang.save_first || 'Save the Tour draft first')
           : (lang.failed || 'Eligibility check unavailable');
        note.style.color = '#9a4c0a';
        continue;
      }
      var set = choices(data, i, count);
      var types = set.types, destinations = set.destinations;
      // When editing one side, filter its partner based on the new value.
      if (currentDest) types = new Set(Array.from(types).filter(function (t) {
        return lookup(data, i, currentDest, t, count);
      }));
      if (currentKind) destinations = new Set(Array.from(destinations).filter(function (d) {
        return lookup(data, i, d, currentKind, count);
      }));
      renderOptions(kind, types, currentKind, 'kind');
      renderOptions(dest, destinations, currentDest, 'destination');
      var invalidPair = currentKind && currentDest &&
        !lookup(data, i, currentDest, currentKind, count);
      if (invalidPair) {
        var reason = !data.tiers || !data.tiers.length ? lang.no_tiers
          : currentKind === 'resort' ? lang.no_resort
          : currentKind === 'sleeper_train' ? lang.no_train
          : currentKind === 'cruise' ? lang.no_cruise : '';
        note.textContent = (lang.saved || 'Existing selection needs review')
          + (reason ? ' · ' + reason : '');
        note.style.color = '#a43e27';
      } else if (i === count - 1) {
        note.textContent = lang.departure || 'Departure day: no overnight';
        note.style.color = '#385c6b';
      } else if (!set.types.size) {
        note.textContent = lang.nocatalog || 'No qualifying services for this day';
        note.style.color = '#9a4c0a';
      } else if (!currentDest) {
        note.textContent = lang.choose || 'Select the overnight destination';
        note.style.color = '#44546c';
      } else {
        var type = currentKind;
        var more = type === 'cruise' || type === 'sleeper_train'
          ? (lang.pending || 'Planning eligible; inventory not confirmed')
          : (lang.ready || 'Eligible by city and tier; inventory not confirmed');
        if ((type === 'hotel' || type === 'resort') && data.hotels[currentDest]
          && data.hotels[currentDest][type]) {
          var group = data.hotels[currentDest][type], names = (group.tiers || []).map(function (tid) {
            return data.choice_labels && data.choice_labels.tiers
              && data.choice_labels.tiers[String(tid)] || String(tid);
          });
          more += ' · ' + group.count + ' ' + (lang.properties || 'qualifying properties') + (names.length ? ' · ' + names.join(' / ') : '');
        }
        note.textContent = more;
        note.style.color = '#335f48';
      }
    }
  }

  var scanQueued = false;
  function scheduleRender() {
    if (scanQueued) return;
    scanQueued = true;
    window.requestAnimationFrame(function () { scanQueued = false; render(); });
  }
  function isTarget(el) {
    return el && el.tagName === 'SELECT'
      && /mad4b_overnight_(kind|destination)/.test(el.name || el.id || '');
  }
  document.addEventListener('change', function (ev) {
    if (isTarget(ev.target)) {
      scheduleRender();
      return;
    }
    var el = ev.target;
    if (el && /package_levels/.test(el.name || el.id || '')) {
      // Unsaved taxonomy edits are NOT reflected by the authoritative
      // backend. Keep existing selections but block unverified new options.
      state = 'failed';
      scheduleRender();
    } else if (el && /itinerary_item/.test(el.name || el.id || '')) scheduleRender();
  }, true);

  var obs = new MutationObserver(function (mutations) {
    if (mutations.some(function (m) {
      // Avoid self-induced loops from the explanatory note being inserted.
      return Array.from(m.addedNodes || []).some(function (n) {
        return n.nodeType === 1 && !n.classList.contains('mad4b-overnight-eligibility-note');
      }) || Array.from(m.removedNodes || []).some(function (n) { return n.nodeType === 1; });
    })) scheduleRender();
  });
  function boot() {
    if (!document.body) return;
    obs.observe(document.body, {childList:true, subtree:true});
    render();
    if (!cfg.tour_id) {
      state = 'new'; scheduleRender(); return;
    }
    var payload = new URLSearchParams();
    payload.set('action', cfg.action);
    payload.set('nonce', cfg.nonce);
    payload.set('tour_id', String(cfg.tour_id));
    fetch(cfg.ajax_url, {
      method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8',
               'Accept':'application/json'},
      body:payload.toString()
    }).then(function (r) {
      if (!r.ok) throw new Error('Eligibility HTTP '+r.status);
      return r.json();
    }).then(function (result) {
      if (!result || !result.success || !result.data
        || result.data.contract !== 'mad4b.editorial-overnight-smart-options.v1')
        throw new Error('Eligibility contract mismatch');
      data = result.data;
      state = 'ready';
      scheduleRender();
    }).catch(function () {
      // No successful current, private backend proof: leave existing selections
      // visible but prevent selecting a new, unverified overnight type.
      state = 'failed'; data = null; scheduleRender();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',boot,{once:true});
  else boot();
})();

