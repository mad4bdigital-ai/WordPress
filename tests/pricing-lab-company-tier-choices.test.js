/* Checks the exact Pricing Lab tour-to-level JS filter without modifying DB. */
'use strict';
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const root=process.env.MAD4B_PRICING_LAB_SCRIPT
 ||path.resolve(__dirname,'../wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.js');
const src=fs.readFileSync(root,'utf8');
const head=src.indexOf("function applyTourTierOptions(preferred=''){");
const tail=src.indexOf('\n// Deep links only select an item',head);
assert.ok(head>0&&tail>head,'native select source function not found');
const functionBody=src.slice(head,tail);
const checks=[];
for(const [site,dataFile] of Object.entries({
 source:process.env.MAD4B_SOURCE_MATRIX,
 wayfa:process.env.MAD4B_WAYFA_MATRIX,
})){
 if(!dataFile)continue;
 const audit=JSON.parse(fs.readFileSync(dataFile,'utf8'));
 const packages=Object.entries(audit.packages).map(([id,details])=>({
    id:Number(id),effective_tier_ids:details.tiers,tier_source:details.source
 }));
 const tiers=audit.catalog;
 const p={value:'57562'};
 const t={value:'',disabled:false,options:[],
    replaceChildren(){this.options=[];this.value='';}};
 const b={disabled:false};
 const opts=(s,items)=>{s.options=items.map(x=>({value:String(x.id),textContent:x.label}));
    if(!s.value && s.options.length)s.value=s.options[0].value;};
 const sandbox={packages,tiers,p,t,b,opts,Set,String,Number,Array};
 const update=vm.runInNewContext('('+functionBody.trim()+')',sandbox);
 const expect={
    source:{'57562':[2353,2354],'60439':[2353,2354,2356,2355]},
    wayfa:{'57562':[2354,2356],'60439':[2353,2354,2356,2355]}
 };
 for(const [tour,expected] of Object.entries(expect[site])){
   p.value=tour;
   const result=update(tour==='57562'?'2355':'2356');
   const ids=result.options.map(x=>Number(x.id));
   assert.deepEqual(ids,expected,site+' '+tour+' tier list');
   assert.ok(!b.disabled && !t.disabled);
   if(tour==='57562') assert.notEqual(t.value,'2355','Platinum cannot be implicitly selected');
   checks.push(site+':'+tour+' valid levels');
 }
 p.value='invalid';
 const noTour=update('2353');
 assert.deepEqual(noTour.options,[]);
 assert.equal(b.disabled,true);
 checks.push(site+':missing_tour_fail_closed');
}
console.log(JSON.stringify({cases:checks.length,checks,all_pass:true,live_tour_postmeta_modified:false}));

