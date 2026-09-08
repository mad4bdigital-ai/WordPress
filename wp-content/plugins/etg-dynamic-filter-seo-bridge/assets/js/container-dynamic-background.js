(function () {
    'use strict';

    var selector = '.etg-dfsb-dynamic-background';
    var states = typeof WeakMap !== 'undefined' ? new WeakMap() : null;
    var resizeTimer = null;

    function intValue(value, fallback, min, max) {
        value = Number(value);
        if (!isFinite(value)) { value = fallback; }
        value = Math.floor(value);
        return Math.max(min, Math.min(max, value));
    }

    function floatValue(value, fallback, min, max) {
        value = Number(value);
        if (!isFinite(value)) { value = fallback; }
        return Math.max(min, Math.min(max, value));
    }

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function device() {
        var width = window.innerWidth || document.documentElement.clientWidth || 1280;
        if (width <= 767) { return 'mobile'; }
        if (width <= 1024) { return 'tablet'; }
        return 'desktop';
    }

    function behavior(el) {
        var value = (el.getAttribute('data-etg-dfsb-background-' + device()) || 'inherit').trim();
        return ['inherit', 'first_image', 'disabled'].indexOf(value) !== -1 ? value : 'inherit';
    }

    function cleanImage(item) {
        if (!item || typeof item !== 'object') { return null; }
        var id = Number(item.id) > 0 ? Math.floor(Number(item.id)) : 0;
        var url = typeof item.url === 'string' ? item.url.trim() : '';
        if (!url) { return null; }
        return { id: id, url: url };
    }

    function uniqueGallery(items, maximum) {
        var out = [], seen = {};
        (Array.isArray(items) ? items : []).slice(0, maximum).forEach(function (item) {
            var image = cleanImage(item);
            if (!image) { return; }
            var key = image.id ? 'id:' + image.id : 'url:' + image.url;
            if (seen[key]) { return; }
            seen[key] = true;
            out.push(image);
        });
        return out;
    }

    function readFallback(el) {
        var raw = el.getAttribute('data-etg-dfsb-background-fallback') || '[]';
        try { return uniqueGallery(JSON.parse(raw), 1); } catch (error) { return []; }
    }

    function readGallery(el) {
        var raw = el.getAttribute('data-etg-dfsb-gallery') || '[]';
        try { return uniqueGallery(JSON.parse(raw), 30); } catch (error) { return []; }
    }

    function safeBackgroundUrl(url) {
        return 'url("' + String(url || '').replace(/\\/g, '%5C').replace(/"/g, '%22').replace(/\n|\r/g, '') + '")';
    }

    function clearTimer(state) {
        if (state && state.timer) { window.clearTimeout(state.timer); state.timer = null; }
    }

    function ensureStage(el) {
        var state = states && states.get(el);
        if (state && state.stage && state.stage.parentNode === el) { return state; }

        var stage = document.createElement('div');
        stage.className = 'etg-dfsb-background-stage';
        stage.setAttribute('aria-hidden', 'true');
        var overlay = document.createElement('div');
        overlay.className = 'etg-dfsb-background-stage__overlay';
        stage.appendChild(overlay);
        if (el.firstChild) { el.insertBefore(stage, el.firstChild); } else { el.appendChild(stage); }

        state = { element: el, stage: stage, overlay: overlay, slides: [], items: [], index: 0, timer: null, paused: false };
        if (states) { states.set(el, state); }

        if ((el.getAttribute('data-etg-dfsb-background-pause-hover') || '0') === '1') {
            el.addEventListener('mouseenter', function () { state.paused = true; clearTimer(state); });
            el.addEventListener('mouseleave', function () { state.paused = false; schedule(state); });
        }
        return state;
    }

    function configure(state) {
        var el = state.element, stage = state.stage;
        var fit = (el.getAttribute('data-etg-dfsb-background-fit') || 'cover').trim();
        var position = (el.getAttribute('data-etg-dfsb-background-position') || 'center center').trim();
        var transition = (el.getAttribute('data-etg-dfsb-background-transition') || 'crossfade').trim();
        var transitionDuration = intValue(el.getAttribute('data-etg-dfsb-background-transition-duration'), 800, 0, 5000);
        var duration = intValue(el.getAttribute('data-etg-dfsb-background-duration'), 5000, 1000, 30000);
        var overlayOpacity = floatValue(el.getAttribute('data-etg-dfsb-background-overlay-opacity'), 0, 0, 1);
        var overlayColor = (el.getAttribute('data-etg-dfsb-background-overlay-color') || '').trim();
        if (['cover', 'contain'].indexOf(fit) === -1) { fit = 'cover'; }
        if (['fade', 'crossfade', 'slide'].indexOf(transition) === -1) { transition = 'crossfade'; }
        stage.setAttribute('data-transition', reducedMotion() ? 'fade' : transition);
        stage.setAttribute('data-ken-burns', !reducedMotion() && (el.getAttribute('data-etg-dfsb-background-ken-burns') || '0') === '1' ? '1' : '0');
        stage.style.setProperty('--etg-dfsb-background-fit', fit);
        stage.style.setProperty('--etg-dfsb-background-position', position);
        stage.style.setProperty('--etg-dfsb-background-transition-duration', reducedMotion() ? '0ms' : transitionDuration + 'ms');
        stage.style.setProperty('--etg-dfsb-background-duration', duration + 'ms');
        stage.style.setProperty('--etg-dfsb-background-overlay-color', overlayColor || 'transparent');
        stage.style.setProperty('--etg-dfsb-background-overlay-opacity', String(overlayOpacity));
    }

    function buildSlides(state, items) {
        var stage = state.stage;
        state.slides.forEach(function (slide) { if (slide.parentNode === stage) { stage.removeChild(slide); } });
        state.slides = [];
        items.forEach(function (item) {
            var slide = document.createElement('div');
            slide.className = 'etg-dfsb-background-stage__slide';
            slide.style.backgroundImage = safeBackgroundUrl(item.url);
            slide.setAttribute('data-etg-dfsb-attachment-id', String(item.id || 0));
            stage.insertBefore(slide, state.overlay);
            state.slides.push(slide);
        });
    }

    function activate(state, index, immediate) {
        if (!state.slides.length) { return; }
        index = ((index % state.slides.length) + state.slides.length) % state.slides.length;
        if (immediate) { state.stage.style.setProperty('--etg-dfsb-background-transition-duration', '0ms'); }
        state.slides.forEach(function (slide, i) { slide.classList.toggle('is-active', i === index); });
        state.index = index;
        if (immediate) {
            window.setTimeout(function () { if (state.element && state.stage) { configure(state); } }, 0);
        }
    }

    function effectiveItems(state, items) {
        var mode = (state.element.getAttribute('data-etg-dfsb-background-mode') || 'image').trim();
        var currentBehavior = behavior(state.element);
        if (currentBehavior === 'disabled') { return []; }
        if (mode === 'image' || currentBehavior === 'first_image' || reducedMotion()) { return items.length ? [items[0]] : []; }
        return items;
    }

    function autoplayEnabled(state) {
        if (reducedMotion() || behavior(state.element) !== 'inherit') { return false; }
        if ((state.element.getAttribute('data-etg-dfsb-background-mode') || '') !== 'slideshow') { return false; }
        return (state.element.getAttribute('data-etg-dfsb-background-autoplay') || '0') === '1';
    }

    function schedule(state) {
        clearTimer(state);
        if (!state || state.paused || !autoplayEnabled(state) || state.slides.length < 2) { return; }
        var duration = intValue(state.element.getAttribute('data-etg-dfsb-background-duration'), 5000, 1000, 30000);
        state.timer = window.setTimeout(function () {
            state.timer = null;
            activate(state, state.index + 1, false);
            schedule(state);
        }, duration);
    }

    function render(el, incoming, options) {
        var state = ensureStage(el);
        configure(state);
        var maximum = intValue(el.getAttribute('data-etg-dfsb-background-max-slides'), 30, 1, 30);
        var items = uniqueGallery(incoming == null ? readGallery(el) : incoming, maximum);
        if (!items.length) { items = readFallback(el); }
        items = effectiveItems(state, items);
        clearTimer(state);
        state.items = items;
        if (!items.length) {
            state.stage.setAttribute('hidden', 'hidden');
            buildSlides(state, []);
            return;
        }
        state.stage.removeAttribute('hidden');
        buildSlides(state, items);
        var random = (el.getAttribute('data-etg-dfsb-background-random-start') || '0') === '1' && items.length > 1;
        var index = options && typeof options.index === 'number' ? options.index : (random ? Math.floor(Math.random() * items.length) : 0);
        activate(state, index, true);
        schedule(state);
    }

    function init(el) {
        if (!el || !el.matches || !el.matches(selector)) { return; }
        render(el, null, { index: 0 });
    }

    function scan(root) {
        root = root || document;
        if (root.matches && root.matches(selector)) { init(root); }
        if (root.querySelectorAll) { Array.prototype.forEach.call(root.querySelectorAll(selector), init); }
    }

    document.addEventListener('etg-dfsb/media-updated', function (event) {
        var detail = event && event.detail ? event.detail : {};
        var el = detail.element;
        if (!el || !el.matches || !el.matches(selector)) { return; }
        var mode = (el.getAttribute('data-etg-dfsb-background-mode') || 'image').trim();
        var items = Array.isArray(detail.gallery) ? detail.gallery : [];
        if (mode === 'image' && detail.image) { items = [detail.image]; }
        if (!items.length && detail.image) { items = [detail.image]; }
        render(el, items, { index: 0 });
    });

    document.addEventListener('etg-dfsb/ajax-presentation-reset', function () {
        Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) { render(el, readGallery(el), { index: 0 }); });
    });

    window.addEventListener('resize', function () {
        if (resizeTimer) { window.clearTimeout(resizeTimer); }
        resizeTimer = window.setTimeout(function () {
            resizeTimer = null;
            Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) {
                var state = states && states.get(el);
                var items = state && state.items && state.items.length ? state.items : readGallery(el);
                render(el, items, { index: 0 });
            });
        }, 100);
    });

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { scan(document); }); }
    else { scan(document); }

    if (window.elementorFrontend && window.elementorFrontend.hooks && typeof window.elementorFrontend.hooks.addAction === 'function') {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/container', function (scope) {
            var node = scope && scope[0] ? scope[0] : scope;
            scan(node || document);
        });
    }

    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes || [], function (node) {
                    if (node && node.nodeType === 1) { scan(node); }
                });
            });
        });
        observer.observe(document.documentElement || document.body, { childList: true, subtree: true });
    }
}());
