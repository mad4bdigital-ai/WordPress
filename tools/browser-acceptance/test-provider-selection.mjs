import assert from "node:assert/strict";
import { configuredProviders, classifyProviderError, loadProviderContracts } from "./providers.mjs";
import { validatePlan } from "./etg-driver.mjs";

const contracts = loadProviderContracts();
assert.equal(contracts.contract, "mad4b.browser-execution-providers.v2");
assert.deepEqual(contracts.priority, ["cloudflare", "browserbase", "browserless", "steel"]);
assert.equal(contracts.providers.cloudflare.advisory_free_limits.browser_seconds_per_day, 600);
assert.equal(contracts.providers.browserbase.runtime_constraints.hard_session_seconds, 900);
assert.equal(contracts.providers.browserless.runtime_constraints.hard_session_seconds, 120);
assert.equal(contracts.providers.steel.advisory_free_limits.one_time_credit_usd, 30);

const env = {
  CLOUDFLARE_ACCOUNT_ID: "a",
  CLOUDFLARE_BROWSER_RUN_API_TOKEN: "b",
  BROWSERBASE_API_KEY: "c"
};
const providers = configuredProviders(env);
assert.equal(providers[0].id, "cloudflare");
assert.equal(providers[0].available, true);
assert.equal(providers[1].id, "browserbase");
assert.equal(providers[1].available, true);
assert.equal(providers[2].id, "browserless");
assert.equal(providers[2].available, false);
assert.equal(providers[3].id, "steel");
assert.equal(providers[3].available, false);

const quota = new Error("429 Browser time limit exceeded for today");
quota.status = 429;
assert.equal(classifyProviderError(quota, contracts.providers.cloudflare).category, "provider_limit_or_capacity");

const auth = new Error("unauthorized");
auth.status = 401;
assert.equal(classifyProviderError(auth, contracts.providers.browserbase).category, "provider_auth_or_config");

const driverFailure = new Error("browser_filter_control_not_found:cairo");
const driverClass = classifyProviderError(driverFailure, contracts.providers.cloudflare);
assert.equal(driverClass.category, "acceptance_execution_error");
assert.equal(driverClass.fallback_allowed, false);

const websocketFailure = new Error("websocket connection temporarily unavailable");
const websocketClass = classifyProviderError(websocketFailure, contracts.providers.cloudflare);
assert.equal(websocketClass.category, "provider_transport_or_runtime");
assert.equal(websocketClass.fallback_allowed, true);

const secretFailure = new Error("connect failed wss://production-sfo.browserless.io?token=super-secret-token&timeout=120000 Authorization: Bearer very-secret");
const secretClass = classifyProviderError(secretFailure, contracts.providers.browserless);
assert.equal(secretClass.message.includes("super-secret-token"), false);
assert.equal(secretClass.message.includes("very-secret"), false);
assert.match(secretClass.message, /REDACTED/);

const now = Math.floor(Date.now() / 1000);
const plan = {
  contract: "mad4b.browser-acceptance-plan.v1",
  state: "ready",
  provider_id: "etg-dfsb",
  plan_digest: "a".repeat(64),
  plan_signature: "b".repeat(64),
  origin: "https://staging.egypttourgates.com/",
  build_identity: { git_sha: "x", tree_sha: "y" },
  challenge: {
    contract: "etg.dfsb.browser-acceptance-challenge.v1",
    nonce: "c".repeat(32),
    issued_at: now,
    expires_at: now + 900,
    signature: "d".repeat(64)
  },
  cases: [{
    case_id: "cairo",
    archive_path: "/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo/",
    provider: "jet-engine",
    query_id: "tours_query_archive",
    taxonomy: "location_jet",
    term_id: 1,
    term_slug: "cairo",
    expected: { result_total: 8, proof_mode: "full_ids", ids: [1,2,3,4,5,6,7,8] }
  }]
};
assert.equal(validatePlan(plan).cases.length, 1);

const bad = structuredClone(plan);
bad.origin = "http://staging.egypttourgates.com/";
assert.throws(() => validatePlan(bad), /https/);

console.log("MAD4B browser execution provider contracts PASS");
