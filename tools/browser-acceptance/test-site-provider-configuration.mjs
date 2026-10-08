import assert from "node:assert/strict";
import {
  resolveEtgBrowserOperatorConfiguration as resolve,
  assertEtgBrowserBindingUnchanged,
  assertEtgBrowserPlanBinding,
  assertEtgBrowserResultBinding
} from "./site-provider-configuration.mjs";

const base = () => ({
  contract: "mad4b.browser-acceptance-capabilities.v1", read_only: true, authorizing: false,
  provider_count: 1, providers: [{ provider_id: "etg-dfsb", contract: "etg.dfsb.browser-acceptance-provider.v2" }],
  operator_preference: {
    contract: "mad4b.browser-operator-preference.v1", authorizing: false, read_only: true,
    executor: "auto", profile_id: "", site_provider_id: "", preference_valid: true, configuration_revision: "a".repeat(32),
    credential_verified: false, external_runner_connected: false, site_provider_registered_by_preference: false
  }
});
const args = { profileId: "tours", requestedExecutor: "auto" };
const good = resolve(base(), args);
assert.equal(good.siteProviderId, "etg-dfsb");
assert.equal(good.executor, "auto");
assert.equal(good.externallyCertified, false);
const withExecutor = base(); withExecutor.operator_preference.executor = "browserless";
assert.equal(resolve(withExecutor, args).executor, "browserless");
const withProfile = base(); withProfile.operator_preference.profile_id = "tours";
assert.equal(resolve(withProfile, args).profileId, "tours");
function denied(mutator, expected, override = args) {
  const x = base(); mutator(x);
  assert.throws(() => resolve(x, override), (err) => err.message === expected);
}
denied(x => { x.providers = []; x.provider_count = 0; }, "mcp_site_browser_etg_provider_not_registered");
denied(x => { x.providers.push({ provider_id: "other" }); x.provider_count = 2; }, "mcp_site_browser_provider_ambiguous");
denied(x => { x.operator_preference.site_provider_id = "other"; }, "mcp_site_browser_selected_provider_not_supported_by_etg_driver");
denied(x => { x.operator_preference.profile_id = "different"; }, "mcp_site_browser_profile_conflicts_with_operator_selection");
denied(x => { x.operator_preference.executor = "steel"; }, "mcp_site_browser_executor_conflicts_with_operator_selection", { profileId: "tours", requestedExecutor: "cloudflare" });
denied(x => { x.operator_preference.executor = "unknown"; }, "mcp_site_browser_executor_invalid");
denied(x => { delete x.operator_preference; }, "mcp_site_browser_operator_preference_unavailable");
denied(x => { x.operator_preference.credential_verified = true; }, "mcp_site_browser_operator_preference_authority_mismatch");
denied(x => { x.operator_preference.preference_valid = false; }, "mcp_site_browser_operator_preference_invalid");
denied(x => { x.read_only = false; }, "mcp_site_browser_capabilities_invalid");
denied(x => { x.operator_preference.site_provider_id = "etg-dfsb"; x.providers.push({provider_id: "other"}); x.provider_count = 2; }, "mcp_site_browser_requested_profile_invalid", { profileId: "invalid/profile", requestedExecutor: "auto" });
denied(x => { delete x.operator_preference.preference_valid; }, "mcp_site_browser_operator_preference_invalid");
denied(x => { x.operator_preference.preference_valid = "true"; }, "mcp_site_browser_operator_preference_invalid");
denied(x => { delete x.operator_preference.site_provider_id; }, "mcp_site_browser_selected_provider_invalid");
denied(x => { delete x.providers; }, "mcp_site_browser_provider_registry_invalid");
denied(x => { x.providers[0].provider_id = "invalid/id"; }, "mcp_site_browser_provider_registry_invalid");
denied(x => { x.provider_count = 0; }, "mcp_site_browser_provider_count_mismatch");
denied(x => { x.providers.push({provider_id: "etg-dfsb"}); x.provider_count = 2; }, "mcp_site_browser_provider_duplicate");
denied(x => { x.providers[0].capabilities = {error: "provider_capabilities_exception"}; }, "mcp_site_browser_provider_capabilities_unavailable");
denied(x => { delete x.providers[0].contract; }, "mcp_site_browser_provider_contract_invalid");
const snapshot = resolve(base(), args);
assertEtgBrowserBindingUnchanged(snapshot, resolve(base(), args));
const changeContract = base(); changeContract.providers[0].contract = "etg.dfsb.browser-acceptance-provider.v3";
assert.throws(() => assertEtgBrowserBindingUnchanged(snapshot, resolve(changeContract, args)), /mcp_site_browser_operator_selection_changed:siteProviderContract/);
const changeExecutor = base(); changeExecutor.operator_preference.executor = "steel";
assert.throws(() => assertEtgBrowserBindingUnchanged(snapshot, resolve(changeExecutor, args)), /mcp_site_browser_operator_selection_changed:executor/);
const plan = { provider_id: snapshot.siteProviderId, provider_contract: snapshot.siteProviderContract, profile_id: snapshot.profileId, suite: "browser_runtime", read_only: true, authorizing: false, plan_digest: "a".repeat(64) };
assertEtgBrowserPlanBinding(snapshot, plan);
assert.throws(() => assertEtgBrowserPlanBinding(snapshot, { ...plan, provider_id: "all-royal" }), /mcp_site_browser_plan_binding_mismatch/);
assert.throws(() => assertEtgBrowserPlanBinding(snapshot, { ...plan, authorizing: true }), /mcp_site_browser_plan_binding_mismatch/);
const result = { provider_id: plan.provider_id, provider_contract: plan.provider_contract, profile_id: plan.profile_id, suite: plan.suite, plan_digest: plan.plan_digest, read_only: true, authorizing: false };
assertEtgBrowserResultBinding(plan, result);
assert.throws(() => assertEtgBrowserResultBinding(plan, { ...result, profile_id: "other" }), /mcp_site_browser_result_binding_mismatch/);
assert.throws(() => assertEtgBrowserResultBinding(plan, { ...result, plan_digest: "b".repeat(64) }), /mcp_site_browser_result_binding_mismatch/);
denied(x => { delete x.operator_preference.configuration_revision; }, "mcp_site_browser_operator_revision_missing_or_invalid");
denied(x => { x.operator_preference.configuration_revision = "bad"; }, "mcp_site_browser_operator_revision_missing_or_invalid");
const changedRevision = base(); changedRevision.operator_preference.configuration_revision = "b".repeat(32);
assert.throws(() => assertEtgBrowserBindingUnchanged(snapshot, resolve(changedRevision, args)), /mcp_site_browser_operator_selection_changed:configurationRevision/);
console.log("MAD4B_BROWSER_SITE_CONFIGURATION: PASS");
