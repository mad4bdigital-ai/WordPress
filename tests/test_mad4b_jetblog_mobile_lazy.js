/* MAD4B JetBlog initial mobile AJAX defer — isolated JS contract test. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../wp-content/mu-plugins/assets/mad4b-jetblog-lazy-mobile.js'), 'utf8');
assert(source.includes('mad4bResponsiveObserver'));
assert(source.includes("rootMargin: '450px 0px'"));
assert.equal((source.match(/\$\.ajax\(\{/g) || []).length, 3, 'all three vendor AJAX sites retained');

const callbacks = {};
const browser = { hasJetBlogPlaylist: 0, JetBlogSettings: { ajaxurl: 'https://staging.example/?jet_blog_ajax=1' } };
const doc = { documentElement: { contains: () => true } };
let ajaxes = 0, observer;
class Observer {
  constructor(cb, opts) { this.cb = cb; this.opts = opts; observer = this; }
  observe(el) { this.el = el; }
  disconnect() { this.disconnected = true; }
}
function element() {
  return {
    length: 1, 0: {},
    data(key) {
      if (key === 'settings') return {
        is_archive_template: 'no', custom_query: '{}',
        posts_rows: 2, posts_columns: 2, featured_post: 'no'
      };
      if (key === 'id') return 'qa-scope';
      return '';
    },
    find() { return element(); },
    on() { return this; },
    one(event, selector, handler) { this.cancelHandler = handler; return this; },
    trigger() { return this; },
    hasClass() { return false; },
    addClass() { return this; },
    removeClass() { return this; },
    html() { return this; },
    replaceWith() { return this; }
  };
}
const scope = element();
const wrapper = element();
const filter = { ...element(), length: 0, data: () => false };
function $(value, within) {
  if (value === browser) return { on(event, handler) { callbacks[event] = handler; return this; } };
  if (value === doc) return { trigger() {} };
  if (value === '.jet-smart-listing-wrap') return wrapper;
  if (value === '.jet-smart-listing__filter') return filter;
  return element();
}
$.fn = {};
$.each = (obj, fn) => Object.keys(obj).forEach(k => fn(k, obj[k]));
$.ajax = () => {
  ajaxes++;
  return { done(fn) { fn({ data: { posts: '<div>test</div>', arrows: '' } }); return { fail() {} }; } };
};
const elementor = {
  getCurrentDeviceMode: () => 'mobile',
  isEditMode: () => false,
  config: { responsive: { activeBreakpoints: { mobile: {} } } },
  hooks: { addAction(name, fn) { callbacks[name] = fn; } },
  elements: { $window: { on() {} } }
};
browser.elementorFrontend = elementor;
const context = {
  window: browser, document: doc, jQuery: $, console,
  elementorFrontend: elementor, IntersectionObserver: Observer
};
context.window.IntersectionObserver = Observer;
vm.runInNewContext(source, context, { timeout: 1000 });
assert.equal(typeof callbacks['elementor/frontend/init'], 'function');
callbacks['elementor/frontend/init']();
const init = callbacks['frontend/element_ready/jet-blog-smart-listing.default'];
assert.equal(typeof init, 'function');
init(scope);
assert.equal(ajaxes, 0, 'offscreen initial smart listing does not POST immediately');
assert.equal(observer.opts.rootMargin, '450px 0px');
observer.cb([{ isIntersecting: true }]);
assert.equal(ajaxes, 1, 'listing posts are fetched upon approaching viewport');
observer.cb([{ isIntersecting: true }]);
assert.equal(ajaxes, 1, 'one-shot observer never duplicates initial request');
assert.equal(observer.disconnected, true, 'observer disconnects after activation');
// User intervention must cancel the pending default refresh. A subsequent
// paging/filter click owns its own signed AJAX path.
const second = element();
const oldCount = ajaxes;
callbacks['frontend/element_ready/jet-blog-smart-listing.default'](second);
assert.equal(ajaxes, oldCount);
assert.equal(typeof second.cancelHandler, 'function');
second.cancelHandler();
observer.cb([{ isIntersecting: true }]);
assert.equal(ajaxes, oldCount, 'user interaction cancels deferred initial overwrite');
console.log('PASS deferred initial mobile POST, viewport activation, one-shot behavior, vendor AJAX parity');
