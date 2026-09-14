#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[1]
read = lambda rel: (root / rel).read_text(encoding='utf-8')
server = read('includes/class-mad4b-scp-local-oauth-server.php')
bridge = read('includes/class-mad4b-scp-oauth-resource-bridge.php')
compat = read('includes/class-mad4b-scp-mcp-client-compatibility.php')
handshake = read('includes/class-mad4b-scp-external-handshake-evidence.php')
connection_ui = read('includes/class-mad4b-scp-chatgpt-connection-admin-ui.php')
acceptance = read('includes/class-mad4b-scp-live-acceptance-observer.php')
request_scope = read('includes/class-mad4b-scp-mcp-request-scope.php')
metadata = read('includes/class-mad4b-scp-mcp-adapter-metadata-bridge.php')
transport = read('includes/class-mad4b-scp-transport-context.php')
skills_ui = read('includes/class-mad4b-scp-skills-admin-ui.php')
for label, text in [('local OAuth server', server), ('OAuth resource bridge', bridge), ('external handshake', handshake), ('ChatGPT connection UI', connection_ui)]:
    for marker in ["MAD4B_SCP_Site_Profile::origin_enrolled()", "MAD4B_SCP_Site_Profile::oauth_enabled()"]:
        if marker not in text: raise SystemExit(f'{label} is not bound to Site Profile OAuth enrollment: {marker}')
if "in_array( $environment, array( 'local', 'development', 'staging' ), true )" not in server: raise SystemExit('local OAuth server does not support the canonical non-production environment set')
if "'production' === $environment && self::production_approved()" not in server: raise SystemExit('local OAuth server lost separate Production approval')
if "'environment_allowed' => (bool) $environment_allowed" not in bridge: raise SystemExit('OAuth bridge does not expose normalized environment eligibility')
if "$environment_allowed = ! empty( $status['environment_allowed'] );" not in compat: raise SystemExit('MCP client compatibility reimplements environment authority instead of consuming bridge truth')
if "self::environment_allowed( $current_environment )" not in handshake or "MAD4B_SCP_Site_Profile::current_environment()" not in handshake: raise SystemExit('external handshake is not bound to the live Site Profile environment')
if "'environment' => 'staging'" in handshake: raise SystemExit('external handshake still hardcodes Staging evidence identity')
if "return 'staging' === $environment" in handshake: raise SystemExit('external handshake still gates capture to Staging')
if "'staging' !== $current_environment" in handshake: raise SystemExit('external handshake still rejects non-Staging enrolled environments')
if "'environment' => 'staging'" in acceptance: raise SystemExit('live acceptance observer still fabricates a Staging environment')
if 'not_exact_staging_origin' in acceptance: raise SystemExit('live acceptance blocker still encodes a deployment-specific Staging identity')
if "MAD4B_SCP_Site_Profile::nonproduction_governed( 'oauth' )" not in connection_ui: raise SystemExit('ChatGPT connection UI does not expose canary readiness from Site Profile truth')
if "$environment_ready = 'staging' === $environment" in connection_ui: raise SystemExit('ChatGPT connection UI still hardcodes Staging readiness')
for label, text in [('request scope', request_scope), ('metadata bridge', metadata), ('transport context', transport), ('skills UI', skills_ui)]:
    for forbidden in ['Exact-Staging request scope', 'governed MAD4B Staging MCP runtime', 'Staging ChatGPT exposes a stable registered write catalog', 'governed Staging App mapping']:
        if forbidden in text: raise SystemExit(f'{label} retained generalized-runtime Staging wording: {forbidden}')
if 'never affects\n * Production' in request_scope: raise SystemExit('request-scope documentation contradicts feature-bound Production behavior')
if 'exported capability remains Read' in skills_ui: raise SystemExit('Skills export UI contradicts dynamic Read / Read + Write capability behavior')
for text in [server, bridge, compat, handshake, connection_ui, acceptance, request_scope, metadata, transport, skills_ui]:
    for forbidden in ['staging.egypttourgates.com', 'egypttourgates.com', 'plugin_asdk_app_6aa05fa2f97481919c24b99855fadba2']:
        if forbidden in text: raise SystemExit(f'generalized runtime leaked tenant identity: {forbidden}')
print('mad4b.general-distribution.environment-contract.v1: PASS')
