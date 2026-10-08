import assert from "node:assert/strict";
import { createEvidenceAssembler } from "./evidence-assembler.mjs";
const plan={plan_digest:"a".repeat(64),plan_signature:"b".repeat(64),origin:"https://example.org/",
  build_identity:{revision:"v1"},challenge:{nonce:"c".repeat(32)},
  cases:[{case_id:"alpha"},{case_id:"beta"}]};
const evidence=(ids)=>({contract:"demo.browser-evidence.v1",plan_digest:plan.plan_digest,
 plan_signature:plan.plan_signature,origin:plan.origin,build_identity:plan.build_identity,
 observer:{javascript_runtime:true,execution_mode:"managed_browser_agent",browser_engine:"Chromium"},
 cases:ids.map(id=>({case_id:id,challenge_nonce:plan.challenge.nonce,observed:true}))});
const contract="demo.browser-evidence.v1";
const good=createEvidenceAssembler(plan,contract);
assert.equal(good.add(evidence(["alpha"]),[plan.cases[0]]),1);
assert.equal(good.add(evidence(["beta"]),[plan.cases[1]]),2);
assert.deepEqual(good.finish().cases.map(c=>c.case_id),["alpha","beta"]);
assert.throws(()=>good.add(evidence(["beta"]),[plan.cases[1]]),/browser_chunk_case_identity_or_nonce_mismatch/);
assert.throws(()=>createEvidenceAssembler(plan,contract).finish(),/browser_chunk_incomplete/);
function bad(f){
 const a=createEvidenceAssembler(plan,contract);
 assert.throws(()=>a.add(f(evidence(["alpha"])),[plan.cases[0]]),/browser_chunk_/);
}
bad(v=>({...v,contract:"unapproved.evidence.v1"}));
bad(v=>({...v,origin:"https://other.example/"}));
bad(v=>({...v,plan_digest:"f".repeat(64)}));
bad(v=>({...v,plan_signature:"f".repeat(64)}));
bad(v=>({...v,build_identity:{revision:"else"}}));
bad(v=>({...v,cases:[]}));
bad(v=>({...v,cases:v.cases.map(c=>({...c,challenge_nonce:"d".repeat(32)}))}));
bad(v=>({...v,cases:v.cases.map(c=>({...c,case_id:"beta"}))}));
bad(v=>({...v,observer:{...v.observer,javascript_runtime:false}}));
const incomplete=createEvidenceAssembler(plan,contract);
incomplete.add(evidence(["alpha"]),[plan.cases[0]]);
assert.throws(()=>incomplete.finish(),/browser_chunk_incomplete/);
const moved=createEvidenceAssembler(plan,contract);
assert.throws(()=>moved.add(evidence(["beta"]),[plan.cases[1]]),/browser_chunk_case_order_invalid/);
console.log("MAD4B_BROWSER_EVIDENCE_ASSEMBLER: PASS");
