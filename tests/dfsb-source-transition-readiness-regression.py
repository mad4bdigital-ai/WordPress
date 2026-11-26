#!/usr/bin/env python3
"""Run exact source-owned DFSB and retirement fixtures, not live certification."""
import json
from pathlib import Path
import shutil
import sys
import subprocess

root = Path(__file__).resolve().parents[1]
php = shutil.which("php")
if not php:
    raise SystemExit("PHP CLI unavailable")
def run(name):
    path = root / name
    if not path.is_file() or path.is_symlink():
        raise SystemExit("Source fixture unavailable: " + name)
    result = subprocess.run([php, str(path)], cwd=root, text=True,
                            capture_output=True, timeout=60, check=False)
    if result.returncode:
        raise SystemExit(result.stdout + result.stderr)
    return result.stdout.strip().splitlines()

# The neutral adapter is an unmounted declaration in this PR. Check the
# actual Control Plane entrypoint and registry, not just fixture metadata.
scp = root / "wp-content/plugins/mad4b-site-control-plane"
entry = (scp / "mad4b-site-control-plane.php").read_text()
assert "includes/adapters/class-mad4b-scp-dfsb-adapter.php" not in entry
registry = json.loads((scp / "config/adapter-registration-policy.json").read_text())
assert "MAD4B_SCP_DFSB_Adapter" not in registry["approved_classes"]
assert "MAD4B_SCP_DFSB_Adapter" not in registry["default_classes"]
catalog = json.loads((scp / "config/cross-plugin-sources.json").read_text())
by_id = {row["id"]: row for row in catalog["sources"]}
legacy, neutral = (by_id[x] for x in ("etg-dynamic-filter-seo-bridge", "dynamic-filter-seo-bridge"))
assert legacy["review_state"] == "legacy_retirement_pending"
assert legacy["replacement_source_id"] == neutral["id"]
assert legacy["candidate_source_commit_sha"] == "76ef918d8485c42e92dd38b3725efd4de5471556"
# Reuse the exact signed source-pin contract. Do not derive expected SHA from
# the same mutable registry being tested or duplicate historical candidates.
subprocess.run([sys.executable, str(scp / "tests/dfsb-rc15-signed-review-source-pin.py")],
               cwd=root, check=True, timeout=60)
for row in (legacy, neutral):
    assert row["package_certification"] == "pending"
    assert all(row[k] == "" for k in ("source_commit_sha", "artifact_sha256", "artifact_version", "production_promotion_attestation"))
rows = run("tests/dfsb-read-adapter-original-hermetic.php")
assert rows[-1].startswith("PASS: DFSB_READ_ADAPTER_ORIGINAL ")
proofs = [json.loads(line) for line in rows if line.startswith("{")]
review = next(row for row in proofs if row["contract"] == "mad4b.dfsb.native-qualification-review.v1")
assert review["candidate_count"] == 24 and review["unclassified_count"] == 24
assert review["authorizing"] is False and review["actual_runtime_admission_verified"] is False
assert review["provider_policy_registered"] is False and review["neutral_class_in_approved_manifest"] is False
assert run("wp-content/plugins/mad4b-site-control-plane/tests/cross-plugin-deployment-runtime.php") == ["CROSS_PLUGIN_DEPLOYMENT: PASS"]
print("PASS: exact native read descriptor fixtures and legacy retirement discovery; no runtime authorization")
