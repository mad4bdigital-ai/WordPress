// Review-owned driver registry. A WordPress site cannot inject module paths,
// arbitrary scripts, selectors or URLs into the external browser process.
import { validatePlan as etgValidatePlan, runBrowserPlan as etgRunBrowserPlan } from "./etg-driver.mjs";
import {
  validateDeclarativePlan, runDeclarativeBrowserPlan,
  DECLARATIVE_PROVIDER_CONTRACT, DECLARATIVE_EVIDENCE_CONTRACT
} from "./declarative-capability-driver.mjs";

const DRIVERS = Object.freeze([
  Object.freeze({
    provider_contract: "etg.dfsb.browser-acceptance-provider.v2",
    driver_id: "etg-dfsb",
    evidence_contract: "etg.dfsb.browser-acceptance-evidence.v1",
    // Reviewed observable Oracles; this does not certify booking or payment.
    approved_oracles: Object.freeze([
      "browser.ajax_round_trip", "browser.event_stream", "browser.dom_result_count",
      "browser.dataset_id_parity", "browser.dataset_digest_parity",
      "browser.order_parity", "browser.url_state", "browser.seo_non_authority",
      "browser.reset_behavior", "browser.performance_baseline",
      "browser.async_digest_snapshot"
    ]),
    validatePlan: etgValidatePlan,
    runBrowserPlan: etgRunBrowserPlan
  }),
  Object.freeze({
    provider_contract: DECLARATIVE_PROVIDER_CONTRACT,
    driver_id: "mad4b-declarative-capabilities",
    evidence_contract: DECLARATIVE_EVIDENCE_CONTRACT,
    approved_oracles: Object.freeze(["browser.canonical_path"]),
    validatePlan: validateDeclarativePlan,
    runBrowserPlan: runDeclarativeBrowserPlan
  })
]);

export function approvedSiteDrivers() {
  // JSON-compatible public declarations only: never export runtime callbacks via MCP.
  return DRIVERS.map(({ provider_contract, driver_id, evidence_contract, approved_oracles }) =>
    Object.freeze({ provider_contract, driver_id, evidence_contract,
      approved_oracles: Object.freeze([...approved_oracles]) }));
}

export function resolveSiteDriverForPlan(plan) {
  if (!plan || typeof plan !== "object" ||
      typeof plan.provider_contract !== "string") {
    throw new Error("site_browser_plan_provider_contract_missing");
  }
  const matches = DRIVERS.filter(driver => driver.provider_contract === plan.provider_contract);
  if (matches.length !== 1) throw new Error("site_browser_driver_not_approved");
  const driver = matches[0];
  // The adapter's own validator is the final authority on its format.
  // A generic envelope alone is NOT enough to certify provider-specific semantics.
  const validated = driver.validatePlan(plan);
  if (validated.provider_contract !== driver.provider_contract) {
    throw new Error("site_browser_driver_contract_drift");
  }
  return Object.freeze({ driver_id: driver.driver_id,
    evidence_contract: driver.evidence_contract,
    runBrowserPlan: driver.runBrowserPlan, plan: validated });
}
