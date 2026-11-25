// Exercise actual editorial binding with native cards and independent scopes.
'use strict';
const assert=require('node:assert/strict'), fs=require('node:fs'), vm=require('node:vm'), path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/dfsb-archive-editorial.js'),'utf8');
const handlers={}; const a='jet-engine/tours', b='jet-engine/activities';
function card(id,text,group=a,role='place') {
 const attrs={'data-term-id':String(id),'data-dfsb-group':group,'data-dfsb-role':role};
 return {hidden:false,textContent:text,innerText:'collapsed heading',getAttribute:k=>attrs[k]||null,closest(){return this;}};
}
function field(cards,extra='') {
 return {hidden:false,get textContent(){return cards.map(c=>c.textContent).join('')+extra;},querySelector(){return null;},querySelectorAll(sel){return sel==='.dfsb-term-detail[data-term-id]'?cards:[];}};
}
const first=card(1,'A unique description'), same=card(1,'A unique description'), different=card(1,'A different description'), other=card(1,'A unique description',b), role=card(1,'A unique description',a,'style'), unique=card(2,'A unique description'), auto1=card(1,'A unique description','auto'), auto2=card(1,'A unique description','auto');
const fields=[field([first]),field([same]),field([different]),field([other]),field([role]),field([unique]),field([auto1]),field([auto2],'custom copy')];
const panel={dataset:{},classList:{toggle(){}},getAttribute(){return null;},setAttribute(k,v){this[k]=v;},querySelector(){return null;},querySelectorAll(sel){if(sel==='.dfsb-term-detail[data-term-id]')return fields.flatMap(f=>f.querySelectorAll(sel));if(sel==='.elementor-widget-jet-listing-dynamic-field')return fields;return [];},appendChild(){}};
const document={documentElement:{lang:'en'},readyState:'complete',querySelectorAll:()=>[panel],createElement:()=>({setAttribute(){}}),addEventListener:(n,fn)=>{(handlers[n]??=[]).push(fn);}};
vm.runInNewContext(source,{document,window:{requestAnimationFrame:fn=>fn()}});
assert.equal(same.hidden,true);assert.equal(fields[1].hidden,true);for(const c of [first,different,other,role,unique,auto1,auto2])assert.equal(c.hidden,false);
function emit(group){handlers['dfsb/ajax-presentation-updated'].forEach(fn=>fn({detail:{group,editorial_auto_group:group}}));}
emit(a);assert.equal(auto1.hidden,true);assert.equal(auto2.hidden,true);assert.equal(fields[7].hidden,false,'custom copy retained');
emit(b);assert.equal(auto1.hidden,true,'exact matching group B');
other.textContent='Updated group B';emit(b);assert.equal(auto1.hidden,false,'previously hidden card restored after scope/content change');assert.equal(auto2.hidden,true);
same.textContent='New distinct text';emit(a);assert.equal(same.hidden,false);assert.equal(fields[1].hidden,false);
console.log('PASS: exact native cards, group/role/term/content isolation, custom copy, unresolved auto, and reappearance');
