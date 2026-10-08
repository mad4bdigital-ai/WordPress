// Site-neutral, read-only binding of WordPress discovery to locally audited drivers.
// Discovery is NEVER a certificate. No network code or arbitrary module paths.
const ID = /^[a-z0-9][a-z0-9._-]{0,63}$/;
const CONTRACT = /^[a-z0-9][a-z0-9._-]{0,159}$/;
const SHA = /^[a-f0-9]{64}$/;
const REV = /^[a-f0-9]{32}$/;
const HOST = /^https:\/\/(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::[0-9]{2,5})?\/?$/;
const EXECUTORS = new Set(["auto", "cloudflare", "browserbase", "browserless", "steel"]);

function origin(raw) {
  if (typeof raw !== "string" || !HOST.test(raw)) throw new Error("site_discovery_origin_invalid");
  return raw.endsWith("/") ? raw.slice(0, -1) : raw;
}
const fail = (code) => { throw new Error("site_browser_" + code); };
const valid = (value, pattern) => typeof value === "string" && pattern.test(value);

export function resolveSiteBrowserAdapter(caps, {
  requestedProfile = "",
  requestedExecutor = "auto",
  approvedDrivers = []
} = {}) {
  if (!caps || caps.contract !== "mad4b.browser-acceptance-capabilities.v1" ||
      caps.read_only !== true || caps.authorizing !== false) fail("capabilities_invalid");
  const siteOrigin = origin(caps.site_origin);
  const observed = caps.site_discovery;
  if (!observed || observed.contract !== "mad4b.site-capability-discovery.v1" ||
      observed.read_only !== true || observed.authorizing !== false ||
      observed.certification_issued !== false || observed.discovery_complete !== true ||
      !valid(observed.snapshot_sha256, SHA) || origin(observed.origin) !== siteOrigin ||
      !Array.isArray(observed.plugins) || observed.plugins.length > 128 ||
      !Array.isArray(observed.post_types) || observed.post_types.length > 96 ||
      !Array.isArray(observed.taxonomies) || observed.taxonomies.length > 96 ||
      observed.post_types.some(x => !valid(x, /^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/)) ||
      observed.taxonomies.some(x => !valid(x, /^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/)) ||
      new Set(observed.post_types).size !== observed.post_types.length ||
      new Set(observed.taxonomies).size !== observed.taxonomies.length ||
      new Set(observed.plugins).size !== observed.plugins.length ||
      observed.plugins.some(x => !valid(x, ID)) ||
      observed.theme_version_complete !== true ||
      !observed.theme || !valid(observed.theme.stylesheet, ID) ||
      !/^[a-zA-Z0-9][a-zA-Z0-9._+-]{0,99}$/.test(observed.theme.version || "") ||
      typeof observed.theme.parent_stylesheet !== "string" ||
      typeof observed.theme.parent_version !== "string" ||
      (observed.theme.parent_stylesheet &&
        (!valid(observed.theme.parent_stylesheet, ID) ||
         !/^[a-zA-Z0-9][a-zA-Z0-9._+-]{0,99}$/.test(observed.theme.parent_version))) ||
      observed.plugin_versions_complete !== true ||
      !Array.isArray(observed.plugin_versions) ||
      observed.plugin_versions.length !== observed.plugins.length ||
      new Set(observed.plugin_versions.map(x => x?.slug)).size !== observed.plugin_versions.length ||
      observed.plugin_versions.some(x => !x || !valid(x.slug, ID) ||
        !observed.plugins.includes(x.slug) ||
        typeof x.basename !== "string" ||
        (x.basename.includes("/") ? x.basename.split("/")[0].toLowerCase() :
          x.basename.slice(0, -4).toLowerCase()) !== x.slug ||
        !/^[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._-]+)?\.php$/.test(x.basename) ||
        x.basename.split("/").some(y => y === "." || y === ".." || y.startsWith(".")) ||
        !/^[a-zA-Z0-9][a-zA-Z0-9._+-]{0,99}$/.test(x.version || "")) ||
      !Array.isArray(observed.unmapped_plugins) ||
      observed.unmapped_plugins.length > 128 ||
      new Set(observed.unmapped_plugins).size !== observed.unmapped_plugins.length ||
      observed.unmapped_plugins.some(x => !valid(x, ID) || !observed.plugins.includes(x)) ||
      !Array.isArray(observed.provider_matches) || observed.provider_matches.length > 32) {
    fail("discovery_invalid_or_incomplete");
  }
  if (!Array.isArray(caps.providers) || caps.providers.length > 32 ||
      caps.provider_count !== caps.providers.length) fail("providers_invalid");
  const providers = new Map();
  for (const row of caps.providers) {
    if (!row || !valid(row.provider_id, ID) || !valid(row.contract, CONTRACT) ||
        !row.descriptor || row.descriptor.provider_id !== row.provider_id ||
        row.descriptor.contract !== row.contract ||
        row.descriptor.read_only !== true || row.descriptor.authorizing !== false ||
        !row.capabilities || row.capabilities.error ||
        row.capabilities.read_only !== true || row.capabilities.authorizing !== false ||
        row.capabilities.provider_id !== row.provider_id ||
        row.capabilities.provider_contract !== row.contract ||
        providers.has(row.provider_id)) fail("provider_registry_invalid");
    providers.set(row.provider_id, row);
  }
  const recognized = new Set();
  const declared = new Set();
  for (const provider of providers.values()) {
    const rules = provider.descriptor.recognition;
    if (!rules) continue;
    if (typeof rules !== "object" || Array.isArray(rules)) fail("provider_recognition_invalid");
    const keys = ["source_plugins", "source_post_types", "source_taxonomies"];
    const requirements = keys.map(k => rules[k] ?? []);
    if (requirements.some(x => !Array.isArray(x) || x.length > 32)) fail("provider_recognition_invalid");
    if (requirements.some(x => x.length)) declared.add(provider.provider_id);
  }
  const seenMatches = new Set();
  const coveredPlugins = new Set();
  for (const match of observed.provider_matches) {
    const sources = [
      ["source_plugins", "matched_plugins", observed.plugins, ID],
      ["source_post_types", "matched_post_types", observed.post_types, /^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/],
      ["source_taxonomies", "matched_taxonomies", observed.taxonomies, /^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/]
    ];
    if (!match || !valid(match.provider_id, ID) || !providers.has(match.provider_id) ||
        match.certified !== false || match.authorizing !== false ||
        !declared.has(match.provider_id) || seenMatches.has(match.provider_id)) fail("discovery_match_invalid");
    seenMatches.add(match.provider_id);
    let total = 0, hits = 0;
    for (const [key, matchedKey, observedValues, pattern] of sources) {
      const required = match[key] || [];
      const descriptorSignal = providers.get(match.provider_id).descriptor.recognition?.[key] || [];
      if (!Array.isArray(descriptorSignal) ||
          JSON.stringify([...descriptorSignal].sort()) !== JSON.stringify([...required].sort())) {
        fail("provider_recognition_mismatch");
      }
      if (!Array.isArray(required) || required.length > 32 ||
          new Set(required).size !== required.length ||
          required.some(x => !valid(x, pattern))) fail("discovery_match_invalid");
      const seen = required.filter(x => observedValues.includes(x)).length;
      if (typeof match[matchedKey] !== "undefined" && match[matchedKey] !== seen) fail("discovery_match_invalid");
      total += required.length;
      hits += seen;
    }
    if (!total || match.recognized !== (hits === total)) fail("discovery_match_invalid");
    if (match.recognized) {
      recognized.add(match.provider_id);
      for (const plugin of match.source_plugins || []) coveredPlugins.add(plugin);
    }
  }
  if (seenMatches.size !== declared.size) fail("discovery_match_incomplete");
  if (JSON.stringify([...observed.unmapped_plugins].sort()) !==
      JSON.stringify(observed.plugins.filter(x => !coveredPlugins.has(x)).sort())) {
    fail("unmapped_plugin_projection_invalid");
  }
  if (!Array.isArray(approvedDrivers) || approvedDrivers.length > 32) fail("driver_registry_invalid");
  const candidates = new Map();
  for (const driver of approvedDrivers) {
    if (!driver || !valid(driver.provider_contract, CONTRACT) || !valid(driver.driver_id, ID) ||
        !valid(driver.evidence_contract, CONTRACT) || candidates.has(driver.provider_contract)) fail("driver_registry_invalid");
    candidates.set(driver.provider_contract, driver);
  }
  const pref = caps.operator_preference;
  if (!pref || pref.contract !== "mad4b.browser-operator-preference.v1" ||
      pref.read_only !== true || pref.authorizing !== false || pref.preference_valid !== true ||
      !valid(pref.configuration_revision, REV) ||
      pref.credential_verified !== false || pref.external_runner_connected !== false ||
      pref.site_provider_registered_by_preference !== false ||
      typeof pref.site_provider_id !== "string" || (pref.site_provider_id && !valid(pref.site_provider_id, ID)) ||
      typeof pref.profile_id !== "string" || (pref.profile_id && !valid(pref.profile_id, ID)) ||
      !EXECUTORS.has(pref.executor) || !EXECUTORS.has(requestedExecutor) ||
      (requestedProfile && !valid(requestedProfile, ID))) fail("operator_preference_invalid");
  if (pref.executor !== "auto" && requestedExecutor !== "auto" && pref.executor !== requestedExecutor) fail("executor_conflict");
  // A discovered provider without a reviewed driver is visible but not executable.
  // Never silently replace it with a different installed provider.
  const eligible = [...recognized].sort();
  let providerId = pref.site_provider_id;
  if (providerId && !providers.has(providerId)) fail("selected_provider_unregistered");
  if (providerId && !recognized.has(providerId)) fail("selected_provider_not_discovered");
  if (!providerId) {
    if (!eligible.length) fail("site_adapter_missing");
    if (eligible.length > 1) fail("site_adapter_ambiguous");
    providerId = eligible[0];
  }
  const selected = providers.get(providerId);
  const driver = candidates.get(selected.contract);
  if (!driver) fail("external_driver_not_approved");
  const defaultProfile = selected.capabilities.default_profile_id;
  if (defaultProfile !== undefined && !valid(defaultProfile, ID)) fail("provider_default_profile_invalid");
  if (pref.profile_id && requestedProfile && pref.profile_id !== requestedProfile) fail("profile_conflict");
  const profileId = requestedProfile || pref.profile_id || defaultProfile;
  if (!valid(profileId, ID)) fail("profile_not_resolved");
  return Object.freeze({
    contract: "mad4b.site-browser-selection.v1", siteOrigin, siteProviderId: providerId,
    siteProviderContract: selected.contract, driverId: driver.driver_id,
    evidenceContract: driver.evidence_contract, profileId,
    executor: requestedExecutor === "auto" ? pref.executor : requestedExecutor,
    configuredExecutor: pref.executor, configurationRevision: pref.configuration_revision,
    discoverySha256: observed.snapshot_sha256, authorizing: false,
    externallyCertified: false, unmappedPlugins: observed.unmapped_plugins || []
  });
}

export function assertSiteBindingUnchanged(initial, next) {
  for (const key of ["siteOrigin", "siteProviderId", "siteProviderContract", "driverId",
    "evidenceContract", "profileId", "executor", "configuredExecutor", "configurationRevision", "discoverySha256"]) {
    if (!initial || !next || initial[key] !== next[key]) fail("binding_changed:" + key);
  }
  return true;
}

export function assertSitePlanBound(selection, plan) {
  if (!plan || origin(plan.origin) !== selection?.siteOrigin ||
      plan.provider_id !== selection.siteProviderId ||
      plan.provider_contract !== selection.siteProviderContract ||
      plan.profile_id !== selection.profileId || plan.suite !== "browser_runtime" ||
      plan.contract !== "mad4b.browser-acceptance-plan.v1" ||
      plan.state !== "ready" || plan.read_only !== true || plan.authorizing !== false ||
      !valid(plan.plan_digest, SHA) || !valid(plan.plan_signature, SHA) ||
      !Array.isArray(plan.cases) || !plan.cases.length || plan.cases.length > 8) fail("plan_binding_mismatch");
  return true;
}

export function assertSiteResultBound(plan, result) {
  if (!result || result.contract !== "mad4b.browser-acceptance-result.v1" ||
      result.provider_id !== plan?.provider_id ||
      result.provider_contract !== plan.provider_contract ||
      result.profile_id !== plan.profile_id || result.suite !== plan.suite ||
      result.plan_digest !== plan.plan_digest ||
      result.read_only !== true || result.authorizing !== false) fail("result_binding_mismatch");
  return true;
}
