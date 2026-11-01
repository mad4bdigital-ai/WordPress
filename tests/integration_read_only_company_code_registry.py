#!/usr/bin/env python3
"""Guard additive, bounded Company Code Reconciliation source intake."""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
data = json.loads((PLUGIN / "config/operation-registry.json").read_text())
operations = {x["id"]: x for x in data["operations"]}
assert len(operations) == len(data["operations"]), "duplicate operation id"
for op in ("company.create", "company.repair", "company.plugin-sync.request"):
    assert op in operations, "original company operation removed: "+op
item = operations["company.code-reconciliation.plan"]
assert item["planner"] == "mad4b/company-code-reconciliation-plan"
assert item["executor"] == "mad4b/company-code-reconciliation-compare"
assert item["effect"] == "read_only" and item["side_effect_class"] == "none"
assert item["generic_mutation_dispatch"] is False
assert item["production_policy"] == "observe_propose_only"
assert item["signed_core_evidence_required"] is True
assert item["company_and_site_bound"] is True
assert item["pipeline_profile"] == "governed_read_only_v1"
profile = data["pipeline_profiles"]["governed_read_only_v1"]
assert [(x["id"],x["type"],x["required"]) for x in profile] == [
    ("discover","read_check",True),("native_plan","planner",True),
    ("exact_execute","executor",True),("readback","verification",True)]
core = (PLUGIN / "includes/class-mad4b-scp-operation-registry.php").read_text()
assert "mad4b_operation_read_only_executor_not_allowlisted" in core
assert "mad4b_operation_read_only_profile_unsafe" in core
assert "mad4b_operation_read_only_effect_invalid" in core
ability = (PLUGIN / "includes/class-mad4b-scp-company-code-reconciliation.php").read_text()
for text in ("MAD4B_SCP_Execution_Receipt::verify", "cryptographic_signature_verified",
             "resource_set_sha256", "target_fingerprint", "company_and_site_authority_verified",
             "'writes_to_sites' => false"):
    assert text in ability, text
main = (PLUGIN / "mad4b-site-control-plane.php").read_text()
assert main.count("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-company-code-reconciliation.php';") == 1
assert main.count("MAD4B_SCP_Company_Code_Reconciliation::boot();") == 1
assert data["generic_shell"] is False
assert data["default_mutation_policy"] == "deny"
print("READ_ONLY_COMPANY_CODE_ADDITIVE_REGISTRY: PASS 3 original operations retained, 1 read-only operation")
