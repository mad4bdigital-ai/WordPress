/* Scoped Core UI adapter pilot: no WP settings save, requests or DOM clones. */
(() => {
  'use strict';
  const root = document.getElementById('mad4b-wp-native-inventory');
  if (!root) return;
  const input = root.querySelector('#mad4b-native-pilot-text');
  const select = root.querySelector('#mad4b-native-pilot-select');
  const check = root.querySelector('#mad4b-native-pilot-check');
  const action = root.querySelector('#mad4b-native-pilot-action');
  const reset = root.querySelector('#mad4b-native-pilot-reset');
  const status = root.querySelector('#mad4b-native-pilot-feedback');
  if (!input || !select || !check || !action || !reset || !status) return;
  action.addEventListener('click', () => {
    status.hidden = !check.checked;
    if (!status.hidden) status.querySelector('p').textContent =
      'Preview interaction: ' + (input.value.trim() || 'Empty') +
      ' · ' + select.options[select.selectedIndex].text +
      '. Nothing was saved.';
    else status.hidden = true;
  });
  const search = root.querySelector('#mad4b-native-screen-query');
  const family = root.querySelector('#mad4b-native-screen-family');
  const screenTable = root.querySelector('#mad4b-native-screen-table');
  const count = root.querySelector('#mad4b-native-screen-results');
  if (search && family && screenTable && count) {
    const rows = Array.from(screenTable.tBodies[0]?.rows || []);
    const update = () => {
      const q = search.value.trim().toLocaleLowerCase();
      const f = family.value;
      let visible = 0;
      for (const row of rows) {
        const matches = (!q || row.textContent.toLocaleLowerCase().includes(q)) &&
          (!f || row.dataset.family === f);
        row.hidden = !matches;
        if (matches) visible++;
      }
      count.textContent = visible + ' of ' + rows.length + ' screens shown';
    };
    search.addEventListener('input', update);
    family.addEventListener('change', update);
  }
  reset.addEventListener('click', () => {
    input.value = 'Preview only';
    select.value = 'cards';
    check.checked = false;
    status.hidden = true;
    input.focus();
  });
})();
