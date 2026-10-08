import assert from "node:assert/strict";
import { resolveEtgBrowserOperatorConfiguration as resolve } from "./site-provider-configuration.mjs";

const base = () => ({
  contract: "mad4b.browser-acceptance-capabilities.v1", read_only: true, authorizing: false,
  providers: [{ provider_id: "etg-dfsb" }],
  operator_preference: {
    contract: "mad4b.browser-operator-preference.v1", authorizing: false, read_only: true,
    executor: "auto", profile_id: "", site_provider_id: "",
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
denied(x => { x.providers = []; }, "mcp_site_browser_etg_provider_not_registered");
denied(x => { x.providers.push({ provider_id: "other" }); }, "mcp_site_browser_provider_ambiguous");
denied(x => { x.operator_preference.site_provider_id = "other"; }, "mcp_site_browser_selected_provider_not_supported_by_etg_driver");
denied(x => { x.operator_preference.profile_id = "different"; }, "mcp_site_browser_profile_conflicts_with_operator_selection");
denied(x => { x.operator_preference.executor = "steel"; }, "mcp_site_browser_executor_conflicts_with_operator_selection", { profileId: "tours", requestedExecutor: "cloudflare" });
denied(x => { x.operator_preference.executor = "unknown"; }, "mcp_site_browser_executor_invalid");
denied(x => { delete x.operator_preference; }, "mcp_site_browser_operator_preference_unavailable");
denied(x => { x.operator_preference.credential_verified = true; }, "mcp_site_browser_operator_preference_authority_mismatch");
denied(x => { x.operator_preference.preference_valid = false; }, "mcp_site_browser_operator_preference_invalid");
denied(x => { x.read_only = false; }, "mcp_site_browser_capabilities_invalid");
denied(x => { x.operator_preference.site_provider_id = "etg-dfsb"; x.providers.push({provider_id: "other"}); }, "mcp_site_browser_requested_profile_invalid", { profileId: "invalid/profile", requestedExecutor: "auto" });
console.log("MAD4B_BROWSER_SITE_CONFIGURATION: PASS");
