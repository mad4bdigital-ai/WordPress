import assert from "node:assert/strict";
import { buildBrowserExecutionReceipt, canonicalSha256 } from "./receipt.mjs";

const plan = {
  provider_id: "etg-dfsb",
  profile_id: "tours",
  plan_digest: "a".repeat(64),
  plan_signature: "b".repeat(64),
  challenge: { nonce: "c".repeat(32) }
};
const attempts = {
  provider_contract: "mad4b.browser-execution-providers.v2",
  selected_provider: "cloudflare",
  run_budget: { browser_sessions_started: 1 }
};
const evidence = {
  contract: "etg.dfsb.browser-acceptance-evidence.v1",
  cases: [{ case_id: "cairo" }]
};
const evidenceDigest = canonicalSha256(evidence);
const result = {
  contract: "mad4b.browser-acceptance-result.v1",
  evidence_digest: evidenceDigest,
  receipt_signature: "d".repeat(64),
  verdict: "PASS",
  verification: { browser_runtime_parity_verified: true }
};

const a = buildBrowserExecutionReceipt({
  plan, attempts, evidence, result,
  sourceHead: "deadbeef",
  generatedAt: "2026-09-22T00:00:00.000Z"
});
const b = buildBrowserExecutionReceipt({
  plan: structuredClone(plan),
  attempts: structuredClone(attempts),
  evidence: structuredClone(evidence),
  result: structuredClone(result),
  sourceHead: "deadbeef",
  generatedAt: "2026-09-22T00:00:00.000Z"
});
assert.equal(a.receipt_sha256, b.receipt_sha256);
assert.equal(a.browser_runtime_parity_verified, true);
assert.equal(a.case_count, 1);
assert.equal(a.authorizing, false);
assert.equal(a.mad4b_evidence_digest, evidenceDigest);
assert.equal(a.mad4b_receipt_signature, "d".repeat(64));
assert.equal(a.integrity_model, "content_addressed_receipt_anchored_by_mad4b_reducer_signature");
assert.equal(a.independently_signed_by_browser_runner, false);
assert.match(a.receipt_sha256, /^[a-f0-9]{64}$/);
assert.equal(a.plan_signature_sha256, canonicalSha256(plan.plan_signature));
assert.notEqual(a.plan_signature_sha256, plan.plan_signature);

const changed = buildBrowserExecutionReceipt({
  plan, attempts: { ...attempts, selected_provider: "browserbase" }, evidence, result,
  sourceHead: "deadbeef",
  generatedAt: "2026-09-22T00:00:00.000Z"
});
assert.notEqual(a.receipt_sha256, changed.receipt_sha256);

console.log("MAD4B browser execution receipt PASS");
