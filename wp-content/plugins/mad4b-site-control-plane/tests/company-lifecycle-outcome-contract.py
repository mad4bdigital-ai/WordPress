#!/usr/bin/env python3
"""Source contract for a single, non-authorizing company lifecycle outcome.

CI source evidence only; does not simulate Site Profile enrollment, operator
grants, an active VPS executor, or live WordPress/Coolify mutation.
"""
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
runtime = (ROOT / "includes/class-mad4b-scp-start-your-company-runtime.php").read_text()
queue = (ROOT / "includes/class-mad4b-scp-remote-work-queue.php").read_text()
main = (ROOT / "mad4b-site-control-plane.php").read_text()
registry = json.loads((ROOT / "config/operation-registry.json").read_text())

assert runtime.count("final class MAD4B_Start_Company_Runtime") == 1
assert runtime.count("public static function status($req)") == 1
assert "mad4b.start-your-company.outcome.v1" in runtime
assert "mad4b/start-your-company-outcome" in runtime
assert "self::PATH.'/status'" in runtime
assert "self::PATH.'/run'" in runtime
assert "self::PATH.'/resume'" in runtime
assert "self::guard($req)" in runtime
assert "($p['site_uuid']??'')!==$c['site_uuid']" in runtime
assert "($p['origin']??'')!==$c['origin']" in runtime
assert "($p['mode']??'')!==$plan['mode']" in runtime
assert "hash_equals((string)$runId,(string)$p['run_id'])" in runtime
assert "if(is_array($active))return true;" in runtime
assert "$complete=count($verified)===count(self::STAGES)" in runtime
assert "'successful'=>$complete" in runtime
assert "MAD4B_SCP_Operation_Journal::status(" in runtime
assert "'infrastructure_ownership'=>'UNVERIFIED_BY_NATIVE_RUNTIME'" in runtime
assert "'coolify_candidate_is_not_active_runtime'=>true" in runtime
assert "'mutation_performed'=>false" in runtime
assert "$queued['outcome']=$outcome;" in runtime
assert "'company.plugins.converge'" in runtime
assert "'company.configuration.converge'" in runtime
assert "'company.backup.ensure'" in runtime
assert "MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed" in queue

ops = {entry["id"]: entry for entry in registry["operations"]}
for name in ("company.create", "company.repair"):
    entry = ops[name]
    assert entry["executor"] == "mad4b/start-your-company-run"
    assert entry["planner"] == "mad4b/start-your-company-plan"
    assert entry["generic_mutation_dispatch"] is False
    assert entry["required_runtime"] is True
assert registry["generic_shell"] is False
assert registry["default_mutation_policy"] == "deny"
assert "class-mad4b-scp-start-your-company-runtime.php" in main
assert "Component: MAD4B Start Your Company" in runtime
assert "Plugin Name: MAD4B Start Your Company" not in runtime
print("COMPANY_LIFECYCLE_OUTCOME_SOURCE_CONTRACT: PASS")
