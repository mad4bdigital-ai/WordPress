'use strict';
const vm=require('vm'),fs=require('fs'),path=require('path');
const assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,
 '../../../wp-content/mu-plugins/assets/mad4b-pricing-mode-conditional-v1.js'),'utf8');
class E {
 constructor(tag='div'){
  this.tag=tag;this.children=[];this.dataset={};this.listeners={};
  this.attributes={};this.value='';this.type='';this.required=false;this.disabled=false;
  this.classList={state:{},toggle:(name,on)=>{this.classList.state[name]=on;}};
  this.nodeType=1;
 }
 append(...items){this.children.push(...items);}
 prepend(...items){this.children.unshift(...items);}
 setAttribute(name,value){this.attributes[name]=value;}
 addEventListener(name,callback){this.listeners[name]=callback;}
 querySelector(selector){return this.map?.[selector]||null;}
 querySelectorAll(selector){return this.lists?.[selector]||[];}
}
const sourceFields=[
 'tax_percent_override','service_fee_percent_override','tax_price_basis',
 'discount_tax_order','payment_fee_taxable','payment_fee_bearer','discount_scope',
 'refund_allocation','refund_payment_fee','tax_price_basis_source',
 'discount_tax_order_source','payment_fee_taxable_source','payment_fee_bearer_source',
 'discount_scope_source','refund_allocation_source','refund_payment_fee_source'
];
const create=(override=false)=>{
 const root=new E(),control=new E(),row=new E(),wrap=new E();
 const groups=sourceFields.map(name=>{
  const e=new E(),input=new E('input');
  input.name='shared_mode_policy_rows[item-0]['+name+']';
  input.value=name.endsWith('_source')?'inherit':'0';
  if(override&&name==='payment_fee_taxable_source')input.value='override';
  e.map={'input[name],select[name],textarea[name]':input};
  return {e,input};
 });
 root.map={'#settings_top .cx-control[data-control-name="shared_mode_policy_rows"]':control};
 control.lists={'.cx-ui-repeater-item':[row]};
 row.lists={'.cx-ui-repeater-item-control':groups.map(g=>g.e)};
 row.map={'.cheryr-ui-repeater-content-box':wrap};
 const document={createElement:tag=>new E(tag),querySelector:selector=>
   selector==='.jet-engine-options-page-wrap'?root:null};
 const context={document,MutationObserver:class{observe(){}}};
 vm.runInNewContext(source,context);
 return {row,groups,wrap,button:wrap.children[0]?.children[0]};
};
const inherited=create();
assert.ok(inherited.button,'Missing financial disclosure on native repeater');
assert.equal(inherited.row.dataset.mad4bFinancialDisclosure,'1');
assert.equal(inherited.button.type,'button','Must not submit native JetEngine form');
assert.equal(inherited.button.attributes['aria-expanded'],'false');
assert.ok(inherited.groups.every(g=>g.e.classList.state['mad4b-pricing-financial-hidden']===true));
assert.ok(inherited.groups.every(g=>g.input.disabled===false),'Collapsing disabled a native input');
const prev=inherited.groups.map(g=>g.input.value);
inherited.button.listeners.click();
assert.equal(inherited.button.attributes['aria-expanded'],'true');
assert.ok(inherited.groups.every(g=>g.e.classList.state['mad4b-pricing-financial-hidden']===false));
assert.deepEqual(inherited.groups.map(g=>g.input.value),prev);
const overridden=create(true);
assert.equal(overridden.button.attributes['aria-expanded'],'true',
 'Stored override must not be hidden by default');
assert.ok(overridden.groups.every(g=>g.e.classList.state['mad4b-pricing-financial-hidden']===false));
console.log('PASS inherited advanced fields progressively disclosed without disabling or reparenting');
console.log('PASS existing financial override remains visible by default');
console.log('PASS native JetEngine form inputs and values unchanged on expansion/collapse');
console.log('JETENGINE_CONDITIONAL_FINANCIAL_MODE_ACCEPTED');
