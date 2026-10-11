from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
main=(ROOT/'mad4b-site-control-plane.php').read_text(encoding='utf-8')
assert "class-mad4b-scp-environment.php" in main
critical=[
'class-mad4b-scp-connection-status.php','class-mad4b-scp-staging-oauth-autoconfig.php','class-mad4b-scp-mcp-provider-isolation.php',
'class-mad4b-scp-runtime-convergence.php','class-mad4b-scp-skill-registry.php','class-mad4b-scp-skill-autoconfig.php',
'class-mad4b-scp-skill-runtime-certification.php','class-mad4b-scp-agent-registry.php','class-mad4b-scp-authorization.php',
'class-mad4b-scp-governance-abilities.php','class-mad4b-scp-approval-decision-admin.php','class-mad4b-scp-developer-runtime.php',
'class-mad4b-scp-developer-workspace.php','class-mad4b-scp-host-bridge.php','class-mad4b-scp-mcp-client-compatibility.php',
'class-mad4b-scp-local-oauth-browser-canary.php','class-mad4b-scp-policy.php',
'class-mad4b-scp-impact-policy.php','class-mad4b-scp-staging-write-authority.php','class-mad4b-scp-local-oauth-server.php',
'class-mad4b-scp-local-oauth-consent-ui.php','class-mad4b-scp-chatgpt-connection-admin-ui.php',
'class-mad4b-scp-external-handshake-evidence.php','class-mad4b-scp-query-monitor-evidence-bridge.php',
'class-mad4b-scp-upgrade-continuity.php','class-mad4b-scp-admin-query-performance.php',
'class-mad4b-scp-live-acceptance-observer.php','class-mad4b-scp-live-truth.php','class-mad4b-scp-google-drive-context.php',
'class-mad4b-scp-site-bootstrap.php','class-mad4b-scp-governed-runtime-gates.php']
for name in critical:
    txt=(ROOT/'includes'/name).read_text(encoding='utf-8')
    assert 'MAD4B_SCP_Environment::effective()' in txt, name
dynamic=(ROOT/'includes/adapters/class-mad4b-scp-dynamic-content-adapter.php').read_text(encoding='utf-8')
assert 'MAD4B_SCP_Environment' in dynamic and 'effective()' in dynamic
connection=(ROOT/'includes/class-mad4b-scp-connection-status.php').read_text(encoding='utf-8')
for marker in ("'wordpress_environment'", "'wordpress_environment_explicit'", "'effective_environment'", "'environment_resolution'"):
    assert marker in connection
print('mad4b.effective-environment-authority.v1: PASS')

site_profile=(ROOT/'includes/class-mad4b-scp-site-profile.php').read_text(encoding='utf-8')
explicit=site_profile.split('public static function wordpress_environment_explicit()',1)[1].split('public static function current_environment()',1)[0]
assert "if ( $explicit ) return true;" in explicit
assert "apply_filters( 'mad4b_scp_wordpress_environment_explicit', false" in explicit


admin=(ROOT/'includes/class-mad4b-scp-site-profile-admin.php').read_text(encoding='utf-8')
experience=(ROOT/'includes/class-mad4b-scp-admin-experience.php').read_text(encoding='utf-8')
for token in (
    "ENV_SYNC_PROFILE_ONLY = 'profile_only'",
    "ENV_SYNC_HOST_MANAGED = 'host_managed'",
    'host_environment_sync_assessment(',
    "'blocked_missing_deployment_binding'",
    "'blocked_profile_identity'",
    "'blocked_explicit_host_conflict'",
    "'blocked_production_or_invalid_target'",
    "'awaiting_host_bootstrap'",
    "'host_aligned'",
    "'environment_sync_mode' => $sync_mode",
    "'environment_sync_state' => $sync_state",
):
    assert token in site_profile, f'Host sync mode contract missing {token}'
assert "mad4b_site_profile_host_sync_production_denied" in site_profile
assert "mad4b_site_profile_environment_sync_mode_invalid" in site_profile
assert "get_option( self::OPTION, null )" in site_profile
for token in (
    "name=\"environment_sync_mode\"",
    "'environment_sync_mode' => isset( $_POST['environment_sync_mode'] )",
    "'environment_sync_state' => (string) $status['environment_sync_state']",
    "WP_ENVIRONMENT_TYPE",
    "MAD4B_SCP_DEPLOYMENT_BINDING",
):
    assert token in admin, f'Host-managed configuration UI missing {token}'
assert "Host-Managed Sync is pending or blocked" in experience
# A WordPress plugin cannot safely rewrite its bootstrap environment after
# wp_get_environment_type() has already cached the value in this request.
assert 'putenv(' not in admin
assert "file_put_contents( " not in admin
assert "define( 'WP_ENVIRONMENT_TYPE', 'staging' );" not in site_profile
print('mad4b.environment-host-sync-mode-static.v1: PASS')
