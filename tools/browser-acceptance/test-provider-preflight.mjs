import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import path from "node:path";

const here = path.dirname(fileURLToPath(import.meta.url));
const script = path.join(here, "provider-preflight.mjs");
const secrets = {
  CLOUDFLARE_ACCOUNT_ID: "account-secret-value",
  CLOUDFLARE_BROWSER_RUN_API_TOKEN: "cf-secret-value",
  BROWSERBASE_API_KEY: "bb-secret-value",
  BROWSERLESS_TOKEN: "bl-secret-value",
  STEEL_API_KEY: "steel-secret-value"
};

const result = spawnSync(process.execPath, [script], {
  env: { ...process.env, ...secrets },
  encoding: "utf8"
});

assert.equal(result.status, 0, result.stderr);
const report = JSON.parse(result.stdout);
assert.equal(report.contract, "mad4b.browser-provider-preflight.v1");
assert.equal(report.network_request_performed, false);
assert.equal(report.browser_session_started, false);
assert.equal(report.provider_secret_values_included, false);
assert.equal(report.case_count, 8);
assert.equal(report.providers.length, 4);
assert.equal(report.providers[0].credential_state, "configured");

for (const secret of Object.values(secrets)) {
  assert.equal(result.stdout.includes(secret), false);
}
assert.equal(report.providers.find((p) => p.provider === "steel").spend_eligible, false);
assert.equal(report.providers.find((p) => p.provider === "steel").selection_blocker, "spend_authorization_required");

console.log("MAD4B browser provider preflight PASS");
