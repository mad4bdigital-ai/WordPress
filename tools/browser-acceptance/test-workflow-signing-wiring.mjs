import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, "../..");
const workflow = fs.readFileSync(path.join(root, ".github/workflows/mad4b-managed-browser-providers.yml"), "utf8");
const liveStart = workflow.indexOf("\n  live:\n");
assert.ok(liveStart >= 0, "live job must exist");
const live = workflow.slice(liveStart);
const jobEnvironment = live.slice(0, live.indexOf("\n    steps:\n"));
const independentPreflight = workflow.slice(workflow.indexOf("\n  readiness:\n"), liveStart);
assert.ok(independentPreflight.startsWith("\n  readiness:\n"), "readiness job must exist");
assert.match(independentPreflight, /node tools\/browser-acceptance\/provider-preflight\.mjs > managed-browser-readiness\.json/,
  "zero-session provider preflight must produce a bounded report");
assert.doesNotMatch(independentPreflight, /MAD4B_MCP_ACCESS_TOKEN|MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64/,
  "readiness must not access MCP bearer or attestation signing key");
assert.ok(!independentPreflight.includes("\n    env:\n"), "readiness provider secrets must not be job-wide");
assert.match(independentPreflight, /      - name: Contract-only provider preflight[\s\S]*?        env:/,
  "provider credentials must be scoped to the preflight step");
const tokenCheckName = "      - name: Verify Cloudflare API token without a browser session";
assert.ok(independentPreflight.includes(tokenCheckName), "Cloudflare authentication verifier step must exist");
const tokenCheck = independentPreflight.slice(independentPreflight.indexOf(tokenCheckName));
assert.ok(tokenCheck.includes("node tools/browser-acceptance/verify-cloudflare-token.mjs > cloudflare-token-verification.json"),
  "verification must emit a sanitized local JSON status only");
assert.ok(tokenCheck.includes("          CLOUDFLARE_BROWSER_RUN_API_TOKEN: "),
  "Token must be scoped to the credential verification step");
assert.ok(tokenCheck.includes("          MAD4B_BROWSER_VERIFY_REQUIRED: "),
  "explicit Cloudflare selection must fail closed if token invalid");
assert.ok(tokenCheck.includes("          CLOUDFLARE_ACCOUNT_ID: "),
  "account id must be scoped to verification step");
assert.doesNotMatch(tokenCheck, /MAD4B_MCP_ACCESS_TOKEN|MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64/,
  "no bearer or signing keys in Cloudflare token verification");
const readinessName = "      - name: Require just-in-time MCP bearer and at least one browser provider";
const runName = "      - name: Generate fresh plan, execute browser, and reduce evidence through MAD4B MCP";
assert.ok(live.includes(readinessName) && live.includes(runName), "live steps must exist");
const readiness = live.slice(live.indexOf(readinessName), live.indexOf(runName));
const execution = live.slice(live.indexOf(runName));
const secrets = [
  "MAD4B_MCP_ACCESS_TOKEN", "CLOUDFLARE_ACCOUNT_ID",
  "CLOUDFLARE_BROWSER_RUN_API_TOKEN", "BROWSERBASE_API_KEY",
  "BROWSERLESS_TOKEN", "STEEL_API_KEY", "MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64"
];
for (const key of secrets) {
  const expected = "          " + key + ": " + "${{ secrets." + key + " }}";
  assert.ok(execution.includes(expected), key + " must be scoped to the live execution step");
  assert.ok(!jobEnvironment.includes(key + ":"), key + " must not reach checkout, setup-node, npm install or artifact upload");
}
assert.ok(!readiness.includes("MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64:"),
  "private evidence signer must never enter the readiness step");
for (const key of secrets.filter(x => x !== "MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64")) {
  assert.ok(readiness.includes("          " + key + ": "), key + " must enter guarded readiness step");
}
assert.doesNotMatch(workflow.slice(0, liveStart), /MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64: \$\{\{/,
  "source/static CI must never load the private signing secret");
assert.match(live, /run-live-site-browser-acceptance\.mjs/, "use canonical site-aware MCP runner");
const runner = fs.readFileSync(path.join(dir, "run-live-site-browser-acceptance.mjs"), "utf8");
const worker = fs.readFileSync(path.join(dir, "worker-environment.mjs"), "utf8");
const attest = fs.readFileSync(path.join(dir, "browser-attestation.mjs"), "utf8");
assert.match(runner, /signerKeyId\(process\.env\)/,
  "site binding must verify matching browser signer key id");
assert.match(runner, /delete childEnv\.MAD4B_MCP_ACCESS_TOKEN/,
  "MCP bearer must be removed from browser worker environment");
assert.match(worker, /"MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64"/,
  "worker must explicitly whitelist signer key for evidence signing");
assert.doesNotMatch(worker.slice(worker.indexOf("const ALLOWED"), worker.indexOf("export function")), /"MAD4B_MCP_ACCESS_TOKEN"/,
  "worker must not whitelist the OAuth access token");
assert.match(attest, /assertSigningConfigured/, "signed evidence must fail closed on missing key");
console.log("MAD4B_MANAGED_BROWSER_WORKFLOW_SIGNER_WIRING: PASS");
