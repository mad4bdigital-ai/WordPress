#!/usr/bin/env python3
"""Hermetic proof: external Staging evidence is fixed-origin, read-only, nonauthorizing.

No live network, credentials, publish operation, or browser acceptance.
"""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile

root = Path(__file__).resolve().parents[1]
source = root / "tools" / "etg-staging-public-http-smoke.py"
spec = importlib.util.spec_from_file_location("mad4b_dual_site_smoke", source)
assert spec is not None and spec.loader is not None
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

assert set(mod.SITES) == {"etg", "are"}
assert mod.SITES["etg"]["origin"] == "https://staging.egypttourgates.com"
assert mod.SITES["are"]["origin"] == "https://staging.allroyalegypt.com"
assert mod.SITES["etg"]["routes"][0] == ("homepage", "/")
assert mod.SITES["are"]["routes"][1] == ("rest_root", "/wp-json/")
assert len(mod.SITES["etg"]["routes"]) == 5
assert len(mod.SITES["are"]["routes"]) == 3

calls = []
def offline(label, path, *, origin=None):
    calls.append((label, path, origin))
    assert origin in {cfg["origin"] for cfg in mod.SITES.values()}
    assert path.startswith("/") and not path.startswith("//")
    return {"route": label, "http_status": 503,
            "error_class": "test_only", "response_body_retained": False}
mod.fetch = offline
with tempfile.TemporaryDirectory(prefix="mad4b-staging-readonly-") as tmp:
    current = Path.cwd()
    try:
        os.chdir(tmp)
        for site in ("etg", "are"):
            with contextlib.redirect_stdout(io.StringIO()):
                mod.main(site)
            output = Path(f"{site}-staging-public-smoke.json")
            assert output.exists()
            record = json.loads(output.read_text())
            assert record["contract"] == "mad4b.staging-external-public-smoke.v2"
            assert record["site_id"] == site
            assert record["site"] == mod.SITES[site]["origin"]
            assert record["read_only"] is True
            assert record["mutation_performed"] is False
            assert record["browser_runtime_executed"] is False
            assert record["site_acceptance_certified"] is False
            assert record["seo_publication_performed"] is False
            assert record["authority_granted"] is False
            assert len(record["observations"]) == len(mod.SITES[site]["routes"])
            assert all(item["http_status"] == 503 for item in record["observations"])
        try:
            mod.main("production")
        except ValueError:
            pass
        else:
            raise AssertionError("An unapproved target was accepted")
    finally:
        os.chdir(current)
assert len(calls) == sum(len(cfg["routes"]) for cfg in mod.SITES.values())
try:
    mod.fetch = None
    # Reject arbitrary origins at the request boundary before network I/O.
    # The actual fetch implementation is restored from a second private module.
    spec.loader.exec_module(mod)
    mod.fetch("homepage", "/", origin="https://example.com")
except ValueError:
    pass
else:
    raise AssertionError("Unapproved site origin bypassed fixed-origin guard")
print("STAGING_DUAL_SITE_HTTP_HERMETIC: PASS")
