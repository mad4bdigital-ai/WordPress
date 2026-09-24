import assert from "node:assert/strict";
import { buildEvidenceEnvelope } from "./etg-driver.mjs";

const plan = {
  plan_digest: "a".repeat(64),
  plan_signature: "b".repeat(64),
  origin: "https://staging.egypttourgates.com/",
  build_identity: { git_sha: "head", tree_sha: "tree" },
  challenge: {
    contract: "etg.dfsb.browser-acceptance-challenge.v1",
    nonce: "c".repeat(32)
  }
};

const envelope = buildEvidenceEnvelope({
  plan,
  providerId: "cloudflare",
  browserEngine: "Chromium/140",
  cases: [{ case_id: "cairo", challenge_nonce: plan.challenge.nonce }]
});

assert.deepEqual(Object.keys(envelope).sort(), [
  "build_identity",
  "cases",
  "contract",
  "observer",
  "origin",
  "plan_digest",
  "plan_signature"
].sort());
assert.equal("challenge" in envelope, false);
assert.equal("provider_id" in envelope, false);
assert.equal(envelope.contract, "etg.dfsb.browser-acceptance-evidence.v1");
assert.equal(envelope.observer.contract, "etg.dfsb.browser-acceptance-observer.v1");
assert.equal(envelope.observer.execution_mode, "managed_browser_agent");
assert.equal(envelope.cases[0].challenge_nonce, plan.challenge.nonce);

console.log("MAD4B ETG browser evidence envelope PASS");
