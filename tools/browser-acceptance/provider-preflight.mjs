#!/usr/bin/env node
import fs from "node:fs";
import process from "node:process";
import {
  configuredProviders,
  loadProviderContracts
} from "./providers.mjs";
import { rankProviderCandidates } from "./scheduler.mjs";
import { validatePlan } from "./etg-driver.mjs";

function arg(name, fallback = "") {
  const index = process.argv.indexOf(`--${name}`);
  return index >= 0 && process.argv[index + 1] ? process.argv[index + 1] : fallback;
}

const planPath = arg("plan", process.env.MAD4B_BROWSER_PLAN_FILE || "");
const requested = arg("provider", process.env.MAD4B_BROWSER_PROVIDER || "auto");
const allowCreditProviders =
  requested !== "auto" ||
  /^(1|true|yes|on)$/i.test(String(process.env.MAD4B_BROWSER_ALLOW_CREDIT_FALLBACK || ""));

const contracts = loadProviderContracts();
let plan;
if (planPath) {
  plan = validatePlan(JSON.parse(fs.readFileSync(planPath, "utf8")));
} else {
  plan = {
    cases: Array.from({ length: 8 }, (_, index) => ({ case_id: `diagnostic-${index + 1}` }))
  };
}

const configured = configuredProviders(process.env, requested);
const ranked = rankProviderCandidates(configured, plan, contracts, {
  allowCreditProviders: requested === "auto" ? allowCreditProviders : true
});

const providers = ranked.map((candidate, index) => ({
  rank: index + 1,
  provider: candidate.id,
  credential_state: candidate.available ? "configured" : "missing",
  missing_credential_names: candidate.missing || [],
  recurring_free_tier: !!candidate.definition?.recurring_free_tier,
  billing_class: candidate.definition?.billing_class || "",
  budget_eligible: !!candidate.budget_eligible,
  spend_eligible: !!candidate.spend_eligible,
  selection_blocker: candidate.selection_blocker || "",
  score: Number.isFinite(candidate.score) ? candidate.score : null,
  execution: candidate.execution || null
}));

const report = {
  contract: "mad4b.browser-provider-preflight.v1",
  provider_contract: contracts.contract,
  selection_policy: contracts.selection_policy,
  requested_provider: requested,
  plan_source: planPath ? "signed_plan" : "diagnostic_max_case_envelope",
  case_count: Array.isArray(plan.cases) ? plan.cases.length : 0,
  allow_credit_fallback: allowCreditProviders,
  provider_secret_values_included: false,
  network_request_performed: false,
  browser_session_started: false,
  providers
};

console.log(JSON.stringify(report, null, 2));
