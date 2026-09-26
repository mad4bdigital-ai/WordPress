#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SPEC = ROOT / "specs/007-content-intelligence-workflow-platform"
matrix = json.loads((SPEC / "bulk-closure-hardening.json").read_text(encoding="utf-8"))
contract = (SPEC / "contracts/bulk-runtime-closure-hardening.md").read_text(encoding="utf-8")
recovery = (ROOT / "tools/mad4b_recovery_plane.py").read_text(encoding="utf-8")
gate = json.loads((SPEC / "gate-graph.json").read_text(encoding="utf-8"))
critical_ci = (ROOT / ".github/workflows/feature-007-critical-kernel.yml").read_text(encoding="utf-8")
runtime_faults = (ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/durable-execution-runtime-faults.php").read_text(encoding="utf-8")

assert matrix["contract"] == "mad4b.feature007-bulk-closure-hardening.v1"
assert matrix["production_authorized"] is False
assert matrix["architecture_freeze"] is True
assert matrix["terminal_gate"] == "critical_kernel_vertical_slice_verified"
assert len(matrix["required_domains"]) == len(set(matrix["required_domains"])) >= 13
assert len(matrix["required_fault_fixtures"]) == len(set(matrix["required_fault_fixtures"])) >= 16
assert matrix["mutation_uncertainty_state"] == "MUTATED_BUT_EVIDENCE_UNCERTAIN"
fixture_evidence = matrix["fixture_evidence"]
assert set(fixture_evidence) == set(matrix["required_fault_fixtures"])
for fixture, row in fixture_evidence.items():
    assert row["repository_status"] in {"PROVEN", "PARTIAL", "PENDING"}, fixture
    assert row["live_status"] in {"NOT_REQUIRED", "PENDING", "PROVEN"}, fixture
    assert isinstance(row["evidence_refs"], list) and row["evidence_refs"], fixture
    if row["repository_status"] in {"PARTIAL", "PENDING"}:
        assert isinstance(row.get("remaining"), list) and row["remaining"], fixture
    if row["live_status"] == "PROVEN":
        assert any(
            not ref.startswith(("specs/", ".github/", "tools/", "wp-content/"))
            for ref in row["evidence_refs"]
        ), fixture

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
    "def recovery_journal_summary",
    "def reconcile_recovery_evidence",
    "recovery-journal",
    "mutation_started",
    "MUTATED_BUT_EVIDENCE_UNCERTAIN",
    "ROLLED_BACK_AFTER_FAILURE",
    "blind_retry_allowed",
    "expected_post_identity",
]:
    assert phrase in recovery, phrase

for phrase in [
    "mad4b_fence_epoch_stale",
    "mad4b_lease_heartbeat_fenced",
    "mad4b_lease_complete_fenced",
    "mad4b_idempotency_reconciliation_required",
    "mad4b_reconciliation_unverified",
    "mad4b_outbox_idempotency_conflict",
    "mad4b_inbox_event_conflict",
]:
    assert phrase in runtime_faults, phrase

assert "durable-execution-runtime-faults.php" in critical_ci
assert "Prove durable execution runtime fault semantics" in critical_ci

ids = {x["id"] for x in gate["gates"]}
assert matrix["terminal_gate"] in ids
print("FEATURE_007_BULK_CLOSURE_HARDENING: PASS")
