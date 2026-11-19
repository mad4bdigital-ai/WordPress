'use strict';
const assert=require('assert');
const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'..');
const js=fs.readFileSync(path.join(root,'payload/wordpress/mu-plugins/shared-product-core/assets/presentation-fluent-quote-v1.js'),'utf8');
const match=js.match(/const needsStayDetail=stage=>\{([\s\S]*?)\n  \};\n  const visibleStages=/);
assert(match, 'Missing source-backed stay UI predicate');
const fn=Function('planStages','stage', 'const needsStayDetail=stage=>{'+match[1]+'\n};return needsStayDetail(stage);');
function stage(policy, mode='simple_or',properties=[36117],nights=0){
 const isReview=mode==='simple_or'&&properties.length===1&&policy==='inquiry'&&nights===0;
 return {key:'simple',ui_contract:{contract:'shared.form6.stay-ui.v1', property_selector:properties.length===1?'rate_owned':'traveler',detail_controls:isReview?'none':'verified_catalog_only'}};
}
const cases=[
 ['sole rate-owned inquiry, no nights',false,stage('inquiry')],
 ['one included property, sourced unit needed',true,stage('included')],
 ['extra stay, sourced unit needed',true,stage('additional')],
 ['two eligible hotel alternatives',true,stage('inquiry','simple_or',[36117,36239])],
 ['dated inquiry stay',true,stage('inquiry','simple_or',[36117],3)],
 ['multi-stage stay',true,stage('inquiry','multi_stage')],
 ['unknown or stale contract fails closed',false,{key:'simple',ui_contract:{contract:'unrecognized'}}],
];
for(const [label,expected,s] of cases){
 const actual=fn([s],{stage_key:'simple',property_id:36117});
 assert.strictEqual(actual,expected,label);console.log('PASS',label);
}
assert(js.includes('if(!units.length){')&&js.includes('row.remove();'));
assert(js.includes("state.stayCatalog.delete(token)"));
console.log('FORM6_SOURCE_POLICY_MATRIX_7_7_PASS');
