/* Keep SEO copy below filtered listings compact and responsive.
 * This only changes presentation, not filter state, content, or SEO output.
 */
(function () {
    'use strict';
    var panelSelector = '.dfsb-archive-editorial';
    function bind(panel) {
        if (!panel || panel.dataset.dfsbEditorialBound === '1') { return; }
        panel.dataset.dfsbEditorialBound = '1';
        var scheduled = false;
        var running = false;
        function update() {
            if (running) { return; }
            running = true;
            scheduled = false;
            panel.removeAttribute('hidden');
            var dynamicFields = panel.querySelectorAll('.elementor-widget-jet-listing-dynamic-field');
            Array.prototype.forEach.call(dynamicFields, function (field) {
                field.removeAttribute('hidden');
                var hasContent = !!(field.innerText || '').trim();
                if (!hasContent) { field.setAttribute('hidden', 'hidden'); }
            });
            var hasText = !!(panel.innerText || '').trim();
            if (!hasText) { panel.setAttribute('hidden', 'hidden'); }
            running = false;
        }
        function schedule() {
            if (scheduled || running) { return; }
            scheduled = true;
            window.requestAnimationFrame(update);
        }
        if (typeof MutationObserver === 'function') {
            new MutationObserver(schedule).observe(panel, {childList: true, subtree: true, characterData: true});
        }
        document.addEventListener('dfsb/presentation-updated', schedule);
        document.addEventListener('etg-dfsb/ajax-presentation-reset', schedule);
        update();
    }
    function init() { Array.prototype.forEach.call(document.querySelectorAll(panelSelector), bind); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
