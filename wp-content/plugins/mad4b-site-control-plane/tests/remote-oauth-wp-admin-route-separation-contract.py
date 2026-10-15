#!/usr/bin/env python3
"""Static contract for separate WordPress UI and remote delegated OAuth authority."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
entry=(root/"mad4b-site-control-plane.php").read_text()
light=(root/"includes/class-mad4b-scp-native-plugin-admin-menu.php").read_text()
remedy=(root/"includes/class-mad4b-scp-operational-remediation.php").read_text()
native=(root/"includes/class-mad4b-scp-deployment-binding-native.php").read_text()
oauth=(root/"includes/class-mad4b-scp-oauth-subject-user-bridge.php").read_text()
policy=(root/"includes/class-mad4b-scp-policy.php").read_text()
for x in ("wordpress_plugin_management_read", "MAD4B_SCP_Native_Plugin_Admin_Menu::boot()", "return;"):
    assert x in entry, "WP native read isolation"
for x in ("plugin_action_links_", "render_setup_action", "admin.php?page=mad4b-operational-remediation"):
    assert x in light, "GET route missing passive visible action"
assert "<form" not in light, "invalid nested native Plugins form"
assert "MAD4B_SCP_Deployment_Binding_Native::status()" not in light, "GET must not perform deep recovery"
for x in ("wp_nonce_field( 'mad4b_complete_staging_host_setup_v1'", "current_user_can( 'activate_plugins' )", "current_user_can( 'manage_options' )"):
    assert x in remedy, "full admin form missing required user and nonce restriction"
for x in ("check_admin_referer( self::WP_SETUP_NONCE )", "wordpress_setup_nonce_verified", "runtime_manifest_match", "bootstrap_from_verified_lifecycle"):
    assert x in native, "POST must preserve exact runtime and local user authority"
for x in ("verified_bearer_active()", "subject_allowed(", "user_is_enrolled(", "wp_set_current_user( $user_id )", "site_identity_mismatch"):
    assert x in oauth, "OAuth mapping must bind actual enrolled delegated user"
for x in ("current_user_can( 'manage_options' )", "current_user_can( 'edit_posts' )"):
    assert x in policy, "permission evaluator must consult current WordPress capabilities"
assert "wp_set_current_user(" not in light and "wp_set_current_user(" not in remedy
assert "MAD4B_SCP_Approval_Tickets::approve(" not in light
assert "MAD4B_SCP_Approval_Tickets::approve(" not in remedy
print("REMOTE_OAUTH_AND_WP_ADMIN_ROUTE_SEPARATION: PASS")
