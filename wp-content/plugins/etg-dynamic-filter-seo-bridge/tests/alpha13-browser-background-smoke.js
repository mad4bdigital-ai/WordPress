'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/container-dynamic-background.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/css/container-dynamic-background.css'), 'utf8');
const main = fs.readFileSync(path.join(root, 'etg-dynamic-filter-seo-bridge.php'), 'utf8');
const bootstrap = fs.readFileSync(path.join(root, 'includes/Bootstrap.php'), 'utf8');

for (const token of [
    'etg-dfsb/media-updated',
    'etg-dfsb/ajax-presentation-reset',
    'MutationObserver',
    'safeBackgroundUrl',
    'sourceItems',
    'responsiveBreakpoints',
    'elementConnected',
    'hydrateSlide',
    'IntersectionObserver',
    "document.addEventListener('visibilitychange'",
    "window.matchMedia('(prefers-reduced-motion: reduce)')",
    "if (mode === 'slideshow' && items.length > 0 && items.length < minimum) { return [items[0]]; }",
    'suitabilityPolicy',
    'imageSuitable',
    'suitableGallery',
    "playback(state.element) === 'static'",
    'used_fallback',
    'render(el, null, null);',
    'current.paused = true;',
    'current.paused = false;'
]) {
    assert(source.includes(token), `missing hardened background runtime contract: ${token}`);
}

assert(source.includes('activeBreakpoints'), 'Elementor custom breakpoints must drive device behavior');
assert(source.includes('state && state.sourceItems ? state.sourceItems : readGallery(el)'), 'responsive/motion rerenders must preserve the uncompressed source gallery');
assert(!source.includes('history.pushState') && !source.includes('history.replaceState'), 'background runtime cannot mutate history');
const buildBody = source.slice(source.indexOf('function buildSlides'), source.indexOf('function hydrateSlide'));
assert(!buildBody.includes('backgroundImage'), 'buildSlides cannot eagerly hydrate every full-size background URL');
assert(source.slice(source.indexOf('function hydrateSlide'), source.indexOf('function activate')).includes('backgroundImage'), 'hydrateSlide owns URL hydration');
assert(css.includes(':where(.etg-dfsb-dynamic-background)'), 'root position fallback must be zero-specificity');
assert(css.includes('z-index: -1'), 'ETG background stage must stay behind native child stacking');
assert(!css.includes('> :not(.etg-dfsb-background-stage)'), 'ETG must not rewrite arbitrary Elementor/UAE/Jet child z-index');
assert(!css.includes('.elementor-background-overlay'), 'Elementor overlay stacking remains Elementor-owned');
assert(css.includes('contain: paint') && css.includes('pointer-events: none'), 'background stage keeps paint and pointer containment');
assert(!css.includes('will-change:'), 'all slideshow layers must not be permanently GPU-promoted');
assert(main.includes("ETG_DFSB_BOOT_BUILD', 'alpha13-container-background-4'"), 'replacement must advance Safe Boot generation');
assert(main.includes("ETG_DFSB_ASSET_VERSION', '0.4.0-alpha.13-build4'"), 'replacement must cache-bust changed editor/admin/runtime assets');
assert(!main.includes('new ETG\\DynamicFilterSEOBridge\\Elementor\\ContainerDynamicBackground'), 'entrypoint must not own a second container runtime');
assert(bootstrap.includes('new ContainerDynamicBackground($this->presentation,$slots)'), 'Bootstrap must own the container runtime with shared slots');

// Minimal behavioral DOM: prove 4 resolved items create 4 logical slides but only
// active + next are hydrated initially. This guards the network-amplification bug.
class FakeStyle {
    constructor() { this.props = {}; this.backgroundImage = ''; }
    setProperty(name, value) { this.props[name] = String(value); }
}
class FakeClassList {
    constructor(owner) { this.owner = owner; this.values = new Set(); }
    toggle(name, force) { if (force) this.values.add(name); else this.values.delete(name); }
}
class FakeElement {
    constructor(tag, attrs = {}) {
        this.tagName = String(tag || 'div').toUpperCase();
        this.attrs = Object.assign({}, attrs);
        this.children = [];
        this.parentNode = null;
        this.style = new FakeStyle();
        this.className = attrs.class || '';
        this.classList = new FakeClassList(this);
        this.listeners = {};
        this.nodeType = 1;
        this.isConnected = true;
    }
    get firstChild() { return this.children[0] || null; }
    setAttribute(name, value) { this.attrs[name] = String(value); if (name === 'class') this.className = String(value); }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; }
    removeAttribute(name) { delete this.attrs[name]; }
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name); }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    insertBefore(child, before) {
        child.parentNode = this;
        if (!before) { this.children.push(child); return child; }
        const index = this.children.indexOf(before);
        if (index < 0) this.children.push(child); else this.children.splice(index, 0, child);
        return child;
    }
    removeChild(child) { const i = this.children.indexOf(child); if (i >= 0) this.children.splice(i, 1); child.parentNode = null; }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
    matches(selector) { return selector === '.etg-dfsb-dynamic-background' && this.className.split(/\s+/).includes('etg-dfsb-dynamic-background'); }
    querySelectorAll(selector) {
        const out = [];
        const walk = (node) => node.children.forEach((child) => { if (child.matches && child.matches(selector)) out.push(child); walk(child); });
        walk(this); return out;
    }
}

const gallery = [1,2,3,4].map((id) => ({ id, url: `https://example.test/${id}.jpg`, width: 1600, height: 900, aspect_ratio: 1.7778 }));
const container = new FakeElement('section', {
    class: 'etg-dfsb-dynamic-background',
    'data-etg-dfsb-background-mode': 'slideshow',
    'data-etg-dfsb-gallery': JSON.stringify(gallery),
    'data-etg-dfsb-background-fallback': '[]',
    'data-etg-dfsb-background-max-slides': '8',
    'data-etg-dfsb-background-min-slides': '2',
    'data-etg-dfsb-background-playback': 'animated',
    'data-etg-dfsb-background-suitability': 'wide',
    'data-etg-dfsb-background-min-width': '1200',
    'data-etg-dfsb-background-min-height': '0',
    'data-etg-dfsb-background-min-ratio': '1.5',
    'data-etg-dfsb-background-no-suitable': 'fallback',
    'data-etg-dfsb-background-autoplay': '1',
    'data-etg-dfsb-background-duration': '5000',
    'data-etg-dfsb-background-transition': 'crossfade',
    'data-etg-dfsb-background-transition-duration': '800',
    'data-etg-dfsb-background-fit': 'cover',
    'data-etg-dfsb-background-position': 'center center',
    'data-etg-dfsb-background-desktop': 'inherit',
    'data-etg-dfsb-background-tablet': 'inherit',
    'data-etg-dfsb-background-mobile': 'inherit'
});
const documentListeners = {};
const documentElement = new FakeElement('html');
documentElement.clientWidth = 1280;
documentElement.appendChild(container);
documentElement.contains = (node) => node === documentElement || node === container || !!node.parentNode;
const document = {
    readyState: 'complete', hidden: false, documentElement, body: documentElement,
    createElement: (tag) => new FakeElement(tag),
    querySelectorAll: (selector) => { const out = []; if (container.matches(selector)) out.push(container); return out.concat(container.querySelectorAll(selector)); },
    addEventListener: (name, fn) => { (documentListeners[name] ||= []).push(fn); },
    dispatchEvent: (event) => { (documentListeners[event.type] || []).forEach((fn) => fn(event)); return true; }
};
let timerId = 0;
const timers = new Map();
const fakeSetTimeout = (fn, ms) => { const id = ++timerId; if (Number(ms) === 0) fn(); else timers.set(id, fn); return id; };
const fakeClearTimeout = (id) => timers.delete(id);
const window = {
    innerWidth: 1280,
    setTimeout: fakeSetTimeout,
    clearTimeout: fakeClearTimeout,
    addEventListener() {},
    matchMedia: () => ({ matches: false, addEventListener() {}, addListener() {} }),
    elementorFrontend: { config: { responsive: { activeBreakpoints: { mobile: { value: 767 }, tablet: { value: 1024 } } } } }
};
const context = {
    window, document, WeakMap, JSON, Array, Object, String, Number, Math, Error, isFinite,
    setTimeout: fakeSetTimeout, clearTimeout: fakeClearTimeout,
    CustomEvent: class CustomEvent { constructor(type, init) { this.type = type; this.detail = (init && init.detail) || {}; } },
    console
};
vm.runInNewContext(source, context, { filename: 'container-dynamic-background.js' });
const stage = container.children.find((child) => child.className === 'etg-dfsb-background-stage');
assert(stage, 'background stage must be created');
const slides = stage.children.filter((child) => child.className === 'etg-dfsb-background-stage__slide');
assert.strictEqual(slides.length, 4, 'all suitable logical slides remain available for rotation');
assert.strictEqual(slides.filter((slide) => slide.style.backgroundImage).length, 2, 'only active + next slide hydrate initially');
assert.strictEqual(slides.filter((slide) => slide.getAttribute('data-etg-dfsb-hydrated') === '1').length, 2, 'hydration state matches assigned URLs');

console.log('Alpha13 browser container-background deep behavior/stack/performance/policy tests passed.');
