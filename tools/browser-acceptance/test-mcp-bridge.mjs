import assert from "node:assert/strict";
import {
  createMad4bMcpSession,
  requestBrowserPlan,
  submitBrowserEvidence,
  validateEvidencePayload
} from "./mcp-bridge.mjs";

const now = Math.floor(Date.now() / 1000);
const plan = {
  contract: "mad4b.browser-acceptance-plan.v1",
  state: "ready",
  provider_id: "etg-dfsb",
  profile_id: "tours",
  plan_digest: "a".repeat(64),
  plan_signature: "b".repeat(64),
  origin: "https://staging.egypttourgates.com/",
  challenge: {
    contract: "etg.dfsb.browser-acceptance-challenge.v1",
    nonce: "c".repeat(32),
    issued_at: now,
    expires_at: now + 900,
    signature: "d".repeat(64)
  },
  cases: []
};
const reduced = {
  contract: "mad4b.browser-acceptance-result.v1",
  verdict: "PASS",
  verification: { browser_runtime_parity_verified: true }
};

const calls = [];
let step = 0;
const fakeFetch = async (url, options) => {
  calls.push({ url, options });
  step += 1;
  if (step === 1) {
    return new Response(JSON.stringify({
      jsonrpc: "2.0",
      id: 1,
      result: {
        protocolVersion: "2025-11-25",
        capabilities: { tools: {} },
        serverInfo: { name: "MAD4B ChatGPT MCP", version: "1" }
      }
    }), {
      status: 200,
      headers: { "content-type": "application/json", "mcp-session-id": "session-123" }
    });
  }
  if (step === 2) return new Response("", { status: 202 });
  if (step === 3) {
    return new Response(JSON.stringify({
      jsonrpc: "2.0",
      id: 2,
      result: {
        tools: [
          { name: "mad4b-browser-acceptance-plan" },
          { name: "mad4b-browser-acceptance-result" }
        ]
      }
    }), { status: 200, headers: { "content-type": "application/json" } });
  }
  if (step === 4) {
    return new Response(JSON.stringify({
      jsonrpc: "2.0",
      id: 3,
      result: { structuredContent: plan }
    }), { status: 200, headers: { "content-type": "application/json" } });
  }
  if (step === 5) {
    return new Response(JSON.stringify({
      jsonrpc: "2.0",
      id: 4,
      result: { content: [{ type: "text", text: JSON.stringify(reduced) }] }
    }), { status: 200, headers: { "content-type": "application/json" } });
  }
  throw new Error("unexpected_fake_fetch");
};

const session = await createMad4bMcpSession({
  resource: "https://staging.egypttourgates.com/wp-json/mcp/mad4b-chatgpt",
  accessToken: "short-lived-token",
  fetchImpl: fakeFetch
});
assert.equal(session.sessionId, "session-123");
assert.equal(calls[0].options.headers.Authorization, "Bearer short-lived-token");
assert.equal(calls[2].options.headers["Mcp-Session-Id"], "session-123");

const livePlan = await requestBrowserPlan(session, { providerId: "etg-dfsb", profileId: "tours" });
assert.equal(livePlan.plan_digest, plan.plan_digest);
const planCall = JSON.parse(calls[3].options.body);
assert.equal(planCall.method, "tools/call");
assert.equal(planCall.params.name, "mad4b-browser-acceptance-plan");
assert.deepEqual(planCall.params.arguments, {
  provider_id: "etg-dfsb",
  profile_id: "tours",
  suite: "browser_runtime"
});

const evidence = { contract: "etg.dfsb.browser-acceptance-evidence.v1", cases: [] };
const result = await submitBrowserEvidence(session, livePlan, evidence);
assert.equal(result.verdict, "PASS");

const evidenceShape = validateEvidencePayload(evidence);
assert.equal(evidenceShape.contract, "etg.dfsb.browser-acceptance-evidence.v1");
assert.ok(evidenceShape.bytes > 0);
assert.ok(evidenceShape.nodes >= 2);

assert.throws(
  () => validateEvidencePayload({ ...evidence, payload: "x".repeat(140000) }),
  /browser_evidence_size_limit_exceeded/
);

let deep = { contract: "etg.dfsb.browser-acceptance-evidence.v1" };
let cursor = deep;
for (let i = 0; i < 9; i += 1) {
  cursor.next = {};
  cursor = cursor.next;
}
assert.throws(() => validateEvidencePayload(deep), /browser_evidence_depth_limit_exceeded/);

const many = { contract: "etg.dfsb.browser-acceptance-evidence.v1", items: [] };
for (let i = 0; i < 1100; i += 1) many.items.push(i);
assert.throws(() => validateEvidencePayload(many), /browser_evidence_node_limit_exceeded/);

const resultCall = JSON.parse(calls[4].options.body);
assert.equal(resultCall.params.name, "mad4b-browser-acceptance-result");
assert.equal(resultCall.params.arguments.plan_digest, plan.plan_digest);
assert.deepEqual(resultCall.params.arguments.evidence, evidence);

await assert.rejects(
  () => createMad4bMcpSession({ resource: "http://example.test/mcp", accessToken: "x", fetchImpl: fakeFetch }),
  /mcp_resource_must_be_https/
);

console.log("MAD4B browser MCP bridge contract PASS");
