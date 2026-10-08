function validHostname(value) {
  const host = String(value || "").trim().toLowerCase().replace(/\.$/, "");
  if (!host || host.length > 253 || host.includes("*") || host.includes("/") || host.includes(":")) return "";
  if (!/^[a-z0-9.-]+$/.test(host)) return "";
  const labels = host.split(".");
  if (labels.some((label) => !label || label.length > 63 || !/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label))) return "";
  // Hostname checks complement, not replace, provider-level network egress
  // firewall rules (DNS rebinding must also be denied at connect time).
  if (host === "localhost" || /\.(?:localhost|local|internal)$/.test(host)) return "";
  if (/^\d+(?:\.\d+){3}$/.test(host)) {
    const octets = host.split(".").map(Number);
    if (octets.some(x => !Number.isInteger(x) || x < 0 || x > 255)) return "";
    if (octets[0] === 10 || octets[0] === 127 || octets[0] === 0 ||
        octets[0] === 169 && octets[1] === 254 ||
        octets[0] === 192 && octets[1] === 168 ||
        octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31) return "";
  }
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
  if (out.length > 50) throw new Error("browser_allowed_asset_domains_overflow");
  return out;
}

export function allowedHostSet(origin, env = process.env) {
  return new Set([originHostname(origin), ...configuredAllowedHosts(env)]);
}

// No passive-content exception: remote images/fonts can leak cookies or
// other browser state through their query strings. Only explicitly reviewed
// asset hosts are allowed, regardless of resource type.

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
  if (allowedHosts.has(host)) {
    const declared = new URL(origin);
    const pageHost = originHostname(origin);
    if (host === pageHost && parsed.origin !== declared.origin) {
      return { allow: false, reason: "site_origin_port_mismatch" };
    }
    if (host !== pageHost && parsed.port) {
      return { allow: false, reason: "asset_host_nonstandard_port" };
    }
    return { allow: true, reason: "allowlisted_host" };
  }

  return { allow: false, reason: "cross_origin_not_allowlisted" };
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
    passive_cross_origin_assets_allowed: false
  };
}
