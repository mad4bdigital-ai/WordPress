#!/usr/bin/env python3
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
config_path = root / "config" / "workflow-provider-contracts.json"
impl_path = root / "includes" / "class-mad4b-scp-workflow-providers.php"
main_path = root / "mad4b-site-control-plane.php"

config = json.loads(config_path.read_text(encoding="utf-8"))
impl = impl_path.read_text(encoding="utf-8")
main = main_path.read_text(encoding="utf-8")

if config.get("contract") != "mad4b.workflow-provider-contracts.v1":
    raise SystemExit("workflow provider contract identity mismatch")
if config.get("source_of_truth") != "mad4b_workflow_plan":
    raise SystemExit("MAD4B workflow plan must remain the source of truth")

bitflows = config.get("providers", {}).get("bitflows")
if not isinstance(bitflows, dict):
    raise SystemExit("Bit Flows must be registered as the first workflow provider")
if bitflows.get("role") != "execution_provider":
    raise SystemExit("Bit Flows must remain an execution provider, not the governance owner")

ops = bitflows.get("operations", {})
expected_read = {
    "list": "bitflows/list-flows",
    "get": "bitflows/get-flow",
    "execution_status": "bitflows/get-executions",
}
for op, ability in expected_read.items():
    if ops.get(op, {}).get("ability") != ability:
        raise SystemExit(f"workflow operation {op} is not bound to the expected governed read ability")

execute = ops.get("execute", {})
if execute.get("ability") != "bitflows/run-flow" or execute.get("risk") != "high_risk_write":
    raise SystemExit("Bit Flows execute mapping must remain high-risk and bound to the governed run-flow ability")
required = set(execute.get("requires", []))
for marker in {"provider_capability_certified", "exact_workflow_fingerprint", "exact_nhi_grant", "one_time_approval", "budget", "audit"}:
    if marker not in required:
        raise SystemExit(f"workflow execute missing governance requirement: {marker}")

for op in ("create", "enable", "disable", "retry", "cancel"):
    entry = ops.get(op, {})
    if entry.get("ability") is not None or entry.get("state") != "unavailable":
        raise SystemExit(f"uncertified workflow operation {op} must remain explicitly unavailable")

policy = bitflows.get("implementation_policy", {})
if policy.get("internal_database_writes") != "forbidden":
    raise SystemExit("workflow provider integration must forbid direct provider database writes")
if policy.get("governance_owner") != "mad4b":
    raise SystemExit("MAD4B must remain workflow governance owner")

for marker in (
    "wp_abilities_api_categories_init",
    "register_category",
    "mad4b-workflows",
    "mad4b/workflow-provider-status",
    "mad4b/workflow-plan",
    "non_authorizing",
    "plan_sha256",
    "mutation_performed",
    "authority_created",
):
    if marker not in impl:
        raise SystemExit(f"workflow provider implementation missing marker: {marker}")

for forbidden in ("update_option(", "$wpdb->", "Flow::", "FlowExecutor"):
    if forbidden in impl:
        raise SystemExit(f"provider-neutral workflow facade leaked provider mutation implementation: {forbidden}")

if "class-mad4b-scp-workflow-providers.php" not in main or "MAD4B_SCP_Workflow_Providers::boot();" not in main:
    raise SystemExit("main plugin does not load and boot workflow provider governance")

print("mad4b.workflow-provider-contract.v1: PASS")
