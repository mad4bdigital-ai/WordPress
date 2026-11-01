#!/usr/bin/env python3
"""Verify live acceptance DNS ordering without network calls or TLS changes."""
import ast
from pathlib import Path
import socket
from unittest.mock import patch

source = Path(__file__).with_name("are-staging-operations-http-contract.py")
tree = ast.parse(source.read_text(encoding="utf-8"))
function = next(n for n in tree.body if isinstance(n, ast.FunctionDef)
                and n.name == "reachable_address_order")
module = ast.Module(body=[function], type_ignores=[])
ns = {"socket": socket, "_SYSTEM_GETADDRINFO": None}
exec(compile(module, str(source), "exec"), ns)
ipv4 = (socket.AF_INET, socket.SOCK_STREAM, 6, "", ("192.0.2.1", 443))
ipv6 = (socket.AF_INET6, socket.SOCK_STREAM, 6, "", ("2001:db8::1", 443))
with patch.dict(ns, {"_SYSTEM_GETADDRINFO": lambda *args: [ipv6, ipv4]}):
    result = ns["reachable_address_order"]("example.invalid", 443)
    assert result == [ipv4, ipv6], "IPv4 should be attempted first"
with patch.dict(ns, {"_SYSTEM_GETADDRINFO": lambda *args: [ipv6]}):
    assert ns["reachable_address_order"]("example.invalid", 443) == [ipv6], "IPv6 fallback must remain"
with patch.dict(ns, {"_SYSTEM_GETADDRINFO": lambda *args: [ipv4]}):
    assert ns["reachable_address_order"]("example.invalid", 443) == [ipv4], "IPv4-only origin"
with patch.dict(ns, {"_SYSTEM_GETADDRINFO": lambda *args: []}):
    assert not ns["reachable_address_order"]("example.invalid", 443), "No fabricated addresses"
print("STAGING_LIVE_DNS_ORDER: PASS 4 hermetic assertions")
