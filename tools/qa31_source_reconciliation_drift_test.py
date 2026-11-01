#!/usr/bin/env python3
"""Synthetic, network-free drift evidence contract for QA31 Staging reconciliation."""
import hashlib
import json
import subprocess
import sys
import tempfile
from pathlib import Path

SCRIPT = Path(__file__).with_name("qa31_source_reconciliation.py")
SENTINEL = "SENSITIVE_LOCAL_TEST_ONLY_BODY_NOT_IN_REPORT"


def run(cmd, **kwargs):
    return subprocess.run(cmd, capture_output=True, text=True, check=False, **kwargs)


def invoke(stage, plugin, sha, target, drift=False):
    argv = [sys.executable, str(SCRIPT),
            "--staging-root", str(stage), "--github-root", str(plugin),
            "--expected-head", sha, "--out", str(target)]
    if drift:
        argv.append("--read-only-drift-report")
    return run(argv)


with tempfile.TemporaryDirectory(prefix="mad4b-qa31-drift-") as tmp:
    root = Path(tmp)
    checkout = root / "repo"
    source = checkout / "wp-content/plugins/mad4b-site-control-plane"
    stage = root / "staging"
    source.mkdir(parents=True)
    stage.mkdir()
    bootstrap = " * Version: 0.4.0-rc.118\n"
    (source / "mad4b-site-control-plane.php").write_text(bootstrap)
    (stage / "mad4b-site-control-plane.php").write_text(bootstrap)
    (source / "config").mkdir()
    (stage / "config").mkdir()
    (source / "config/policy.json").write_text('{"mode":"read_only"}\n')
    (stage / "config/policy.json").write_text('{"mode":"read_only"}\n')
    commit_rows = []
    for path in (stage / "mad4b-site-control-plane.php", stage / "config/policy.json"):
        data = path.read_bytes()
        commit_rows.append({"path":path.relative_to(stage).as_posix(),
                            "sha256":hashlib.sha256(data).hexdigest(),
                            "bytes":len(data)})
    manifest = {
        "control_plane_version":"0.4.0-rc.118",
        "source_commit_sha":"a"*40,
        "package_files":commit_rows
    }
    (stage / "MAD4B-BUILD-PROVENANCE.json").write_text(json.dumps(manifest))
    assert run(["git", "-C", str(checkout), "init", "-q"]).returncode == 0
    assert run(["git", "-C", str(checkout), "add", "."]).returncode == 0
    commit = run(["git", "-C", str(checkout), "-c", "user.name=QA",
                  "-c", "user.email=qa@example.invalid", "commit", "-qm", "fixture"])
    assert commit.returncode == 0, commit.stderr
    sha = run(["git", "-C", str(checkout), "rev-parse", "HEAD"]).stdout.strip()
    valid = root / "valid.json"
    r = invoke(stage, source, sha, valid)
    assert r.returncode == 0, r.stderr
    clean = json.loads(valid.read_text())
    assert clean["staging"]["manifest_mismatch_count"] == 0
    assert clean["manifest_verified"] is True and clean["authorizing"] is False
    assert clean["release_gate"] == "REVIEW_REQUIRED"

    (stage / "config/policy.json").write_text('{"mode":"modified", "secret":"'+SENTINEL+'"}')
    denied = root / "strict-denied.json"
    r = invoke(stage, source, sha, denied)
    assert r.returncode == 2 and not denied.exists(), "Strict manifest gate weakened"
    observed = root / "drift-evidence.json"
    r = invoke(stage, source, sha, observed, True)
    assert r.returncode == 3, (r.returncode, r.stderr)
    evidence_text = observed.read_text()
    evidence = json.loads(evidence_text)
    assert SENTINEL not in evidence_text, "Source content leaked into evidence"
    assert evidence["release_gate"] == "BLOCKED_RUNTIME_MANIFEST_DRIFT"
    assert evidence["staging"]["manifest_mismatch_count"] == 1
    assert evidence["staging"]["manifest_mismatch_paths"] == ["config/policy.json"]
    assert evidence["drift_report_only"] is True
    assert evidence["authorizing"] is False and evidence["production_authorized"] is False
    assert evidence["protected_source_divergence_count"] >= 1

    wrong_head = root / "wrong-head.json"
    r = invoke(stage, source, "b"*40, wrong_head, True)
    assert r.returncode == 2 and not wrong_head.exists(), "Unbound Git checkout accepted"

    (stage / "config/policy.json").unlink()
    missing = root / "missing.json"
    r = invoke(stage, source, sha, missing, True)
    assert r.returncode == 3 and json.loads(missing.read_text())["staging"]["manifest_mismatch_count"] == 1

print("QA31_READONLY_DRIFT_REPORT: PASS strict rejection, nonauthorizing report, exact HEAD, no source leakage")
