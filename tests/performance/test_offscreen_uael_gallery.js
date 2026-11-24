'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const filename=process.env.MAD4B_UAEL_PLUGIN_PHP;
assert(filename,'Pass actual first-party PHP as MAD4B_UAEL_PLUGIN_PHP');
const php=fs.readFileSync(filename,'utf8'),m=php.match(/<<<'JS'\n([\s\S]*?)\nJS;/);
assert(m,'inline code must derive from actual plugin');
const source=m[1];
function fixture(opt={}){
 const observers=[],listeners={};
 let nativeCalls=0;
 const native=function(){nativeCalls++;return this};
 const jQuery=function(){};
 jQuery.fn={imagesLoaded:native};
 const window={innerHeight:844,IntersectionObserver:class IO{
   constructor(fn,options){this.fn=fn;this.options=options;this.disconnected=false;observers.push(this)}
   observe(el){this.target=el}
   disconnect(){this.disconnected=true}
   activate(){this.fn([{isIntersecting:true,target:this.target}])}
 }};
 const document={readyState:'loading',addEventListener(n,fn){listeners[n]=fn}};
 window.jQuery=jQuery;
 vm.runInNewContext(source,{window,document,IntersectionObserver:window.IntersectionObserver,WeakMap},{timeout:700});
 assert.equal(typeof listeners.DOMContentLoaded,'function');
 listeners.DOMContentLoaded();
 const setup=()=>{
  const node={
   isConnected:opt.connected!==false,
   getAttribute(n){return n==='data-widget_type'?(opt.widgetType||'uael-image-gallery.default'):null},
   querySelector(n){return n==='.uael-img-carousel-wrap'&&!opt.noCarousel?{}:null},
   closest(){return opt.inForm?{}:null},
   getBoundingClientRect(){return {top:opt.top??10168,bottom:opt.bottom??10495,height:opt.height??327,width:360}}
  };
  const selection=[node];
  return {node,selection};
 };
 return {window,document,jQuery,observers,nativeCount:()=>nativeCalls,setup};
}
const far=fixture();
assert.equal(far.window.MAD4BUaelOffscreen.installed,true);
const {selection:farSelection}=far.setup();
assert.strictEqual(far.jQuery.fn.imagesLoaded.call(farSelection,()=>{}),farSelection);
assert.equal(far.nativeCount(),0);
assert.equal(far.observers.length,1);
assert.equal(far.window.MAD4BUaelOffscreen.deferred,1);
assert.equal(far.observers[0].options.rootMargin,'1100px 0px');
far.observers[0].activate();
far.observers[0].activate();
assert.equal(far.nativeCount(),1,'native callback delivered exactly once');
assert.equal(far.window.MAD4BUaelOffscreen.activated,1);
console.log('PASS offscreen: native UAEL callback delayed then delivered once');

const many=fixture();
const {selection:selectionMany}=many.setup();
many.jQuery.fn.imagesLoaded.call(selectionMany,()=>{});
many.jQuery.fn.imagesLoaded.call(selectionMany,()=>{});
assert.equal(many.nativeCount(),0);
assert.equal(many.observers.length,1,'repeated initialization shares one observer');
many.observers[0].activate();
assert.equal(many.nativeCount(),2,'all queued callbacks handed back to vendor');
console.log('PASS repeated calls: preserves original vendor callback order');

for(const [name,opt] of [
 ['near-gallery',{top:700,bottom:1027}],
 ['hidden',{height:0}],
 ['no-carousel',{noCarousel:true}],
 ['booking',{inForm:true}],
 ['other-widget',{widgetType:'other.default'}]
]){
 const f=fixture(opt),x=f.setup();
 f.jQuery.fn.imagesLoaded.call(x.selection,()=>{});
 assert.equal(f.nativeCount(),1,name+' must use native path');
 assert.equal(f.observers.length,0,name+' no observation');
 console.log('PASS native fallback '+name);
}
console.log('ALL_UAEL_OFFSCREEN_GALLERY_JS_TESTS_PASS');
