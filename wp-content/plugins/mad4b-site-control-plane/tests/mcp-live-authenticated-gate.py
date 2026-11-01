#!/usr/bin/env python3
"""Fail-closed MCP live invocation gate; never treats public 401 as authenticated health."""
import argparse
import json
import sys
import urllib.error
import urllib.request

def exchange(url, body, bearer, timeout=12):
    headers = {"Accept": "application/json, text/event-stream", "Content-Type": "application/json", "Authorization": "Bearer " + bearer, "MCP-Protocol-Version": "2025-06-18"}
    req = urllib.request.Request(url, data=json.dumps(body).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as res:
            return res.status, res.read(262144).decode("utf-8", "replace")
    except urllib.error.HTTPError as error:
        return error.code, error.read(262144).decode("utf-8", "replace")

def require_reply(status, text, method):
    if status != 200:
        raise RuntimeError("%s HTTP_%s: authenticated MCP invocation failed" % (method, status))
    if '"error"' in text and '"result"' not in text and "event: message" not in text:
        raise RuntimeError("%s returned MCP error" % method)
    if '"jsonrpc"' not in text and "event: message" not in text:
        raise RuntimeError("%s returned no valid JSON-RPC/SSE response" % method)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--url", required=True)
    ap.add_argument("--token-env", default="MAD4B_TEST_BEARER")
    args=ap.parse_args()
    import os
    token=os.getenv(args.token_env, "")
    if not token:
        print("BLOCKED: authenticated MCP live proof absent (no bearer supplied); NOT PASS")
        return 2
    if not args.url.startswith("https://"):
        print("BLOCKED: HTTPS MCP endpoint required")
        return 2
    requests=[
        ("initialize", {"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mad4b-ci-acceptance","version":"1"}}}),
        ("tools/list", {"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}),
    ]
    for name,payload in requests:
        status,content=exchange(args.url,payload,token)
        require_reply(status,content,name)
    print("PASS: AUTHENTICATED_MCP_INITIALIZE_AND_TOOLS_LIST")
    return 0

if __name__ == "__main__":
    try: sys.exit(main())
    except Exception as exc:
        print("FAIL: "+str(exc), file=sys.stderr)
        sys.exit(1)
