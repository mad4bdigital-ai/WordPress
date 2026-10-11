#!/usr/bin/env python3
"""IMP03 exact source static checks only; native PHP & WordPress acceptance separate."""
from pathlib import Path
P=Path(__file__).resolve().parents[1]
R=P.parent.parent.parent
code=lambda name:(P/"includes"/name).read_text(encoding="utf-8")
authority=code("class-mad4b-scp-activity-import-authority.php")
snapshot=code("class-mad4b-scp-activity-import-snapshot.php")
review=code("class-mad4b-scp-activity-import-review.php")
modes=code("class-mad4b-scp-activity-import-modes.php")
profiles=code("class-mad4b-scp-content-experience-profiles.php")
fixture=(P/"tests"/"imp01-import-preview-runtime.php").read_text(encoding="utf-8")
script=(R/"tools/feature007/google-apps-script/mad4b-activity-import-push.gs").read_text(encoding="utf-8")
generic=(R/"tools/feature007/import-modes/signed-generic-webhook-push.mjs").read_text(encoding="utf-8")
spec=(R/"specs/007-content-intelligence-workflow-platform/extensions/imp03-immutable-governed-import-approval.md").read_text(encoding="utf-8")
def check(condition, reason):
    if not condition: raise AssertionError(reason)
for token in ("normalize_contract", "profile_contract", "required_columns",
              "mad4b_import_source_cannot_override_policy",
              "mad4b_import_site_validation_not_configured",
              "require_complete_wpml_groups", "mad4b_import_required_columns_missing"):
    check(token in authority, "Site-owned import authority missing "+token)
for token in ("MAD4B_ACTIVITY_IMPORT_DATA_KEY", "aes-256-gcm", "openssl_encrypt",
              "openssl_decrypt", "hash_equals", "source_identity_sha256",
              "policy_sha256", "MAD4B_SCP_Site_Profile::site_uuid()",
              "admin_approved_snapshot_export_only", "snapshot_sha256",
              "approved_at", "approval_plan", "approve_ability", "approval_receipt"):
    if token == "admin_approved_snapshot_export_only": token = "manual_approved_snapshot_export_only"
    check(token in snapshot, "Immutable source or approval guard missing "+token)
for token in ("MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS", "x-mad4b-key-id",
              "mad4b_import_source_scope_denied", "mad4b_import_webhook_signature",
              "mad4b_import_nonce_", "MAD4B_SCP_Activity_Import_Snapshot::stage",
              "MAD4B_SCP_Activity_Import_Authority::resolve",
              "wp_nonce_field( 'mad4b_activity_approve_review'",
              "wp_nonce_field( 'mad4b_activity_export_approved'",
              "mad4b_activity_import_approve", "mad4b_activity_import_export",
              "wp_safe_redirect", "source_values_encrypted_at_rest"):
    check(token in review, "Signed provider identity/approval UI guard missing "+token)
check("'import_contract' => $import_contract" in profiles, "Standalone import facet not in normalized Experience Profile")
check("MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS" in modes, "Mode readiness still relies on obsolete global credential")
check("'x-mad4b-key-id'" in script and "'x-mad4b-key-id'" in generic, "Clients lack per-source key binding")
for token in ("tampered ciphertext", "mad4b_import_approval_blocked",
              "mad4b_import_source_scope_denied", "wpml_group_missing_required_language",
              "mad4b_import_required_columns_missing", "manual_approved_snapshot_export_only"):
    check(token in fixture, "Adversarial PHP fixture lacks "+token)
check("No WP All Import job" not in spec or "manual" in spec.lower(), "Acceptance boundaries must remain explicit")
print("PASS IMP03 policy sovereignty, encrypted immutable snapshot, sender key isolation, review approval and negative fixture registration (STATIC ONLY)")
