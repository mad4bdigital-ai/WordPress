#!/usr/bin/env python3
import json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
config=json.loads((root/"config/operating-model-contracts.json").read_text(encoding="utf-8"))
impl=(root/"includes/class-mad4b-scp-operating-model.php").read_text(encoding="utf-8")
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8")
servers=(root/"includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
if config.get("contract")!="mad4b.operating-model-contracts.v1" or config.get("site_neutral") is not True: raise SystemExit("operating-model contract must be site-neutral")
serialized=json.dumps(config,sort_keys=True).lower()
for forbidden in ("egypttourgates","30843","location_jet","tour-types-jet","tour-styles-jet"):
    if forbidden in serialized or forbidden in impl.lower(): raise SystemExit(f"generic operating model leaked site-specific identifier: {forbidden}")
for key,contract in (("semantic_identity","mad4b.semantic-identity.v1"),("ownership_model","mad4b.ownership-model.v1"),("state_model","mad4b.desired-observed-state.v1"),("operation_plan","mad4b.operation-plan.v1"),("evidence_graph","mad4b.evidence-dependency-graph.v1")):
    if config.get(key,{}).get("contract")!=contract: raise SystemExit(f"{key} contract missing")
if config["operation_plan"].get("approval_binding")!="exact_plan_sha256_and_target_fingerprints": raise SystemExit("approval must bind exact plan and targets")
nodes=config["evidence_graph"]["nodes"]
if "template_parity" not in nodes["browser_acceptance"] or "browser_acceptance" not in nodes["seo_publication"] or "seo_publication" not in nodes["production_activation"] or "rollback_readiness" not in nodes["production_activation"]: raise SystemExit("release evidence dependency graph incomplete")
machine=config["candidate_state_machine"]
if machine.get("states")!=["UNBOUND","CANDIDATE_VERIFIED","BINDING_BOOTSTRAP_ALLOWED","BOUND","WRITE_RUNTIME_READY"]: raise SystemExit("candidate state machine invalid")
if "write_runtime_ready" in machine["transitions"][1].get("requires",[]): raise SystemExit("bootstrap depends on write runtime")
bridge=config["workflow_provider_bridge"]
if bridge.get("provider")!="bitflows" or bridge.get("state")!="blocked_pending_public_provider_contract" or bridge.get("direct_provider_database_writes")!="forbidden" or bridge.get("production_authority")!="forbidden": raise SystemExit("Bit Flows bridge fail-closed contract invalid")
boundaries=config.get("coverage_boundaries",{})
expected_boundaries={
    "generic_browser_assertion_expression_dsl":"partial",
    "persistent_universal_evidence_graph_store_query_api":"partial",
    "universal_ownership_driven_reconciliation_engine":"partial",
    "bitflows_addon_bridge_activation":"contracted_not_activated",
}
for boundary,state in expected_boundaries.items():
    if boundaries.get(boundary,{}).get("state")!=state: raise SystemExit(f"operating-model coverage boundary drifted: {boundary}")
markers=("mad4b/operating-model-status","mad4b/semantic-identity-map","mad4b/site-feature-bundle-validate","mad4b/state-diff","mad4b/operation-plan","mad4b/evidence-invalidation-plan","mad4b/invariant-evaluate","mad4b/candidate-state","mad4b/workflow-compile","plan_sha256","workflow_sha256","non_authorizing","mutation_performed","authority_created","mad4b_capability")
for marker in markers:
    if marker not in impl: raise SystemExit(f"implementation missing {marker}")
for forbidden in ("$wpdb->","update_option(","wp_insert_post(","FlowExecutor","_elementor_data"):
    if forbidden in impl: raise SystemExit(f"read-only operating model leaked mutation/provider code: {forbidden}")
if "class-mad4b-scp-operating-model.php" not in main or "MAD4B_SCP_Operating_Model::boot();" not in main: raise SystemExit("main plugin wiring missing")
for ability in markers[:9]:
    if ability not in servers: raise SystemExit(f"projection missing {ability}")
print("mad4b.operating-model-contract.v1: PASS")
