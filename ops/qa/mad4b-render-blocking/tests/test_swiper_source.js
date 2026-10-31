// Static guard: keep Swiper accessible without forcing layout in JS.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const code = fs.readFileSync('wp-content/mu-plugins/assets/accessibility-swiper.js', 'utf8');
assert.match(code, /IntersectionObserver/);
assert.match(code, /slide\.classList\.contains\('swiper-slide-active'\)/);
assert.match(code, /attributeFilter:\['class'\]/);
const executable = code.replace(/\/\*[\s\S]*?\*\/|\/\/[^\n]*/g, '');
assert.doesNotMatch(executable, /\b(getBoundingClientRect|getComputedStyle)\s*\(|\.\s*(offsetWidth|offsetHeight)\b/);
console.log('PASS: Swiper active focus is preserved without geometry reads');
