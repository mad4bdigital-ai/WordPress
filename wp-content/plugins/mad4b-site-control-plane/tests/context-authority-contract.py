from pathlib import Path

root = Path(__file__).resolve().parents[1]
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
authority = (root / "includes/class-mad4b-scp-context-authority.php").read_text(encoding="utf-8")
drive = (root / "includes/class-mad4b-scp-google-drive-context.php").read_text(encoding="utf-8")
admin = (root / "includes/class-mad4b-scp-context-admin-ui.php").read_text(encoding="utf-8")
adapter = (root / "includes/adapters/class-mad4b-scp-context-adapter.php").read_text(encoding="utf-8")
registry = (root / "includes/class-mad4b-scp-adapter-registry.php").read_text(encoding="utf-8")

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
require(authority, "classification_confidence", "classification confidence")
require(authority, "authority_class", "authority class")
require(authority, "quality_score", "quality score")
require(authority, "review_asset", "human review contract")
require(authority, "needs_review_content_changed", "rescan review invalidation")
require(authority, "status'] = 'unavailable'", "missing asset retention")
require(authority, "not_seen_in_latest_scan", "missing asset reason")
require(authority, "brand_context_contains_unavailable_assets", "unavailable asset fail closed")
require(authority, "upsert_asset_from_provider", "provider registry refresh")
require(authority, "mark_asset_recreated", "replacement lineage")
require(authority, "mad4b/context-asset-review", "asset review audit")
require(authority, "mad4b_context_audit_not_ready", "audit fail-closed preflight")

# Google OAuth supports explicit read-only and read+write, never implicit upgrade.
require(drive, "const READ_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';", "Drive read-only scope")
require(drive, "const WRITE_SCOPE = 'https://www.googleapis.com/auth/drive';", "Drive read-write scope")
assert "https://www.googleapis.com/auth/drive.file" not in drive, "drive.file is not sufficient for governed existing-asset repair"
require(drive, "scope_is_allowed", "scope allowlist")
require(drive, "scope_allows_write", "write-scope detection")
require(drive, "mad4b_google_drive_write_scope_missing", "explicit OAuth write grant")
require(drive, "access_mode", "OAuth access mode binding")
require(drive, "requested_scope", "OAuth requested-scope binding")
require(drive, "mad4b_google_drive_oauth_site_binding_changed", "OAuth Site Profile binding")
require(drive, "mad4b_google_drive_oauth_redirect_binding_changed", "OAuth redirect binding")
require(drive, "aes-256-gcm", "encrypted token storage")
require(drive, "MAX_WRITE_BYTES", "bounded Drive write size")
require(drive, "mad4b_google_drive_root_write_forbidden", "no broad My Drive root writes")
require(drive, "mad4b_google_drive_asset_outside_selected_source", "selected-source write boundary")
require(drive, "mad4b_google_drive_asset_remote_stale", "remote stale hash guard")
require(drive, "mad4b_google_drive_recreate_requires_unavailable_asset", "recreate only missing assets")
require(drive, "create_asset", "Drive asset create")
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
require(adapter, "google_drive_write_scope_required", "write mount requires OAuth write capability")
require(adapter, "source_required_for_write", "write mount requires selected Context source")
require(adapter, "return false;", "provider certification override remains explicit")
assert "'content' => array(\n\t\t\t'context/" not in adapter, "Context Drive mutations must not mount on content surface"

# Guided UX makes OAuth capability vs MAD4B authority explicit.
require(admin, "Connect Read-only", "read-only connect UX")
require(admin, "Connect Read + Write", "read-write connect UX")
require(admin, "Upgrade to Read + Write", "explicit scope upgrade UX")
require(admin, "one-time approval", "governed write UX")
require(admin, "Folder Picker", "folder picker")
require(admin, "Governed Library", "governed library UX")
require(admin, "Task-only Source", "task-only source UX")
require(admin, "Quality and authority are separate", "quality/authority UX guidance")

print("mad4b.site-control-plane.context-authority-contract.v2: PASS")
