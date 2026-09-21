import assert from "node:assert/strict";
import { configuredProviders, loadProviderContracts } from "./providers.mjs";
import { BrowserRunBudget, providerExecutionPlan, rankProviderCandidates } from "./scheduler.mjs";

const contracts = loadProviderContracts();
assert.equal(contracts.contract, "mad4b.browser-execution-providers.v2");

const plan = {
  cases: Array.from({ length: 8 }, (_, i) => ({ case_id: `case-${i + 1}` }))
};
const env = {
  CLOUDFLARE_ACCOUNT_ID: "a",
  CLOUDFLARE_BROWSER_RUN_API_TOKEN: "b",
  BROWSERBASE_API_KEY: "c",
  BROWSERLESS_TOKEN: "d",
  STEEL_API_KEY: "e"
};

const ranked = rankProviderCandidates(configuredProviders(env), plan, contracts);
assert.deepEqual(ranked.map((x) => x.id), ["cloudflare", "browserbase", "browserless", "steel"]);
assert.equal(ranked[0].execution.sessions_required, 1);
assert.equal(ranked[0].execution.estimated_total_seconds, 370);
assert.equal(ranked[1].execution.sessions_required, 1);
assert.equal(ranked[2].execution.sessions_required, 8);
assert.equal(ranked[2].execution.estimated_total_seconds, 440);
assert.equal(ranked[2].execution.chunked, true);
assert.equal(ranked[3].execution.sessions_required, 1);
assert.equal(ranked[3].execution.recurring_free_tier, false);

const browserless = providerExecutionPlan(contracts.providers.browserless, 8, {
  estimatedCaseSeconds: 45,
  reserveSeconds: 30
});
assert.equal(browserless.cases_per_session, 1);
assert.equal(browserless.sessions_required, 8);

const constrained = rankProviderCandidates(configuredProviders(env), plan, contracts, { maxSessions: 4 });
const constrainedBrowserless = constrained.find((x) => x.id === "browserless");
assert.equal(constrainedBrowserless.budget_eligible, false);
assert.equal(constrainedBrowserless.selection_blocker, "session_budget_exceeded");

const budget = new BrowserRunBudget(contracts);
assert.equal(budget.canAttemptProvider("cloudflare"), true);
budget.reserveProvider("cloudflare");
budget.reserveSession();
budget.openCircuit("cloudflare");
assert.equal(budget.canAttemptProvider("cloudflare"), false);
assert.deepEqual(budget.snapshot().open_circuits, ["cloudflare"]);

const tiny = new BrowserRunBudget({ run_budget: { max_provider_attempts: 1, max_browser_sessions: 1 } });
tiny.reserveProvider("cloudflare");
tiny.reserveSession();
assert.throws(() => tiny.reserveSession(), /browser_session_budget_exceeded/);
assert.equal(tiny.canAttemptProvider("browserbase"), false);

console.log("MAD4B browser adaptive scheduler PASS");
