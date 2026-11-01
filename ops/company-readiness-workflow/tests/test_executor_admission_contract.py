#!/usr/bin/env python3
"""Hermetic source checks for governed company VPS executor admission.

The test never calls a VPS, WordPress tenant, Docker or Coolify.
Passing is not live executor enrollment or deployment permission.
"""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
CONTRACT_PATH = ROOT / "ops/company-readiness-workflow/contracts/company-vps-executor-admission.v1.json"

contract = json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))
ops = json.loads((PLUGIN / "config/operation-registry.json").read_text(encoding="utf-8"))
runtime = (PLUGIN / "includes/class-mad4b-scp-start-your-company-runtime.php").read_text(encoding="utf-8")
stage_runtime = (ROOT / "ops/company-readiness-workflow/staging-mu/252-mad4b-start-your-company-runtime.php").read_text(encoding="utf-8")
queue = (PLUGIN / "includes/class-mad4b-scp-remote-work-queue.php").read_text(encoding="utf-8")
fixtures = (ROOT / "ops/company-readiness-workflow/tests/test_start_company_runtime.php").read_text(encoding="utf-8")
assert contract["contract"] == "mad4b.company-vps-executor-admission.v1"
assert contract["parent_operation"] == "company.repair"
assert contract["initial_state"] == "NOT_ENROLLED"
assert contract["rollout"]["phase"] == "source_contract_only"
assert contract["rollout"]["production_mutation_authorized"] is False
assert contract["rollout"]["source_review_is_not_deployment"] is True
assert contract["admission"]["deny_generic_command_transport"] is True
assert contract["admission"]["preserve_users_orders_media_secrets"] is True
assert contract["stage_result"]["require_independent_readback"] is True
assert contract["stage_result"]["require_provider_return_checkpoint"] is True
assert contract["stage_result"]["aggregate_completion"] == "all_13_verified_stage_receipts"
assert ops["generic_shell"] is False and ops["default_mutation_policy"] == "deny"
for op, support in (("company.create", "create"), ("company.repair", "repair_existing")):
    row = next(x for x in ops["operations"] if x["id"] == op)
    assert row["host_runner_required"] is True
    assert row["required_runtime"] is True
    assert row["supports"] == [support]
    assert row["retry_semantics"] == "readback_before_retry"
    assert row["generic_mutation_dispatch"] is False
assert len(contract["supported_semantic_actions"]) == 13
for action in contract["supported_semantic_actions"]:
    assert "'" + action + "'" in runtime, action
assert runtime == stage_runtime.replace("* Plugin Name:", "* Component:", 1)
for expected in (
    "mad4b.company-vps-executor.v1",
    "registered_wp_cli_stage_executor",
    "'apply_handler'",
    "'rollback_handler'",
    "'readback_handler'",
    "'restoration_ready'",
    "'probe_evidence_sha256'",
    "verified_company_executor_missing_or_stale",
):
    assert expected in runtime, expected
for negative in (
    "ready_label_alone_is_not_verified_executor",
    "registered_capabilities_required",
    "missing_rollback_denied",
    "missing_readback_denied",
    "unverified_restore_denied",
):
    assert negative in fixtures, negative
for required in (
    "mad4b_company_completion_evidence_invalid",
    "independent_readback",
    "postcondition_verified",
    "provider_returned",
):
    assert required in queue, required
print("GOVERNED_COMPANY_VPS_EXECUTOR_ADMISSION: PASS")
