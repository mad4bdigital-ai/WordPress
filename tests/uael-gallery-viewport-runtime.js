'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.join(__dirname,'../wp-content/mu-plugins');
const plugin = fs.readFileSync(path.join(root,'425-mad4b-uael-gallery-viewport-init.php'),'utf8');
const digest = plugin.match(/ASSET_SHA256\s*=\s*'([a-f0-9]{64})'/)[1];
const source = fs.readFileSync(path.join(root,'mad4b-uael-gallery-viewport','uael-gallery.'+digest.slice(0,12)+'.js'),'utf8');
let listener;
const events=[], registered=[];
const jq = () => ({ on(name, callback) { assert.equal(name,'elementor/frontend/init');listener=callback; } });
const native = (...args)=>{events.push({type:'native',args});};
const hooks = {
 addAction(name,callback,priority) {
   registered.push({name,callback,priority});
   return 'native-hook-result';
 }
};
const frontend={hooks,isEditMode:()=>false};
const observes=[];
class Observer {
 constructor(callback, options) {this.callback=callback;this.options=options;observes.push(this);}
 observe(el){this.element=el;}
 disconnect(){this.disconnected=true;}
 trigger(hit=true){this.callback([{isIntersecting:hit}]);}
}
const window={jQuery:jq,elementorFrontend:frontend,innerHeight:768,IntersectionObserver:Observer};
const ctx=vm.createContext({window,document:{},IntersectionObserver:Observer});
vm.runInContext(source,ctx);
assert.equal(typeof listener,'function','Early jQuery registration needed');
listener();
assert.equal(window.__mad4bUAELGalleryViewportV1.installed,true);
assert.equal(hooks.__mad4bUAELGalleryViewportPatched,true);
assert.equal(hooks.addAction('frontend/element_ready/uael-image-gallery.default',native,17),'native-hook-result');
assert.equal(registered[0].priority,17,'preserve original hook priority');
assert.equal(registered.length,1);
const callback=registered[0].callback;
const gallery={};
function element(top=9000,hasCarousel=true) {
 return {
  isConnected:true,
  closest:()=>null,
  querySelector:(selector)=>selector==='.uael-img-carousel-wrap'&&hasCarousel?gallery:null,
  getBoundingClientRect:()=>({width:400,height:360,top,bottom:top+360})
 };
}
const offscreen=element();
callback([offscreen]);callback([offscreen]);
assert.equal(events.length,0,'No offscreen native init before intent');
assert.equal(observes.length,1,'Duplicate ready event should not add second observer');
assert.equal(observes[0].options.rootMargin,'1500px 0px 1500px 0px');
observes[0].trigger(false);
assert.equal(events.length,0,'False intersection cannot activate carousel');
observes[0].trigger(true);
observes[0].trigger(true);
assert.equal(events.length,1,'Exactly one native init per deferred Elementor action');
assert.equal(window.__mad4bUAELGalleryViewportV1.activated,1);
callback([element(400)]);
assert.equal(events.length,2,'Near-gallery native init must never be delayed');
callback([element(9000,false)]);
assert.equal(events.length,3,'Other gallery layouts remain native');
const actionCount=registered.length;
hooks.addAction('frontend/element_ready/heading.default',native,5);
assert.equal(registered.length,actionCount+1,'Unrelated Elementor hook must pass through');
assert.equal(registered.at(-1).callback,native);
assert.equal(window.__mad4bUAELGalleryViewportV1.deferred,1);
console.log('PASS UAEL gallery action deferral / fallback / repeated events');
