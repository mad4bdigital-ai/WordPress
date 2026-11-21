/* MAD4B: Native JetSmartFilters destinations, first root always expanded.
   Runs only on tours archives. No taxonomy rewrites or synthetic filter changes. */
(function () {
    'use strict';
    var selectors = '.jet-checkboxes-list[data-query-var="destinations"][data-filter-id]';
    var bindings = new WeakMap();
    var pending = false;
    var nativeBound = false;

    function eligible() {
        return !!document.body && document.body.classList.contains('post-type-archive-tours-and-activities');
    }
    function rootOf(filter) {
        var fieldset = filter.querySelector('.jet-checkboxes-list-wrapper');
        if (!fieldset) { return null; }
        var tree = Array.prototype.find.call(fieldset.children, function (child) {
            return child.classList && child.classList.contains('jet-list-tree');
        });
        if (!tree) { return null; }
        var toggle = Array.prototype.find.call(tree.children, function (child) {
            return child.classList && child.classList.contains('jet-list-tree__parent');
        });
        var content = toggle && toggle.nextElementSibling;
        if (!toggle || !content || !content.classList.contains('jet-list-tree__children')) { return null; }
        return {tree:tree,toggle:toggle,content:content};
    }
    function expanded(root) {
        // Trust native classes during slideDown/slideUp: computed display can
        // briefly be none while the already-open transition is still settling.
        return !root.toggle.classList.contains('jet-list-toggle-collapsed') &&
            !root.content.classList.contains('jet-list-content-collapsed');
    }
    function open(root) {
        if (expanded(root)) { return; }
        // Native toggle click: do not mutate checkboxes/query and do not
        // double-toggle a partially opened animation.
        if (root.toggle.classList.contains('jet-list-toggle-collapsed')) {
            root.toggle.click();
        }
    }
    function attach(filter) {
        var root = rootOf(filter);
        if (!root) { return; }
        if (bindings.get(filter) !== root.toggle) {
            bindings.set(filter,root.toggle);
            root.toggle.setAttribute('data-mad4b-first-destination','expanded');
            root.content.setAttribute('data-mad4b-first-destination-children','expanded');
            // Explicit user intent: this first root must stay open. Leave other
            // native branches fully collapsible and all checkboxes interactive.
            root.toggle.addEventListener('click',function(ev) {
                if (ev.target.closest('label, input')) { return; }
                if (expanded(root)) {
                    ev.preventDefault();
                    ev.stopImmediatePropagation();
                }
            },true);
            var observer = new MutationObserver(function () { schedule(); });
            observer.observe(root.toggle,{attributes:true,attributeFilter:['class']});
            observer.observe(root.content,{attributes:true,attributeFilter:['class','style']});
        }
        open(root);
    }
    function scan() {
        pending=false;
        if (!eligible()) { return; }
        document.querySelectorAll(selectors).forEach(attach);
    }
    function schedule() {
        if (pending) return;
        pending=true;
        window.requestAnimationFrame(scan);
    }
    function subscribeNative() {
        if (nativeBound) return;
        var events=window.JetSmartFilters && window.JetSmartFilters.events;
        if (!events || typeof events.subscribe!=='function') return;
        nativeBound=true;
        ['ajaxFilters/updated','ajaxFilters/end-loading','ajaxFilters/filters-updated'].forEach(function (name) {
            events.subscribe(name,function () { schedule(); });
        });
    }
    function init() {
        if (!eligible()) return;
        schedule();
        subscribeNative();
    }
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',init,{once:true});
    else init();
    window.addEventListener('load',init,{once:true});
    document.addEventListener('dfsb/ajax-presentation-updated',schedule);
    document.addEventListener('dfsb/ajax-presentation-reset',schedule);
    // Elementor modal and JetSmartFilters may replace descendant nodes on AJAX.
    // Observe child lists only; per-root class watchers handle native toggles.
    var bodyObserver=new MutationObserver(function(records) {
        for (var i=0;i<records.length;i++) {
            if (records[i].addedNodes.length || records[i].removedNodes.length) { schedule(); return; }
        }
    });
    function observeBody() {
        if (!eligible()) return;
        bodyObserver.observe(document.body,{childList:true,subtree:true});
        schedule();
        subscribeNative();
    }
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',observeBody,{once:true});
    else observeBody();
}());
