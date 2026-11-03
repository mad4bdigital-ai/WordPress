'use strict';
/**
 * Node VM smoke: the Editorial UI must respond to explicit DFSB lifecycle
 * events without observing and mutating its own DOM indefinitely.
 */
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/dfsb-archive-editorial.js'), 'utf8');
const handlers = new Map();
const panel = {
  dataset: {}, hidden: false, classList: {toggle() {}},
  setAttribute(name, value) { this[name] = value; },
  querySelector(selector) {
    if (selector === '.seo-readmore-content') return {innerText:'Explore selected terms.'};
    return null;
  },
  querySelectorAll(selector) { return []; },
  appendChild(el) {this.status=el;}
};
const document = {
  documentElement:{lang:'en'},
  readyState:'loading',
  createElement(){return {setAttribute(name,value){this[name]=value;},hidden:false,textContent:''};},
  querySelectorAll(selector){return selector==='.dfsb-archive-editorial'?[panel]:[];},
  addEventListener(name,handler){handlers.set(name,handler);}
};
function emit(name,detail={}) {
  const fn=handlers.get(name);
  assert.equal(typeof fn,'function',name+' listener must be registered');
  fn({detail});
}
const window={
  requestAnimationFrame(callback){callback();return 1;}
};
const globals={document,window,MutationObserver:function(){throw Error('Broad observer regressed');}};
vm.runInNewContext(source, globals, {timeout:1200});
emit('DOMContentLoaded');
assert.equal(panel.dataset.dfsbEditorialBound,'1');
assert.equal(panel.status.hidden,true);
emit('dfsb/ajax-presentation-updated',{result_count_authoritative:true,result_count:0});
assert.equal(panel.status.hidden,false);
assert.match(panel.status.textContent,/No tours match/);
emit('dfsb/ajax-presentation-reset');
assert.equal(panel.status.hidden,true);
emit('dfsb/ajax-presentation-blocked');
assert.equal(panel.status.hidden,false);
assert.match(panel.status.textContent,/could not refresh/);
assert.equal(panel['aria-busy'],'false');
console.log('PASS: explicit AJAX lifecycle, reset, error, and no self-observing DOM');
