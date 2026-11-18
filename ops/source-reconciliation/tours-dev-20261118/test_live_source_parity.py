#!/usr/bin/env python3
"""Small no-network regressions of Control Plane live-source parity gate."""
import hashlib
import importlib.util
import tempfile
from pathlib import Path

MODULE = Path(__file__).with_name("check_live_source_parity.py")
spec = importlib.util.spec_from_file_location("mad4b_source_parity", MODULE)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def sha(v):
    return hashlib.sha256(v).hexdigest()


def ensure(ok, name):
    if not ok:
        raise AssertionError(name)


with tempfile.TemporaryDirectory() as temp:
    root = Path(temp)
    (root / "includes").mkdir()
    (root / "includes" / "unit.php").write_bytes(b"<?php echo 1;\n")
    v = b"<?php echo 1;\n"
    inv = {"contract": mod.CONTRACT, "source_count": 1, "files": [
        {"path": "includes/unit.php", "sha256": sha(v), "comparison_master": "different"}]}
    a = mod.inspect(root, inv)
    ensure(a["status"] == "SOURCE_CONTINUITY_REVIEWED" and a["counts"]["identical"] == 1, "Exact candidate source")
    ensure(a["release_authorized"] is False, "Source continuity never grants a release")
    (root / "includes" / "unit.php").write_bytes(b"<?php echo 2;\n")
    a = mod.inspect(root, inv)
    ensure(a["status"] == "SOURCE_REVIEW_REQUIRED" and a["counts"]["changed_unreviewed"] == 1, "Changed unreviewed source blocked")
    decision = {"contract": "mad4b.source-parity-review-decisions.v1", "reviewed_differences": [
        {"path": "includes/unit.php", "source_sha256": sha(v), "candidate_sha256": sha(b"<?php echo 2;\n"), "action": "replace"}]}
    a = mod.inspect(root, inv, decision)
    ensure(a["status"] == "SOURCE_CONTINUITY_REVIEWED" and a["counts"]["reviewed_difference"] == 1, "Explicit hash-paired replacement")
    decision["reviewed_differences"][0]["source_sha256"] = sha(b"old stale input")
    ensure(mod.inspect(root, inv, decision)["status"] == "SOURCE_REVIEW_REQUIRED", "Stale source decision denied")
    (root / "includes" / "unit.php").unlink()
    ensure(mod.inspect(root, inv)["counts"]["missing_unreviewed"] == 1, "Missing file reported")
    decision["reviewed_differences"][0] = {"path":"includes/unit.php", "source_sha256":sha(v), "candidate_sha256":None,"action":"retire"}
    ensure(mod.inspect(root, inv, decision)["status"] == "SOURCE_CONTINUITY_REVIEWED", "Exact reviewed retirement only")
    inv["files"][0]["path"] = "../outside.php"
    try:mod.inspect(root, inv)
    except ValueError:pass
    else:raise AssertionError("Path traversal not rejected")
    inv["files"][0]["path"] = "includes/unit.php"
    (root / "includes" / "unit.php").symlink_to(root / "outside.php")
    try:mod.inspect(root, inv)
    except ValueError:pass
    else:raise AssertionError("Symlink not rejected")
    (root / "includes" / "unit.php").unlink()
    inv["source_count"] = 2
    try:mod.inspect(root, inv)
    except ValueError:pass
    else:raise AssertionError("Truncated or tampered source count not rejected")
print("PASS: exact source parity, explicit reviewed replacements, missing files, symlink/path traversal and no release authority")
