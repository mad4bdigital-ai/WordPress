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
const env = live.slice(0, live.indexOf("\n    steps:\n"));
assert.match(env, /^      MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64: \$\{\{ secrets\.MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64 \}\}$/m,
  "evidence signing private key must enter only governed live job as GitHub secret");
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
