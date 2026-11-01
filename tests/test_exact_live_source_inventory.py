#!/usr/bin/env python3
"""Hermetic regression for exact file + provenance inventory; no WordPress access."""
import importlib.util
import json
import tempfile
from pathlib import Path

MODULE = Path(__file__).resolve().parents[1] / "tools/exact_live_source_inventory.py"
spec = importlib.util.spec_from_file_location("inventory", MODULE)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    live = root/"live"
    source = root/"source"
    live.mkdir()
    source.mkdir()
    (live/"includes").mkdir()
    (source/"includes").mkdir()
    (source/"tests").mkdir()
    (live/"includes/a.php").write_text("<?php // v1\n")
    (source/"includes/a.php").write_text("<?php // v1\n")
    import hashlib
    blob=(live/"includes/a.php").read_bytes()
    manifest={"contract":"mad4b.build-provenance.v1",
              "control_plane_version":"0.4.0-rc.118",
              "source_commit_sha":"a"*40,
              "package_files":[{"path":"includes/a.php","bytes":len(blob),
                                "sha256":hashlib.sha256(blob).hexdigest()}]}
    row=manifest["package_files"][0]
    canonical=row["path"] + chr(0) + str(row["bytes"]) + chr(0) + row["sha256"] + chr(10)
    manifest["package_manifest_digest"]=hashlib.sha256(canonical.encode()).hexdigest()
    (live/"MAD4B-BUILD-PROVENANCE.json").write_text(json.dumps(manifest))
    initial=m.status(live,source,"b"*40)
    assert initial["release_ready"] and initial["observed"]["active_gaps"]==0
    (source/"tests/t.php").write_text("<?php // source only\n")
    (source/"includes/a.php").write_text("<?php // change\n")
    after=m.status(live,source,"b"*40)
    assert after["observed"]["different"]==1
    assert after["observed"]["source_only"]==1
    assert after["observed"]["active_gaps"]==1
    assert not after["release_ready"]
    (live/"includes/a.php").write_text("<?php // hotfix\n")
    drift=m.status(live,source,"b"*40)
    assert drift["manifest"]["mismatches"]==["includes/a.php"]
    assert not drift["release_ready"]
    assert drift["manifest"]["manifest_digest_valid"]
    manifest["package_files"][0]["path"]="../bad"
    (live/"MAD4B-BUILD-PROVENANCE.json").write_text(json.dumps(manifest))
    try:
        m.status(live,source,"b"*40)
    except ValueError as e:
        assert str(e)=="unsafe_manifest_path"
    else:
        raise AssertionError("traversal must fail")
print("EXACT_LIVE_SOURCE_INVENTORY_TEST: PASS")
