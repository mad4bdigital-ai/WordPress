function validHostname(value) {
  const host = String(value || "").trim().toLowerCase().replace(/\.$/, "");
  if (!host || host.length > 253 || host.includes("*") || host.includes("/") || host.includes(":")) return "";
  if (!/^[a-z0-9.-]+$/.test(host)) return "";
  const labels = host.split(".");
  if (labels.some((label) => !label || label.length > 63 || !/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label))) return "";
  return host;
}

export function originHostname(origin) {
  const url = new URL(origin);
  if (url.protocol !== "https:") throw new Error("browser_origin_must_be_https");
  const host = validHostname(url.hostname);
  if (!host) throw new Error("browser_origin_hostname_invalid");
  return host;
}

export function configuredAllowedHosts(env = process.env) {
  const values = [
    String(env.MAD4B_BROWSER_ALLOWED_ASSET_DOMAINS || ""),
    String(env.MAD4B_CLOUDFLARE_ALLOWED_DOMAINS || "")
  ].join(",");
  const out = [];
  for (const item of values.split(",")) {
    const raw = item.trim();
    if (!raw) continue;
    const host = validHostname(raw);
    if (!host) throw new Error("browser_allowed_asset_domain_invalid");
    if (!out.includes(host)) out.push(host);
  }
  return out.slice(0, 50);
}

export function allowedHostSet(origin, env = process.env) {
  return new Set([originHostname(origin), ...configuredAllowedHosts(env)]);
}

const ACTIVE_CROSS_ORIGIN_TYPES = new Set([
  "document",
  "script",
  "xhr",
  "fetch",
  "eventsource",
  "websocket",
  "manifest",
  "other"
]);

export function requestBoundaryDecision({ url, resourceType, origin, env = process.env }) {
  const raw = String(url || "");
  if (/^(?:data|blob|about):/i.test(raw)) return { allow: true, reason: "local_scheme" };

  let parsed;
  try { parsed = new URL(raw); }
  catch { return { allow: false, reason: "invalid_url" }; }

  if (parsed.protocol !== "https:") return { allow: false, reason: "non_https_network_request" };

  const allowedHosts = allowedHostSet(origin, env);
  const host = validHostname(parsed.hostname);
  if (!host) return { allow: false, reason: "invalid_hostname" };
  if (allowedHosts.has(host)) return { allow: true, reason: "allowlisted_host" };

  const type = String(resourceType || "other").toLowerCase();
  if (ACTIVE_CROSS_ORIGIN_TYPES.has(type)) {
    return { allow: false, reason: "cross_origin_active_request_denied" };
  }
  return { allow: true, reason: "passive_asset" };
}

export async function installContextNetworkBoundary(context, origin, env = process.env) {
  if (!context || typeof context.route !== "function") throw new Error("browser_context_route_unavailable");
  await context.route("**/*", async (route) => {
    const request = route.request();
    const decision = requestBoundaryDecision({
      url: request.url(),
      resourceType: request.resourceType(),
      origin,
      env
    });
    if (decision.allow) return route.continue();
    return route.abort("blockedbyclient");
  });
  return {
    contract: "mad4b.browser-network-boundary.v1",
    origin_host: originHostname(origin),
    configured_asset_hosts: configuredAllowedHosts(env),
    active_cross_origin_denied: true,
    passive_cross_origin_assets_allowed: true
  };
}
