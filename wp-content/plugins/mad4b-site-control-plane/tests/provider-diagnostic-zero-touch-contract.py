#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

policy = (PLUGIN / "includes/class-mad4b-scp-provider-diagnostic-policy.php").read_text(encoding="utf-8")
rest = (PLUGIN / "includes/class-mad4b-scp-rest-compatibility.php").read_text(encoding="utf-8")
connection = (PLUGIN / "includes/class-mad4b-scp-connection-status.php").read_text(encoding="utf-8")
wpml = (PLUGIN / "includes/class-mad4b-scp-wpml-response-contract.php").read_text(encoding="utf-8")
plugin = (PLUGIN / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
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

print("provider diagnostic zero-touch contract: PASS")
