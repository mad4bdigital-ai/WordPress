import assert from "node:assert/strict";
import crypto from "node:crypto";
import { validateDeclarativePlan, runDeclarativeBrowserPlan } from "./declarative-capability-driver.mjs";
const now = Math.floor(Date.now()/1000);
const base = () => ({
 contract:"mad4b.browser-acceptance-plan.v1",
 provider_contract:"mad4b.capability-browser-provider.v1",
 provider_id:"wordpress-native", profile_id:"public-observations",
 state:"ready",suite:"browser_runtime",read_only:true,authorizing:false,
 origin:"https://sample.example/",plan_digest:"a".repeat(64),plan_signature:"b".repeat(64),
 build_identity:{git_sha:"c".repeat(40),build_fingerprint:"d".repeat(64)},
 challenge:{nonce:"e".repeat(32),signature:"f".repeat(64),issued_at:now-3,expires_at:now+120},
 cases:[
  {case_id:"title-test",capability_id:"content.title",probe_type:"public.document_title_digest",
   page_path:"/tours/",expected:{title_sha256:crypto.createHash("sha256").update("Demo Title").digest("hex")}},
  {case_id:"canonical-test",capability_id:"seo.canonical",probe_type:"public.canonical_path",
   page_path:"/tours/",expected:{path:"/tours/"}},
  {case_id:"list-count",capability_id:"listing.result_count",probe_type:"public.semantic_marker_count",
   page_path:"/tours/",expected:{marker_key:"listing.results",count:2}}
 ]
});
const plan=base();plan.case_count=plan.cases.length;
const validate=(x)=>validateDeclarativePlan(x);
assert.equal(validate(plan).provider_id,"wordpress-native");
assert.equal(validate(plan).cases.length,3);
const subsite=structuredClone(plan);
subsite.origin="https://sample.example/travel/";
subsite.cases=subsite.cases.map(c=>({...c,page_path:"/travel"+c.page_path,
 expected:c.probe_type==="public.canonical_path"?{path:"/travel/tours/"}:c.expected}));
assert.equal(validate(subsite).origin,"https://sample.example/travel/");
const escapeSubsite=structuredClone(subsite);
escapeSubsite.cases[0].page_path="/another-site/page/";
assert.throws(()=>validate(escapeSubsite),/capability_browser_case_outside_site_scope/);
function denies(modifier, pattern=/capability_browser_/){
 const invalid=structuredClone(plan);modifier(invalid);
 assert.throws(()=>validate(invalid),pattern);
}
denies(p=>p.provider_contract="etg.dfsb.browser-acceptance-provider.v2");
denies(p=>p.provider_id="All Royal Egypt");
denies(p=>p.origin="http://sample.example/");
denies(p=>p.origin="https://sample.example/scope/");
denies(p=>p.read_only=false);
denies(p=>p.build_identity.build_fingerprint="");
denies(p=>p.challenge.expires_at=now-1);
denies(p=>p.case_count=2);
denies(p=>p.cases[0].page_path="//different.example/path");
denies(p=>p.cases[0].page_path="/%2e%2e/wp-admin/");
denies(p=>p.cases[0].page_path="/x/%2fcollect");
denies(p=>p.cases[0].page_path="/wp-admin/");
denies(p=>p.cases[0].page_path="/wp-json/evil");
denies(p=>p.cases[0].page_path="/tour?danger=1");
denies(p=>p.cases[0].probe_type="click.payment");
denies(p=>p.cases[0].javascript="fetch('https://bad.test')");
denies(p=>p.cases[0].expected={title_sha256:"a".repeat(64),extra:"bad"});
denies(p=>p.cases[1].expected.path="https://different.example/");
denies(p=>p.cases[2].expected.marker_key="[onclick]");
denies(p=>p.cases.push(structuredClone(p.cases[0])));
const created=[];
const page={
 current:"",
 url(){return this.current;},
 async goto(url){this.current=url;return {status:()=>200};},
 async title(){return "Demo Title";},
 locator(selector){
   if(selector==='link[rel="canonical"]')return {first(){return {getAttribute:async()=>"/tours/"}}};
   if(selector==="[data-mad4b-capability-key]")return {evaluateAll:async(cb,key)=>cb([
      {getAttribute:()=>key},{getAttribute:()=>key}],key)};
   throw Error("unapproved selector:"+selector);
 }
};
const browser={
 async newContext(opts){
  assert.equal(opts.serviceWorkers,"block");
  const ctx={
   route:async()=>{},
   routeWebSocket:async()=>{},
   async newPage(){return page;},
   async close(){ctx.closed=true;}
  };
  created.push(ctx);return ctx;
 },
 version:async()=>"Chromium test"
};
const evidence=await runDeclarativeBrowserPlan({browser,providerId:"local",plan});
assert.equal(evidence.contract,"mad4b.capability-browser-evidence.v1");
assert.deepEqual(evidence.cases.map(c=>c.matches_expected),[true,true,true]);
assert(evidence.cases.every(c=>c.authorizing===false && c.certification_issued===false));
assert.equal(created[0].closed,true);
const wrong=base();wrong.cases=[{...wrong.cases[0],expected:{title_sha256:"1".repeat(64)}}];wrong.case_count=1;
const mismatch=await runDeclarativeBrowserPlan({browser,providerId:"local",plan:wrong});
assert.equal(mismatch.cases[0].matches_expected,false);
assert.equal(mismatch.cases[0].certification_issued,false);
assert.equal(created[1].closed,true);
console.log("MAD4B_DECLARATIVE_CAPABILITY_DRIVER: PASS");
