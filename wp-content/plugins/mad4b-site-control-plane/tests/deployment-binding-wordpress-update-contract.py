#!/usr/bin/env python3
from pathlib import Path
root=Path("wp-content/plugins/mad4b-site-control-plane")
native=(root/"includes/class-mad4b-scp-deployment-binding-native.php").read_text()
update=(root/"includes/class-mad4b-scp-self-update.php").read_text()
server=(root/"includes/class-mad4b-scp-servers.php").read_text()
planner=(root/"includes/class-mad4b-scp-staging-write-planning-guard.php").read_text()
for s in (
    "admin_post_' . self::WP_SETUP_ACTION",
    "check_admin_referer( self::WP_SETUP_NONCE )",
    "wordpress_setup_nonce_verified",
    "private static function wordpress_update_bootstrap()",
    "MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status()",
    "runtime_manifest_match",
    "bootstrap_from_verified_lifecycle( 'mad4b/deployment-binding-native-wordpress-updated' )",
    "EXISTING_BINDING_REUSED",
    "current_user_can( 'activate_plugins' )",
):
    assert s in native, "Missing strict WordPress POST setup boundary: "+s
for s in (
    "native_update_action_link",
    "MAD4B_SCP_Deployment_Binding_Native::wordpress_setup_action_link",
    "native_host_setup_notice",
    "handle_native_update",
    "verify_archive",
    "apply_verified_archive",
    "check_admin_referer( 'mad4b_control_plane_native_update' )",
):
    assert s in update, "Missing WordPress native update lifecycle: "+s
for s in (
    "MAD4B_SCP_Staging_Write_Authority::effective()",
    "mad4b/approval-plan",
    "Use the compact governed write dispatcher",
    "'staging' !== sanitize_key",
    "self::ability_is_mounted( 'mad4b-write', $ability_name )",
):
    assert s in server, "Initial binding ticket discovery fails source gate: "+s
for s in (
    "validate_remote_plan_input(",
    "MAD4B_SCP_Authorization::authorize_mutation(",
    "MAD4B_SCP_Approval_Tickets::bind_ticket_to_current_candidate(",
):
    assert s in planner, "Remote planner no longer governed: "+s
assert "MAD4B_SCP_Deployment_Binding_Native::apply(" not in update
assert "MAD4B_SCP_Approval_Tickets::approve(" not in native
assert (root/"tests/deployment-binding-wordpress-update-runtime.php").is_file()
print("WORDPRESS_UPDATE_AND_TICKET_SOURCE_CONTRACT: PASS")
