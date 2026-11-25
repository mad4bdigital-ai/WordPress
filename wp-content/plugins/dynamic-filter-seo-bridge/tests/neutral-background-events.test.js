const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const read = name => fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8');
class CustomEvent { constructor(type, init){this.type=type;this.detail=init.detail;} }
const events=[];
const group={currentQuery:{_tax_query_old:'1',search:'keep'}};
vm.runInNewContext(read('js/ajax-taxonomy-reconcile.js'),{CustomEvent,document:{addEventListener(){},dispatchEvent(e){events.push(e);}},window:{location:{pathname:'/archive/jsf/jet-engine:Grid/tax/region:12'},JetSmartFilters:{filterGroups:{'jet-engine/Grid':group},events:{subscribe(){}}},setTimeout(fn){fn();}}});
assert.deepEqual(events.map(e=>e.type),['dfsb/jsf-taxonomy-reconciled','etg-dfsb/jsf-taxonomy-reconciled']);
assert.equal(events[0].detail,events[1].detail);
assert.equal(group.currentQuery._tax_query_region,'12');assert.equal(group.currentQuery.search,'keep');assert.equal(group.currentQuery._tax_query_old,undefined);
function element(){return {attrs:{},children:[],style:{values:{},setProperty(k,v){this.values[k]=v;}},className:'',getAttribute(k){return this.attrs[k]??null;},setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];},appendChild(e){this.children.push(e);e.parentNode=this;},addEventListener(){},matches(s){return s.includes('.dfsb-dynamic-background');},querySelectorAll(){return [];}};}
const container=element();container.setAttribute('data-dfsb-background-fit','contain');container.setAttribute('data-dfsb-background-position','left top');container.setAttribute('data-etg-dfsb-background-position','right bottom');const seen=[];const listeners={};const doc={readyState:'loading',documentElement:{clientWidth:1024},addEventListener(n,f){listeners[n]=f;},querySelectorAll(s){assert.ok(s.includes('.dfsb-dynamic-background')&&s.includes('.etg-dfsb-dynamic-background'));return [container];},createElement:element,dispatchEvent(e){seen.push(e);}};
vm.runInNewContext(read('js/container-dynamic-background.js'),{CustomEvent,document:doc,window:{innerWidth:1024,addEventListener(){},setTimeout(){},clearTimeout(){}}});
listeners.DOMContentLoaded();assert.equal(container.children.length,1);
assert.equal(container.children[0].className,'dfsb-background-stage etg-dfsb-background-stage');
assert.equal(container.children[0].children[0].className,'dfsb-background-stage__overlay etg-dfsb-background-stage__overlay');
const vars=container.children[0].style.values;assert.equal(vars['--dfsb-background-fit'],'contain');assert.equal(vars['--etg-dfsb-background-fit'],'contain');assert.equal(vars['--dfsb-background-position'],'right bottom');assert.equal(vars['--etg-dfsb-background-position'],'right bottom');
assert.deepEqual(seen.map(e=>e.type),['dfsb/background-policy-applied','etg-dfsb/background-policy-applied']);assert.equal(seen[0].detail,seen[1].detail);
const css=read('css/container-dynamic-background.css');for(const id of ['dynamic-background','dynamic-background--frame-first_child','background-stage','background-stage__slide','background-stage__overlay'])assert.ok(css.includes(':is(.dfsb-'+id+', .etg-dfsb-'+id+')'));
// Execute the actual render function with isolated rendering dependencies.
const code=read('js/container-dynamic-background.js');const start=code.indexOf('    function render(el, incoming, options)'),end=code.indexOf('    function init(el)',start);
const frame=element(),state={stage:element(),items:[]};frame.setAttribute('data-dfsb-background-template-gallery','[]');frame.setAttribute('data-dfsb-background-source','registered_slot');
const rc={ensureStage:()=>state,configure(){},intValue:(v,f)=>f,backgroundAttribute:(el,k)=>el.getAttribute('data-dfsb-background-'+k),uniqueGallery:items=>items,suitabilityPolicy:()=>({name:'any',empty:'hide'}),suitableGallery:(el,items)=>items,readFallback:()=>[],effectiveItems:(st,items)=>items,document:{dispatchEvent(){}},CustomEvent,clearTimer(){},buildSlides(){},safeBackgroundUrl:url=>'url("'+url+'")',playback:()=> 'static',activate(){},schedule(){}};
vm.runInNewContext(code.slice(start,end),rc);
rc.render(frame,[{url:'/selected.jpg'}],{});assert.equal(frame.style.backgroundImage,'url("/selected.jpg")');rc.render(frame,[],{});assert.equal(frame.style.backgroundImage,'none');
frame.setAttribute('data-dfsb-background-frame-scope','first_child');rc.render(frame,[{url:'/template.jpg'}],{});assert.equal(frame.style.values['--dfsb-first-frame'],'url("/template.jpg")');rc.render(frame,[],{});assert.equal(frame.style.values['--dfsb-first-frame'],'none');
const suitabilityStart=code.indexOf('    function imageSuitable('),suitabilityEnd=code.indexOf('    function suitableGallery(',suitabilityStart);
vm.runInNewContext(code.slice(suitabilityStart,suitabilityEnd),rc);
const wide={name:'wide',minWidth:1200,minHeight:0,minRatio:1.5};
assert.equal(rc.imageSuitable({url:'/uploads/101.png',width:1800,height:900,aspect_ratio:2},wide),true);
assert.equal(rc.imageSuitable({url:'https://media.example.com/a.jpg',width:0,height:0,aspect_ratio:0},wide),false);
assert.equal(rc.imageSuitable({url:'/portrait.png',width:900,height:1800,aspect_ratio:0.5},wide),false);
console.log('DFSB_NEUTRAL_BACKGROUND_AND_EVENTS: PASS');
