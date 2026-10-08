import assert from "node:assert/strict";
import crypto from "node:crypto";
import { canonicalEvidenceBytes, signDeclarativeEvidence, assertSigningConfigured } from "./browser-attestation.mjs";
const { privateKey, publicKey } = crypto.generateKeyPairSync("rsa", { modulusLength: 2048 });
const pem=privateKey.export({type:"pkcs8",format:"pem"});
const env={MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64:Buffer.from(pem).toString("base64")};
const evidence={
 contract:"mad4b.capability-browser-evidence.v1",
 plan_digest:"a".repeat(64),
 cases:[{case_id:"page-2",observed:{path:"/news/"}}],
 observer:{javascript_runtime:true,plan_issued_at:1800000000}
};
const signed=signDeclarativeEvidence(evidence,env);
assert.equal(signed.attestation.algorithm,"rsa-sha256");
assert.equal(signed.attestation.key_id,"mad4b-browser-v1");
assert(crypto.verify("sha256",canonicalEvidenceBytes(evidence),publicKey,
  Buffer.from(signed.attestation.signature,"base64")));
const mutated={...evidence,cases:[{case_id:"page-2",observed:{path:"/changed/"}}]};
assert.equal(crypto.verify("sha256",canonicalEvidenceBytes(mutated),publicKey,
  Buffer.from(signed.attestation.signature,"base64")),false);
assert.throws(()=>signDeclarativeEvidence(evidence,{}),/browser_attestation_signer_unconfigured/);
assert.throws(()=>assertSigningConfigured({}),/browser_attestation_signer_unconfigured/);
assert.throws(()=>canonicalEvidenceBytes(signed),/browser_attestation_payload_invalid/);
const { privateKey:weak }=crypto.generateKeyPairSync("rsa",{modulusLength:1024});
const weakEnv={MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64:Buffer.from(
  weak.export({type:"pkcs8",format:"pem"})).toString("base64")};
assert.throws(()=>signDeclarativeEvidence(evidence,weakEnv),/browser_attestation_key_strength_invalid/);
assert.equal(signDeclarativeEvidence({...evidence,contract:"etg.dfsb.browser-acceptance-evidence.v1"},{}).attestation,undefined);
console.log("MAD4B_BROWSER_ATTESTATION: PASS");
