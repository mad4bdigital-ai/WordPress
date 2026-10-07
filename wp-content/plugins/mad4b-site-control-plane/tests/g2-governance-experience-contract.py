#!/usr/bin/env python3
"""Static contract for Feature 007 G2 repository implementation."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MAIN = ROOT / "mad4b-site-control-plane.php"
RUNTIME = ROOT / "includes/class-mad4b-scp-g2-governance-experience.php"
ADMIN = ROOT / "includes/class-mad4b-scp-admin-ui.php"
SERVERS = ROOT / "includes/class-mad4b-scp-servers.php"


def require(raw: str, needle: str, code: str) -> None:
    if needle not in raw:
        raise SystemExit(code)


runtime = RUNTIME.read_text(encoding="utf-8")
admin = ADMIN.read_text(encoding="utf-8")
servers = SERVERS.read_text(encoding="utf-8")
main = MAIN.read_text(encoding="utf-8")

for ability in (
    "mad4b/recovery-preview",
    "mad4b/agent-access-workspace",
    "mad4b/consent-profile-status",
    "mad4b/change-history-search",
):
    require(runtime, ability, "missing_g2_ability:" + ability)
    require(servers, ability, "g2_admin_mount_missing:" + ability)

require(runtime, "'readonly' => true", "g2_abilities_must_be_readonly")
require(runtime, "'destructive' => false", "g2_abilities_must_be_nondestructive")
require(runtime, "'authorizing' => false", "g2_projection_must_be_nonauthorizing")
require(runtime, "'mutation_performed' => false", "g2_projection_must_not_claim_mutation")
require(runtime, "'generic_full_access_supported' => false", "generic_full_access_must_be_denied")
require(runtime, "'new_scopes_require_external_consent' => true", "new_scope_reconsent_missing")
require(runtime, "'automatic_tool_mounts_allowed' => false", "automatic_tool_mounts_must_be_denied")
require(runtime, "'wildcard_grants_allowed' => false", "wildcard_grants_must_be_denied")
require(runtime, "'history_is_rollback_authority' => false", "history_must_not_equal_rollback")
require(runtime, "'rollback_payload_exposed' => false", "rollback_payload_must_stay_private")
require(runtime, "'secret_or_token_values_exposed' => false", "history_secret_redaction_missing")
require(runtime, "'approval_ticket_sha256'", "approval_ticket_digest_missing")
if "'approval_ticket_id' =>" in runtime:
    raise SystemExit("raw_approval_ticket_identifier_exposed")
require(runtime, "'execution_available_here' => false", "recovery_preview_must_not_execute")

for forbidden in (
    "MAD4B_SCP_Agent_Registry::grant_ability(",
    "MAD4B_SCP_Agent_Registry::set_subject_status(",
    "MAD4B_SCP_Mutation_Manager::undo_post_mutation(",
    "MAD4B_SCP_Reversible_Adapter_Mutations::undo(",
    "wp_update_post(",
    "update_option(",
    "delete_option(",
):
    if forbidden in runtime:
        raise SystemExit("g2_readonly_surface_contains_mutation:" + forbidden)

require(main, "class-mad4b-scp-g2-governance-experience.php", "g2_runtime_not_loaded")
require(main, "MAD4B_SCP_G2_Governance_Experience::boot();", "g2_runtime_not_booted")

for tab in ("Consent & Clients", "Change History", "Mutations & Recovery"):
    require(admin, tab, "g2_admin_tab_missing:" + tab)

require(admin, "Preview only. Undo still requires the existing exact mutation authority", "undo_ui_boundary_missing")
require(admin, "History is not rollback authority", "history_ui_boundary_missing")
require(admin, "New scopes require external consent", "consent_ui_boundary_missing")

print("G2_GOVERNANCE_EXPERIENCE_CONTRACT: PASS")
