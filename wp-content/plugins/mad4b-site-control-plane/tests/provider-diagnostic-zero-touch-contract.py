#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

policy = (PLUGIN / "includes/class-mad4b-scp-provider-diagnostic-policy.php").read_text(encoding="utf-8")
rest = (PLUGIN / "includes/class-mad4b-scp-rest-compatibility.php").read_text(encoding="utf-8")
connection = (PLUGIN / "includes/class-mad4b-scp-connection-status.php").read_text(encoding="utf-8")
wpml = (PLUGIN / "includes/class-mad4b-scp-wpml-response-contract.php").read_text(encoding="utf-8")
plugin = (PLUGIN / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
servers = (PLUGIN / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
bootstrap = (PLUGIN / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

assert "class-mad4b-scp-provider-diagnostic-policy.php" in bootstrap
for token in (
    "passive_by_default",
    "request_serving_provider_self_calls_allowed",
    "internal_provider_rest_dispatch_allowed",
    "loopback_provider_http_allowed",
    "automatic_probe_retry_allowed",
    "governed_external_executor",
):
    assert token in policy, token

probe = rest.split("public static function wpml_probe()", 1)[1].split("private static function passive_rest_route_snapshot", 1)[0]
for forbidden in ("rest_get_server(", "rest_do_request(", "new WP_REST_Request", "wp_remote_get(", "wp_remote_post("):
    assert forbidden not in probe, forbidden
assert "self::$wpml_probe_cache" in probe
assert "passive_snapshot" in probe

server_status = connection.split("private static function server_status", 1)[1].split("private static function route_validation_deferred", 1)[0]
assert "MAD4B_SCP_Provider_Diagnostic_Policy::current_rest_server()" in server_status
assert "rest_get_server(" not in server_status
assert "deep_route_validation_deferred" in server_status

receipt = wpml.split("public static function receipt_status()", 1)[1].split("public static function live_acceptance_status", 1)[0]
assert "MAD4B_SCP_REST_Compatibility::status()" not in receipt
assert "MAD4B_SCP_Provider_Diagnostic_Policy::rest_route_snapshot" in receipt

prime = plugin.split("public static function prime_admin_mcp_runtime()", 1)[1].split("public static function governance_bootstrap_error_code", 1)[0]
assert "MAD4B_SCP_Provider_Diagnostic_Policy::explicit_rest_materialization_allowed()" in prime
assert prime.count("rest_get_server()") == 1
assert "admin_connection_endpoints_prime" in prime


assert "explicit_deep_diagnostic_allowed" in policy
explicit_gate = policy.split("public static function explicit_deep_diagnostic_allowed()", 1)[1].split("public static function status()", 1)[0]
assert "defined( 'WP_CLI' ) && WP_CLI" in explicit_gate
assert "self::current_request_is_mad4b_protocol()" in explicit_gate
assert "self::explicit_rest_materialization_allowed()" in explicit_gate
assert "'explicit_deep_diagnostic_ability' => 'mad4b/provider-deep-diagnostic'" in policy

deep = rest.split("public static function deep_diagnostic", 1)[1].split("public static function status()", 1)[0]
for required in (
    "rest_get_server()",
    "new WP_REST_Request( 'GET', self::WPML_ROUTE )",
    "rest_do_request( $request )",
    "'mode' => 'explicit_deep_diagnostic'",
    "'loopback_http_performed' => false",
    "'automatic_retry_allowed' => false",
    "'authorizing' => false",
    "'acceptance_evidence_persisted' => false",
    "'external_acceptance_authority' => false",
):
    assert required in deep, required
for forbidden in ("wp_remote_get(", "wp_remote_post(", "update_option(", "add_option(", "delete_option("):
    assert forbidden not in deep, forbidden

admin_block = servers.split("'mad4b-admin' => array(", 1)[1].split("),", 1)[0]
read_block = servers.split("'mad4b-read' =>", 1)[1].split("'mad4b-chatgpt' =>", 1)[0]
chatgpt_block = servers.split("'mad4b-chatgpt' =>", 1)[1].split("'mad4b-enrollment' =>", 1)[0]
assert "'mad4b/provider-deep-diagnostic'" in admin_block
assert "'mad4b/provider-deep-diagnostic'" not in read_block
assert "'mad4b/provider-deep-diagnostic'" not in chatgpt_block

print("provider diagnostic zero-touch contract: PASS")
