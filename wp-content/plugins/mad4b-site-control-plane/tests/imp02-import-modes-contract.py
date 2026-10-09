#!/usr/bin/env python3
"""IMP02 static safety contract; never claims Staging/WordPress success."""
from pathlib import Path
P=Path(__file__).resolve().parents[1]
ROOT=P.parent.parent.parent
modes=(P/"includes/class-mad4b-scp-activity-import-modes.php").read_text(encoding="utf8")
review=(P/"includes/class-mad4b-scp-activity-import-review.php").read_text(encoding="utf8")
profile=(P/"includes/class-mad4b-scp-content-experience-profiles.php").read_text(encoding="utf8")
activity=(P/"includes/class-mad4b-scp-business-activity-contracts.php").read_text(encoding="utf8")
script=(ROOT/"tools/feature007/import-modes/signed-generic-webhook-push.mjs").read_text(encoding="utf8")
spec=(ROOT/"specs/007-content-intelligence-workflow-platform/extensions/imp02-alternative-import-modes.md").read_text(encoding="utf8")
def demand(expr, reason):
    if not expr: raise AssertionError(reason)
ids=(
"admin_csv_upload", "admin_xlsx_convert", "wp_media_csv", "local_managed_file",
"https_csv_pull", "sftp_ftp_pull", "object_storage", "google_sheets_oauth",
"google_drive_file", "google_apps_script", "signed_generic_webhook",
"wordpress_authenticated_rest", "email_attachment", "wp_all_import_wizard",
"wp_all_import_from_url", "wp_all_import_manual_rerun", "wp_all_import_cron",
"wp_all_import_wpcli", "wp_all_import_auto_schedule", "wordpress_cron_worker",
"action_scheduler_worker", "woocommerce_product_csv", "governed_profile_apply",
)
for ident in ids: demand("'"+ident+"'" in modes, "Missing explicit Mode: "+ident)
demand(modes.count("self::mode( ")==len(ids), "Expected bounded 23 built-in Modes")
for token in ("mad4b_activity_import_mode_manifests", "no_implicit_production_writes",
              "auto_write_enabled' => false", "automated_write_certified'] = false",
              "fallback_requires_new_explicit_plan",
              "mad4b_import_mode_disabled_for_profile",
              "environment_allowed( array( 'staging' ) )"):
    demand(token in modes,"Mode safety invariant missing: "+token)
for token in ("'import_modes'","'preferred_mode'","'fallback_modes'",
              "'enabled_modes'","'manual_review_required'","'auto_execute' => false",
              "mad4b_activity_import_mode_policy_mismatch"):
    demand(token in activity,"Governed Activity Profile mode policy missing: "+token)
for token in ("business-activity-import-modes","business-activity-import-mode-plan",
              "class-mad4b-scp-activity-import-modes.php"):
    demand(token in profile, "MCP route/loader missing: "+token)
for token in ("admin_upload_csv", "check_admin_referer( 'mad4b_activity_csv_intake'",
              "is_uploaded_file(", "UPLOAD_ERR_OK", "wp_safe_redirect(",
              "archive_preview", "check_admin_referer( 'mad4b_activity_archive_review'",
              "mad4b_activity_import_archive", "ready_for_import_execution' => false",
              "wp_json_encode( $input )", "'source_mode'",
              "array( 'staging' )", "$source_mode = $sender['mode']"):
    demand(token in review, "Alternative review intake gate missing: "+token)
for token in ("createHmac", "randomBytes", "MAD4B_INTAKE_SECRET",
              "source_mode: 'signed_generic_webhook'", "https:", "provider_writes: 0"):
    demand(token in script,"Generic signed push example insufficient: "+token)
demand("all methods are runtime certified" not in spec.lower(),
       "Mode specification must not overclaim operational readiness")
print("PASS IMP02 23-mode discovery, governed per-site alternatives, CSV admin source, signed webhook and no-implicit-write source gates (STATIC)")
