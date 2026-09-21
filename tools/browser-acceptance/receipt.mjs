import crypto from "node:crypto";

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (!value || typeof value !== "object") return value;
  const out = {};
  for (const key of Object.keys(value).sort()) out[key] = canonicalize(value[key]);
  return out;
}

export function canonicalSha256(value) {
  return crypto.createHash("sha256")
    .update(JSON.stringify(canonicalize(value)))
    .digest("hex");
}

export function buildBrowserExecutionReceipt({
  plan,
  attempts,
  evidence,
  result,
  sourceHead = "",
  generatedAt = new Date().toISOString()
}) {
  const base = {
    contract: "mad4b.browser-execution-receipt.v1",
    generated_at: generatedAt,
    source_head: String(sourceHead || ""),
    provider_id: String(plan?.provider_id || ""),
    profile_id: String(plan?.profile_id || ""),
    plan_digest: String(plan?.plan_digest || ""),
    plan_signature_sha256: canonicalSha256(String(plan?.plan_signature || "")),
    challenge_nonce_sha256: canonicalSha256(String(plan?.challenge?.nonce || "")),
    selected_browser_provider: String(attempts?.selected_provider || ""),
    provider_contract: String(attempts?.provider_contract || ""),
    run_budget: attempts?.run_budget || null,
    attempt_ledger_sha256: canonicalSha256(attempts || {}),
    evidence_contract: String(evidence?.contract || ""),
    evidence_sha256: canonicalSha256(evidence || {}),
    result_contract: String(result?.contract || ""),
    result_sha256: canonicalSha256(result || {}),
    verdict: String(result?.verdict || ""),
    browser_runtime_parity_verified: result?.verification?.browser_runtime_parity_verified === true,
    case_count: Array.isArray(evidence?.cases) ? evidence.cases.length : 0,
    authorizing: false
  };
  return { ...base, receipt_sha256: canonicalSha256(base) };
}
