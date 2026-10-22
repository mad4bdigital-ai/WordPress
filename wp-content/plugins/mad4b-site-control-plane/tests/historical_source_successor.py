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
