import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CONTRACT_PATH = path.join(HERE, "provider-contracts.json");

export function loadProviderContracts() {
  const parsed = JSON.parse(fs.readFileSync(CONTRACT_PATH, "utf8"));
  if (parsed.contract !== "mad4b.browser-execution-providers.v1") {
    throw new Error("browser_provider_contract_invalid");
  }
  return parsed;
}

export function configuredProviders(env = process.env, requested = "auto") {
  const contracts = loadProviderContracts();
  const order = requested && requested !== "auto" ? [requested] : contracts.priority.slice();
  return order.map((id) => {
    const definition = contracts.providers[id];
    if (!definition) return { id, available: false, missing: ["provider_unknown"], definition: null };
    const missing = (definition.credential_env || []).filter((key) => !String(env[key] || "").trim());
    return { id, available: missing.length === 0, missing, definition };
  });
}

export function classifyProviderError(error, definition = {}) {
  const status = Number(error?.status || error?.statusCode || error?.response?.status || 0);
  const text = String(error?.message || error || "").toLowerCase();
  const fallbackStatuses = new Set(definition.fallback_statuses || []);
  const quotaLike =
    fallbackStatuses.has(status) ||
    /quota|rate.?limit|too many|capacity|concurr|browser time limit|credits?|payment|required|exhaust|timeout|temporar|unavailable/.test(text);
  const authLike = status === 401 || status === 403 || /unauthori|forbidden|invalid api|invalid token|credential/.test(text);
  return {
    status,
    category: quotaLike ? "provider_limit_or_capacity" : authLike ? "provider_auth_or_config" : "provider_runtime_error",
    fallback_allowed: true,
    message: String(error?.message || error || "").slice(0, 500)
  };
}

function normalizeOrigin(origin) {
  const url = new URL(origin);
  if (url.protocol !== "https:") throw new Error("browser_origin_must_be_https");
  return url;
}

async function cloudflareSession(chromium, env, origin) {
  const account = env.CLOUDFLARE_ACCOUNT_ID;
  const token = env.CLOUDFLARE_BROWSER_RUN_API_TOKEN;
  const endpoint = `https://api.cloudflare.com/client/v4/accounts/${encodeURIComponent(account)}/browser-rendering/devtools/browser?keep_alive=600000`;
  const host = normalizeOrigin(origin).hostname;
  const extra = String(env.MAD4B_CLOUDFLARE_ALLOWED_DOMAINS || "")
    .split(",").map((x) => x.trim()).filter(Boolean);
  const response = await fetch(endpoint, {
    method: "POST",
    headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
    body: JSON.stringify({
      guardrails: {
        allowedDomains: Array.from(new Set([host, ...extra])).slice(0, 50),
        allowedDomainSets: ["common-cdns"]
      }
    })
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload?.errors?.[0]?.message || payload?.message || `cloudflare_http_${response.status}`);
    error.status = response.status;
    throw error;
  }
  const session = payload.result || payload;
  const ws = session.webSocketDebuggerUrl;
  if (!ws) throw new Error("cloudflare_websocket_missing");
  const browser = await chromium.connectOverCDP(ws, {
    headers: { Authorization: `Bearer ${token}` },
    timeout: 30000
  });
  return {
    browser,
    sessionId: session.sessionId || "",
    async release() {
      try { await browser.close(); } catch {}
      if (session.sessionId) {
        try {
          await fetch(`https://api.cloudflare.com/client/v4/accounts/${encodeURIComponent(account)}/browser-rendering/devtools/browser/${encodeURIComponent(session.sessionId)}`, {
            method: "DELETE",
            headers: { Authorization: `Bearer ${token}` }
          });
        } catch {}
      }
    }
  };
}

async function browserbaseSession(chromium, env) {
  const mod = await import("@browserbasehq/sdk");
  const Browserbase = mod.Browserbase || mod.default;
  const client = new Browserbase({ apiKey: env.BROWSERBASE_API_KEY });
  const session = await client.sessions.create();
  if (!session?.connectUrl) throw new Error("browserbase_connect_url_missing");
  const browser = await chromium.connectOverCDP(session.connectUrl, { timeout: 30000 });
  return {
    browser,
    sessionId: session.id || "",
    async release() {
      try { await browser.close(); } catch {}
    }
  };
}

async function browserlessSession(chromium, env) {
  const region = String(env.BROWSERLESS_REGION || "production-sfo").trim();
  if (!/^[a-z0-9-]+$/.test(region)) throw new Error("browserless_region_invalid");
  const endpoint = `wss://${region}.browserless.io?token=${encodeURIComponent(env.BROWSERLESS_TOKEN)}&timeout=120000`;
  const browser = await chromium.connectOverCDP(endpoint, { timeout: 30000 });
  return {
    browser,
    sessionId: "",
    async release() {
      try { await browser.close(); } catch {}
    }
  };
}

async function steelSession(chromium, env) {
  const mod = await import("steel-sdk");
  const Steel = mod.default || mod.Steel;
  const client = new Steel({ steelAPIKey: env.STEEL_API_KEY });
  const session = await client.sessions.create();
  if (!session?.websocketUrl) throw new Error("steel_websocket_missing");
  const joiner = session.websocketUrl.includes("?") ? "&" : "?";
  const browser = await chromium.connectOverCDP(`${session.websocketUrl}${joiner}apiKey=${encodeURIComponent(env.STEEL_API_KEY)}`, { timeout: 30000 });
  return {
    browser,
    sessionId: session.id || "",
    async release() {
      try { await browser.close(); } catch {}
      if (session.id && client.sessions?.release) {
        try { await client.sessions.release(session.id); } catch {}
      }
    }
  };
}

export async function connectBrowserProvider(providerId, chromium, env, origin) {
  if (providerId === "cloudflare") return cloudflareSession(chromium, env, origin);
  if (providerId === "browserbase") return browserbaseSession(chromium, env);
  if (providerId === "browserless") return browserlessSession(chromium, env);
  if (providerId === "steel") return steelSession(chromium, env);
  throw new Error("browser_provider_unknown");
}

export async function connectWithFallback({ chromium, env = process.env, origin, requested = "auto" }) {
  const contracts = loadProviderContracts();
  const candidates = configuredProviders(env, requested);
  const attempts = [];

  for (const candidate of candidates) {
    if (!candidate.definition) {
      attempts.push({ provider: candidate.id, state: "skipped", reason: "provider_unknown" });
      continue;
    }
    if (!candidate.available) {
      attempts.push({ provider: candidate.id, state: "skipped", reason: "credentials_missing", missing: candidate.missing });
      continue;
    }
    try {
      const connection = await connectBrowserProvider(candidate.id, chromium, env, origin);
      attempts.push({ provider: candidate.id, state: "selected" });
      return {
        provider: candidate.id,
        definition: candidate.definition,
        connection,
        attempts,
        contract: contracts.contract
      };
    } catch (error) {
      const classified = classifyProviderError(error, candidate.definition);
      attempts.push({ provider: candidate.id, state: "failed", ...classified });
      if (requested !== "auto") {
        const wrapped = new Error(`browser_provider_failed:${candidate.id}:${classified.category}`);
        wrapped.attempts = attempts;
        throw wrapped;
      }
    }
  }
  const error = new Error("no_browser_provider_available");
  error.attempts = attempts;
  throw error;
}
