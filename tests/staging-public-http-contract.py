#!/usr/bin/env python3
"""Hermetic multi-business target scoping and public HTTPS transport contract."""
import contextlib
import copy
import importlib.util
import io
import json
from pathlib import Path
import tempfile

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("public_smoke", root / "tools/staging-public-http-smoke.py")
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
targets = [
    {"id": "retail", "origin": "https://shop.example.org", "environment": "staging",
     "routes": [["catalog", "/products/"]]},
    {"id": "education", "origin": "https://learn.example.net", "environment": "staging",
     "routes": [["courses", "/courses/"]]},
    {"id": "services", "origin": "https://practice.example.com", "environment": "staging"},
]
calls = []
def offline(label, path, *, target):
    calls.append((target["id"], path))
    return {"route": label, "http_status": 503, "response_body_retained": False}
real_fetch = mod.fetch
mod.fetch = offline
with tempfile.TemporaryDirectory() as folder, contextlib.redirect_stdout(io.StringIO()):
    assert mod.main([], folder) == [] and not calls
    records = mod.main(targets, folder)
    assert len(records) == 3 and len(calls) == 5
    for target, record in zip(targets, records):
        assert record["site"] == target["origin"] and record["site_id"] == target["id"]
        assert record["read_only"] is True
        for key in ("mutation_performed", "browser_runtime_executed",
                    "site_acceptance_certified", "authority_granted", "seo_publication_performed"):
            assert record[key] is False
        assert Path(folder, target["id"] + "-staging-public-smoke.json").exists()
    for patch in ({"origin": "http://shop.example.org"}, {"origin": "https://user:pass@shop.example.org"},
                  {"origin": "https://shop.example.org/"}, {"origin": "https://shop.example.org:443"},
                  {"environment": "production"}, {"id": "../escape"},
                  {"routes": [["admin", "/wp-admin/"]]}, {"routes": [["escape", "//foreign.example/"]]},
                  {"routes": [["query", "/?action=delete"]]}, {"routes": [["escape", "/%2fadmin"]]},
                  {"routes": [["traverse", "/one/../two"]]}):
        invalid = dict(targets[0], **patch)
        count = len(calls)
        try:
            mod.main([targets[1], invalid], folder)
        except ValueError:
            pass
        else:
            raise AssertionError(patch)
        assert len(calls) == count, "Entire scope must validate before any network request"
mod.fetch = real_fetch
try:
    mod.fetch("other", "/unapproved", target=targets[0])
except ValueError:
    pass
else:
    raise AssertionError("Unapproved route reached transport")
real_dns = mod.socket.getaddrinfo
for ip in ("127.0.0.1", "10.0.0.2", "169.254.169.254", "::1", "192.0.2.1"):
    mod.socket.getaddrinfo = lambda *a, ip=ip, **k: [(None, None, None, None, (ip, 443))]
    try:
        mod.public_endpoint("public.example")
    except ValueError:
        pass
    else:
        raise AssertionError("Private or non-global endpoint accepted")
mod.socket.getaddrinfo = lambda *a, **k: [(None, None, None, None, ("8.8.8.8", 443))]
assert mod.public_endpoint("public.example") == "8.8.8.8"
mod.socket.getaddrinfo = real_dns
transport = []
class Response:
    status = 302
    def read(self, limit):
        assert limit == mod.MAX_BODY
        return b""
    def getheaders(self):
        return [("Location", "http://127.0.0.1/private")]
class Connection:
    def __init__(self, host, address):
        transport.append((host, address))
    def request(self, method, path, headers):
        assert method == "GET" and path == "/products/"
        assert not any(k.lower() in ("cookie", "authorization") for k in headers)
    def getresponse(self):
        return Response()
    def close(self):
        pass
mod.public_endpoint = lambda host: "8.8.8.8"
mod.PinnedHTTPSConnection = Connection
with contextlib.redirect_stdout(io.StringIO()):
    assert mod.fetch("catalog", "/products/", target=targets[0])["http_status"] == 302
assert len(transport) == 1, "Redirect must never be followed"
print("CONFIGURED_STAGING_HTTP: PASS (three business fixtures; no default targets; scoped public GET)")
