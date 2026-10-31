#!/usr/bin/env python3
"""Non-authorizing QA31 peer PR config merge regression. Run against repository files."""
import json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
def load(name):
    return json.loads((root/"config"/name).read_text(encoding="utf-8"))
ops=load("operation-registry.json")["operations"]
ids=[x["id"] for x in ops]
assert len(ids)==len(set(ids)), "duplicate operation ID"
required={"company.create","company.repair","company.plugin-sync.request"}
assert required <= set(ids), "missing company operation"
byid={x["id"]:x for x in ops}
for key in ("company.create","company.repair"):
    op=byid[key]
    assert op["executor"]=="mad4b/start-your-company-run"
    assert op["generic_mutation_dispatch"] is False
    assert op["host_runner_required"] is True
assert byid["company.plugin-sync.request"]["supports"]==["queue_plan_only"]
assert byid["company.plugin-sync.request"]["generic_mutation_dispatch"] is False
assert byid["company.plugin-sync.request"]["executor"]=="mad4b/company-plugin-sync-request"
sources=load("cross-plugin-sources.json")["sources"]
assert len({x["id"] for x in sources})==len(sources), "duplicate source"
hub=[x for x in sources if x["id"]=="mad4b-content-hub"]
assert len(hub)==1
hub=hub[0]
assert hub["review_state"]=="needs_repository_owner_review"
assert hub["candidate_state"]=="STAGING_SOURCE_REVIEW_PENDING"
assert hub["package_certification"]=="pending"
assert hub["source_commit_sha"]=="" and hub["production_promotion_attestation"]==""
assert hub["environments"]==["staging"]
providers=load("provider-capability-contracts.json")["providers"]
expected={"jetengine":"jet-engine/jet-engine.php","jetsmartfilters":"jet-smart-filters/jet-smart-filters.php",
"rank_math":"seo-by-rank-math/rank-math.php","elementor":"elementor/elementor.php",
"litespeed":"litespeed-cache/litespeed-cache.php","polylang":"polylang/polylang.php",
"woocommerce":"woocommerce/woocommerce.php"}
for name, file in expected.items():
    entry=providers[name]
    assert entry["artifact_plugin_files"]==[file], (name, "artifact file mismatch")
    assert "capabilities" in entry and entry["capabilities"], (name, "capability catalog missing")
    assert not entry.get("production_mutation_authorized",False), "artifact hint authorized mutation"
# Batch preflight: availability of company operations is not execution authority.
governance=(root/"includes/class-mad4b-scp-company-readiness-governance.php").read_text()
runtime=(root/"includes/class-mad4b-scp-start-your-company-runtime.php").read_text()
for token in ("mad4b_company_cross_tenant_denied", "mad4b_company_assignment_required", "mad4b_manage_company_operations", "mad4b_propose_company_content", "host_binding_verified", "company_site_uuid"):
    assert token in governance + runtime, ("company boundary missing", token)
for token in ("MAD4B_Company_Readiness_Governance::guard($r)", "mad4b_company_binding_missing", "independent_readback", "stage_job_authorized", "current_user_can('activate_plugins')"):
    assert token in runtime, ("company execution boundary missing", token)
for token in ("permission_callback'=>array(__CLASS__,'guard')", "mad4b_company_cross_tenant_denied"):
    assert token in governance, ("readiness route boundary missing", token)
assert "tours.dev.mad4b.com" not in governance + runtime

print("QA31_PEER_PR_CONFIG_RECONCILIATION: PASS")
