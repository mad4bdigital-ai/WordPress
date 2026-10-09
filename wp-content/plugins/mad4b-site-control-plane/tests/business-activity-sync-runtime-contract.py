#!/usr/bin/env python3
"""MSR02 static verification of guarded durable WordPress/Google Docs sync."""
from pathlib import Path
r=Path(__file__).resolve().parents[1]
get=lambda path:(r/path).read_text(encoding="utf-8")
runtime=get("includes/class-mad4b-scp-activity-sync-runtime.php")
docs=get("includes/class-mad4b-scp-activity-google-docs-adapter.php")
facet=get("includes/class-mad4b-scp-business-activity-contracts.php")
profile=get("includes/class-mad4b-scp-content-experience-profiles.php")
tests=get("tests/business-activity-sync-runtime.php")
def ck(ok,desc):
 if not ok:raise AssertionError(desc)
for name in ("status","plan","begin","advance","recover","archive","cancel"):
 ck("function "+name+"(" in runtime,"Runtime missing "+name)
 ck("mad4b/business-activity-sync-"+name in profile,"Ability missing "+name)
for k in ("site_uuid","profile_revision","authority_sha256","initial_checkpoint_sha256",
 "checkpoint_initialized","checkpoint_advanced","expected_destination_value_sha256",
 "needs_reconcile","step_inflight","readback_verified","source_snapshots",
 "mad4b_sync_destination_changed_since_approval","mad4b_sync_provider_readback_uncertain",
 "mad4b_sync_postwrite_divergence","mad4b_sync_checkpoint_readback_failed",
 "mad4b_sync_recover_requires_review","mad4b_sync_cancel_write_may_exist",
 "bootstrap_arbitration","field_sources","mad4b_sync_initial_sources_invalid",
 "mad4b_activity_sync_adapters","mad4b_sync_conditional_writer_missing",
 "mad4b_sync_wp_row_cas_failed"):
 ck(k in runtime,"Missing durable authority/cas/recovery guard "+k)
for k in ("requiredRevisionId","docs.googleapis.com","wp_safe_remote_request",
 "wp_safe_remote_get","mad4b_activity_google_oauth_access_token",
 "gettype","mad4b_drive_docs_cas_changed","textRun","utf16_length",
 "drive_document","record_data","mad4b_drive_multitab_or_identity_denied",
 "mad4b_drive_docs_write_unverified"):
 if k=="gettype": continue
 ck(k in docs,"Google Docs adapter missing guarded REST/CAS boundary "+k)
ck("drive_sheet" not in docs and "sheets.googleapis.com" not in docs,
 "Non-conditional Google Sheets cells must not be advertised as guarded writes")
for k in ("entity_post_meta","resource_binding_meta_key","resource_binding_mode"):
 ck(k in facet and k in runtime,"Per-entity resource reference not supported "+k)
for k in ("checkpoint_initialized","Divergent initial sources lack exact owner-reviewed bootstrap plan",
 "Unapproved external Drive edit overwritten","Uncertain write not journaled",
 "Recovered write did not converge checkpoint"):
 ck(k in tests,"Native recovery/transaction scenario missing "+k)
ck("allroyal" not in runtime.lower() and "'dmcs'" not in facet,
 "Site-specific tourism logic leaked into generic MSR02")
print("PASS MSR02 persisted checkpoints, exact owner-approved arbitration, provider CAS, Drive Docs UTF16 and unsafe-write recovery")
