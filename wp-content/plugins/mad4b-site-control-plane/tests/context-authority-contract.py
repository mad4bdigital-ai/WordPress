from pathlib import Path

root = Path(__file__).resolve().parents[1]
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
authority = (root / "includes/class-mad4b-scp-context-authority.php").read_text(encoding="utf-8")
drive = (root / "includes/class-mad4b-scp-google-drive-context.php").read_text(encoding="utf-8")
admin = (root / "includes/class-mad4b-scp-context-admin-ui.php").read_text(encoding="utf-8")
adapter = (root / "includes/adapters/class-mad4b-scp-context-adapter.php").read_text(encoding="utf-8")
registry = (root / "includes/class-mad4b-scp-adapter-registry.php").read_text(encoding="utf-8")
servers = (root / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
preflight = (root / "includes/class-mad4b-scp-context-preflight.php").read_text(encoding="utf-8")

def require(text, needle, label):
    assert needle in text, f"missing {label}: {needle}"

# Lifecycle and adapter registration.
require(main, "class-mad4b-scp-context-authority.php", "context authority wiring")
require(main, "class-mad4b-scp-google-drive-context.php", "google drive wiring")
require(main, "class-mad4b-scp-context-adapter.php", "context adapter wiring")
require(main, "class-mad4b-scp-context-admin-ui.php", "context admin wiring")
require(main, "MAD4B_SCP_Context_Admin_UI::boot();", "context admin boot")
require(registry, "'MAD4B_SCP_Context_Adapter'", "context adapter registry registration")

# Context Authority semantics.
require(authority, "'governed'", "governed source mode")
require(authority, "'task_attachment'", "task-only source mode")
require(authority, "context_fingerprint", "context fingerprint")
require(authority, "$site = self::site_binding();", "Context reads bind to current Site Profile")
require(authority, "hash_equals( (string) $site['site_uuid']", "exact Site UUID read isolation")
require(authority, "classification_confidence", "classification confidence")
require(authority, "authority_class", "authority class")
require(authority, "quality_score", "quality score")
require(authority, "mad4b.context-quality-score.v2", "quality scoring v2 contract")
require(authority, "quality_profile_for_category", "category-aware quality profiles")
require(authority, "market_research", "market research scoring profile")
require(authority, "writer_reference", "writer reference scoring profile")
require(authority, "language_quality", "language-quality scoring dimension")
require(authority, "retrieval_quality", "retrieval-quality scoring dimension")
require(authority, "metadata_provisional", "metadata-only provisional scoring")
require(authority, "review_asset", "human review contract")
require(authority, "needs_review_content_changed", "rescan review invalidation")
require(authority, "status'] = 'unavailable'", "missing asset retention")
require(authority, "not_seen_in_latest_scan", "missing asset reason")
require(authority, "brand_context_contains_unavailable_assets", "unavailable asset fail closed")
require(authority, "upsert_asset_from_provider", "provider registry refresh")
require(authority, "register_recreated_asset", "atomic replacement registration")
require(authority, "mad4b_context_recreate_registry_commit_failed", "atomic replacement commit failure")
require(authority, "mark_asset_recreated", "legacy replacement lineage helper")
require(authority, "mad4b/context-asset-review", "asset review audit")
require(authority, "mad4b_context_audit_not_ready", "audit fail-closed preflight")

# Google OAuth supports explicit read-only and read+write, never implicit upgrade.
require(drive, "const READ_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';", "Drive read-only scope")
require(drive, "const WRITE_SCOPE = 'https://www.googleapis.com/auth/drive';", "Drive read-write scope")
assert "https://www.googleapis.com/auth/drive.file" not in drive, "drive.file is not sufficient for governed existing-asset repair"
require(drive, "scope_is_allowed", "scope allowlist")
require(drive, "scope_allows_write", "write-scope detection")
require(drive, "scope_matches_requested_mode", "exact OAuth mode matching")
require(drive, "mad4b_google_drive_write_scope_missing", "explicit OAuth write grant")
require(drive, "REVOKE_ENDPOINT", "Google OAuth revoke endpoint")
require(drive, "mad4b_google_drive_readonly_downgrade_requires_revoke", "least-privilege downgrade guard")
require(drive, "remote_revocation_confirmed", "disconnect revocation evidence")
require(drive, "access_mode", "OAuth access mode binding")
require(drive, "requested_scope", "OAuth requested-scope binding")
require(drive, "'include_granted_scopes' => 'false'", "non-incremental OAuth scope contract")
require(drive, "mad4b_google_drive_granted_scope_missing", "initial OAuth granted-scope evidence")
require(drive, "mad4b_google_drive_readonly_scope_escalated", "read-only least-privilege enforcement")
require(drive, "mad4b_google_drive_oauth_site_binding_changed", "OAuth Site Profile binding")
require(drive, "mad4b_google_drive_oauth_redirect_binding_changed", "OAuth redirect binding")
require(drive, "aes-256-gcm", "encrypted token storage")
require(drive, "MAX_WRITE_BYTES", "bounded Drive write size")
require(drive, "MAX_REVERSIBLE_TEXT_BYTES", "bounded reversible snapshot size")
require(drive, "reversible_update_state", "Drive update rollback snapshot")
require(drive, "restore_update_state", "Drive update rollback restore")
require(drive, "reversible_recreate_state", "Drive recreate rollback snapshot")
require(drive, "restore_recreate_state", "Drive recreate rollback restore")
require(drive, "asset_write_capabilities", "per-asset Drive actionability")
require(drive, "google_docs_rich_rollback_not_certified", "rich Google Docs update fail-closed")
require(drive, "provider_observed_text", "provider readback canonicalization")
require(drive, "compensate_created_file_failure", "post-create compensation")
require(drive, "mad4b_google_drive_post_create_compensation_failed", "orphan-risk compensation failure")
require(drive, "mad4b_google_drive_rollback_delete_failed", "bounded internal replacement delete failure")
require(authority, "rollback_recreated_asset", "registry recreation rollback")
require(drive, "mad4b_google_drive_root_write_forbidden", "no broad My Drive root writes")
require(drive, "mad4b_google_drive_asset_outside_selected_source", "selected-source write boundary")
require(drive, "mad4b_google_drive_asset_remote_stale", "remote stale hash guard")
require(drive, "mad4b_google_drive_recreate_requires_unavailable_asset", "recreate only missing assets")
require(drive, "provider_absence_from_metadata_result", "provider absence classification")
require(drive, "mad4b_google_drive_recreate_original_restored", "restored-original blocker")
require(drive, "mad4b_google_drive_recreate_absence_unverified", "unverified-absence blocker")
require(authority, "'parent_folder_id'", "asset parent-folder lineage")
require(drive, "recreate_parent_candidate", "recreate parent candidate")
require(drive, "resolve_recreate_target_folder", "exact recreate parent resolver")
require(drive, "mad4b_google_drive_recreate_parent_unavailable", "missing parent fail-closed")
require(drive, "mad4b_google_drive_recreate_parent_outside_source", "parent source-boundary fail-closed")
require(drive, "create_provider_file( $target_folder_id", "recreate writes to exact parent folder")
assert "create_provider_file( (string) $source['external_root_id'], $title" not in drive, "Recreate must never silently fall back to source root"
assert drive.count("self::assert_original_file_absent(") >= 2, "Recreate capture and execution must independently prove provider absence"
require(drive, "create_asset", "Drive asset create")
require(drive, "$target_folder_id = self::bounded_drive_id", "selected-folder create binding")
require(drive, "create_provider_file( $target_folder_id, $name", "create writes only to selected source folder")
require(drive, "verify_created_file_parent", "provider-confirmed created parent")
require(drive, "mad4b_google_drive_created_parent_mismatch", "created-parent mismatch fail-closed")
require(drive, "delete_provider_file_for_rollback( $file_id, $source, false )", "exact newly-created file compensation")
assert "'target_folder_id' => $target_folder_id" in drive, "create receipt must bind the exact selected folder"
require(drive, "update_asset", "Drive asset update")
require(drive, "recreate_asset", "Drive asset recreate")
assert "delete_asset(" not in drive, "Drive delete surface must remain absent"
assert "trash_asset(" not in drive, "Drive trash surface must remain absent"

status_body = drive.split("public static function connection_status()",1)[1].split("public static function authorization_url",1)[0]
assert "'access_token' =>" not in status_body, "connection status must not expose access token"
assert "'refresh_token' =>" not in status_body, "connection status must not expose refresh token"

# Governed adapter surfaces.
for ability in [
    "context/status",
    "context/assets",
    "context/google-drive-status",
    "context/create-drive-asset",
    "context/update-drive-asset",
    "context/recreate-drive-asset",
]:
    require(adapter, ability, f"Context adapter ability {ability}")
require(adapter, "'write' => array(", "dedicated write surface")
require(adapter, "mutation_ability_runtime_eligibility", "write runtime eligibility")
require(adapter, "mad4b.rollback.google-drive-context-update.v1", "Drive update reversible contract")
require(adapter, "mad4b.rollback.google-drive-context-recreate.v1", "Drive recreate reversible contract")
require(adapter, "capture_reversible_state", "Drive reversible capture")
require(adapter, "read_reversible_state", "Drive reversible readback")
require(adapter, "restore_reversible_state", "Drive reversible restore")
assert "context/create-drive-asset' => 'mad4b.rollback." not in adapter, "Drive create must not claim pre-target reversibility"
require(adapter, "google_drive_write_scope_required", "write mount requires OAuth write capability")
require(adapter, "source_required_for_write", "write mount requires selected Context source")
require(adapter, "return false;", "provider certification override remains explicit")
assert "'content' => array(\n\t\t\t'context/" not in adapter, "Context Drive mutations must not mount on content surface"

# Server mounting must stay structural: reads are projected from the read
# adapter surface; mutations are projected only through the dedicated write
# surface and are never hard-coded into content/admin core maps.
require(servers, "$registry->ability_names( 'read' )", "adapter read projection")
require(servers, "foreach ( array( 'content', 'admin', 'write' ) as $surface )", "dedicated adapter write projection")
require(servers, "if ( 'mad4b-write' === $server_id )", "dedicated write authority branch")
require(servers, "self::write_tools()", "runtime-gated write mount")
for ability in [
    "context/create-drive-asset",
    "context/update-drive-asset",
    "context/recreate-drive-asset",
]:
    assert ability not in servers, f"{ability} must be adapter-projected, never hard-coded into core server maps"

# Skill Context preflight is read-only, bounded and fail-closed.
require(preflight, "This service is read-only", "read-only Context preflight")
require(preflight, "MAX_CONTEXT_BYTES", "bounded Context envelope")
require(preflight, "required_context_asset_unreadable", "provider-read fail-closed")
require(preflight, "required_context_sets_missing", "missing required category fail-closed")
require(preflight, "review_status", "human review carried into Context receipt")
require(preflight, "'approved' !== ( isset( $asset['review_status'] )", "mandatory governed assets require human approval")

# Guided UX makes OAuth capability vs MAD4B authority explicit.
require(admin, "Connect Read-only", "read-only connect UX")
require(admin, "Connect Read + Write", "read-write connect UX")
require(admin, "Upgrade to Read + Write", "explicit scope upgrade UX")
require(admin, "Disconnect & Revoke Google Access", "least-privilege disconnect UX")
require(admin, "one-time approval", "governed write UX")
require(admin, "Folder Picker", "folder picker")
require(admin, "Governed Library", "governed library UX")
require(admin, "Task-only Source", "task-only source UX")
require(admin, "Quality and authority are separate", "quality/authority UX guidance")
require(admin, "Actionability", "per-asset actionability UX")
require(admin, "Reversible text update", "reversible update UX")
require(admin, "Reversible missing-asset recreation", "reversible recreate UX")
require(admin, "Write Governance Readiness", "write governance readiness UX")
require(admin, "MAD4B_SCP_Live_Truth::current_authority_status()", "live authority truth in UX")
require(admin, "Context Authority never reconciles grants automatically", "no automatic grant reconciliation UX")
require(admin, "runtime_authority_not_reconciled", "runtime reconciliation blocker UX")

print("mad4b.site-control-plane.context-authority-contract.v15: PASS")
