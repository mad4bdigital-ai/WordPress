#!/usr/bin/env python3
from copy import deepcopy
from pathlib import Path
import importlib.util
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location("competitive_evidence",HERE/"competitive-evidence.py")
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
s=m.build_snapshot()
assert s["authorizing"] is False and s["runtime_certification_inferred"] is False and s["marketing_claims_promoted"] is False
assert len(s["packages"])==4 and len(s["capabilities"])==59
assert all(len(p["sha256"])==64 for p in s["packages"])
assert all(c["task_ids"] and c["evidence_ids"] and c["evidence_sources"] for c in s["capabilities"])
assert all(c["mad4b_foundation_paths"] and c["acceptance_requirements"] for c in s["capabilities"])
assert all(src["runtime_verified"] is False for c in s["capabilities"] for src in c["evidence_sources"])
same=m.semantic_diff(s,deepcopy(s)); assert same["added"]==[] and same["removed"]==[] and same["changed"]==[] and same["package_changed"]==[] and same["source_drift"] is False and same["task_drift"] is False
changed=deepcopy(s); changed["capabilities"][0]["implementation_status"]="DONE"; changed["generation_sha256"]="1"*64
delta=m.semantic_diff(s,changed); assert delta["changed"][0]["id"]==s["capabilities"][0]["id"] and "implementation_status" in delta["changed"][0]["fields"]
drift=deepcopy(s); drift["source_generation"]="0"*64; drift["generation_sha256"]="2"*64
assert m.semantic_diff(s,drift)["source_drift"] is True
package_drift=deepcopy(s); package_drift["packages"][0]["version"]="99.0"; package_drift["generation_sha256"]="3"*64
assert m.semantic_diff(s,package_drift)["package_changed"][0]["id"]==s["packages"][0]["id"]
task_drift=deepcopy(s); task_drift["task_generation"]="0"*64; task_drift["generation_sha256"]="4"*64
assert m.semantic_diff(s,task_drift)["task_drift"] is True
summary=m.operator_summary(s); assert summary["authorizing"] is False and summary["package_count"]==4 and summary["capability_count"]==59
assert all("runtime_parity_claimed" in r and "mad4b_foundation_paths" in r and "evidence_sources" in r for r in summary["capabilities"])
m.verify()
print("mad4b.competitive-evidence-tests.v1: PASS")
