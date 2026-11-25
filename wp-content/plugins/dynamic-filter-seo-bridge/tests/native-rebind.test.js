// Real adapter lifecycle with native-like registries and event channels.
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/jsf-native-rebind.js'),'utf8');
const groups={}, nodes=[], callbacks={}, timers=[], events=[], calls=[];let now=0;const foreign=()=>{};
function el(id,provider,query,connected=true,compound=false){const attrs={'jsf-filter':'','data-filter-id':String(id),'data-content-provider':provider,'data-query-id':query};const node={nodeType:1,isConnected:connected,querySelector(sel){if(sel==='[data-additional-providers]')return compound?this:null;return this;},matches(){return false;},getAttribute:k=>attrs[k]??null,setAttribute(k,v){attrs[k]=v;},removeAttribute(k){delete attrs[k];},attrs};return node;}
function filter(node){return {filterId:Number(node.getAttribute('data-filter-id')),$container:[node]};}
function group(key,items){return groups[key]={filters:items.map(filter),isAjaxLoading:false};}
const staleA=el(5,'jet-engine','custom_A',false),staleB=el(5,'other-provider','custom_B',false),a=el(5,'jet-engine','custom_A'),b=el(5,'other-provider','custom_B'),foreignNode=el(5,'unknown','custom_A'),compound=el(5,'jet-engine','custom_A',true,true);
const ga=group('jet-engine/custom_A',[staleA]),gb=group('other-provider/custom_B',[staleB]);nodes.push(a,b,foreignNode,compound);
const channels={existing:[foreign]};const jsf={filterGroups:groups,events:{channels,subscribe(n,f){(channels[n]??=[]).push(f);}},initFilter(node){calls.push(node);node.setAttribute('jsf-filter','');const key=node.getAttribute('data-content-provider')+'/'+node.getAttribute('data-query-id');groups[key].filters.push(filter(node));channels.existing.push(()=>{});}};
const window={DFSB_AJAX:{jsfVersion:'3.8.5.1',supportedJsfVersions:['3.8.5.1']},JetSmartFilters:jsf,jQuery:n=>n,setTimeout(fn,delay){timers.push({fn,delay:delay||0});},addEventListener(){}};
const document={readyState:'loading',addEventListener(n,f){callbacks[n]=f;},querySelectorAll:()=>nodes,dispatchEvent(e){events.push(e);}};
vm.runInNewContext(source,{window,document,Date:{now:()=>now},CustomEvent:class{constructor(type,opts){this.type=type;this.detail=opts.detail;}}});
function drain(){while(timers.length){const t=timers.shift();now+=t.delay;t.fn();}}
callbacks['jet-smart-filters/inited']();drain();assert.deepEqual(calls,[a,b]);assert.deepEqual(ga.filters.map(x=>x.$container[0]),[a]);assert.deepEqual(gb.filters.map(x=>x.$container[0]),[b]);assert.equal(channels.existing[0],foreign);assert.deepEqual(Array.from(events.find(e=>e.type==='dfsb/jsf-native-rebind').detail.groups),['jet-engine/custom_A','other-provider/custom_B']);
callbacks['jet-smart-filters/inited']();channels['ajaxFilters/updated'][0]();drain();assert.equal(calls.length,2,'no duplicate listeners or filter init');
const a2=el(5,'jet-engine','custom_A');a.isConnected=false;nodes.push(a2);ga.isAjaxLoading=true;channels['ajaxFilters/updated'][0]();drain();assert.equal(calls.length,2,'loading group not touched');ga.isAjaxLoading=false;channels['ajaxFilters/end-loading'][0]();drain();assert.equal(calls.length,3);assert.deepEqual(ga.filters.map(x=>x.$container[0]),[a2]);assert.equal(channels.existing.length,3,'remove only adapter-owned detached callback');
// Native addFilter may replace its registry entry before the adapter prunes it.
const b2=el(5,'other-provider','custom_B');b.isConnected=false;nodes.push(b2);
const init=jsf.initFilter;jsf.initFilter=function(node){init(node);const g=groups[node.getAttribute('data-content-provider')+'/'+node.getAttribute('data-query-id')];g.filters=g.filters.filter(x=>x.$container[0].isConnected);};
channels['ajaxFilters/updated'][0]();drain();assert.equal(calls.length,4);assert.deepEqual(gb.filters.map(x=>x.$container[0]),[b2]);assert.equal(channels.existing.length,3,'owned callback removed after native eviction');
const a3=el(5,'jet-engine','custom_A');a2.isConnected=false;nodes.push(a3);window.DFSB_AJAX.jsfVersion='unsupported';channels['ajaxFilters/updated'][0]();drain();assert.equal(calls.length,4,'unsupported runtime inert');
console.log('PASS: native group discovery, multiple providers, stale repair, listener ownership, loading and version gates');
