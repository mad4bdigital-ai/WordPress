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
function makePanel(group, childGroups=[]) {
  return {
    dataset: {}, hidden: false, classList: {toggle() {}},
    getAttribute(name) { return name==='data-dfsb-group' ? group : null; },
    setAttribute(name,value){this[name]=value;},
    querySelector(selector){return selector==='.seo-readmore-content'?{innerText:'Explore selected terms.'}:null;},
    querySelectorAll(selector){return selector.startsWith('[data-etg-dfsb-token]')?childGroups.map(g=>({getAttribute:n=>n==='data-etg-dfsb-group'?g:null})):[];},
    appendChild(el){this.status=el;}
  };
}
const panel=makePanel('jet-engine/tours'), other=makePanel('jet-engine/activities'), auto=makePanel('auto'), mixed=makePanel('auto',['jet-engine/tours','jet-engine/activities']);
const document = {
  documentElement:{lang:'en'}, readyState:'loading',
  createElement(){return {setAttribute(name,value){this[name]=value;},hidden:false,textContent:''};},
  querySelectorAll(selector){return selector==='.dfsb-archive-editorial'?[panel,other,auto,mixed]:[];},
  addEventListener(name,handler){if(!handlers.has(name))handlers.set(name,[]);handlers.get(name).push(handler);}
};
function emit(name,detail={}) {
  assert.ok(handlers.has(name),name+' listener must be registered');
  handlers.get(name).forEach(fn=>fn({detail}));
}
const window={requestAnimationFrame(callback){callback();return 1;}};
vm.runInNewContext(source,{document,window,MutationObserver:function(){throw Error('Broad observer regressed');}},{timeout:1200});
emit('DOMContentLoaded');
const a='jet-engine/tours', b='jet-engine/activities';
function event(kind,key,extra={}){emit('dfsb/ajax-presentation-'+kind,{group:key,editorial_auto_group:a,...extra});}
assert.equal(panel.dataset.dfsbEditorialBound,'1');
event('start',a);assert.equal(panel['aria-busy'],'true');assert.equal(other['aria-busy'],'false');assert.equal(auto['aria-busy'],'true');
event('start',b);assert.equal(other['aria-busy'],'true');
event('updated',a,{result_count_authoritative:true,result_count:0});
assert.equal(panel['aria-busy'],'false');assert.match(panel.status.textContent,/No tours match/);assert.equal(other['aria-busy'],'true');assert.equal(mixed['aria-busy'],'true');
event('updated',b,{result_count_authoritative:true,result_count:3});assert.equal(mixed.status.hidden,true);
event('blocked',b);assert.match(other.status.textContent,/could not refresh/);assert.match(panel.status.textContent,/No tours match/);
event('reset',b);assert.equal(other.status.hidden,true);assert.match(panel.status.textContent,/No tours match/);
event('start',b,{editorial_auto_group:b});assert.equal(auto['aria-busy'],'true');assert.equal(panel['aria-busy'],'false');
event('updated',b,{editorial_auto_group:b,result_count_authoritative:false,result_count:0});assert.equal(auto.status.hidden,true);
event('reset',a);assert.equal(panel.status.hidden,true);
emit('dfsb/ajax-presentation-blocked',{reason:'jsf_runtime_contract_unavailable',editorial_auto_group:''});assert.equal(other['aria-busy'],'false');assert.match(other.status.textContent,/could not refresh/);
event('start',a);assert.equal(panel['aria-busy'],'true');assert.match(other.status.textContent,/could not refresh/,'unrelated recovery must not clear global failure for the other group');
event('updated',a,{result_count_authoritative:true,result_count:2});assert.equal(panel.status.hidden,true);
event('updated',b,{result_count_authoritative:true,result_count:1});assert.equal(other.status.hidden,true);
// Historical event without an auto-group projection still resolves a single group.
emit('dfsb/ajax-presentation-reset',{group:a,editorial_auto_group:''});
emit('dfsb/ajax-presentation-start',{group:a});assert.equal(auto['aria-busy'],'true');
emit('dfsb/ajax-presentation-updated',{provider:'jet-engine',query_id:'tours',result_count_authoritative:true,result_count:1});assert.equal(auto['aria-busy'],'false');
console.log('PASS: independent explicit/auto/mixed groups, authoritative zero, reset, error, and no self-observing DOM');
