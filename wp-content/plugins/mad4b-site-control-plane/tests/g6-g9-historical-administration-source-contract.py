#!/usr/bin/env python3
"""Regression for two *different* historical Feature 007 admin smoke snapshots.

Never re-signs a G6/G9 delivery manifest, grants approval, or replaces
human/source review. An unexpected snapshot/current byte is rejected.
"""
from pathlib import Path
import hashlib
import json
import sys

ROOT = Path(__file__).resolve().parents[4]
TESTS = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests"
sys.path.insert(0, str(TESTS))
from historical_source_successor import is_exact_historical_test_successor

FIXTURE = "wp-content/plugins/mad4b-site-control-plane/tests/admin-pages-wordpress.php"
CURRENT = (ROOT / FIXTURE).read_bytes()
EXT = ROOT / "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience"
G6 = json.loads((EXT / "g6-delivery.json").read_text(encoding="utf-8"))
G9 = json.loads((EXT / "g9-delivery.json").read_text(encoding="utf-8"))
G6_RECORD = next(row for row in G6["test_paths"] if row["path"] == FIXTURE)
G9_FIXTURE = "wp-content/plugins/mad4b-site-control-plane/tests/g9-delivery-contract.py"
G9_CURRENT = (ROOT / G9_FIXTURE).read_bytes()
G9_RECORD = next(row for row in G9["evidence_integrity"] if row["path"] == G9_FIXTURE)

checks = [
    ("g6_reviewed_original_master_fixture",
     is_exact_historical_test_successor(ROOT, FIXTURE, CURRENT, G6_RECORD["sha256"], G6_RECORD["bytes"])),
    ("g9_historical_test_fixture_still_accepted",
     hashlib.sha256(G9_CURRENT).hexdigest() == G9_RECORD["sha256"] or
     is_exact_historical_test_successor(ROOT, G9_FIXTURE, G9_CURRENT, G9_RECORD["sha256"], G9_RECORD["bytes"])),
    ("g6_unknown_historic_sha_denied",
     not is_exact_historical_test_successor(ROOT, FIXTURE, CURRENT, "f" * 64, G6_RECORD["bytes"])),
    ("g6_unknown_historic_bytes_denied",
     not is_exact_historical_test_successor(ROOT, FIXTURE, CURRENT, G6_RECORD["sha256"], G6_RECORD["bytes"] + 1)),
    ("g6_changed_current_source_denied",
     not is_exact_historical_test_successor(ROOT, FIXTURE, CURRENT + b"unreviewed", G6_RECORD["sha256"], G6_RECORD["bytes"])),
    ("g9_changed_current_source_denied",
     not is_exact_historical_test_successor(ROOT, G9_FIXTURE, G9_CURRENT + b"unreviewed", G9_RECORD["sha256"], G9_RECORD["bytes"])),
    ("g6_unknown_fixture_path_denied",
     not is_exact_historical_test_successor(ROOT, "wp-content/plugins/mad4b-site-control-plane/tests/unknown.php", CURRENT, G6_RECORD["sha256"], G6_RECORD["bytes"])),
    ("g6_review_not_derived_from_current_file_sha",
     hashlib.sha256(CURRENT).hexdigest() != G6_RECORD["sha256"]),
]
for name, passed in checks:
    print(("PASS " if passed else "FAIL ") + name)
    if not passed:
        raise SystemExit(1)
print(f"SUMMARY pass={len(checks)} fail=0")
