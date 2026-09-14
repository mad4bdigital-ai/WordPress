#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'

def replace(rel, old, new, expected=1):
    path = PLUGIN / rel
    text = path.read_text(encoding='utf-8')
    count = text.count(old)
    if count != expected:
        raise SystemExit(f'{rel}: expected {expected} occurrence(s) of {old!r}, found {count}')
    path.write_text(text.replace(old, new), encoding='utf-8')

replace(
    'includes/class-mad4b-scp-local-oauth-server.php',
    "\tprivate static function environment_allowed() {\n\t\t$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';\n\t\treturn 'staging' === $environment || ( 'production' === $environment && self::production_approved() );\n\t}",
    "\tprivate static function environment_allowed() {\n\t\t$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );\n\t\t$profile_ready = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::oauth_enabled();\n\t\tif ( ! $profile_ready ) return false;\n\t\tif ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) return true;\n\t\treturn 'production' === $environment && self::production_approved();\n\t}"
)
replace(
    'includes/class-mad4b-scp-oauth-resource-bridge.php',
    "\t\t$https = self::resource_is_https();\n\t\t$environment_allowed = ( 'staging' === $environment ) || ( 'production' === $environment && $production_approved );",
    "\t\t$https = self::resource_is_https();\n\t\t$profile_ready = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::oauth_enabled();\n\t\t$environment_allowed = $profile_ready && ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) || ( 'production' === $environment && $production_approved ) );"
)
replace(
    'includes/class-mad4b-scp-oauth-resource-bridge.php',
    "\t\t\t'production_approved' => $production_approved,\n\t\t\t'authority_mode' => $mode,",
    "\t\t\t'production_approved' => $production_approved,\n\t\t\t'environment_allowed' => (bool) $environment_allowed,\n\t\t\t'authority_mode' => $mode,"
)
replace(
    'includes/class-mad4b-scp-mcp-client-compatibility.php',
    "\t\t$environment = isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : '';\n\t\t$environment_allowed = 'staging' === $environment || ( 'production' === $environment && ! empty( $status['production_approved'] ) );",
    "\t\t$environment_allowed = ! empty( $status['environment_allowed'] );"
)
replace(
    'includes/class-mad4b-scp-external-handshake-evidence.php',
    "\t\t$current_environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';\n\t\t$current_resource = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : '';\n\t\tif ( 'staging' !== $current_environment || 'staging' !== $environment ) { $base['status'] = 'environment_mismatch'; return $base; }",
    "\t\t$current_environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );\n\t\t$current_resource = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : '';\n\t\tif ( ! self::environment_allowed( $current_environment ) || '' === $environment || ! hash_equals( $current_environment, $environment ) ) { $base['status'] = 'environment_mismatch'; return $base; }"
)
replace(
    'includes/class-mad4b-scp-external-handshake-evidence.php',
    "\t\t$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';\n\t\treturn 'staging' === $environment;\n\t}\n\n\tprivate static function capture_initialize",
    "\t\t$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );\n\t\treturn self::environment_allowed( $environment );\n\t}\n\n\tprivate static function environment_allowed( $environment ) {\n\t\t$environment = sanitize_key( (string) $environment );\n\t\tif ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::oauth_enabled() ) return false;\n\t\tif ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) return true;\n\t\tif ( 'production' !== $environment || ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return false;\n\t\t$status = MAD4B_SCP_OAuth_Resource_Bridge::status();\n\t\treturn ! empty( $status['effective'] ) && ! empty( $status['production_approved'] );\n\t}\n\n\tprivate static function capture_initialize"
)
replace(
    'includes/class-mad4b-scp-external-handshake-evidence.php',
    '// The external schema is a stable registered Staging catalog. Exact equality',
    '// The external schema is a stable registered tenant-bound catalog. Exact equality'
)
replace(
    'includes/class-mad4b-scp-external-handshake-evidence.php',
    "\t\t\t'environment' => 'staging',",
    "\t\t\t'environment' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' ),"
)
replace(
    'includes/class-mad4b-scp-chatgpt-connection-admin-ui.php',
    "\t\t$production_readonly_enabled = 'production' === $environment && ! empty( $profile['production_readonly_enabled'] );\n\t\t$environment_ready = 'staging' === $environment || $production_readonly_enabled;\n\t\t$ready = $environment_ready && ! empty( $local['effective'] ) && ! empty( $bridge['effective'] ) && $cimd_ready && $gateway_registered;",
    "\t\t$production_readonly_enabled = 'production' === $environment && ! empty( $profile['production_readonly_enabled'] );\n\t\t$profile_oauth_ready = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::oauth_enabled();\n\t\t$environment_ready = $profile_oauth_ready && ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) || $production_readonly_enabled );\n\t\t$oauth_canary_available = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::nonproduction_governed( 'oauth' ) && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();\n\t\t$ready = $environment_ready && ! empty( $local['effective'] ) && ! empty( $bridge['effective'] ) && $cimd_ready && $gateway_registered;"
)
replace(
    'includes/class-mad4b-scp-chatgpt-connection-admin-ui.php',
    "\t\t\t'production_readonly_breakglass_enabled' => false,\n\t\t\t'server_url' => $server_url,",
    "\t\t\t'production_readonly_breakglass_enabled' => false,\n\t\t\t'profile_oauth_ready' => (bool) $profile_oauth_ready,\n\t\t\t'environment_ready' => (bool) $environment_ready,\n\t\t\t'oauth_canary_available' => (bool) $oauth_canary_available,\n\t\t\t'server_url' => $server_url,"
)
replace(
    'includes/class-mad4b-scp-chatgpt-connection-admin-ui.php',
    "<?php if ( 'staging' === $status['environment'] ) : ?>",
    "<?php if ( ! empty( $status['oauth_canary_available'] ) ) : ?>"
)
replace(
    'includes/class-mad4b-scp-chatgpt-connection-admin-ui.php',
    'Production read-only mode never mounts content mutation, mad4b-write, mad4b-admin or mad4b-breakglass; Staging governed writes remain a separate exact-origin authority.',
    'Production read-only mode never mounts content mutation, mad4b-write, mad4b-admin or mad4b-breakglass; governed writes remain a separate exact Site Profile authority.'
)
replace(
    'includes/class-mad4b-scp-live-acceptance-observer.php',
    "return array( 'environment' => 'staging', 'resource' => $resource,",
    "return array( 'environment' => ( class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' ) ), 'resource' => $resource,"
)
replace(
    'includes/class-mad4b-scp-live-acceptance-observer.php',
    "array( 'not_exact_staging_origin' )",
    "array( 'not_enrolled_governed_nonproduction_origin' )"
)
replace(
    'includes/class-mad4b-scp-mcp-adapter-metadata-bridge.php',
    'Official WordPress MCP Adapter used by the governed MAD4B Staging MCP runtime.',
    'Official WordPress MCP Adapter used by the governed MAD4B Site Profile runtime.'
)
replace(
    'includes/class-mad4b-scp-mcp-request-scope.php',
    ' * Exact-Staging request scope for the official MCP Adapter runtime.',
    ' * Site-Profile-governed request scope for the official MCP Adapter runtime.'
)
replace(
    'includes/class-mad4b-scp-mcp-request-scope.php',
    " * settings, never replaces a foreign Adapter runtime, and never affects\n * Production.",
    " * settings, and never replaces a foreign Adapter runtime. Eligibility is bound\n * to the exact enrolled Site Profile and its managed-runtime feature."
)
replace(
    'includes/class-mad4b-scp-transport-context.php',
    '// Staging ChatGPT exposes a stable registered write catalog so provider',
    '// The tenant-bound ChatGPT surface exposes a stable registered write catalog so provider'
)
replace(
    'includes/class-mad4b-scp-skills-admin-ui.php',
    'On Staging the Control Plane enables the Skill editor automatically unless an explicit MAD4B_SKILLS_EDITOR_ENABLED=false kill-switch is present. Production is never auto-enabled and requires both MAD4B_SKILLS_EDITOR_ENABLED=true and MAD4B_SKILLS_PRODUCTION_EDITOR_ENABLED=true. These switches do not enable MCP mutation.',
    'On an explicitly enrolled non-production Site Profile the Control Plane enables the Skill editor automatically unless an explicit MAD4B_SKILLS_EDITOR_ENABLED=false kill-switch is present. Production is never enabled by installation and requires both explicit editor gates. These switches do not enable MCP mutation.'
)
replace(
    'includes/class-mad4b-scp-skills-admin-ui.php',
    'Exports enabled runtime skills into a portable Agent Plugins ZIP with root plugin.json, snapshot identity files, and skills/. The governed Staging App mapping is included automatically and the exported capability remains Read.',
    'Exports enabled runtime skills into a portable Agent Plugins ZIP with root plugin.json, snapshot identity files, and skills/. The exact Site Profile App mapping is included, and exported capabilities reflect current governed certification: Read, or Read + Write when write authority is ready.'
)
replace(
    'tests/chatgpt-connection-ui-contract.py',
    'Governed write actions, when available, require separate Staging write authority and a one-time approval.',
    'Governed write actions, when available, require separate governed write authority and a one-time approval.'
)
replace(
    'tests/live-acceptance-evidence-contract.py',
    'stable registered Staging catalog',
    'stable registered tenant-bound catalog'
)
fixture = PLUGIN / 'tests/external-handshake-inventory-runtime.php'
text = fixture.read_text(encoding='utf-8')
anchor = "final class MAD4B_SCP_Identity_Context { public static function current() { return array( 'authenticated' => true, 'auth_method' => 'oauth2_bearer', 'subject_fingerprint' => str_repeat( 'a', 64 ), 'token_scopes' => array( 'mad4b:read' ), 'wp_user_id' => 7 ); } }\n"
addition = anchor + "final class MAD4B_SCP_Site_Profile { public static function current_environment() { return 'staging'; } public static function origin_enrolled() { return true; } public static function oauth_enabled() { return true; } }\n"
if text.count(anchor) != 1:
    raise SystemExit('tests/external-handshake-inventory-runtime.php: fixture anchor drifted')
fixture.write_text(text.replace(anchor, addition), encoding='utf-8')
contract = PLUGIN / 'tests/general-distribution-environment-contract.py'
if contract.exists():
    raise SystemExit('general-distribution-environment-contract.py already exists unexpectedly')
contract.write_text(r'''#!/usr/bin/env python3
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
''', encoding='utf-8')
print('rc32 final environment generalization: APPLIED')
