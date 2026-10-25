#!/usr/bin/env python3
"""Review exact newer runtime source without re-signing historical delivery evidence.

Original Feature 007 source SHA/bytes remain historical. This exception is
restricted to three already-governed executable runtime paths and depends on
their exact current Git blob being CI-reviewed with regression tests. It
never provides Site Owner approval or live runtime acceptance.
"""
import hashlib
import json
from pathlib import Path
import re
import subprocess

REVIEWABLE_RUNTIME = frozenset({
    "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php",
    "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-servers.php",
    "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-runtime-convergence.php",
})


def is_exact_reviewed_successor(root, path, contents):
    root = Path(root)
    if path not in REVIEWABLE_RUNTIME or not isinstance(contents, bytes):
        return False
    try:
        source = root / path
        if source.is_symlink() or not source.is_file() or source.read_bytes() != contents:
            return False
        registry = json.loads(
            (root / "governance/production-readiness/capability-coverage.json").read_text(encoding="utf-8")
        )
    except (OSError, ValueError, TypeError):
        return False

    header = b"blob " + str(len(contents)).encode("ascii") + b"\0"
    identity = hashlib.sha1(header + contents).hexdigest()
    tests_prefix = "wp-content/plugins/mad4b-site-control-plane/tests/"
    for entry in registry.get("reviewed_changes", []):
        if (entry.get("path") != path or entry.get("source_blob_sha") != identity
            or entry.get("status") != "reviewed_for_ci_only"
            or entry.get("production_authorized") is not False
            or entry.get("live_evidence_required") is not True):
            continue
        tests = entry.get("regression_tests", [])
        if (not isinstance(tests, list) or not tests
            or not all(isinstance(t, str) and
                       (t.startswith(tests_prefix) or t.startswith("tests/"))
                       and (root / t).is_file() and not (root / t).is_symlink()
                       for t in tests)):
            continue
        if not isinstance(entry.get("control_ids"), list) or len(entry["control_ids"]) < 2:
            continue
        return True
    return False

# Only these existing disposable QA fixtures may evolve alongside a newer
# reviewed runtime. Their historical evidence must *exactly* match the
# immutable PR base, and the new bytes must be the checked-out Git HEAD.
REVIEWABLE_TEST_FIXTURES = frozenset({
    "wp-content/plugins/mad4b-site-control-plane/tests/admin-pages-wordpress.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/g6-delivery-integrity.py",
    "wp-content/plugins/mad4b-site-control-plane/tests/g9-delivery-contract.py",
})
FEATURE_007_REVIEWED_BASE = "12199d0e8588dcc89d9e35fd8bac98ad6a1cc18f"
# The G6 admin-page inventory snapshot was independently bound to the
# integration's original master. G9 continues using the older Feature 007
# snapshot, so select this alternate ancestor only when the passed evidence
# is exactly the SHA/size of the original master admin fixture.
G6_ADMIN_SMOKE_REVIEWED_BASE = "68ce3a12bf1bcc476c7ca8ab58c696a7cdadb504"
G6_ADMIN_SMOKE_HISTORIC_SHA256 = "dac745b1e9617b76a755f690d918ab46be6924b495058cc62bcc542bde4aeab2"
G6_ADMIN_SMOKE_HISTORIC_BYTES = 18047



def is_exact_historical_test_successor(root, path, contents, historic_sha, historic_bytes=None):
    root = Path(root)
    if path not in REVIEWABLE_TEST_FIXTURES or not isinstance(contents, bytes):
        return False
    if not isinstance(historic_sha, str) or not re.fullmatch(r"[a-f0-9]{64}", historic_sha):
        return False
    try:
        baseline = FEATURE_007_REVIEWED_BASE
        if (path == "wp-content/plugins/mad4b-site-control-plane/tests/admin-pages-wordpress.php"
            and historic_sha == G6_ADMIN_SMOKE_HISTORIC_SHA256
            and historic_bytes == G6_ADMIN_SMOKE_HISTORIC_BYTES):
            baseline = G6_ADMIN_SMOKE_REVIEWED_BASE
        old = subprocess.check_output(
            ["git", "show", f"{baseline}:{path}"],
            cwd=root, stderr=subprocess.DEVNULL, timeout=8
        )
        current_commit = subprocess.check_output(
            ["git", "rev-parse", f"HEAD:{path}"],
            cwd=root, stderr=subprocess.DEVNULL, timeout=8
        ).decode().strip()
        current = (root / path).read_bytes()
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired):
        return False
    if current != contents or hashlib.sha256(old).hexdigest() != historic_sha:
        return False
    if historic_bytes is not None and (type(historic_bytes) is not int or len(old) != historic_bytes):
        return False
    blob = hashlib.sha1(b"blob " + str(len(contents)).encode("ascii") + b"\0" + contents).hexdigest()
    if current_commit != blob or contents == old:
        return False
    # A newer bootstrap is not a test-only escape hatch. The matching source
    # remains separately guarded by the production-readiness source registry.
    bootstrap = "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
    if not is_exact_reviewed_successor(root, bootstrap, (root / bootstrap).read_bytes()):
        return False
    if path.endswith("admin-pages-wordpress.php"):
        return b"'mad4b-direct-access-profile' => array( 'MAD4B_SCP_Direct_Profile', 'render_page'" in contents
    if path.endswith("g6-delivery-integrity.py"):
        return b"g6_delivery_source_evidence_drift" in contents and b"is_exact_reviewed_successor" in contents
    if path.endswith("g9-delivery-contract.py"):
        return b"G9_SOURCE_SHA_DRIFT" in contents and b"is_exact_reviewed_successor" in contents
    return False


def is_exact_ci_history_checkout_successor(root, path, contents, historic_sha, historic_bytes):
    """Allow only two full-history fetch-depth lines, without re-signing G9."""
    root = Path(root)
    if path != ".github/workflows/feature-007-spec-ci.yml":
        return False
    if not isinstance(contents, bytes):
        return False
    try:
        original = subprocess.check_output(
            ["git", "show", f"{FEATURE_007_REVIEWED_BASE}:{path}"],
            cwd=root, stderr=subprocess.DEVNULL, timeout=8
        )
        current = (root / path).read_bytes()
        git_blob = subprocess.check_output(
            ["git", "rev-parse", f"HEAD:{path}"],
            cwd=root, stderr=subprocess.DEVNULL, timeout=8,
        ).decode().strip()
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired):
        return False
    if (hashlib.sha256(original).hexdigest() != historic_sha
        or len(original) != historic_bytes or current != contents):
        return False
    original_text = original.decode("utf-8")
    only_allowed = original_text
    anchor = "          ref: ${{ github.event.pull_request.head.sha || github.sha }}\n"
    for job in ("g6-runtime:", "g9-resilience:"):
        start = only_allowed.find("\n  " + job)
        if start < 0:
            return False
        next_job = re.search(r"\n  [a-zA-Z0-9][a-zA-Z0-9_-]*:\n", only_allowed[start+len(job):])
        end = len(only_allowed) if next_job is None else start + len(job) + next_job.start()
        section = only_allowed[start:end]
        if section.count(anchor) != 1:
            return False
        changed = section.replace(anchor, anchor + "          fetch-depth: 0\n", 1)
        only_allowed = only_allowed[:start] + changed + only_allowed[end:]
    expected = only_allowed.encode("utf-8")
    blob = hashlib.sha1(b"blob " + str(len(contents)).encode("ascii") + b"\0" + contents).hexdigest()
    return expected == contents and git_blob == blob
