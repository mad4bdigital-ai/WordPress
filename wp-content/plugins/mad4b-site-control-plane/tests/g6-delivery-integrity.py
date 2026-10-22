#!/usr/bin/env python3
"""Validate exact G6 source evidence; this does not certify live AI or vector providers."""
import hashlib
import json
from pathlib import Path
from historical_source_successor import is_exact_reviewed_successor

root = Path(__file__).resolve().parents[4]
path = root / "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/g6-delivery.json"
d = json.loads(path.read_text(encoding="utf-8"))
assert d["contract"] == "mad4b.competitive-g6-delivery.v1"
assert d["implementation_pr"] == 287 and d["integration_pr"] == 258
for key in ("authorizing", "production_authorized", "provider_execution_certified",
            "vector_ingestion_certified", "dependency_execution_certified", "live_browser_acceptance"):
    assert d[key] is False, key
assert len(d["scope_task_ids"]) == 15
assert d["external_acceptance_required"]
seen = set()
for group in ("code_paths", "test_paths", "spec_paths"):
    assert d[group], group
    for item in d[group]:
        name = item["path"]
        assert name not in seen, "duplicate evidence source"
        assert name.startswith(("wp-content/plugins/mad4b-site-control-plane/",
                                "specs/007-content-intelligence-workflow-platform/")), name
        seen.add(name)
        data = (root / name).read_bytes()
        actual = hashlib.sha256(data).hexdigest()
        assert actual == item["sha256"] or is_exact_reviewed_successor(root, name, data), (
            "g6_delivery_source_evidence_drift:" + name
        )
        if actual != item["sha256"]:
            assert not is_exact_reviewed_successor(root, name, data + b"unreviewed"), (
                "g6_reviewed_successor_must_reject_unknown_runtime_bytes:" + name
            )
assert len(seen) >= 15, len(seen)
print("mad4b.feature007-g6-delivery-integrity.v1: PASS " + str(len(seen)))
