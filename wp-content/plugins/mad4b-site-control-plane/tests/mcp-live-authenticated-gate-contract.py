#!/usr/bin/env python3
"""Hermetic regression for fail-closed MCP acceptance checks."""
import importlib.util
from pathlib import Path
import contextlib
import io
import os
import sys
from unittest.mock import patch

p=Path(__file__).with_name("mcp-live-authenticated-gate.py")
spec=importlib.util.spec_from_file_location("mcp_gate",p)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
for code in (401,403,429,500,503):
    try: m.require_reply(code,'{"jsonrpc":"2.0","error":{"code":-32603}}',"tools/list")
    except RuntimeError: pass
    else: raise AssertionError("HTTP %s cannot pass" % code)
for text in ('', '<html>ok</html>', '{"error":"forbidden"}'):
    try: m.require_reply(200,text,"initialize")
    except RuntimeError: pass
    else: raise AssertionError("Non-MCP response cannot pass")
with patch.dict(os.environ,{"MAD4B_TEST_BEARER":""}):
    with patch.object(sys,"argv",["gate","--url","https://staging.example.test/wp-json/mcp/mad4b-chatgpt"]):
        with contextlib.redirect_stdout(io.StringIO()) as output:
            assert m.main()==2
            assert "NOT PASS" in output.getvalue()
print("PASS: MCP_LIVE_AUTHENTICATED_GATE_CONTRACT")
