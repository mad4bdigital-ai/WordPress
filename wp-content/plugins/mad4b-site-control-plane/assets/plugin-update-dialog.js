(function () {
  'use strict';
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-mad4b-update-dialog]');
    if (trigger) {
      var id = trigger.getAttribute('data-mad4b-update-dialog');
      var dialog = id ? document.getElementById(id) : null;
      if (!dialog || typeof dialog.showModal !== 'function') return;
      event.preventDefault();
      if (!dialog.open) dialog.showModal();
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
})();
