#!/usr/bin/env python3
"""Static contract for G2 reviewed authority-reduction workflow."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "includes/class-mad4b-scp-g2-permission-changes.php"
SERVERS = ROOT / "includes/class-mad4b-scp-servers.php"
PLUGIN = ROOT / "mad4b-site-control-plane.php"

src = SOURCE.read_text(encoding="utf-8")
servers = SERVERS.read_text(encoding="utf-8")
plugin = PLUGIN.read_text(encoding="utf-8")

def require(needle: str, code: str) -> None:
    if needle not in src:
        raise SystemExit(code)

for ability in ("mad4b/agent-permission-plan", "mad4b/agent-permission-apply"):
    require(ability, "permission_ability_missing:" + ability)
    if ability not in servers:
        raise SystemExit("permission_admin_mount_missing:" + ability)

require("APPLY_AUTHORITY_REDUCTION", "explicit_confirmation_missing")
require("plan_sha256", "plan_binding_missing")
require("expected_revision", "revision_binding_missing")
require("resource_constraints_sha256", "grant_constraint_plan_binding_missing")
require("'current_status'", "subject_status_plan_binding_missing")
require("'authority_expansion' => false", "authority_expansion_boundary_missing")
require("'grant_creation_allowed' => false", "grant_creation_boundary_missing")
require("'enable_or_restore_allowed' => false", "enable_restore_boundary_missing")
require("'production_authorized' => false", "production_authority_boundary_missing")
require("array( 'staging', 'development', 'local' )", "nonproduction_environment_allowlist_missing")
require("mad4b_g2_permission_environment_denied", "production_environment_denial_missing")
require("MAD4B_SCP_Agent_Registry::disable_agent(", "disable_agent_path_missing")
require("MAD4B_SCP_Agent_Registry::set_subject_status(", "disable_subject_path_missing")
require("MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id(", "grant_revoke_path_missing")
require("MAD4B_SCP_Audit::record(", "append_only_audit_missing")

for forbidden in (
    "MAD4B_SCP_Agent_Registry::grant_ability(",
    "MAD4B_SCP_Agent_Registry::bind_subject(",
    "MAD4B_SCP_Agent_Registry::create_agent(",
    "'enabled' )",
    "'status' => 'enabled'",
    "wp_update_post(",
    "update_option(",
):
    if forbidden in src:
        raise SystemExit("authority_reduction_contains_forbidden_expansion:" + forbidden)

if "class-mad4b-scp-g2-permission-changes.php" not in plugin:
    raise SystemExit("permission_component_not_loaded")
if "MAD4B_SCP_G2_Permission_Changes::boot();" not in plugin:
    raise SystemExit("permission_component_not_booted")

print("G2_PERMISSION_REDUCTION_CONTRACT: PASS")
