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
