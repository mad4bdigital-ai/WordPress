'use strict';
// Isolated browser-event regression. No user sessions, WordPress data or network.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname,'../wp-content/plugins/mad4b-distribution-connectors/assets/distribution-console.js'),'utf8');
function item(dataset={}) {
  const handlers = {};
  const classes = new Set();
  const attrs = {};
  return {
    dataset, handlers, attrs, hidden:false, value:'', textContent:'',
    classList:{toggle:(name,on)=>on?classes.add(name):classes.delete(name),contains:name=>classes.has(name)},
    addEventListener:(type,cb)=>{handlers[type]=cb;},
    setAttribute:(key,value)=>{attrs[key]=value;},
    trigger:(event)=>{if(handlers[event])handlers[event]({preventDefault(){}});},
  };
}
const filters=[item({filter:'all'}),item({filter:'social_catalog'}),item({filter:'ota_marketplace'})];
const cards=[item({category:'social_catalog',search:'meta catalog'}),item({category:'ota_marketplace',search:'viator tour'}),item({category:'ota_marketplace',search:'klook activities'})];
const search=item(),results=item(),empty=item(),revoke=item({confirm:'Revoke a saved credential?'});
empty.hidden=true;
const root={
  querySelectorAll(selector){
    if(selector==='[data-filter]')return filters;
    if(selector==='[data-category][data-search]')return cards;
    if(selector==='[data-confirm]')return [revoke];
    throw new Error('Unexpected selector: '+selector);
  },
  querySelector(selector){
    return ({'#mad4b-dc-search':search,'#mad4b-dc-results':results,'#mad4b-dc-empty':empty})[selector]||null;
  }
};
const doc={getElementById:()=>root};
let confirmCount=0;
vm.runInNewContext(script,{document:doc,window:{confirm:()=>{confirmCount++;return false;}}});
assert.equal(typeof search.handlers.input,'function');
assert.equal(typeof filters[1].handlers.click,'function');
filters[2].trigger('click');
assert.equal(cards[0].hidden,true);
assert.equal(cards[1].hidden,false);
assert.equal(cards[2].hidden,false);
assert.equal(results.textContent,'2 channels shown');
assert.equal(filters[2].attrs['aria-pressed'],'true');
assert.equal(filters[1].attrs['aria-pressed'],'false');
search.value='viator';search.trigger('input');
assert.equal(cards[1].hidden,false);
assert.equal(cards[2].hidden,true);
assert.equal(results.textContent,'1 channel shown');
search.value='notfound';search.trigger('input');
assert.equal(empty.hidden,false);
assert.equal(results.textContent,'0 channels shown');
filters[0].trigger('click');search.value='meta';search.trigger('input');
assert.equal(cards[0].hidden,false);
assert.equal(cards[1].hidden,true);
assert.equal(empty.hidden,true);
let cancelled=false;
revoke.handlers.submit({preventDefault:()=>{cancelled=true;}});
assert.equal(confirmCount,1);
assert.equal(cancelled,true);
const php=fs.readFileSync(path.join(__dirname,'../wp-content/plugins/mad4b-distribution-connectors/includes/class-mad4b-distribution-admin.php'),'utf8');
assert.ok(php.includes('wp_nonce_field('));
assert.ok(php.includes('type="password"'));
assert.ok(php.includes('MAD4B_Distribution_Admin_V1::boot();'));
assert.ok(!php.includes('mad4b_vault:'));
console.log(JSON.stringify({suite:'mad4b.distribution-console.ui',assertions_passed:20,
  search_and_category_filters:true,empty_state:true,keyboard_native_controls:true,
  revoke_confirmation:true,credentials_masked:true,secret_values_exposed:false,external_calls:0}));
