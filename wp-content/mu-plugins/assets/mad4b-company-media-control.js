(function () {
  'use strict';
  function init() {
    var form = document.getElementById('mad4b-media-company-form');
    var note = document.getElementById('mad4b-media-unsaved');
    var save = document.getElementById('mad4b-media-save');
    if (!form || !note || !save) return;
    var labels = {
      inherit: 'Use inherited default',
      ai_only: 'AI review',
      human_and_ai: 'Human + AI',
      human_only: 'Manual review'
    };
    var stored = form.getAttribute('data-persisted-mode');
    var inherited = form.getAttribute('data-inherited-mode');
    function update() {
      var selected = form.querySelector('input[name="mode"]:checked');
      if (!selected) return;
      var mode = selected.value;
      var changed = mode !== stored;
      if (changed) {
        var effective = mode === 'inherit' ? inherited : mode;
        note.textContent = 'Not saved yet: ' + (labels[mode] || mode) +
          '. If saved, the workflow will use ' + (labels[effective] || effective) + '.';
      } else {
        note.textContent = 'Saved company selection loaded from WordPress. No unsaved changes.';
      }
      // The server always rechecks the saved revision and policy fingerprint.
      if (!save.hasAttribute('data-server-disabled')) save.disabled = !changed;
    }
    if (save.disabled) save.setAttribute('data-server-disabled', '1');
    Array.prototype.forEach.call(form.querySelectorAll('input[name="mode"]'), function (input) {
      input.addEventListener('change', update);
    });
    update();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
