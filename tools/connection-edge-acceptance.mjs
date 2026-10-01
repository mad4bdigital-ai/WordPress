#!/usr/bin/env node

import process from "node:process";
import crypto from "node:crypto";

const contract = "mad4b.connection-edge-acceptance.v1";
const baseArg = process.argv[2] || process.env.MAD4B_CONNECTION_BASE_URL || "";
const expectedFingerprint = (process.env.MAD4B_EXPECTED_CONNECTION_FINGERPRINT || "").trim().toLowerCase();

function fail(code, detail = {}) {
  process.stdout.write(JSON.stringify({ contract, ready: false, blocker: code, ...detail }, null, 2) + "\n");
  process.exit(1);
}
function canonicalBase(value) {
  let u;
  try { u = new URL(value); } catch { fail("edge_base_url_invalid"); }
  if (u.protocol !== "https:" || u.username || u.password || u.search || u.hash) fail("edge_base_url_not_https");
  u.pathname = u.pathname.replace(/\/+$/, "");
  return u;
}
function jsonContentType(res) {
  return (res.headers.get("content-type") || "").toLowerCase().includes("application/json");
}
function looksHtml(text) {
  return /<!doctype\s+html|<html[\s>]|<title>.*(?:cloudflare|access denied|just a moment|login)/is.test(text);
}
function sameOrigin(a, b) {
  const x = new URL(a), y = new URL(b);
  return x.protocol === y.protocol && x.hostname === y.hostname && (x.port || "443") === (y.port || "443");
}
async function request(url, { expectJson = false, allow = [200] } = {}) {
  const res = await fetch(url, {
    method: "GET",
    redirect: "manual",
    headers: { accept: expectJson ? "application/json" : "application/json, */*;q=0.1", "user-agent": "MAD4B-Connection-Edge-Acceptance/1" },
    signal: AbortSignal.timeout(12000),
  });
  const text = await res.text();
  const location = res.headers.get("location") || "";
  const auth = res.headers.get("www-authenticate") || "";
  if (res.status >= 300 && res.status < 400) fail("edge_unexpected_redirect", { url, status: res.status, location });
  if (/^basic\b/i.test(auth.trim())) fail("edge_private_basic_auth_gate", { url, status: res.status });
  if (looksHtml(text)) fail("edge_html_or_waf_challenge", { url, status: res.status });
  if (!allow.includes(res.status)) fail("edge_http_status_unexpected", { url, status: res.status });
  if (expectJson && !jsonContentType(res)) fail("edge_discovery_content_type_invalid", { url, status: res.status, content_type: res.headers.get("content-type") || "" });
  let json = null;
  if (expectJson) {
    try { json = JSON.parse(text); } catch { fail("edge_discovery_json_invalid", { url, status: res.status }); }
  }
  return { res, text, json, auth };
}

const base = canonicalBase(baseArg);
const origin = base.origin + base.pathname;
const resource = origin + "/wp-json/mcp/mad4b-chatgpt";
const issuer = origin + "/oauth/mcp";
const protectedMetadata = origin + "/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt";
const asMetadata = origin + "/.well-known/oauth-authorization-server/oauth/mcp";
const jwks = issuer + "/jwks";

const protectedResult = await request(protectedMetadata, { expectJson: true });
const p = protectedResult.json;
if (p.resource !== resource) fail("edge_resource_mismatch", { expected: resource, observed: p.resource || "" });
if (!Array.isArray(p.authorization_servers) || !p.authorization_servers.includes(issuer)) {
  fail("edge_authorization_server_mismatch", { expected: issuer, observed: p.authorization_servers || [] });
}

const asResult = await request(asMetadata, { expectJson: true });
const a = asResult.json;
if (a.issuer !== issuer) fail("edge_issuer_mismatch", { expected: issuer, observed: a.issuer || "" });
if (!a.jwks_uri || !sameOrigin(a.jwks_uri, issuer) || a.jwks_uri !== jwks) fail("edge_jwks_uri_mismatch", { expected: jwks, observed: a.jwks_uri || "" });
if (!Array.isArray(a.code_challenge_methods_supported) || !a.code_challenge_methods_supported.includes("S256")) fail("edge_pkce_s256_missing");
if (!a.token_endpoint || !sameOrigin(a.token_endpoint, issuer)) fail("edge_token_endpoint_invalid", { observed: a.token_endpoint || "" });

const jwksResult = await request(jwks, { expectJson: true });
if (!Array.isArray(jwksResult.json.keys) || jwksResult.json.keys.length < 1) fail("edge_jwks_empty");

const mcpResult = await request(resource, { expectJson: false, allow: [200, 400, 401, 405] });
if (mcpResult.res.status === 401 && !/^Bearer\b/i.test(mcpResult.auth.trim())) {
  fail("edge_mcp_bearer_challenge_missing", { status: mcpResult.res.status, challenge: mcpResult.auth });
}

const fpValues = [
  p.mad4b_connection_fingerprint || "",
  a.mad4b_connection_fingerprint || "",
].filter(Boolean).map(v => String(v).toLowerCase());
if (fpValues.length < 2 || new Set(fpValues).size !== 1) fail("connection_projection_drift", { fingerprints: fpValues });
const fingerprint = fpValues[0];
if (!/^[a-f0-9]{64}$/.test(fingerprint)) fail("connection_fingerprint_invalid", { fingerprint });
if (expectedFingerprint && fingerprint !== expectedFingerprint) fail("connection_fingerprint_mismatch", { expected: expectedFingerprint, observed: fingerprint });

const evidence = {
  contract,
  ready: true,
  base_url: origin,
  resource,
  issuer,
  endpoints: {
    protected_resource_metadata: protectedMetadata,
    authorization_server_metadata: asMetadata,
    jwks,
    mcp: resource,
  },
  connection_fingerprint: fingerprint,
  content_sha256: crypto.createHash("sha256").update(JSON.stringify({ p, a, jwks: jwksResult.json })).digest("hex"),
  checks: {
    resource_exact_match: true,
    authorization_servers_contains_exact_issuer: true,
    issuer_exact_match: true,
    jwks_uri_same_issuer: true,
    pkce_s256_advertised: true,
    no_wp_login_redirect: true,
    no_html_or_waf_challenge: true,
    no_private_or_basic_auth_gate: true,
    no_wrong_host_redirect: true,
    json_content_type_for_discovery: true,
    connection_fingerprint_match: true,
  },
};
process.stdout.write(JSON.stringify(evidence, null, 2) + "\n");
