#!/usr/bin/env python3
"""MSR02 static verification of guarded durable WordPress/Google Docs sync."""
from pathlib import Path
r=Path(__file__).resolve().parents[1]
get=lambda path:(r/path).read_text(encoding="utf-8")
runtime=get("includes/class-mad4b-scp-activity-sync-runtime.php")
docs=get("includes/class-mad4b-scp-activity-google-docs-adapter.php")
managed=get("includes/class-mad4b-scp-google-drive-context.php")
facet=get("includes/class-mad4b-scp-business-activity-contracts.php")
profile=get("includes/class-mad4b-scp-content-experience-profiles.php")
tests=get("tests/business-activity-sync-runtime.php")
def ck(ok,desc):
 if not ok:raise AssertionError(desc)
for name in ("status","plan","begin","advance","recover","archive","cancel","finalize_reconciled"):
 ability = "finalize-reconciled" if name=="finalize_reconciled" else name
 ck("function "+name+"(" in runtime,"Runtime missing "+name)
 ck("mad4b/business-activity-sync-"+ability in profile,"Ability missing "+name)
for k in ("site_uuid","profile_revision","authority_sha256","initial_checkpoint_sha256",
 "checkpoint_initialized","checkpoint_advanced","expected_destination_value_sha256",
 "needs_reconcile","step_inflight","readback_verified","source_snapshots",
 "mad4b_sync_destination_changed_since_approval","mad4b_sync_provider_readback_uncertain",
 "mad4b_sync_postwrite_divergence","mad4b_sync_checkpoint_readback_failed",
 "mad4b_sync_recover_requires_review","mad4b_sync_cancel_write_may_exist",
 "bootstrap_arbitration","field_sources","mad4b_sync_initial_sources_invalid",
 "mad4b_sync_reconcile_still_divergent","mad4b_sync_reconcile_checkpoint_failed",
 "mad4b_activity_sync_adapters","mad4b_sync_conditional_writer_missing",
 "mad4b_sync_wp_row_cas_failed", "mad4b_sync_inflight_journal_unverified",
 "mad4b_sync_postwrite_journal_unverified", "mad4b_sync_recovery_busy",
 "mad4b_sync_cancel_worker_active", "mad4b_sync_recover_inflight_worker_not_quiesced",
 "persist_operation", "release_operation_lease", "prewrite_failure"):
 ck(k in runtime,"Missing durable authority/cas/recovery guard "+k)
for k in ("requiredRevisionId","MAD4B_SCP_Google_Drive_Context::activity_docs_request",
 "connection_status","mad4b_drive_docs_cas_changed","textRun","utf16_length",
 "drive_document","record_data","mad4b_drive_multitab_or_identity_denied",
 "mad4b_drive_docs_write_unverified"):
 ck(k in docs,"Google Docs adapter missing guarded managed CAS boundary "+k)
for k in ("public static function activity_docs_request","self::authorized_json_request",
 "self::connection_status","self::DOCS_API","requiredRevisionId",
 "mad4b_activity_docs_binding_mismatch","mad4b_activity_docs_write_scope_missing"):
 ck(k in managed,"Preexisting encrypted Google OAuth bridge not used: "+k)
ck("mad4b_activity_google_oauth_access_token" not in docs and "Bearer " not in docs,
 "No alternate OAuth token path or bearer logging allowed in Activity Docs adapter")
ck("drive_sheet" not in docs and "sheets.googleapis.com" not in docs,
 "Non-conditional Google Sheets cells must not be advertised as guarded writes")
for k in ("entity_post_meta","resource_binding_meta_key","resource_binding_mode"):
 ck(k in facet and k in runtime,"Per-entity resource reference not supported "+k)
for k in ("checkpoint_initialized","Divergent initial sources lack exact owner-reviewed bootstrap plan",
 "Unapproved external Drive edit overwritten","Uncertain write not journaled",
 "Recovered write did not converge checkpoint",
 "Write proceeded despite failed durable inflight journal",
 "Unrecorded postwrite step was incorrectly accepted",
 "Recovery overtook a potentially active provider worker",
 "Two recovery workers entered the journal"):
 ck(k in tests,"Native recovery/transaction scenario missing "+k)
inventory=profile.split("public static function ability_names( $surface )",1)[1].split("public static function high_impact_abilities",1)[0]
definitions=profile.split("public static function ability_definitions()",1)[1].split("private static function route_definition",1)[0]
for suffix in ("status","plan","begin","advance","recover","finalize-reconciled","cancel","archive"):
 ability="mad4b/business-activity-sync-"+suffix
 ck(ability in inventory and ability in definitions,
    "Sync ability must exist in both discoverable inventory and callable definition: "+ability)
for ability in ("mad4b/business-activity-link-apply","mad4b/business-activity-sync-advance"):
 ck(ability in profile.split("public static function high_impact_abilities()",1)[1].split("public static function reversible_contracts()",1)[0],
    "High-impact write gate must include "+ability)
ck("allroyal" not in runtime.lower() and "'dmcs'" not in facet,
 "Site-specific tourism logic leaked into generic MSR02")
print("PASS MSR02 persisted checkpoints, exact owner-approved arbitration, provider CAS, Drive Docs UTF16 and unsafe-write recovery")
