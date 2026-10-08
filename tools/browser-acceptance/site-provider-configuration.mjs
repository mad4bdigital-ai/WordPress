// Fail-closed binding between the WordPress operator UI and the ETG-only external driver.
// Provider preferences are non-authorizing. Credentials and browser authority remain external.
const ALLOWED_EXECUTORS = new Set(["auto", "cloudflare", "browserbase", "browserless", "steel"]);
const ID = /^[a-z0-9][a-z0-9._-]{0,63}$/;

export function resolveEtgBrowserOperatorConfiguration(capabilities, { profileId, requestedExecutor }) {
  if (!capabilities || capabilities.contract !== "mad4b.browser-acceptance-capabilities.v1" || capabilities.read_only !== true || capabilities.authorizing !== false) {
    throw new Error("mcp_site_browser_capabilities_invalid");
  }
  const registered = Array.isArray(capabilities.providers) ? capabilities.providers : [];
  const ids = registered.map((row) => String(row?.provider_id || "")).filter((id) => ID.test(id));
  if (!ids.includes("etg-dfsb")) throw new Error("mcp_site_browser_etg_provider_not_registered");
  const pref = capabilities.operator_preference;
  if (!pref || pref.contract !== "mad4b.browser-operator-preference.v1" || pref.authorizing !== false || pref.read_only !== true) {
    throw new Error("mcp_site_browser_operator_preference_unavailable");
  }
  if (pref.credential_verified !== false || pref.external_runner_connected !== false || pref.site_provider_registered_by_preference !== false) {
    throw new Error("mcp_site_browser_operator_preference_authority_mismatch");
  }
  const siteId = pref.site_provider_id || "";
  if (typeof siteId !== "string" || (siteId && !ID.test(siteId))) throw new Error("mcp_site_browser_selected_provider_invalid");
  if (siteId && siteId !== "etg-dfsb") throw new Error("mcp_site_browser_selected_provider_not_supported_by_etg_driver");
  if (!siteId && ids.length !== 1) throw new Error("mcp_site_browser_provider_ambiguous");
  if (typeof profileId !== "string" || !ID.test(profileId)) throw new Error("mcp_site_browser_requested_profile_invalid");
  if (typeof pref.profile_id !== "string" || (pref.profile_id && !ID.test(pref.profile_id))) {
    throw new Error("mcp_site_browser_operator_profile_invalid");
  }
  if (pref.profile_id && pref.profile_id !== profileId) throw new Error("mcp_site_browser_profile_conflicts_with_operator_selection");
  if (!ALLOWED_EXECUTORS.has(requestedExecutor) || !ALLOWED_EXECUTORS.has(pref.executor)) {
    throw new Error("mcp_site_browser_executor_invalid");
  }
  if (pref.executor !== "auto" && requestedExecutor !== "auto" && pref.executor !== requestedExecutor) {
    throw new Error("mcp_site_browser_executor_conflicts_with_operator_selection");
  }
  return Object.freeze({
    siteProviderId: "etg-dfsb",
    profileId,
    executor: requestedExecutor === "auto" ? pref.executor : requestedExecutor,
    configuredExecutor: pref.executor,
    operatorSelectionValidated: true,
    externallyCertified: false,
  });
}
