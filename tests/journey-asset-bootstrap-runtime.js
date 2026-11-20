/* Exact first-party Journey originals. Bootstrap only on absent widget.
 * No remote request, DOM replacement, source data, user permissions or media.
 */
'use strict';
const fs=require('fs'),path=require('path'),assert=require('assert'),vm=require('vm');
const root=path.resolve(__dirname,'../wp-content/plugins/mad4b-site-control-plane/assets');
for(const filename of ['tour-journey-ui.js','tour-journey-split-v3.js']){
 const code=fs.readFileSync(path.resolve(root,filename),'utf8');
 assert(!/\beval\s*\(|new\s+Function\s*\(/.test(code),'Untrusted dynamic evaluation is not permitted in journey widgets');
 let listeners=0,networkCalls=0,domChanges=0;
 const win={addEventListener(n,cb){listeners++;assert.strictEqual(n,'elementor/frontend/init');assert.strictEqual(typeof cb,'function')}};
 const document={readyState:'complete',matches(){return false},
  querySelectorAll(selector){
   assert.strictEqual(selector,'.tour-journey-discovery');
   return [];
  },
  addEventListener(name,cb){assert.strictEqual(name,'jet-engine/listing-grid/after-load');assert.strictEqual(typeof cb,'function')},
  createElement(){domChanges++;throw Error('Page without Journey cannot modify the DOM')}
 };
 const context={document,window:win,
  URL,history:{replaceState(){}},location:{pathname:'/',search:''},
  matchMedia(){return {matches:false}},
  fetch(){networkCalls++;throw Error('No network on dormant widget')},
  requestAnimationFrame(){throw Error('No frames without widgets')},
  console};
 vm.runInNewContext(code,context,{filename,timeout:1000});
 assert.strictEqual(listeners,1,filename+' registers Elementor reinjection once');
 assert.strictEqual(networkCalls,0,filename+' made unexpected network request');
 assert.strictEqual(domChanges,0,filename+' modified non-Journey page');
}
const styles=fs.readFileSync(path.resolve(root,'tour-journey-split-v3.css'),'utf8');
assert(styles.includes('.jd-split-v3__panel[hidden]{display:none!important;}'));
assert(styles.includes('@media(prefers-reduced-motion:reduce)'));
assert(styles.includes('.jd-modes-v3') && styles.includes('.tour-journey-discovery'));
const highlight=fs.readFileSync(path.resolve(root,'tour-highlights-v1.css'),'utf8');
assert(highlight.includes('.elementor-element-d90c3d1') && highlight.includes('.tour-hl-ui'));
console.log('PASS: JOURNEY_ASSET_BOOTSTRAP no-widget safety, Elementor lifecycle and source-scoped CSS');
