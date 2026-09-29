#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
rest = (PLUGIN / "includes/class-mad4b-scp-rest-compatibility.php").read_text(encoding="utf-8")
runtime = (PLUGIN / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")
finalizer = (PLUGIN / "includes/class-mad4b-scp-live-acceptance-finalizer.php").read_text(encoding="utf-8")
external_finalizer = (PLUGIN / "includes/class-mad4b-scp-external-wpml-acceptance-finalizer.php").read_text(encoding="utf-8")
policy = (PLUGIN / "includes/class-mad4b-scp-provider-diagnostic-policy.php").read_text(encoding="utf-8")
response = (PLUGIN / "includes/class-mad4b-scp-wpml-response-contract.php").read_text(encoding="utf-8")

assert "MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status_from_local_wpml( $wpml )" in rest
assert "MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status()" not in rest
assert "public static function external_wpml_receipt_status_from_local_wpml( $wpml )" in external_finalizer
assert "MAD4B_SCP_Live_Acceptance_Finalizer::external_wpml_receipt_status_from_local_wpml( $wpml )" in external_finalizer
assert "self::authoritative_external_receipt()" in external_finalizer
assert "bounded_external_wpml_receipt" in rest
assert "'external_wpml_acceptance_required' => $external_wpml_required" in rest
assert "'external_wpml_acceptance_verified' => $external_wpml_verified" in rest
assert "'external_http_probe_performed' => false" in rest
assert "'provider_probe_mode' => 'passive_snapshot'" in rest
assert "'provider_self_calls_started' => 0" in rest
assert "'external_wpml_acceptance_verified' => false" not in rest

assert "external_wpml_acceptance_verified" in runtime
assert "if ( ! $wpml_active && ! $route_registered )" in rest
assert "'wpml_active' => (bool) $wpml_active" in rest
assert "external_wpml_acceptance_required" in runtime
assert "MAD4B_SCP_External_WPML_Acceptance_Finalizer::" not in runtime

probe = rest.split("public static function wpml_probe()", 1)[1].split("private static function passive_rest_route_snapshot", 1)[0]
assert "MAD4B_SCP_Provider_Diagnostic_Policy::rest_route_snapshot" in probe
assert "rest_get_server(" not in probe
assert "rest_do_request(" not in probe
assert "new WP_REST_Request" not in probe
assert "'internal_rest_dispatch_performed' => false" in probe
assert "'automatic_retry_allowed' => false" in probe
assert "self::$wpml_probe_cache" in probe

snapshot = policy.split("public static function rest_route_snapshot", 1)[1].split("public static function explicit_rest_materialization_allowed", 1)[0]
assert "current_rest_server()" in snapshot
assert "rest_get_server(" not in snapshot
assert "rest_do_request(" not in snapshot
assert "'provider_self_calls_started' => 0" in snapshot
assert "active_provider_dispatch_allowed" in policy
assert "return false;" in policy.split("public static function active_provider_dispatch_allowed", 1)[1]

receipt = response.split("public static function receipt_status()", 1)[1].split("public static function live_acceptance_status", 1)[0]
assert "MAD4B_SCP_REST_Compatibility::status()" not in receipt
assert "MAD4B_SCP_Provider_Diagnostic_Policy::rest_route_snapshot" in receipt
assert "'provider_self_calls_started'] = 0" in receipt

projection = finalizer.split("public static function external_wpml_receipt_status_from_local_wpml", 1)[1].split("private static function acceptance_environment", 1)[0]
assert "MAD4B_SCP_REST_Compatibility::status()" not in projection
assert "current_candidate_identity()" in projection
assert "build_provenance_status()" not in projection
assert "'full_runtime_hash_validation_performed'] = false" in projection

identity = finalizer.split("private static function current_candidate_identity()", 1)[1].split("private static function current_candidate()", 1)[0]
assert "build_provenance_identity_status()" in identity
assert "build_provenance_status()" not in identity

print("REST external WPML receipt reconciliation contract: PASS")

# Exact outer-request authority: nested/internal REST dispatch cannot become
# external WPML evidence, and the canonical response contract is the single
# writer when available.
observer = (PLUGIN / "includes/class-mad4b-scp-live-acceptance-observer.php").read_text(encoding="utf-8")
response_observer = response.split("public static function observe_response", 1)[1].split("/** @internal Pure evaluator", 1)[0]
assert "current_request_is_external_provider_rest( self::ROUTE )" in response_observer
assert "'observation_source' => 'external_http_request'" in response_observer
assert "'internal_rest_dispatch_accepted' => false" in response_observer

identity = response.split("private static function candidate_identity()", 1)[1].split("private static function staging_allowed()", 1)[0]
assert "build_provenance_identity_status()" in identity
assert "build_provenance_status()" not in identity

legacy = observer.split("private static function observe_wpml_response", 1)[1].split("public static function evaluate_wpml_receipt", 1)[0]
assert "class_exists( 'MAD4B_SCP_WPML_Response_Contract' )" in legacy
assert "current_request_is_external_provider_rest( '/wpml/v1/rest/status' )" in legacy

finalizer_observer = finalizer.split("public static function observe_wpml_response", 1)[1].split("public static function classify_wpml_response", 1)[0]
assert "class_exists( 'MAD4B_SCP_WPML_Response_Contract' )" in finalizer_observer
assert "current_request_is_external_provider_rest( '/wpml/v1/rest/status' )" in finalizer_observer

projection = finalizer.split("public static function external_wpml_receipt_status_from_local_wpml", 1)[1].split("private static function acceptance_environment", 1)[0]
assert "null !== $wpml['route_registered']" in projection
