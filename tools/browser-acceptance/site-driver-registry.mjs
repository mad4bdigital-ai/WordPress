// Review-owned driver registry. A WordPress site cannot inject module paths,
// arbitrary scripts, selectors or URLs into the external browser process.
import { validatePlan as etgValidatePlan, runBrowserPlan as etgRunBrowserPlan } from "./etg-driver.mjs";

const DRIVERS = Object.freeze([
  Object.freeze({
    provider_contract: "etg.dfsb.browser-acceptance-provider.v2",
    driver_id: "etg-dfsb",
    evidence_contract: "etg.dfsb.browser-acceptance-evidence.v1",
    validatePlan: etgValidatePlan,
    runBrowserPlan: etgRunBrowserPlan
  })
]);

export function approvedSiteDrivers() {
  // JSON-compatible public declarations only: never export runtime callbacks via MCP.
  return DRIVERS.map(({ provider_contract, driver_id, evidence_contract }) =>
    Object.freeze({ provider_contract, driver_id, evidence_contract }));
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
