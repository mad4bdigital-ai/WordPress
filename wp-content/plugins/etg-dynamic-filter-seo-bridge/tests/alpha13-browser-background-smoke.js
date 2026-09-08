'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/container-dynamic-background.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/css/container-dynamic-background.css'), 'utf8');

for (const token of [
    'etg-dfsb/media-updated',
    'etg-dfsb/ajax-presentation-reset',
    'data-etg-dfsb-background-fallback',
    "data-etg-dfsb-background-' + device()",
    "matchMedia('(prefers-reduced-motion: reduce)')",
    'MutationObserver',
    'safeBackgroundUrl',
    'uniqueGallery',
    'clearTimer',
]) {
    assert(source.includes(token), `missing browser background contract: ${token}`);
}

assert(!source.includes('history.pushState'), 'background stage must not mutate history');
assert(!source.includes('history.replaceState'), 'background stage must not replace history');
assert(css.includes('overflow: hidden'), 'chosen container clips background stage');
assert(css.includes('isolation: isolate'), 'chosen container isolates stacking context');
assert(css.includes('@media (prefers-reduced-motion: reduce)'), 'reduced motion CSS missing');
assert(css.includes('pointer-events: none'), 'background stage must never intercept controls');

console.log('Alpha13 browser container-background contract smoke tests passed.');
