(function () {
  'use strict';
  function updateSourceFields(select) {
    var form = select.closest('form');
    if (!form) return;
    var type = select.value;
    form.querySelectorAll('[data-mad4b-source-field]').forEach(function (wrapper) {
      var field = wrapper.getAttribute('data-mad4b-source-field');
      var active = (field === 'branch' && (type === 'branch' || type === 'branch_commit')) ||
        (field === 'commit' && (type === 'commit' || type === 'branch_commit')) ||
        (field === 'reference' && type === 'pull_request');
      wrapper.hidden = !active;
      var input = wrapper.querySelector('input');
      if (input) {
        input.required = active;
        if (!active) input.value = '';
      }
    });
  }
  document.querySelectorAll('[data-mad4b-source-select]').forEach(updateSourceFields);
  document.addEventListener('change', function (event) {
    if (event.target.matches('[data-mad4b-source-select]')) updateSourceFields(event.target);
  });
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-mad4b-update-dialog]');
    if (trigger) {
      var id = trigger.getAttribute('data-mad4b-update-dialog');
      var dialog = id ? document.getElementById(id) : null;
      if (!dialog || typeof dialog.showModal !== 'function') return;
      event.preventDefault();
      if (!dialog.open) dialog.showModal();
      var sourceSelect = dialog.querySelector('[data-mad4b-source-select]');
      if (sourceSelect) updateSourceFields(sourceSelect);
      var heading = dialog.querySelector('h2');
      if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus();
      }
      dialog._mad4bOrigin = trigger;
      return;
    }
    var close = event.target.closest('[data-mad4b-dialog-close]');
    if (close) {
      var parent = close.closest('dialog.mad4b-ud-dialog');
      if (parent && parent.open) {
        event.preventDefault();
        parent.close();
      }
    }
  });
  document.addEventListener('click', function (event) {
    if (!event.target.matches('dialog.mad4b-ud-dialog')) return;
    var dialog = event.target;
    var rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right ||
        event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
  });
  document.addEventListener('close', function (event) {
    var dialog = event.target;
    if (dialog && dialog.matches && dialog.matches('dialog.mad4b-ud-dialog')) {
      dialog.querySelectorAll('form[data-mad4b-async-preview]').forEach(invalidate);
    }
    if (dialog && dialog.matches && dialog.matches('dialog.mad4b-ud-dialog') &&
        dialog._mad4bOrigin && dialog._mad4bOrigin.isConnected) {
      dialog._mad4bOrigin.focus();
    }
  }, true);
  var messages = window.MAD4BUpdateDialog || {};
  function message(key, fallback) { return String(messages[key] || fallback); }
  var previews = new WeakMap();
  function signature(body) {
    return JSON.stringify(['source_id', 'ref_type', 'reference', 'reason'].map(function (key) {
      return String(body.get(key) || '');
    }));
  }
  function invalidate(form) {
    var request = previews.get(form);
    if (request) {
      previews.delete(form);
      window.clearTimeout(request.timer);
      if (request.controller) request.controller.abort();
      if (request.button) request.button.disabled = false;
    }
    form.removeAttribute('aria-busy');
    var result = form.querySelector('[data-mad4b-preview-result]');
    if (result && !result.hidden) {
      result.textContent = message('changed', 'Source changed. Preview again to check the current selection.');
    }
  }
  ['input', 'change'].forEach(function (type) {
    document.addEventListener(type, function (event) {
      var form = event.target.closest('form[data-mad4b-async-preview]');
      if (form) invalidate(form);
    });
  });
  // Only the explicit Cross-Plugin preview uses AJAX. Without fetch, the
  // original admin-post form remains a functioning accessible fallback.
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-mad4b-async-preview]');
    if (!form || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') return;
    var result = form.querySelector('[data-mad4b-preview-result]');
    var url = form.getAttribute('data-mad4b-ajax-url');
    if (!result || !url) return;
    event.preventDefault();
    if (previews.has(form)) return;
    var button = form.querySelector('button[type="submit"]');
    var body = new FormData(form);
    body.set('action', 'mad4b_cross_plugin_preview_ajax');
    var request = {result: result, button: button, signature: signature(body),
      controller: typeof window.AbortController === 'function' ? new window.AbortController() : null};
    previews.set(form, request);
    form.setAttribute('aria-busy', 'true');
    if (button) button.disabled = true;
    result.hidden = false;
    result.textContent = message('checking', 'Checking source and package eligibility...');
    request.timer = window.setTimeout(function () {
      if (previews.get(form) !== request) return;
      previews.delete(form);
      if (request.controller) request.controller.abort();
      if (button) button.disabled = false;
      form.removeAttribute('aria-busy');
      result.textContent = message('timeout', 'The preview timed out. Check your connection and preview again.');
    }, 30000);
    var options = {method: 'POST', credentials: 'same-origin', body: body};
    if (request.controller) options.signal = request.controller.signal;
    window.fetch(url, options)
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (previews.get(form) !== request) return;
        if (request.signature !== signature(new FormData(form))) { invalidate(form); return; }
        if (!payload || !payload.data) throw new Error('Invalid preview response');
        var data = payload.data;
        result.textContent = '';
        var summary = document.createElement('strong');
        var stateLabels = {
          PLAN_ELIGIBLE_NOT_AUTHORIZED: message('eligible', 'Eligible for separate approval'),
          NEEDS_REVIEW: message('needsReview', 'Needs review'),
          NOT_AUTHORIZED: message('notAuthorized', 'Not authorized'),
          BLOCKED: message('blocked', 'Blocked')
        };
        summary.textContent = stateLabels[data.state] || stateLabels.BLOCKED;
        result.appendChild(summary);
        [
          [message('operation', 'Operation'), data.operation],
          [message('commit', 'Exact commit'), data.resolved_sha],
          [message('plugin', 'Plugin'), data.plugin_file]
        ].forEach(function (entry) {
          if (!entry[1]) return;
          var row = document.createElement('div');
          row.textContent = entry[0] + ': ' + String(entry[1]);
          result.appendChild(row);
        });
        if (Array.isArray(data.blockers) && data.blockers.length) {
          var guidance = document.createElement('p');
          guidance.textContent = message('guidance', 'Open Plugin Deployment to review the missing certificate, site permissions or source evidence before requesting approval.');
          result.appendChild(guidance);
          var details = document.createElement('details');
          var detailLabel = document.createElement('summary');
          detailLabel.textContent = message('details', 'Technical details');
          details.appendChild(detailLabel);
          var list = document.createElement('ul');
          data.blockers.slice(0, 20).forEach(function (reason) {
            var item = document.createElement('li');
            item.textContent = String(reason);
            list.appendChild(item);
          });
          details.appendChild(list);
          result.appendChild(details);
        }
        var note = document.createElement('p');
        note.textContent = message('previewOnly', 'Preview only. Installation requires a separately certified ZIP and exact approval.');
        result.appendChild(note);
      })
      .catch(function () {
        if (previews.get(form) !== request) return;
        result.textContent = message('failed', 'Preview could not be completed. Use Open Plugin Deployment for a full review.');
      })
      .finally(function () {
        window.clearTimeout(request.timer);
        if (previews.get(form) !== request) return;
        previews.delete(form);
        if (button) button.disabled = false;
        form.removeAttribute('aria-busy');
      });
  });
})();
