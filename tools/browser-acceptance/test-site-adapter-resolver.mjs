import assert from "node:assert/strict";
import { resolveSiteBrowserAdapter as resolve, assertSiteBindingUnchanged, assertSitePlanBound, assertSiteResultBound } from "./site-adapter-resolver.mjs";
const etg = { provider_contract:"etg.dfsb.browser-acceptance-provider.v2",driver_id:"etg-dfsb",evidence_contract:"etg.dfsb.browser-acceptance-evidence.v1" };
const royal = { provider_contract:"demo.royal-provider.v1",driver_id:"demo-royal",evidence_contract:"demo.royal-evidence.v1" };
const provider = (id,driver,slug,profile) => ({provider_id:id,contract:driver.provider_contract,
  descriptor:{provider_id:id,contract:driver.provider_contract,read_only:true,authorizing:false,
    recognition:{source_plugins:[slug]}},
  capabilities:{provider_id:id,provider_contract:driver.provider_contract,read_only:true,authorizing:false,default_profile_id:profile}});
const match=(id,slug)=>({provider_id:id,source_plugins:[slug],matched_plugins:1,recognized:true,certified:false,authorizing:false});
const example=(where="etg")=>{
 const other=where!=="etg", origin=other?"https://staging.allroyalegypt.com":"https://staging.egypttourgates.com";
 const slug=other?"royal-plugin":"etg-dynamic-filter-seo-bridge",driver=other?royal:etg,id=other?"royal-provider":"etg-dfsb";
 return {contract:"mad4b.browser-acceptance-capabilities.v1",site_origin:origin,read_only:true,authorizing:false,
 provider_count:1,providers:[provider(id,driver,slug,other?"site.v1":"tours")],
 site_discovery:{contract:"mad4b.site-capability-discovery.v1",origin,read_only:true,authorizing:false,
 discovery_complete:true,certification_issued:false,snapshot_sha256:"f".repeat(64),
 plugins:[slug,"unmapped-plugin"],post_types:["post","page","tour"],taxonomies:["category","tour_type"],unmapped_plugins:["unmapped-plugin"],provider_matches:[match(id,slug)]},
 operator_preference:{contract:"mad4b.browser-operator-preference.v1",read_only:true,authorizing:false,
 preference_valid:true,configuration_revision:"a".repeat(32),executor:"auto",profile_id:"",site_provider_id:"",
 credential_verified:false,external_runner_connected:false,site_provider_registered_by_preference:false}};
};
const options={approvedDrivers:[etg,royal]};
const etgResult=resolve(example(),options);
assert.equal(etgResult.siteProviderId,"etg-dfsb");
assert.equal(etgResult.profileId,"tours");
assert.equal(etgResult.externallyCertified,false);
assert.deepEqual(etgResult.unmappedPlugins,["unmapped-plugin"]);
const royalResult=resolve(example("royal"),options);
assert.equal(royalResult.siteProviderId,"royal-provider");
assert.equal(royalResult.profileId,"site.v1");
assert.throws(()=>resolve(example("royal"),{approvedDrivers:[etg]}),/site_browser_external_driver_not_approved/);
const unknown=example();unknown.providers=[];unknown.provider_count=0;unknown.site_discovery.provider_matches=[];
assert.throws(()=>resolve(unknown,options),/site_browser_site_adapter_missing/);
const changed=example();changed.site_discovery.snapshot_sha256="b".repeat(64);
assert.throws(()=>assertSiteBindingUnchanged(etgResult,resolve(changed,options)),/site_browser_binding_changed:discoverySha256/);
const fake=example();fake.site_discovery.provider_matches[0].matched_plugins=0;
assert.throws(()=>resolve(fake,options),/site_browser_discovery_match_invalid/);
fake.site_discovery.provider_matches[0].matched_plugins=1;fake.site_discovery.certification_issued=true;
assert.throws(()=>resolve(fake,options),/site_browser_discovery_invalid_or_incomplete/);
const mismatched=example();
mismatched.providers[0].descriptor.recognition.source_plugins=["unmapped-plugin"];
assert.throws(()=>resolve(mismatched,options),/site_browser_provider_recognition_mismatch/);
const duplicated=example();duplicated.providers.push(provider("another",royal,"royal-plugin","v1"));
duplicated.provider_count=2;duplicated.site_discovery.plugins.push("royal-plugin");duplicated.site_discovery.provider_matches.push(match("another","royal-plugin"));
assert.throws(()=>resolve(duplicated,options),/site_browser_site_adapter_ambiguous/);
duplicated.operator_preference.site_provider_id="etg-dfsb";
assert.equal(resolve(duplicated,options).siteProviderId,"etg-dfsb");
const cpt = example("royal");
cpt.providers[0].descriptor.recognition = {
  source_plugins:[],source_post_types:["tour"],source_taxonomies:["tour_type"]
};
cpt.site_discovery.plugins = [];
cpt.site_discovery.provider_matches = [{
  provider_id:"royal-provider",source_plugins:[],source_post_types:["tour"],
  source_taxonomies:["tour_type"],matched_plugins:0,matched_post_types:1,matched_taxonomies:1,
  recognized:true,certified:false,authorizing:false
}];
assert.equal(resolve(cpt,options).siteProviderId,"royal-provider");
const badPreference=example();delete badPreference.operator_preference.configuration_revision;
assert.throws(()=>resolve(badPreference,options),/site_browser_operator_preference_invalid/);
const plan={contract:"mad4b.browser-acceptance-plan.v1",state:"ready",origin:etgResult.siteOrigin+"/",
 provider_id:etgResult.siteProviderId,provider_contract:etgResult.siteProviderContract,
 profile_id:etgResult.profileId,suite:"browser_runtime",read_only:true,authorizing:false,
 plan_digest:"f".repeat(64),plan_signature:"a".repeat(64),cases:[{case_id:"case-a"}]};
assertSitePlanBound(etgResult,plan);
assert.throws(()=>assertSitePlanBound(etgResult,{...plan,origin:"https://another.example"}),/site_browser_plan_binding_mismatch/);
const result={contract:"mad4b.browser-acceptance-result.v1",provider_id:plan.provider_id,
 provider_contract:plan.provider_contract,profile_id:plan.profile_id,suite:plan.suite,
 plan_digest:plan.plan_digest,read_only:true,authorizing:false};
assertSiteResultBound(plan,result);
assert.throws(()=>assertSiteResultBound(plan,{...result,provider_id:"other"}),/site_browser_result_binding_mismatch/);
console.log("MAD4B_SITE_ADAPTER_RESOLVER: PASS");
