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
'class-mad4b-scp-upgrade-continuity.php']
for name in critical:
    txt=(ROOT/'includes'/name).read_text(encoding='utf-8')
    assert 'MAD4B_SCP_Environment::effective()' in txt, name
connection=(ROOT/'includes/class-mad4b-scp-connection-status.php').read_text(encoding='utf-8')
for marker in ("'wordpress_environment'", "'wordpress_environment_explicit'", "'effective_environment'", "'environment_resolution'"):
    assert marker in connection
print('mad4b.effective-environment-authority.v1: PASS')
