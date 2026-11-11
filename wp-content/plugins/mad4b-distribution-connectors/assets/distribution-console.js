/* Progressive enhancement: client-side channel filters only. No network requests or secret access. */
(function () {
  'use strict';
  const root = document.getElementById('mad4b-distribution-console');
  if (!root) return;
  const filters = Array.from(root.querySelectorAll('[data-filter]'));
  const cards = Array.from(root.querySelectorAll('[data-category][data-search]'));
  const search = root.querySelector('#mad4b-dc-search');
  const results = root.querySelector('#mad4b-dc-results');
  const empty = root.querySelector('#mad4b-dc-empty');
  let category = 'all';
  function apply() {
    const phrase = String(search ? search.value : '').toLocaleLowerCase().trim();
    let visible = 0;
    cards.forEach(function (card) {
      const match = (category === 'all' || card.dataset.category === category) &&
        (!phrase || String(card.dataset.search || '').toLocaleLowerCase().includes(phrase));
      card.hidden = !match;
      if (match) visible++;
    });
    if (empty) empty.hidden = visible !== 0;
    if (results) results.textContent = visible + (visible === 1 ? ' channel shown' : ' channels shown');
  }
  filters.forEach(function (button) {
    button.addEventListener('click', function () {
      category = button.dataset.filter || 'all';
      filters.forEach(function (item) {
        const active = item === button;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      apply();
    });
  });
  if (search) search.addEventListener('input', apply);
  root.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.dataset.confirm || 'Confirm credential revocation?')) {
        event.preventDefault();
      }
    });
  });
})();
