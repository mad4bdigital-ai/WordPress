#!/usr/bin/env python3
"""Sector-neutral market and activity facet source acceptance. Historical filename retained for CI references."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
def read(p): return (root/p).read_text(encoding="utf-8")
market=read("includes/class-mad4b-scp-market-growth-policies.php")
research=read("includes/class-mad4b-scp-market-content-exchange.php")
activity=read("includes/class-mad4b-scp-business-activity-contracts.php")
profiles=read("includes/class-mad4b-scp-content-experience-profiles.php")
dynamic=read("includes/adapters/class-mad4b-scp-dynamic-content-adapter.php")
context=read("includes/adapters/class-mad4b-scp-context-adapter.php")
retry=read("includes/class-mad4b-scp-recovery-attempt-budget.php")
media=read("includes/class-mad4b-scp-external-media-ingest.php")
media_adapter=read("includes/adapters/class-mad4b-scp-media-adapter.php")
def ck(ok,msg):
    if not ok: raise AssertionError(msg)
for group in ("competitors","suppliers","pricing_rules","media_rules","assistant_roles"):
    ck("'"+group+"'" in market,"Missing generic market group "+group)
for token in ("dmc_connections","feed_mappings","DMC_PLAN","DMC_EXPORT","DMC_IMPORT_PREPARE"):
    ck(token not in market and token not in dynamic and token not in research,
       "Tourism-specific DMC rules leaked into generic core: "+token)
for code in ("mad4b/business-activity-link-plan","mad4b/business-activity-link-apply",
             "mad4b/business-activity-sync-plan","'activity_contract' => $activity_contract"):
    ck(code in profiles,"Content Experience contract is missing: "+code)
for code in ("class MAD4B_SCP_Business_Activity_Contracts","function normalize(",
             "post_user_meta_key","user_post_meta_key","attribute_meta_keys","taxonomy_slugs",
             "function sync_plan(","google_drive","wordpress","site_uuid","plan_sha256",
             "function apply(","profile_authority_sha256","mad4b_activity_relation_conflict",
             "get_object_taxonomies","wp_insert_user","wp_insert_post"):
    ck(code in activity,"Dynamic activity facet missing "+code)
ck("'activity_contract' => $activity_contract" in profiles and "profile_for_ability" in profiles,
   "Contract not integrated into governed profile system")
ck("staging.allroyalegypt.com" not in activity and "'dmcs'" not in activity and "'drivers'" not in activity and "'guides'" not in activity,
   "Site-specific tourism name or hostname hardcoded in reusable business facet")
ck("media/import-external" in media_adapter and "media/import-plan" in media_adapter,
   "General media import not exposed by existing media adapter")
ck("media_handle_sideload" in media and "source_scope_sha256" in media and "mad4b_media_import_busy" in media,
   "Media source ingestion is not bounded and idempotent")
for marker in ("mad4b_retry_circuit_open","mad4b_retry_prior_not_reconciled","function reserve","function reset"):
    ck(marker in retry,"Persistent retry contract missing "+marker)
for method in ("brand_draft_create","materialize_brand_draft","reconcile_brand_materialization"):
    ck("guarded_brand_attempt( '"+method+"'" in context, "Brand retry guard absent for "+method)
ck("draft_without_supplier_contract_allowed" in research and "media_copied' => false" in research,
   "Competition research must remain independent from supplier contracts")
print("PASS generic competition/media, optional Content Experience business facet and persisted retry source contracts")
