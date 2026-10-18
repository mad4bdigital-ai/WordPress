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
    if (dialog && dialog.matches && dialog.matches('dialog.mad4b-ud-dialog') &&
        dialog._mad4bOrigin && dialog._mad4bOrigin.isConnected) {
      dialog._mad4bOrigin.focus();
    }
  }, true);
}  // Only the explicit Cross-Plugin preview uses AJAX. Without fetch, the
  // original admin-post form remains a functioning accessible fallback.
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-mad4b-async-preview]');
    if (!form || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') return;
    var result = form.querySelector('[data-mad4b-preview-result]');
    var url = form.getAttribute('data-mad4b-ajax-url');
    if (!result || !url) return;
    event.preventDefault();
    var button = form.querySelector('button[type="submit"]');
    var body = new FormData(form);
    body.set('action', 'mad4b_cross_plugin_preview_ajax');
    if (button) button.disabled = true;
    result.hidden = false;
    result.textContent = 'Checking source and package eligibility...';
    window.fetch(url, {method: 'POST', credentials: 'same-origin', body: body})
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || !payload.data) throw new Error('Invalid preview response');
        var data = payload.data;
        result.textContent = '';
        var summary = document.createElement('strong');
        summary.textContent = String(data.state || 'BLOCKED');
        result.appendChild(summary);
        [
          ['Operation', data.operation],
          ['Exact commit', data.resolved_sha],
          ['Plugin', data.plugin_file]
        ].forEach(function (entry) {
          if (!entry[1]) return;
          var row = document.createElement('div');
          row.textContent = entry[0] + ': ' + String(entry[1]);
          result.appendChild(row);
        });
        if (Array.isArray(data.blockers) && data.blockers.length) {
          var list = document.createElement('ul');
          data.blockers.slice(0, 20).forEach(function (reason) {
            var item = document.createElement('li');
            item.textContent = String(reason);
            list.appendChild(item);
          });
          result.appendChild(list);
        }
        var note = document.createElement('p');
        note.textContent = 'Preview only. Installation requires a separately certified ZIP and exact approval.';
        result.appendChild(note);
      })
      .catch(function () {
        result.textContent = 'Preview could not be completed. Use Open Plugin Deployment for a full review.';
      })
      .finally(function () { if (button) button.disabled = false; });
  });
})();
