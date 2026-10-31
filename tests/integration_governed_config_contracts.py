#!/usr/bin/env python3
"""Positive and fail-closed shape checks for ten exact governance JSON assets."""
import json
import re
from pathlib import Path
root=Path(__file__).resolve().parents[1]/"wp-content/plugins/mad4b-site-control-plane/config"
def load(file,contract):
    doc=json.loads((root/file).read_text())
    assert doc["contract"]==contract, file
    return doc
a=load("adapter-registration-policy.json","mad4b.adapter-registration-policy.v1")
assert a["unknown_plugin_mutation"]=="deny"
assert a["new_adapter_without_review"]=="candidate_only"
assert a["scope"]=="bundled_built_in_classes_only"
assert "no_auto_trust" in a["callback_source_policy"]
b=load("central-staging-source.json","mad4b.central-staging-source.v1")
assert b["evidence_transport"]=="signed_server_readback" and b["signature_algorithm"]=="RS256"
assert b["host_origin_provider"]=="read_only_host_mount"
assert b["production_approval"]=="separate_governed_native_owner_batch"
assert not b["origin"] and b["evidence_freshness_seconds"]>0
c=load("runtime-release-policy.json","mad4b.runtime-release-policy.v1")
assert c["pair_certification_required"] and c["runtime_class_provenance_required"]
assert all(c["source_policy"][x] is False for x in ("caller_url_allowed","caller_path_allowed","caller_package_bytes_allowed"))
assert c["source_policy"]["archive_sha256_required"]
assert c["authority"]["production_auto_apply"] is False
d=load("semantic-content-field-contracts.json","mad4b.semantic-content-field-contracts.v1")
assert d["fallback_evidence_authorizing"] is False
assert d["default_unknown_policy"]=="review_if_brand_evidence"
assert len(d["abilities"])>=5
for name,value in d["abilities"].items():
    assert "/" in name and value["mode"] in ("exact_paths","object_fields","ticket_bound_target","content_bundle","keyed_value")
e=load("user-role-governance-contract.json","mad4b.wordpress-user-role-governance-blueprint.v1")
assert e["site_neutral"] and e["mode"]=="plan_only"
assert e["does_not_register_wp_roles"] and e["does_not_register_wp_capabilities"] and e["does_not_register_mutation_abilities"]
assert e["default_unavailable_behavior"]=="deny_and_return_plan_only"
f=load("skill-seed-manifest.json","mad4b.skill-seed-manifest.v1")
assert f["seed_version"]>=1 and len(f["skills"])>=10
keys=[(v["level"],v["target"],v["name"]) for v in f["skills"]]
assert len(keys)==len(set(keys)),"duplicate seed"
h=load("skill-provider-catalog.json","mad4b.skill-provider-catalog.v1")
assert len(h["packs"])>=10
for provider,items in h["packs"].items():
    assert provider and items and all(x.get("name") and x.get("level") for x in items)
i=load("certified-providers.json","mad4b.site-control-plane.certified-providers.v1")
assert i["certification_mode"]=="exact_packaged_version" and len(i["providers"])>=6
for name,provider in i["providers"].items():
    assert name and ((provider.get("plugin_file") and provider.get("version")) or (name=="wp-import-export" and provider.get("components")))
    if provider.get("archive_sha256"):assert re.fullmatch("[a-f0-9]{64}",provider["archive_sha256"])
j=load("provider-capability-contracts.json","mad4b.provider-capability-contracts.v1")
assert len(j["providers"])>=10
for provider_id,provider in j["providers"].items():
    assert provider["adapter_id"] and provider["capabilities"],provider_id
    for key,cap in provider["capabilities"].items():
        assert cap["risk"] in ("read","bounded_write","high_risk_write")
        assert isinstance(cap["probes"],list) and isinstance(cap["traits"],dict)
k=load("evidence/woocommerce-11.2.1-lab-package.json","mad4b.woocommerce.package-source-evidence.v1")
assert k["deployment_authorized"] is False and k["authorizing"] is False
assert k["zip_unsafe_entries"]==0 and k["critical_installed_matches"]>=14
assert re.fullmatch("[a-f0-9]{64}",k["archive_sha256"])
assert k["source"]=="wordpress_org_exact_version"
print("GOVERNED_CONFIG_CONTRACTS: PASS 10 exact JSON assets, no inferred permissions")
