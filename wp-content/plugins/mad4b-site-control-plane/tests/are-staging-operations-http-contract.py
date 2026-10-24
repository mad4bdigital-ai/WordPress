#!/usr/bin/env python3
"""Read-only live operational acceptance for ARE Staging; no real credentials or mutations.

The test intentionally supplies malformed Bearer credentials and unauthenticated
MCP methods only. A successful HTTP 2xx on any unauthenticated MCP mutation fails.
It never accesses Production, stores tokens, installs plugins, or changes WordPress.
"""
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

SITE = "https://staging.allroyalegypt.com"
MCP = SITE + "/wp-json/mcp/mad4b-chatgpt"
DISCOVERY = SITE + "/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def call(label, path, expected, *, method="GET", body=None, bearer=None):
    uri = SITE + path
    if urllib.parse.urlsplit(uri).hostname != "staging.allroyalegypt.com":
        raise AssertionError("Origin must stay on isolated ARE Staging")
    headers = {"User-Agent": "MAD4B-ARE-Staging-Operations-Contract/1.0",
               "Accept": "application/json, text/event-stream"}
    if body is not None:
        headers["Content-Type"] = "application/json"
    if bearer is not None:
        headers["Authorization"] = bearer
    request = urllib.request.Request(
        uri, headers=headers, method=method,
        data=json.dumps(body, separators=(",", ":")).encode() if body is not None else None
    )
    start = time.monotonic()
    try:
        with OPENER.open(request, timeout=18) as response:
            status, data, response_headers = response.status, response.read(2_000_000), response.headers
    except urllib.error.HTTPError as e:
        status, data, response_headers = e.code, e.read(2_000_000), e.headers
    seconds = round(time.monotonic() - start, 3)
    print(f"{label}: HTTP {status}, {seconds}s", flush=True)
    if status != expected:
        raise AssertionError(f"{label}: expected HTTP {expected}, got {status}")
    try:
        payload = json.loads(data)
    except (ValueError, UnicodeDecodeError):
        payload = {}
    return payload, response_headers


def challenge(headers, label):
    value = headers.get("WWW-Authenticate", "")
    if not value.startswith("Bearer ") or DISCOVERY not in value:
        raise AssertionError(f"{label}: missing canonical WWW-Authenticate metadata challenge")


call("WordPress home", "/", 200)
call("REST discovery", "/wp-json/", 200)
for label, route in (
    ("Public posts query", "/wp-json/wp/v2/posts?per_page=1&_fields=id,type,status"),
    ("Public pages query", "/wp-json/wp/v2/pages?per_page=1&_fields=id,type,status"),
):
    result, _ = call(label, route, 200)
    assert isinstance(result, list), f"{label}: unexpected response type"

result, _ = call("Protected read denied anonymously", "/wp-json/mcp/mad4b-read", 403)
assert result.get("code") == "mad4b_transport_read_permission_denied"
_, headers = call("Anonymous ChatGPT resource", "/wp-json/mcp/mad4b-chatgpt", 401)
challenge(headers, "GET resource")
resource, _ = call("RFC9728 OAuth resource discovery",
                    "/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt", 200)
assert resource.get("resource") == MCP, "Discovery identifies the wrong resource"
assert resource.get("authorization_servers"), "No authorization server advertised"
assert "mad4b:read" in resource.get("scopes_supported", []), "Read scope absent"
internal, _ = call("Internal OAuth resource metadata",
                   "/wp-json/mad4b/v1/oauth-protected-resource", 200)
assert internal.get("resource") == MCP, "Internal OAuth metadata resource mismatch"

def jsonrpc(method, params):
    return {"jsonrpc": "2.0", "id": 431, "method": method, "params": params}


for label, m, params, token in (
    ("No bearer tools/list", "tools/list", {}, None),
    ("Malformed bearer tools/list", "tools/list", {}, "Bearer definitely-not-a-jwt"),
    ("Wrong authorization scheme", "tools/list", {}, "Basic deliberately-invalid"),
    ("No bearer tools/call", "tools/call",
     {"name": "mad4b-write-execute", "arguments": {}}, None),
):
    result, headers = call(
        label, "/wp-json/mcp/mad4b-chatgpt", 401,
        method="POST", body=jsonrpc(m, params), bearer=token
    )
    challenge(headers, label)
    if result.get("error") not in (
        "mad4b_oauth_bearer_required",
        "mad4b_oauth_bearer_malformed",
        "mad4b_oauth_jwt_header_invalid",
        "mad4b_oauth_jwt_malformed",
        "mad4b_oauth_jwt_decode_failed",
    ):
        raise AssertionError(f"{label}: unexpected auth error {result.get('error')!r}")

print("ARE_STAGING_OPERATIONS_HTTP: PASS")
