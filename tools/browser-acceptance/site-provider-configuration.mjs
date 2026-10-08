// Fail-closed binding between the WordPress operator UI and the ETG-only external driver.
// Provider preferences are non-authorizing. Credentials and browser authority remain external.
const ALLOWED_EXECUTORS = new Set(["auto", "cloudflare", "browserbase", "browserless", "steel"]);
const ID = /^[a-z0-9][a-z0-9._-]{0,63}$/;

export function resolveEtgBrowserOperatorConfiguration(capabilities, { profileId, requestedExecutor }) {
  if (!capabilities || capabilities.contract !== "mad4b.browser-acceptance-capabilities.v1" || capabilities.read_only !== true || capabilities.authorizing !== false) {
    throw new Error("mcp_site_browser_capabilities_invalid");
  }
  if (!Array.isArray(capabilities.providers)) throw new Error("mcp_site_browser_provider_registry_invalid");
  const registered = capabilities.providers;
  const ids = registered.map((row) => {
    if (!row || typeof row.provider_id !== "string" || !ID.test(row.provider_id)) {
      throw new Error("mcp_site_browser_provider_registry_invalid");
    }
    if (row.capabilities && (typeof row.capabilities !== "object" || row.capabilities.error)) {
      throw new Error("mcp_site_browser_provider_capabilities_unavailable");
    }
    return row.provider_id;
  });
  if (capabilities.provider_count !== ids.length) throw new Error("mcp_site_browser_provider_count_mismatch");
  if (new Set(ids).size !== ids.length) throw new Error("mcp_site_browser_provider_duplicate");
  if (!ids.includes("etg-dfsb")) throw new Error("mcp_site_browser_etg_provider_not_registered");
  const selected = registered.find((row) => row.provider_id === "etg-dfsb");
  if (!selected || typeof selected.contract !== "string" || !/^[a-z0-9][a-z0-9._-]{0,159}$/.test(selected.contract)) {
    throw new Error("mcp_site_browser_provider_contract_invalid");
  }
  const pref = capabilities.operator_preference;
  if (!pref || pref.contract !== "mad4b.browser-operator-preference.v1" || pref.authorizing !== false || pref.read_only !== true) {
    throw new Error("mcp_site_browser_operator_preference_unavailable");
  }
  if (pref.preference_valid !== true) throw new Error("mcp_site_browser_operator_preference_invalid");
  if (pref.credential_verified !== false || pref.external_runner_connected !== false || pref.site_provider_registered_by_preference !== false) {
    throw new Error("mcp_site_browser_operator_preference_authority_mismatch");
  }
  const siteId = pref.site_provider_id;
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
    siteProviderContract: selected.contract,
    profileId,
    executor: requestedExecutor === "auto" ? pref.executor : requestedExecutor,
    configuredExecutor: pref.executor,
    operatorSelectionValidated: true,
    externallyCertified: false,
  });
}

/** Non-authorizing readback guard, repeated before execution and after reduction. */
export function assertEtgBrowserBindingUnchanged(initial, current) {
  for (const key of ["siteProviderId", "siteProviderContract", "profileId", "executor", "configuredExecutor"]) {
    if (!initial || !current || initial[key] !== current[key]) {
      throw new Error("mcp_site_browser_operator_selection_changed:" + key);
    }
  }
  return true;
}

/** The MCP server must not return a plan or result for a different target. */
export function assertEtgBrowserPlanBinding(selection, plan) {
  if (!plan || plan.provider_id !== selection?.siteProviderId ||
      plan.provider_contract !== selection?.siteProviderContract ||
      plan.profile_id !== selection?.profileId || plan.suite !== "browser_runtime" ||
      plan.read_only !== true || plan.authorizing !== false) {
    throw new Error("mcp_site_browser_plan_binding_mismatch");
  }
  return true;
}
export function assertEtgBrowserResultBinding(plan, result) {
  if (!result || result.provider_id !== plan?.provider_id ||
      result.provider_contract !== plan?.provider_contract ||
      result.profile_id !== plan?.profile_id || result.suite !== "browser_runtime" ||
      result.plan_digest !== plan?.plan_digest ||
      result.read_only !== true || result.authorizing !== false) {
    throw new Error("mcp_site_browser_result_binding_mismatch");
  }
  return true;
}
