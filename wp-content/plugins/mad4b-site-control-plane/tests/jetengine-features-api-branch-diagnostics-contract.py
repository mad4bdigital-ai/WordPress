#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / "includes/adapters/class-mad4b-scp-jetengine-features-api-branch-diagnostics-adapter.php"
LOADER = ROOT / "includes/adapters/class-mad4b-scp-mutation-semantics-adapter.php"

src = ADAPTER.read_text(encoding="utf-8")
loader = LOADER.read_text(encoding="utf-8")

required = [
    "mad4b.jetengine-features-api-branch-diagnostics.v1",
    "jetengine/features-api-branch-diagnostics",
    "Jet_Engine\\\\MCP_Tools\\\\Registry",
    "register_features_api",
    "enable_features_api",
    "enable_mcp_server",
    "current_user_can",
    "first_proven_early_return",
    "recognized_early_return_proven",
    "no_recognized_early_return_proven",
    "predicate_result",
    "'UNKNOWN'",
    "simple_boolean_polarity",
    "false !== strpos( $lower, '&&' )",
    "false !== strpos( $lower, '||' )",
    "preg_match( '/={2,3}|!=|!==/', $lower )",
    "false !== strrpos( $prefix_without_not_equal, '!' )",
    "callback_execution_attempted' => false",
    "route_registration_attempted' => false",
    "rest_api_init_replayed' => false",
    "rest_server_instantiated' => false",
    "settings_mutation' => false",
    "no_secrets_exposed' => true",
]
for token in required:
    assert token in src, f"missing branch diagnostics contract token: {token}"

for forbidden in [
    "rest_get_server(",
    "rest_do_request(",
    "register_rest_route(",
    "do_action( 'rest_api_init'",
    'do_action( "rest_api_init"',
    "call_user_func(",
    "call_user_func_array(",
    "update_option(",
    "add_option(",
    "delete_option(",
    "$wpdb->query(",
    "$wpdb->insert(",
    "$wpdb->update(",
    "$wpdb->delete(",
    "wp_remote_post(",
    "wp_remote_request(",
]:
    assert forbidden not in src, f"branch diagnostics must remain observational: {forbidden}"

assert "if ( 1 !== count( $tokens ) ) return null;" in src, "multi-signal predicates must remain UNKNOWN rather than guessed"
assert "if ( false !== strpos( $lower, '&&' ) || false !== strpos( $lower, '||' ) ) return null;" in src, "compound boolean expressions must remain UNKNOWN"
assert "if ( preg_match( '/={2,3}|!=|!==/', $lower ) ) return null;" in src, "unrecognized comparisons must remain UNKNOWN"
assert "class-mad4b-scp-jetengine-features-api-branch-diagnostics-adapter.php" in loader, "branch diagnostics must be loaded by the governed adapter bootstrap"

print("JetEngine Features API branch diagnostics contract: PASS")
