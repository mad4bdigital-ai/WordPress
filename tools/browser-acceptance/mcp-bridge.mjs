const PROTOCOL_VERSION = "2025-11-25";
const CLIENT_INFO = { name: "mad4b-managed-browser-agent", version: "1.0.0" };

function decodeRpcBody(contentType, text) {
  const body = String(text || "").trim();
  if (!body) return null;
  if (String(contentType || "").toLowerCase().includes("text/event-stream")) {
    const payloads = [];
    for (const line of body.split(/\r?\n/)) {
      if (!line.startsWith("data:")) continue;
      const raw = line.slice(5).trim();
      if (!raw || raw === "[DONE]") continue;
      try { payloads.push(JSON.parse(raw)); } catch {}
    }
    return payloads[payloads.length - 1] || null;
  }
  return JSON.parse(body);
}

async function postRpc({ resource, accessToken, sessionId = "", payload, fetchImpl = fetch, allowEmpty = false }) {
  const headers = {
    Authorization: `Bearer ${accessToken}`,
    Accept: "application/json, text/event-stream",
    "Content-Type": "application/json",
    "MCP-Protocol-Version": PROTOCOL_VERSION
  };
  if (sessionId) headers["Mcp-Session-Id"] = sessionId;

  const response = await fetchImpl(resource, {
    method: "POST",
    headers,
    body: JSON.stringify(payload)
  });
  const text = await response.text();
  if (!response.ok) {
    const error = new Error(`mcp_http_${response.status}`);
    error.status = response.status;
    throw error;
  }

  const nextSessionId = response.headers.get("mcp-session-id") || sessionId;
  if (!text.trim() && allowEmpty) return { payload: null, sessionId: nextSessionId };
  const parsed = decodeRpcBody(response.headers.get("content-type"), text);
  if (!parsed && allowEmpty) return { payload: null, sessionId: nextSessionId };
  if (!parsed) throw new Error("mcp_empty_response");
  if (parsed.error) {
    const error = new Error(`mcp_rpc_error:${parsed.error.code || "unknown"}:${parsed.error.message || "unknown"}`);
    error.rpc = parsed.error;
    throw error;
  }
  return { payload: parsed, sessionId: nextSessionId };
}

function extractToolValue(rpcPayload) {
  const result = rpcPayload?.result;
  if (!result) throw new Error("mcp_tool_result_missing");
  if (result.isError) throw new Error("mcp_tool_reported_error");
  if (result.structuredContent && typeof result.structuredContent === "object") {
    if (result.structuredContent.result && typeof result.structuredContent.result === "object") return result.structuredContent.result;
    return result.structuredContent;
  }
  if (Array.isArray(result.content)) {
    for (const item of result.content) {
      if (!item || item.type !== "text" || typeof item.text !== "string") continue;
      try {
        const parsed = JSON.parse(item.text);
        if (parsed && typeof parsed === "object") return parsed.result && typeof parsed.result === "object" ? parsed.result : parsed;
      } catch {}
    }
  }
  if (result.result && typeof result.result === "object") return result.result;
  return result;
}

function normalizeToolNames(rpcPayload) {
  const tools = rpcPayload?.result?.tools;
  if (!Array.isArray(tools)) throw new Error("mcp_tools_list_invalid");
  return tools.map((tool) => String(tool?.name || "")).filter(Boolean);
}

function toolNameForAbility(toolNames, abilityName) {
  const expected = String(abilityName).replace(/\//g, "-");
  if (toolNames.includes(expected)) return expected;
  if (toolNames.includes(abilityName)) return abilityName;
  const suffix = expected.replace(/^mad4b-/, "");
  const candidates = toolNames.filter((name) => name === expected || name.endsWith(`-${suffix}`));
  if (candidates.length === 1) return candidates[0];
  throw new Error(`mcp_required_tool_missing:${expected}`);
}

export async function createMad4bMcpSession({
  resource,
  accessToken,
  fetchImpl = fetch
}) {
  if (!/^https:\/\//i.test(String(resource || ""))) throw new Error("mcp_resource_must_be_https");
  if (!String(accessToken || "").trim()) throw new Error("mcp_access_token_missing");

  let nextId = 1;
  const initialized = await postRpc({
    resource,
    accessToken,
    fetchImpl,
    payload: {
      jsonrpc: "2.0",
      id: nextId++,
      method: "initialize",
      params: {
        protocolVersion: PROTOCOL_VERSION,
        capabilities: {},
        clientInfo: CLIENT_INFO
      }
    }
  });
  if (!initialized.sessionId) throw new Error("mcp_session_id_missing");
  let sessionId = initialized.sessionId;

  await postRpc({
    resource,
    accessToken,
    sessionId,
    fetchImpl,
    allowEmpty: true,
    payload: {
      jsonrpc: "2.0",
      method: "notifications/initialized",
      params: {}
    }
  });

  const listed = await postRpc({
    resource,
    accessToken,
    sessionId,
    fetchImpl,
    payload: { jsonrpc: "2.0", id: nextId++, method: "tools/list", params: {} }
  });
  sessionId = listed.sessionId;
  const toolNames = normalizeToolNames(listed.payload);

  return {
    contract: "mad4b.browser-mcp-session.v1",
    resource,
    sessionId,
    toolNames,
    async callAbility(abilityName, args = {}) {
      const name = toolNameForAbility(toolNames, abilityName);
      const response = await postRpc({
        resource,
        accessToken,
        sessionId,
        fetchImpl,
        payload: {
          jsonrpc: "2.0",
          id: nextId++,
          method: "tools/call",
          params: { name, arguments: args }
        }
      });
      sessionId = response.sessionId;
      return extractToolValue(response.payload);
    }
  };
}

export async function requestBrowserPlan(session, {
  providerId = "etg-dfsb",
  profileId = "tours"
} = {}) {
  const plan = await session.callAbility("mad4b/browser-acceptance-plan", {
    provider_id: providerId,
    profile_id: profileId,
    suite: "browser_runtime"
  });
  if (plan?.contract !== "mad4b.browser-acceptance-plan.v1" || plan?.state !== "ready") {
    const reasons = Array.isArray(plan?.blocking_reasons) ? plan.blocking_reasons.join(",") : "unknown";
    throw new Error(`mcp_browser_plan_not_ready:${reasons}`);
  }
  return plan;
}

export async function submitBrowserEvidence(session, plan, evidence) {
  const result = await session.callAbility("mad4b/browser-acceptance-result", {
    provider_id: String(plan.provider_id || "etg-dfsb"),
    profile_id: String(plan.profile_id || "tours"),
    suite: "browser_runtime",
    plan_digest: String(plan.plan_digest || ""),
    plan_signature: String(plan.plan_signature || ""),
    evidence
  });
  if (result?.contract !== "mad4b.browser-acceptance-result.v1") {
    throw new Error("mcp_browser_result_contract_invalid");
  }
  return result;
}
