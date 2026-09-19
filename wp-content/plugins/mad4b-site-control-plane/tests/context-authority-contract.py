from pathlib import Path

root = Path(__file__).resolve().parents[1]
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
plugin = (root / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
authority = (root / "includes/class-mad4b-scp-context-authority.php").read_text(encoding="utf-8")
drive = (root / "includes/class-mad4b-scp-google-drive-context.php").read_text(encoding="utf-8")
admin = (root / "includes/class-mad4b-scp-context-admin-ui.php").read_text(encoding="utf-8")
servers = (root / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")

def require(text, needle, label):
    assert needle in text, f"missing {label}: {needle}"

require(main, "class-mad4b-scp-context-authority.php", "context authority wiring")
require(main, "class-mad4b-scp-google-drive-context.php", "google drive wiring")
require(main, "class-mad4b-scp-context-admin-ui.php", "context admin wiring")
require(main, "MAD4B_SCP_Context_Authority::boot();", "context ability boot")
require(plugin, "MAD4B_SCP_Context_Admin_UI::boot();", "context admin boot")

require(authority, "const ABILITY = 'mad4b/context-authority-status';", "read status ability")
require(authority, "'readonly' => true", "read-only annotation")
require(authority, "'governed'", "governed source mode")
require(authority, "'task_attachment'", "task-only source mode")
require(authority, "context_fingerprint", "context fingerprint")
require(authority, "classification_confidence", "classification confidence")
require(authority, "نبرة الصوت", "Arabic classification signals")
require(authority, "authority_class", "authority class")
require(authority, "quality_score", "quality score")
require(authority, "mandatory_context_not_ready", "fail-closed mandatory context")
require(authority, "review_asset", "human review contract")
require(authority, "needs_review_content_changed", "rescan review invalidation")
require(authority, "remove_source", "bounded source removal")
require(authority, "mad4b/context-asset-review", "asset review audit")
require(authority, "mad4b/context-source-remove", "source removal audit")

require(drive, "https://www.googleapis.com/auth/drive.readonly", "Drive read-only OAuth scope")
assert "https://www.googleapis.com/auth/drive.file" not in drive, "Drive write scope must not be requested"
assert "https://www.googleapis.com/auth/drive\'" not in drive, "Full Drive scope must not be requested"
require(drive, "aes-256-gcm", "encrypted token storage")
require(drive, "mad4b_google_drive_client_secret_required_for_new_client", "safe OAuth client rotation")
require(drive, "refresh_token", "offline token refresh")
require(drive, "MAX_SCAN_FILES", "bounded file scan")
require(drive, "MAX_SCAN_FOLDERS", "bounded folder scan")
require(drive, "MAX_TEXT_BYTES", "bounded content extraction")
require(drive, "'read_only' => true", "read-only connection status")

status_body = drive.split("public static function connection_status()",1)[1].split("public static function authorization_url()",1)[0]
assert "'access_token' =>" not in status_body, "connection status must not expose access token"
assert "'refresh_token' =>" not in status_body, "connection status must not expose refresh token"

require(admin, "Connect Google Drive", "guided connection CTA")
require(admin, "Folder Picker", "folder picker")
require(admin, "Open a shared folder by ID", "shared-folder direct navigation")
require(admin, "Governed Library", "governed library UX")
require(admin, "Task-only Source", "task-only source UX")
require(admin, "Quality and authority are separate", "quality/authority UX guidance")
require(admin, "Action stopped safely", "fail-closed UX feedback")
require(admin, "Needs review", "review queue filter")
require(admin, "Remove Source", "source cleanup UX")

assert servers.count("mad4b/context-authority-status") == 2, "Context status must be mounted only on read and ChatGPT read catalogs"
write_section = servers.split("private static function core_write_candidates()",1)[1].split("private static function registered_adapter_write_candidates()",1)[0]
assert "mad4b/context-authority-status" not in write_section, "Context status must never be a write candidate"

print("mad4b.site-control-plane.context-authority-contract.v1: PASS")
