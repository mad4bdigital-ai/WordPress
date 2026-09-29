#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
rest = (PLUGIN / "includes/class-mad4b-scp-rest-compatibility.php").read_text(encoding="utf-8")
runtime = (PLUGIN / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")
finalizer = (PLUGIN / "includes/class-mad4b-scp-live-acceptance-finalizer.php").read_text(encoding="utf-8")

assert "MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status_from_local_wpml( $wpml )" in rest
assert "bounded_external_wpml_receipt" in rest
assert "'external_wpml_acceptance_required' => $external_wpml_required" in rest
assert "'external_wpml_acceptance_verified' => $external_wpml_verified" in rest
assert "'external_http_probe_performed' => false" in rest
assert "'external_wpml_acceptance_verified' => false" not in rest

# Runtime convergence must consume the canonical REST projection rather than
# independently interpreting the receipt and creating a second truth source.
assert "external_wpml_acceptance_verified" in runtime
assert "external_wpml_acceptance_required" in runtime
assert "MAD4B_SCP_External_WPML_Acceptance_Finalizer::" not in runtime

# Inactive WPML must never materialize the WordPress REST/MCP server merely to
# prove a negative capability.
probe = rest.split("public static function wpml_probe()", 1)[1].split("private static function bounded_external_wpml_receipt", 1)[0]
assert "$wpml_active = self::wpml_active();" in probe
assert "if ( ! $wpml_active )" in probe
assert "'rest_server_materialized' => false" in probe
assert probe.index("if ( ! $wpml_active )") < probe.index("rest_get_server()")

# Canonical external WPML truth must not recurse through REST_Compatibility::status
# or force full package hashing. REST status passes its existing local probe into
# a pure finalizer projection; standalone receipt reads may acquire one local probe.
projection = finalizer.split("public static function external_wpml_receipt_status_from_local_wpml", 1)[1].split("private static function acceptance_environment", 1)[0]
assert "MAD4B_SCP_REST_Compatibility::status()" not in projection
assert "current_candidate_identity()" in projection
assert "build_provenance_status()" not in projection
assert "'full_runtime_hash_validation_performed'] = false" in projection

identity = finalizer.split("private static function current_candidate_identity()", 1)[1].split("private static function current_candidate()", 1)[0]
assert "build_provenance_identity_status()" in identity
assert "build_provenance_status()" not in identity

print("REST external WPML receipt reconciliation contract: PASS")
