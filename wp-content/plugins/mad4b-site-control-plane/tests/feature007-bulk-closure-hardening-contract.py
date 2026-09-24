#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SPEC = ROOT / "specs/007-content-intelligence-workflow-platform"
matrix = json.loads((SPEC / "bulk-closure-hardening.json").read_text(encoding="utf-8"))
contract = (SPEC / "contracts/bulk-runtime-closure-hardening.md").read_text(encoding="utf-8")
recovery = (ROOT / "tools/mad4b_recovery_plane.py").read_text(encoding="utf-8")
gate = json.loads((SPEC / "gate-graph.json").read_text(encoding="utf-8"))

assert matrix["contract"] == "mad4b.feature007-bulk-closure-hardening.v1"
assert matrix["production_authorized"] is False
assert matrix["architecture_freeze"] is True
assert matrix["terminal_gate"] == "critical_kernel_vertical_slice_verified"
assert len(matrix["required_domains"]) == len(set(matrix["required_domains"])) >= 13
assert len(matrix["required_fault_fixtures"]) == len(set(matrix["required_fault_fixtures"])) >= 16
assert matrix["mutation_uncertainty_state"] == "MUTATED_BUT_EVIDENCE_UNCERTAIN"

for phrase in [
    "MUTATED_BUT_EVIDENCE_UNCERTAIN",
    "Documentation-only abstraction growth is not a closure mechanism",
    "Backup existence alone is not readiness",
    "fixed executable + structured argv",
]:
    assert phrase in contract, phrase

# Recovery implementation must use atomic durable JSON persistence and a pre-mutation journal.
for phrase in [
    "def atomic_json_write",
    "recovery-journal",
    "mutation_started",
    "evidence_state",
]:
    assert phrase in recovery, phrase

ids = {x["id"] for x in gate["gates"]}
assert matrix["terminal_gate"] in ids
print("FEATURE_007_BULK_CLOSURE_HARDENING: PASS")
