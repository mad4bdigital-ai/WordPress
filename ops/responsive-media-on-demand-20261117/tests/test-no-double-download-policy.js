'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync(process.argv[2],'utf8');
const ORIGIN='https://wayfatours.com';
const DERIVATIVE=ORIGIN+'/wp-content/uploads/cairo.jpg.mad4b-demand-576-aaaaaaaaaaaa.webp';
function flushed(){return new Promise(r=>setImmediate(r));}
async function test(scenario){
 const signals={rest:0,image:0,applied:0,first:0,success:0};
 let restResolve=null,revealObserver=null,io=null;
 const rootClass='mad4b-city-geometry-lazy',visibleClass='mad4b-city-geometry-visible';
 const classNames=new Set(['elementor-element-95d1c9c']);
 if(scenario==='already-visible')classNames.add(visibleClass);
 const card={isConnected:true,classList:{contains:n=>classNames.has(n)},style:{setProperty(name,value,priority){assert.strictEqual(name,'background-image');assert.strictEqual(priority,'important');assert(value.includes('mad4b-demand-576'));signals.applied++;}},setAttribute(name,value){assert.strictEqual(name,'data-mad4b-responsive-image');assert.strictEqual(value,'ready');signals.success++;},closest(sel){return sel==='.jet-listing-grid__item'?item:null;}};
 const item={className:'jet-listing-grid__item jet-listing-dynamic-post-123',hasAttribute(){return false;}};
 const doc={readyState:'complete',documentElement:{classList:{contains:n=>n===rootClass}},querySelectorAll:s=>s==='.elementor-element-95d1c9c'?[card]:[],body:{},addEventListener(){}};
 class IntersectionObserver {constructor(cb){io=cb;}observe(target){queueMicrotask(()=>io([{isIntersecting:true,target,boundingClientRect:{width:288}}]));}unobserve(){}}
 class MutationObserver {constructor(cb){this.cb=cb;}observe(target,options){if(target===card)revealObserver=this.cb;}disconnect(){revealObserver=null;}}
 class Image {set src(value){if(value){signals.image++;queueMicrotask(()=>this.onload&&this.onload());}}}
 const config={protectedTermIds:scenario==='primary-lcp-protected'?[123]:[],endpoint:ORIGIN+'/wp-json/mad4b/v1/responsive-media',selector:'.elementor-element-95d1c9c',rootLazyClass:rootClass,cardVisibleClass:visibleClass,minWidth:192,maxWidth:1536,step:64};
 const window={MAD4BResponsiveMedia:config,IntersectionObserver,MutationObserver,requestIdleCallback:fn=>fn(),devicePixelRatio:2,addEventListener(){}};
 const navigator={connection:{saveData:false}};
 const fetch=async url=>{signals.rest++;assert(new URL(url).searchParams.get('items')==='123:576');if(scenario==='slow-response')await new Promise(resolve=>{restResolve=resolve;});return {ok:true,json:async()=>({items:{'123:576':{state:'ready',width:576,url:DERIVATIVE}}})};};
 const ctx={window,document:doc,navigator,location:{origin:ORIGIN},fetch,IntersectionObserver,MutationObserver,Image,URL,setTimeout,requestIdleCallback:window.requestIdleCallback,console};
 vm.runInNewContext(src,ctx,{timeout:2000});
 await flushed();await flushed();
 function reveal(){classNames.add(visibleClass);if(revealObserver)revealObserver([{type:'attributes',attributeName:'class',target:card}]);}
 if(scenario==='slow-response'){
   assert.strictEqual(signals.rest,1);
   reveal();restResolve();await flushed();await flushed();
   assert.deepStrictEqual({rest:signals.rest,image:signals.image,applied:signals.applied},{rest:1,image:0,applied:0});
 } else if(scenario==='before-reveal'){
   await flushed();
   assert.deepStrictEqual({rest:signals.rest,image:signals.image,applied:signals.applied},{rest:1,image:1,applied:0});
   reveal();await flushed();
   assert.deepStrictEqual({rest:signals.rest,image:signals.image,applied:signals.applied},{rest:1,image:1,applied:1});
 } else if(scenario==='primary-lcp-protected'){
   assert.deepStrictEqual({rest:signals.rest,image:signals.image,applied:signals.applied},{rest:0,image:0,applied:0});
 } else if(scenario==='already-visible'){
   assert.deepStrictEqual({rest:signals.rest,image:signals.image,applied:signals.applied},{rest:0,image:0,applied:0});
 }
 console.log('PASS',scenario,JSON.stringify(signals));
}
(async()=>{await test('before-reveal');await test('slow-response');await test('already-visible');await test('primary-lcp-protected');console.log('PASS 4/4 no duplicate image swap and LCP protection invariants');})().catch(e=>{console.error(e.stack);process.exit(1)});
