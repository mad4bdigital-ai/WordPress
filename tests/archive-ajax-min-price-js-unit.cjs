/* Hermetic real-script regression: no network, browser, WordPress or DB. */
'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const path=require('node:path');
const script=fs.readFileSync(path.join(__dirname,'../wp-content/mu-plugins/assets/mad4b-archive-compact-price-context-v2.js'),'utf8');
const calls=[],events={},timers=[];
const location={origin:'https://example.test',protocol:'https:',pathname:'/es/tours-and-activities/',search:'?utm_source=test'};
const slotBuilder=()=>({hidden:true,className:'',textContent:'',attributes:{},setAttribute(k,v){this.attributes[k]=v}});
const widget={style:{display:'',visibility:'',setProperty(k,v){this[k]=v},removeProperty(k){delete this[k]}}};
const target={children:[],style:{visibility:''},replaceChildren(){this.children=[]},appendChild(c){this.children.push(c)},querySelector(selector){if(selector==='.mad4b-archive-price-value')return this.children[0]??null;return null}};
widget.querySelector=s=>s==='.elementor-widget-container'?target:target.querySelector(s);
const document={
 readyState:'complete',
 querySelector:s=>s==='.elementor-34607 .elementor-element-b512fea'?widget:null,
 createElement:slotBuilder,
 addEventListener(){},
};
const history={pushState(...args){if(args[2])location.pathname=String(args[2]);return null},replaceState(...args){if(args[2])location.pathname=String(args[2]);return null}};
const group={currentQuery:{}};
const window={
 MAD4B_ARCHIVE_MIN_PRICE_ENDPOINT:location.origin+'/wp-json/mad4b/v1/archive/tours/min-price',
 JetSmartFilters:{
  filterGroups:{'jet-engine/tours_query_archive':group},
  events:{subscribe(name,fn){(events[name]??=[]).push(fn)}},
 },
 addEventListener(){}
};
let aborts=0;
class AbortControllerMock {constructor(){this.signal={aborted:false}}abort(){this.signal.aborted=true;aborts++}}
const fetchMock=(url,options)=>{
 const request={url:new URL(url),options,resolve:null,reject:null,promise:null};
 request.promise=new Promise((resolve,reject)=>{request.resolve=resolve;request.reject=reject;});
 calls.push(request);return request.promise;
};
const context={window,document,location,history,AbortController:AbortControllerMock,URL,URLSearchParams,fetch:fetchMock,
 setTimeout(fn){timers.push(fn);return timers.length},clearTimeout(){},console};
vm.runInNewContext(script,context,{filename:'archive-compact-price-context-v2.js',timeout:1500});
const tick=async()=>{
 while(timers.length){const next=timers.shift();next();}
 await new Promise(resolve=>setImmediate(resolve));
 await new Promise(resolve=>setImmediate(resolve));
};
const send=(request,value)=>{
 request.resolve({ok:true,json:()=>Promise.resolve(value)});
};
const available=(amount)=>({status:'available',label:'Start from',amount,currency:'USD',unit:'per person'});
const emit=(name,...args)=>{for(const fn of events[name]??[])fn(...args)};
let passed=0;
const test=(x,m)=>{assert(x,m);passed++;console.log('PASS',m)};
(async()=>{
 await tick();
 test(calls.length===1,'Initial archive price request exists only once');
 test(calls[0].url.searchParams.get('path')==='/es/tours-and-activities/','Marketing UTMs are excluded from membership path');
 test(calls[0].url.searchParams.get('query')==='{}','No filters yields complete authorized catalog');
 test(target.children[0]?.hidden===true && widget.style.display==='none','Unverified Spanish 0$ is replaced with an empty hidden slot');
 send(calls[0],available('28.00'));await tick();
 test(target.children[0]?.textContent.includes('28.00') && target.children[0]?.hidden===false,'Positive validated initial minimum is visible');
 group.currentQuery={'_tax_query_tour-types':'192'};
 emit('ajaxFilters/start-loading','jet-engine','tours_query_archive');
 test(target.children[0]?.hidden===true,'Starting new AJAX filter hides previous price immediately');
 emit('ajaxFilters/updated','jet-engine','tours_query_archive');
 emit('ajaxFilters/end-loading','jet-engine','tours_query_archive');
 await tick();
 test(calls.length===2 && JSON.parse(calls[1].url.searchParams.get('query'))['_tax_query_tour-types']==='192','Filter request carries native JSF query');
 send(calls[1],available('42.00'));await tick();
 test(target.children[0]?.textContent.includes('42.00'),'Filtered minimum replaces unrelated global minimum');
 group.currentQuery={'_tax_query_tour-types':'193'};
 emit('ajaxFilters/start-loading','jet-engine','tours_query_archive');
 emit('ajaxFilters/updated','jet-engine','tours_query_archive');
 await tick();
 test(calls.length===3,'Next filter initiates new request');
 group.currentQuery={'_tax_query_tour-types':'194'};
 emit('ajaxFilters/start-loading','jet-engine','tours_query_archive');
 emit('ajaxFilters/updated','jet-engine','tours_query_archive');
 await tick();
 test(calls.length===4 && aborts>=2,'Previous request cancelled for rapid filter change');
 send(calls[3],available('1,110.00'));await tick();
 send(calls[2],available('530.00'));await tick();
 test(target.children[0]?.textContent.includes('1,110.00'),'Late stale AJAX response cannot override newer price');
 group.currentQuery={'_date_query_departure':'unknown'};
 emit('ajaxFilters/start-loading','jet-engine','tours_query_archive');
 emit('ajaxFilters/updated','jet-engine','tours_query_archive');
 await tick();
 send(calls[4],{status:'unavailable',reason:'filter_scope_not_certified'});await tick();
 test(target.children[0]?.hidden===true && widget.style.display==='none','Unsupported filter hides price rather than showing false bargain');
 group.currentQuery={};
 emit('ajaxFilters/start-loading','jet-engine','tours_query_archive');
 emit('ajaxFilters/updated','jet-engine','tours_query_archive');
 await tick();send(calls[5],available('0.00'));await tick();
 test(target.children[0]?.hidden===true,'Zero pricing never appears');
 group.currentQuery={'_tax_query_tour-types':'192'};
 emit('ajaxFilters/start-loading','epro-archive','tours_query_archive');
 test(calls.length===6,'Other provider events do not mutate current price authority');
 console.log('PASS '+passed+' real-JS AJAX regression assertions');
})().catch(error=>{console.error(error.stack);process.exitCode=1});
