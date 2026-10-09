import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { spawn } from "node:child_process";
import { canonicalSha256 } from "./receipt.mjs";
import { consumeLocalBrowserPlanOnce } from "./plan-consumption-ledger.mjs";
const start = Math.floor(Date.now()/1000);
const base = fs.mkdtempSync(path.join(os.tmpdir(),"mad4b-ledger-test-"));
fs.chmodSync(base,0o700);
const plan = {
  provider_contract:"mad4b.capability-browser-provider.v1",
  provider_id:"mad4b-native-public",origin:"https://site.example/",
  profile_id:"public-canonical",suite:"browser_runtime",
  build_identity:{git_sha:"f".repeat(40),build_fingerprint:"e".repeat(64)},
  state:"ready",read_only:true,authorizing:false,
  plan_digest:"a".repeat(64),plan_signature:"b".repeat(64),
  challenge:{nonce:"c".repeat(32),issued_at:start-2,expires_at:start+300}
};
const evidence = {plan_digest:plan.plan_digest,plan_signature:plan.plan_signature,
  origin:plan.origin,build_identity:plan.build_identity,
  contract:"mad4b.capability-browser-evidence.v1",
  cases:[{case_id:"page-10",challenge_nonce:plan.challenge.nonce}]};
const result = {
  contract:"mad4b.browser-acceptance-result.v1",
  provider_id:plan.provider_id,provider_contract:plan.provider_contract,
  profile_id:plan.profile_id,suite:plan.suite,
  verdict:"PASS",plan_digest:plan.plan_digest,
  evidence_digest:canonicalSha256(evidence),receipt_signature:"d".repeat(64),
  verification:{browser_runtime_parity_verified:true},
  read_only:true,authorizing:false,receipt_authorizing:false,
  release_ready:false,globally_unique_consumption_proven:false
};
const args = {plan,evidence,result,ledgerDir:base,authorityMode:"single-host-posix-v1",now:start};
const passes = ()=>consumeLocalBrowserPlanOnce(args);
try {
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,authorityMode:""}),/authority_unavailable/);
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,ledgerDir:"/invalid"}),/directory_unavailable/);
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,
    evidence:{...evidence,cases:[]}}),/invalid_or_stale_proof/);
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,
    evidence:{...evidence,origin:"https://other.example/"}}),/invalid_or_stale_proof/);
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,
    evidence:{...evidence,build_identity:{...plan.build_identity,git_sha:"d".repeat(40)}}}),
    /invalid_or_stale_proof/);
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,
    now:start+301}),/invalid_or_stale_proof/);
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,
    result:{...result,release_ready:true}}),/invalid_or_stale_proof/);
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,
    result:{...result,globally_unique_consumption_proven:true}}),
    /invalid_or_stale_proof/);
  const claimed=passes();
  assert.equal(claimed.scope,"single_host_posix_filesystem");
  assert.equal(claimed.globally_unique_consumption_proven,false);
  assert.equal(claimed.release_ready,false);
  assert.match(claimed.claim_key,/^[a-f0-9]{64}$/);
  assert.throws(passes,/replay_detected/);
  assert.equal(fs.readdirSync(base).length,1);
  const entry=JSON.parse(fs.readFileSync(path.join(base,claimed.claim_key+".json"),"utf8"));
  assert.equal(entry.evidence_digest,result.evidence_digest);
  assert.equal(entry.globally_unique_consumption_proven,false);

  // Simulate a short write that returns zero bytes but does not throw.
  // The local claim must be burned rather than returning an alleged PASS.
  const partialDir=fs.mkdtempSync(path.join(os.tmpdir(),"mad4b-ledger-shortwrite-"));
  fs.chmodSync(partialDir,0o700);
  const originalWrite=fs.writeSync;
  let attempted=0;
  try {
    fs.writeSync=(fd,buffer,offset,length,position)=>{
      attempted++;
      if(attempted===1)return originalWrite(fd,buffer,offset,Math.min(length,7),position);
      return 0;
    };
    assert.throws(()=>consumeLocalBrowserPlanOnce({...args,ledgerDir:partialDir}),
      /persistence_not_confirmed/);
  } finally {fs.writeSync=originalWrite;}
  assert.equal(fs.readdirSync(partialDir).length,1);
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,ledgerDir:partialDir}),
    /replay_detected/);
  fs.rmSync(partialDir,{recursive:true,force:true});

  const dir2=fs.mkdtempSync(path.join(os.tmpdir(),"mad4b-ledger-parallel-"));
  fs.chmodSync(dir2,0o700);
  try {
    const worker=`
      import {consumeLocalBrowserPlanOnce} from ${JSON.stringify(new URL("./plan-consumption-ledger.mjs", import.meta.url).href)};
      const input=JSON.parse(process.env.MAD4B_LEDGER_TEST_PAYLOAD);
      try {consumeLocalBrowserPlanOnce(input);process.exit(0);}
      catch(error) { if (/replay_detected/.test(error.message)) process.exit(8); throw error; }
    `;
    const parallelArgs={...args,ledgerDir:dir2};
    const jobs=Array.from({length:8},()=>new Promise((resolve,reject)=>{
      const child=spawn(process.execPath,["--input-type=module","-e",worker],{
        env:{...process.env,MAD4B_LEDGER_TEST_PAYLOAD:JSON.stringify(parallelArgs)},
        stdio:["ignore","pipe","pipe"]
      });
      let err=""; child.stderr.on("data",x=>{err+=x.toString()});
      child.on("error",reject);
      child.on("exit",code=>resolve({code,err}));
    }));
    const codes=(await Promise.all(jobs)).map(x=>x.code).sort((a,b)=>a-b);
    assert.deepEqual(codes,[0,8,8,8,8,8,8,8]);
    assert.equal(fs.readdirSync(dir2).length,1);
  } finally {fs.rmSync(dir2,{recursive:true,force:true});}

  const unsafe=fs.mkdtempSync(path.join(os.tmpdir(),"mad4b-ledger-unsafe-"));
  fs.chmodSync(unsafe,0o777);
  assert.throws(()=>consumeLocalBrowserPlanOnce({...args,ledgerDir:unsafe}),
    /directory_untrusted/);
  fs.rmSync(unsafe,{recursive:true,force:true});
  console.log("MAD4B_BROWSER_PLAN_CONSUMPTION: PASS (serial, 8 independent processes, tamper, TTL, trust)");
} finally { fs.rmSync(base,{recursive:true,force:true}); }
