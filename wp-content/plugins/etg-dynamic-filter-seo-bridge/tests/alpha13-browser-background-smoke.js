'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/container-dynamic-background.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/css/container-dynamic-background.css'), 'utf8');
const main = fs.readFileSync(path.join(root, 'etg-dynamic-filter-seo-bridge.php'), 'utf8');

for (const token of [
  'etg-dfsb/media-updated', 'etg-dfsb/ajax-presentation-reset', 'MutationObserver',
  'safeBackgroundUrl', 'sourceItems', 'responsiveBreakpoints', 'elementConnected',
  "window.matchMedia('(prefers-reduced-motion: reduce)')",
  "if (mode === 'slideshow' && items.length > 0 && items.length < minimum) { return [items[0]]; }",
  'render(el, null, null);', 'current.paused = true;', 'current.paused = false;'
]) assert(source.includes(token), `missing hardened background runtime contract: ${token}`);

assert(source.includes('activeBreakpoints'), 'Elementor custom breakpoints must drive device behavior');
assert(source.includes('state && state.sourceItems ? state.sourceItems : readGallery(el)'), 'responsive/motion rerenders must preserve the uncompressed source gallery');
assert(!source.includes('history.pushState') && !source.includes('history.replaceState'), 'background runtime cannot mutate history');
const rootRule = css.match(/\.etg-dfsb-dynamic-background\s*\{([\s\S]*?)\}/);
assert(rootRule && !rootRule[1].includes('overflow: hidden'), 'chosen Container content must not be clipped by ETG');
const stageRule = css.match(/\.etg-dfsb-dynamic-background\s*>\s*\.etg-dfsb-background-stage\s*\{([\s\S]*?)\}/);
assert(stageRule && stageRule[1].includes('overflow: hidden') && stageRule[1].includes('contain: paint'), 'only the background stage owns clipping/paint containment');
assert(!css.includes('will-change:'), 'all slideshow layers must not be permanently GPU-promoted');
assert(css.includes('isolation: isolate') && css.includes('pointer-events: none'), 'stacking and pointer isolation remain explicit');
assert(main.includes("ETG_DFSB_BOOT_BUILD', 'alpha13-container-background-2'"), 'deep runtime replacement must advance Safe Boot generation');
console.log('Alpha13 browser container-background deep runtime contract tests passed.');
