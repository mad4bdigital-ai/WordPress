import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
const here = path.dirname(fileURLToPath(import.meta.url));
const files = [
  "site-adapter-resolver.mjs",
  "declarative-capability-driver.mjs",
  "run-site-browser-acceptance.mjs",
  "run-live-site-browser-acceptance.mjs",
  "mcp-bridge.mjs",
  "site-driver-registry.mjs"
];
for (const file of files) {
  const source = fs.readFileSync(path.join(here, file), "utf8");
  for (const leaked of ["tours_query_archive", "staging.egypttourgates.com",
    "staging.allroyalegypt.com", "MAD4B_BROWSER_PROFILE_ID || \"tours\"",
    "site_provider_id || \"etg-dfsb\""]) {
    assert(!source.includes(leaked), "site-neutral boundary contains site-specific fallback: " + file + ": " + leaked);
  }
  assert(!/import\s*\(\s*(?:process|plan|site|selection|provider)/.test(source),
    "dynamic site-provided modules forbidden: " + file);
}
const registry = fs.readFileSync(path.join(here, "site-driver-registry.mjs"), "utf8");
assert(registry.includes('import { validatePlan as etgValidatePlan'), "legacy ETG adapter remains supported explicitly");
assert(registry.includes('throw new Error("site_browser_driver_not_approved")'), "unmapped driver must block");
assert(registry.includes("DECLARATIVE_PROVIDER_CONTRACT"), "generic capability probe adapter is reviewed explicitly");
const genericDriver = fs.readFileSync(path.join(here, "declarative-capability-driver.mjs"), "utf8");
assert(genericDriver.includes("certification_issued: false"), "generic observations must not certify themselves");
assert(genericDriver.includes("permittedCaseKeys"), "untrusted case actions must be blocked");
assert(genericDriver.includes("javaScriptEnabled: false"), "generic site scripts must be disabled");
assert(genericDriver.includes("allowedDocumentPaths:"), "generic navigation must be scoped to the signed case plan");
assert(!genericDriver.includes("eval(") && !genericDriver.includes("new Function("),
  "no arbitrary executable site JS");
const generic = fs.readFileSync(path.join(here, "run-live-site-browser-acceptance.mjs"), "utf8");
assert(generic.includes("assertSiteBindingUnchanged(configured, preExecution)"), "preflight drift readback required");
assert(generic.includes("assertSiteBindingUnchanged(configured, postExecution)"), "postflight drift readback required");
assert(generic.includes("delete childEnv.MAD4B_MCP_ACCESS_TOKEN"), "bearer token must not reach browser worker");
assert(generic.includes("run-site-browser-acceptance.mjs"), "site-neutral executor is used");
assert(generic.includes("consumeLocalBrowserPlanOnce({ plan, evidence, result })"),
  "generic native PASS must claim local replay ledger before any receipt");
assert(generic.includes('globally_unique_consumption_proven: false'),
  "local replay claim must never be presented as distributed certification");
const resolver = fs.readFileSync(path.join(here, "site-adapter-resolver.mjs"), "utf8");
assert(resolver.includes("provider_recognition_mismatch"), "untrusted discovery-to-descriptor mismatch forbidden");
assert(resolver.includes("discoverySha256"), "provider discovery generation must be fenced");
console.log("MAD4B_SITE_NEUTRAL_BOUNDARIES: PASS");
