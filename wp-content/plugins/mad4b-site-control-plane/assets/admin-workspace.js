/* Progressive navigation only. This module never submits forms or performs network requests. */
(function () {
  'use strict';
  function ready() {
    if (!document.body.classList.contains('mad4b-workspace-page')) return;
    var root = document.querySelector('.mad4b-workspace');
    if (root) {
      var controls = root.querySelector('[data-mad4b-directory-controls]');
      var input = root.querySelector('#mad4b-workspace-filter');
      var items = Array.from(root.querySelectorAll('[data-mad4b-workspace-item]'));
      var empty = root.querySelector('[data-mad4b-directory-empty]');
      var status = root.querySelector('[data-mad4b-directory-status]');
      if (controls && input && empty && status) {
        controls.hidden = false;
        input.addEventListener('input', function () {
          var query = input.value.trim().toLocaleLowerCase();
          var count = 0;
          items.forEach(function (item) {
            item.hidden = item.textContent.toLocaleLowerCase().indexOf(query) === -1;
            if (!item.hidden) count += 1;
          });
          empty.hidden = count !== 0;
          status.textContent = status.dataset.template.replace('%d', String(count));
        });
      }
    }
    // Wide evidence tables scroll inside their own named keyboard-accessible region.
    document.querySelectorAll('#wpbody-content .wrap table.widefat').forEach(function (table) {
      if (table.closest('.mad4b-scp-table-wrap, .mad4b-workspace-table')) return;
      var region = document.createElement('div');
      region.className = 'mad4b-workspace-table';
      region.tabIndex = 0;
      region.setAttribute('role', 'region');
      var heading = table.caption ? table.caption.textContent : '';
      if (!heading) {
        var previous = table.previousElementSibling;
        while (previous && !/^H[1-6]$/.test(previous.tagName)) previous = previous.previousElementSibling;
        heading = previous ? previous.textContent : document.querySelector('.wrap h1')?.textContent;
      }
      if (heading) region.setAttribute('aria-label', heading.trim());
      table.parentNode.insertBefore(region, table);
      region.appendChild(table);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready, { once: true });
  else ready();
}());
