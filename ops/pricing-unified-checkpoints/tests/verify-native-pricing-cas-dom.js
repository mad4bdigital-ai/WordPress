'use strict';
const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const code=fs.readFileSync(path.join(__dirname,'../../../wp-content/mu-plugins/assets/mad4b-jetengine-native-pricing-cas-v1.js'),'utf8');
class El {
 constructor(tag='div'){this.tag=tag;this.children=[];this.value='';this.name='';this.type='';}
 append(child){this.children.push(child);}
 querySelector(selector){
  if(selector==='#settings_top')return this.top;
  if(selector==='button[type="submit"]')return this.button;
  if(selector.startsWith('input[name="'))return this.children.find(x=>x.name===selector.slice(12,-2))||null;
  return null;
 }
}
const form=new El('form');form.top=new El();form.button=new El('button');
const document={querySelectorAll(sel){return sel==='.jet-engine-options-page-wrap form'?[form]:[];},
 createElement(tag){return new El(tag);}};
const context={document,window:{MAD4BJetEngineNativePricingCAS:{nonce:'signed-nonce',revision:'a'.repeat(64),action:'jet-engine-op-save-settings'}}};
vm.runInNewContext(code,context);
assert.equal(form.children.length,2,'Expected nonce and revision inside native form');
assert.equal(form.children[0].name,'mad4b_native_cas_nonce');
assert.equal(form.children[1].name,'mad4b_native_cas_revision');
assert.equal(form.children[1].value,'a'.repeat(64));
vm.runInNewContext(code,context);
assert.equal(form.children.length,2,'Retry must not append a second pair of hidden controls');
assert.ok(form.children.every(x=>x.type==='hidden'));
console.log('PASS signed source revision and nonce mounted into original JetEngine form exactly once');
console.log('PASS no second form, second Save button, or shadow data field');
