(function () {
    'use strict';

    var selector = '.dfsb-dynamic-background, .etg-dfsb-dynamic-background';
    var states = typeof WeakMap !== 'undefined' ? new WeakMap() : null;
    var resizeTimer = null;
    var viewportObserver = null;

    // Existing rendered values remain authoritative during the compatibility phase.
    // A neutral-only background also works without duplicating runtime state.
    function backgroundAttribute(el, name) {
        var legacy = el.getAttribute('data-etg-dfsb-background-' + name);
        return legacy !== null ? legacy : el.getAttribute('data-dfsb-background-' + name);
    }
    function setSlideAttribute(slide, name, value) {
        slide.setAttribute('data-dfsb-' + name, value);
        slide.setAttribute('data-etg-dfsb-' + name, value);
    }
    function setBackgroundProperty(stage, name, value) {
        stage.style.setProperty('--dfsb-background-' + name, value);
        stage.style.setProperty('--etg-dfsb-background-' + name, value);
    }

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

    function breakpointValue(value, fallback) {
        if (value && typeof value === 'object' && isFinite(Number(value.value))) { value = value.value; }
        value = Number(value);
        return isFinite(value) && value > 0 ? value : fallback;
    }

    function responsiveBreakpoints() {
        var mobile = 767, tablet = 1024;
        var config = window.elementorFrontend && window.elementorFrontend.config && window.elementorFrontend.config.responsive;
        var active = config && config.activeBreakpoints && typeof config.activeBreakpoints === 'object' ? config.activeBreakpoints : {};
        mobile = breakpointValue(active.mobile, mobile);
        tablet = breakpointValue(active.tablet, tablet);
        if (tablet < mobile) { tablet = mobile; }
        return { mobile: mobile, tablet: tablet };
    }

    function device() {
        var width = window.innerWidth || document.documentElement.clientWidth || 1280;
        var breakpoints = responsiveBreakpoints();
        if (width <= breakpoints.mobile) { return 'mobile'; }
        if (width <= breakpoints.tablet) { return 'tablet'; }
        return 'desktop';
    }

    function behavior(el) {
        var value = (backgroundAttribute(el, device()) || 'inherit').trim();
        return ['inherit', 'first_image', 'disabled'].indexOf(value) !== -1 ? value : 'inherit';
    }

    function cleanImage(item) {
        if (!item || typeof item !== 'object') { return null; }
        var id = Number(item.id) > 0 ? Math.floor(Number(item.id)) : 0;
        var url = typeof item.url === 'string' ? item.url.trim() : '';
        if (!url) { return null; }
        var width = Number(item.width) > 0 ? Math.floor(Number(item.width)) : 0;
        var height = Number(item.height) > 0 ? Math.floor(Number(item.height)) : 0;
        var ratio = Number(item.aspect_ratio) > 0 ? Number(item.aspect_ratio) : (height > 0 ? width / height : 0);
        var source = item.source === 'shared' ? 'shared' : 'local';
        return { id: id, url: url, width: width, height: height, aspect_ratio: ratio, source: source };
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

    function playback(el) {
        var value = (backgroundAttribute(el, 'playback') || 'animated').trim();
        return value === 'static' ? 'static' : 'animated';
    }

    function suitabilityPolicy(el) {
        var name = (backgroundAttribute(el, 'suitability') || 'any').trim();
        if (['any','landscape','wide','custom'].indexOf(name) === -1) { name = 'any'; }
        return {
            name: name,
            minWidth: floatValue(backgroundAttribute(el, 'min-width'), 0, 0, 10000),
            minHeight: floatValue(backgroundAttribute(el, 'min-height'), 0, 0, 10000),
            minRatio: floatValue(backgroundAttribute(el, 'min-ratio'), 0, 0, 5),
            empty: (backgroundAttribute(el, 'no-suitable') || 'fallback').trim() === 'hide' ? 'hide' : 'fallback'
        };
    }

    function imageSuitable(item, rule) {
        if (!item || !item.url) { return false; }
        if (rule.name === 'any') { return true; }
        var width = Number(item.width) || 0, height = Number(item.height) || 0;
        var ratio = Number(item.aspect_ratio) || (height > 0 ? width / height : 0);
        if (!(width > 0 && height > 0 && ratio > 0)) { return false; }
        return width >= rule.minWidth && height >= rule.minHeight && ratio >= rule.minRatio;
    }

    function suitableGallery(el, items) {
        var rule = suitabilityPolicy(el);
        if (rule.name === 'any') { return items.slice(); }
        return items.filter(function (item) { return imageSuitable(item, rule); });
    }

    function readFallback(el) {
        var raw = backgroundAttribute(el, 'fallback') || '[]';
        try { return uniqueGallery(JSON.parse(raw), 1); } catch (error) { return []; }
    }

    function readGallery(el) {
        var legacy = el.getAttribute('data-etg-dfsb-gallery');
        var raw = (legacy !== null ? legacy : el.getAttribute('data-dfsb-gallery')) || '[]';
        try { return uniqueGallery(JSON.parse(raw), 30); } catch (error) { return []; }
    }

    function safeBackgroundUrl(url) {
        return 'url("' + String(url || '').replace(/\\/g, '%5C').replace(/"/g, '%22').replace(/\n|\r/g, '') + '")';
    }

    function clearTimer(state) {
        if (state && state.timer) { window.clearTimeout(state.timer); state.timer = null; }
    }

    function ensureStage(el) {
        var scope = backgroundAttribute(el, 'frame-scope') || 'self';
        var frame = scope === 'first_child' ? Array.prototype.find.call(el.children, function (node) {
            return node.nodeType === 1 && node.classList.contains('elementor-element') && node !== el.querySelector('.dfsb-background-stage, .etg-dfsb-background-stage');
        }) : el;
        if (!frame) { frame = el; }
        var state = states && states.get(el);
        if (state && state.stage && state.stage.parentNode === frame) { return state; }
        if (!state) {
            state = { element: el, stage: null, overlay: null, slides: [], sourceItems: [], items: [], index: 0, timer: null, paused: false, hoverBound: false, observed: false, inViewport: true };
            if (states) { states.set(el, state); }
        } else {
            clearTimer(state);
            state.slides = [];
            state.items = [];
            state.paused = false;
        }

        var stage = document.createElement('div');
        stage.className = 'dfsb-background-stage etg-dfsb-background-stage';
        stage.setAttribute('aria-hidden', 'true');
        var overlay = document.createElement('div');
        overlay.className = 'dfsb-background-stage__overlay etg-dfsb-background-stage__overlay';
        stage.appendChild(overlay);
        if (frame.firstChild) { frame.insertBefore(stage, frame.firstChild); } else { frame.appendChild(stage); }
        state.stage = stage;
        state.overlay = overlay;
        if (viewportObserver && !state.observed) { viewportObserver.observe(el); state.observed = true; }

        if (!state.hoverBound) {
            el.addEventListener('mouseenter', function () {
                var current = states && states.get(el) ? states.get(el) : state;
                if (!current || (backgroundAttribute(el, 'pause-hover') || '0') !== '1') { return; }
                current.paused = true;
                clearTimer(current);
            });
            el.addEventListener('mouseleave', function () {
                var current = states && states.get(el) ? states.get(el) : state;
                if (!current || (backgroundAttribute(el, 'pause-hover') || '0') !== '1') { return; }
                current.paused = false;
                schedule(current);
            });
            state.hoverBound = true;
        }
        return state;
    }

    function elementConnected(el) {
        if (!el) { return false; }
        if (typeof el.isConnected === 'boolean') { return el.isConnected; }
        if (document.documentElement && typeof document.documentElement.contains === 'function') { return document.documentElement.contains(el); }
        return true;
    }

    function pageVisible() {
        return typeof document.hidden === 'boolean' ? !document.hidden : true;
    }

    function configure(state) {
        var el = state.element, stage = state.stage;
        var fit = (backgroundAttribute(el, 'fit') || 'cover').trim();
        var position = (backgroundAttribute(el, 'position') || 'center center').trim();
        var transition = (backgroundAttribute(el, 'transition') || 'crossfade').trim();
        var transitionDuration = intValue(backgroundAttribute(el, 'transition-duration'), 800, 0, 5000);
        var duration = intValue(backgroundAttribute(el, 'duration'), 5000, 1000, 30000);
        var overlayOpacity = floatValue(backgroundAttribute(el, 'overlay-opacity'), 0, 0, 1);
        var overlayColor = (backgroundAttribute(el, 'overlay-color') || '').trim();
        if (['cover', 'contain'].indexOf(fit) === -1) { fit = 'cover'; }
        if (['fade', 'crossfade', 'slide'].indexOf(transition) === -1) { transition = 'crossfade'; }
        stage.setAttribute('data-transition', reducedMotion() ? 'fade' : transition);
        stage.setAttribute('data-ken-burns', !reducedMotion() && playback(el) === 'animated' && (backgroundAttribute(el, 'ken-burns') || '0') === '1' ? '1' : '0');
        setBackgroundProperty(stage, 'fit', fit);
        setBackgroundProperty(stage, 'position', position);
        setBackgroundProperty(stage, 'transition-duration', reducedMotion() ? '0ms' : transitionDuration + 'ms');
        setBackgroundProperty(stage, 'duration', duration + 'ms');
        setBackgroundProperty(stage, 'overlay-color', overlayColor || 'transparent');
        setBackgroundProperty(stage, 'overlay-opacity', String(overlayOpacity));
    }

    function buildSlides(state, items) {
        var stage = state.stage;
        state.slides.forEach(function (slide) { if (slide.parentNode === stage) { stage.removeChild(slide); } });
        state.slides = [];
        items.forEach(function (item) {
            var slide = document.createElement('div');
            slide.className = 'dfsb-background-stage__slide etg-dfsb-background-stage__slide';
            setSlideAttribute(slide, 'attachment-id', String(item.id || 0));
            setSlideAttribute(slide, 'hydrated', '0');
            stage.insertBefore(slide, state.overlay);
            state.slides.push(slide);
        });
    }

    function hydrateSlide(state, index) {
        if (!state || !state.slides.length || !state.items.length) { return; }
        index = ((index % state.slides.length) + state.slides.length) % state.slides.length;
        var slide = state.slides[index], item = state.items[index];
        if (!slide || !item || slide.getAttribute('data-etg-dfsb-hydrated') === '1') { return; }
        // Shared files are served by another public image origin. Verify
        // them in the browser, not via a server-side fetch/SSRF. When a
        // shared item is missing, return to an available company-local frame.
        if (item.source === 'shared') {
            setSlideAttribute(slide, 'hydrated', 'loading');
            var requested = item.url;
            var image = new Image();
            image.onload = function () {
                if (!state.slides || state.slides[index] !== slide || !state.items || state.items[index] !== item) { return; }
                slide.style.backgroundImage = safeBackgroundUrl(requested);
                setSlideAttribute(slide, 'hydrated', '1');
            };
            image.onerror = function () {
                if (!state.slides || state.slides[index] !== slide || !state.items || state.items[index] !== item) { return; }
                setSlideAttribute(slide, 'hydrated', 'failed');
                var fallback = state.items.some(function (candidate) {
                    return candidate !== item && candidate.source !== 'shared' && candidate.url;
                });
                // Drop failed public asset from this view, including the last
                // valid source snapshot, so autoplay never retries a broken
                // remote slide or resurrects it after a filter refresh.
                var remaining = (state.sourceItems || []).filter(function (candidate) { return candidate.url !== requested; });
                state.items = (state.items || []).filter(function (candidate) { return candidate.url !== requested; });
                document.dispatchEvent(new CustomEvent('dfsb/shared-media-unavailable', { detail: { element: state.element, image_url: requested, local_fallback: fallback } }));
                render(state.element, remaining, { index: 0 });
            };
            image.src = requested;
            return;
        }
        slide.style.backgroundImage = safeBackgroundUrl(item.url);
        setSlideAttribute(slide, 'hydrated', '1');
    }

    function activate(state, index, immediate) {
        if (!state.slides.length) { return; }
        index = ((index % state.slides.length) + state.slides.length) % state.slides.length;
        if (immediate) { setBackgroundProperty(state.stage, 'transition-duration', '0ms'); }
        hydrateSlide(state, index);
        if (state.slides.length > 1 && autoplayEnabled(state)) { hydrateSlide(state, index + 1); }
        state.slides.forEach(function (slide, i) { slide.classList.toggle('is-active', i === index); });
        state.index = index;
        if (immediate) {
            window.setTimeout(function () { if (state.element && state.stage) { configure(state); } }, 0);
        }
    }

    function effectiveItems(state, items) {
        var mode = (backgroundAttribute(state.element, 'mode') || 'image').trim();
        var currentBehavior = behavior(state.element);
        var minimum = intValue(backgroundAttribute(state.element, 'min-slides'), 2, 1, 30);
        if (currentBehavior === 'disabled') { return []; }
        if (mode === 'image' || currentBehavior === 'first_image' || reducedMotion() || playback(state.element) === 'static') { return items.length ? [items[0]] : []; }
        if (mode === 'slideshow' && items.length > 0 && items.length < minimum) { return [items[0]]; }
        return items;
    }

    function autoplayEnabled(state) {
        if (reducedMotion() || behavior(state.element) !== 'inherit' || playback(state.element) !== 'animated') { return false; }
        if ((backgroundAttribute(state.element, 'mode') || '') !== 'slideshow') { return false; }
        return (backgroundAttribute(state.element, 'autoplay') || '0') === '1';
    }

    function schedule(state) {
        clearTimer(state);
        if (!state || !elementConnected(state.element) || !pageVisible() || state.inViewport === false || state.paused || !autoplayEnabled(state) || state.slides.length < 2) { return; }
        var duration = intValue(backgroundAttribute(state.element, 'duration'), 5000, 1000, 30000);
        state.timer = window.setTimeout(function () {
            state.timer = null;
            if (!elementConnected(state.element) || !pageVisible() || state.inViewport === false) { return; }
            activate(state, state.index + 1, false);
            schedule(state);
        }, duration);
    }

    function render(el, incoming, options) {
        var state = ensureStage(el);
        configure(state);
        var maximum = intValue(backgroundAttribute(el, 'max-slides'), 30, 1, 30);
        var sourceItems = uniqueGallery(incoming == null ? readGallery(el) : incoming, maximum);
        state.sourceItems = sourceItems;
        var rule = suitabilityPolicy(el);
        var eligible = suitableGallery(el, sourceItems);
        var usedFallback = false;
        if (!eligible.length && rule.empty === 'fallback') {
            eligible = readFallback(el);
            // During an AJAX refresh an empty media response is not proof the
            // gallery was removed. Preserve the last *healthy* collection,
            // but never override a deliberate Hide or disabled policy.
            if (!eligible.length && state.items && state.items.length && backgroundAttribute(el, 'source') !== 'registered_slot') {
                eligible = suitableGallery(el, state.items);
            }
            usedFallback = eligible.length > 0;
        }
        var items = effectiveItems(state, eligible);
        var policyDetail = { element: el, policy: rule.name, source_count: sourceItems.length, eligible_count: eligible.length, used_fallback: usedFallback };
        document.dispatchEvent(new CustomEvent('dfsb/background-policy-applied', { detail: policyDetail }));
        document.dispatchEvent(new CustomEvent('etg-dfsb/background-policy-applied', { detail: policyDetail }));
        clearTimer(state);
        state.items = items;
        // The server's first-paint image must not outlive the selected gallery.
        // Only manage inline first-frame styling for an explicitly template-bound gallery.
        if (backgroundAttribute(el, 'template-gallery') !== null && el.style) {
            var firstFrame = items.length ? safeBackgroundUrl(items[0].url) : 'none';
            if (backgroundAttribute(el, 'frame-scope') === 'first_child') { el.style.setProperty('--dfsb-first-frame', firstFrame); }
            else { el.style.backgroundImage = firstFrame; }
        }
        if (!items.length) {
            state.stage.setAttribute('hidden', 'hidden');
            buildSlides(state, []);
            return;
        }
        state.stage.removeAttribute('hidden');
        buildSlides(state, items);
        var random = playback(el) === 'animated' && (backgroundAttribute(el, 'random-start') || '0') === '1' && items.length > 1;
        var index = options && typeof options.index === 'number' ? options.index : (random ? Math.floor(Math.random() * items.length) : 0);
        activate(state, index, true);
        schedule(state);
    }

    function init(el) {
        if (!el || !el.matches || !el.matches(selector)) { return; }
        render(el, null, null);
    }

    function scan(root) {
        root = root || document;
        if (root.matches && root.matches(selector)) { init(root); }
        if (root.querySelectorAll) { Array.prototype.forEach.call(root.querySelectorAll(selector), init); }
    }

    document.addEventListener('dfsb/media-updated', function (event) {
        var detail = event && event.detail ? event.detail : {};
        var el = detail.element;
        if (!el || !el.matches || !el.matches(selector)) { return; }
        var mode = (backgroundAttribute(el, 'mode') || 'image').trim();
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
                var items = state && state.sourceItems ? state.sourceItems : readGallery(el);
                var index = state && typeof state.index === 'number' ? state.index : 0;
                render(el, items, { index: index });
            });
        }, 100);
    });

    if (typeof IntersectionObserver !== 'undefined') {
        viewportObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var el = entry && entry.target;
                var state = el && states ? states.get(el) : null;
                if (!state) { return; }
                state.inViewport = !!(entry.isIntersecting || Number(entry.intersectionRatio) > 0);
                if (!state.inViewport) { clearTimer(state); } else { schedule(state); }
            });
        }, { rootMargin: '200px 0px' });
    }

    document.addEventListener('visibilitychange', function () {
        Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) {
            var state = states && states.get(el);
            if (!state) { return; }
            if (!pageVisible()) { clearTimer(state); } else { schedule(state); }
        });
    });

    if (window.matchMedia) {
        var motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
        var onMotionChange = function () {
            Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) {
                var state = states && states.get(el);
                var items = state && state.sourceItems ? state.sourceItems : readGallery(el);
                render(el, items, { index: 0 });
            });
        };
        if (motionPreference && typeof motionPreference.addEventListener === 'function') { motionPreference.addEventListener('change', onMotionChange); }
        else if (motionPreference && typeof motionPreference.addListener === 'function') { motionPreference.addListener(onMotionChange); }
    }

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
