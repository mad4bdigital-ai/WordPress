#!/usr/bin/env python3
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

runtime = (PLUGIN / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")
self_update = (PLUGIN / "includes/class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
main = (PLUGIN / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
remote = (PLUGIN / "includes/class-mad4b-scp-remote-operation-parity.php").read_text(encoding="utf-8")
registry = json.loads((PLUGIN / "config/operation-registry.json").read_text(encoding="utf-8"))

required = [
    "mad4b.runtime-convergence.v1",
    "mad4b/runtime-convergence-status",
    "mad4b/runtime-convergence-plan",
    "mad4b/runtime-convergence-apply",
    "mad4b_scp_runtime_convergence_phases",
    "CONVERGE STAGING RUNTIME",
    "MAD4B_SCP_Schema::install_or_upgrade",
    "MAD4B_SCP_Skill_Provider_Discovery::reconcile",
    "candidate_binding_mutation_performed",
    "provider_write_certification_performed",
    "production_mutation_performed",
]
for token in required:
    assert token in runtime, token

assert "MAD4B_SCP_Full_Staging_Authority::apply" not in runtime
assert "MAD4B_SCP_Staging_Write_Candidate_Binding::" not in runtime
assert "MAD4B_SCP_Provider_Behavioral_Recertification::" not in runtime
assert "MAD4B_SCP_Provider_Canary_Execution::" not in runtime
assert "runtime-convergence.php" in main
assert "mark_post_update_pending" in self_update
assert "'runtime_convergence' => array(" in remote
assert "production_policy' => 'deny" in remote

ops = {row["id"]: row for row in registry["operations"]}
op = ops["wordpress.runtime.converge"]
assert op["planner"] == "mad4b/runtime-convergence-plan"
assert op["executor"] == "mad4b/runtime-convergence-apply"
assert op["required_runtime"] is True
assert registry["default_mutation_policy"] == "deny"
assert registry["dynamic_provider_autopilot"]["environments"]["production"] == "observe_propose_only"
assert registry["dynamic_provider_autopilot"]["auto_enable_mutation"] is False

assert "MAD4B_SCP_Self_Update::status()" not in runtime

assert "MAD4B_SCP_Full_Staging_Authority::status" not in runtime

assert runtime.count("final class MAD4B_SCP_Runtime_Convergence") == 1
assert runtime.count("public static function status") == 1
assert runtime.count("public static function plan") == 1
assert runtime.count("public static function apply") == 1
assert runtime.count("MAD4B_SCP_Runtime_Convergence::boot();") == 1
assert "MAD4B_SCP_Staging_Write_Authority::candidate_binding_status" in runtime
assert "remove_filter( 'wp_register_ability_args'" in runtime
assert "mad4b_runtime_convergence_phase_cycle" in runtime
assert "mad4b_runtime_convergence_phase_dependency_missing" in runtime

print("runtime convergence contract: PASS")
