#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
bridge = (root / "includes" / "class-mad4b-scp-oauth-resource-bridge.php").read_text(encoding="utf-8")
registry = (root / "includes" / "class-mad4b-scp-multi-authority-registry.php").read_text(encoding="utf-8")
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
servers = (root / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
hybrid = (root / "tests" / "runtime-oauth-hybrid-smoke.php").read_text(encoding="utf-8")

for marker in [
    "MAD4B_MCP_OAUTH_ADVERTISED_ISSUERS",
    "MAD4B_MCP_OAUTH_RESOURCE_POLICY_BY_ISSUER",
    "public static function advertised_issuers",
    "public static function resource_policy_for_issuer",
    "public static function issuer_allowed_for_resource",
    "mad4b_oauth_authority_resource_denied",
    "'authorization_servers' => self::advertised_issuers( $resource )",
    "trust_advertisement_separated",
]:
    if marker not in bridge:
        raise SystemExit(f"missing multi-authority bridge marker: {marker}")

for marker in [
    "mad4b.multi-authority-registry.v1",
    "mad4b.multi-authority-live-certification.v1",
    "mad4b/multi-authority-registry-status",
    "'trusted' => true",
    "'advertised' => $is_advertised",
    "'resource_policy_id'",
    "'subject_mapper_id'",
    "'runtime_verified' => false",
    "'last_live_verified_at' => ''",
    "'last_live_verification_ref' => ''",
    "'live_certification_verdict' => 'PENDING'",
    "'live_certification_is_inferred_from_configuration' => false",
]:
    if marker not in registry:
        raise SystemExit(f"missing multi-authority registry marker: {marker}")

for marker in [
    "class-mad4b-scp-multi-authority-registry.php",
    "MAD4B_SCP_Multi_Authority_Registry::boot()",
]:
    if marker not in main:
        raise SystemExit(f"main plugin does not load multi-authority projection: {marker}")

if "mad4b/multi-authority-registry-status" not in servers:
    raise SystemExit("multi-authority status is not exposed on governed read surfaces")

for marker in [
    "advertised_issuers()",
    "resource_policy_for_issuer( $local_issuer )",
    "multi-authority registry",
    "Local authority unexpectedly reached Developer resource",
]:
    if marker not in hybrid:
        raise SystemExit(f"hybrid runtime proof missing: {marker}")

for forbidden in [
    "'runtime_verified' => true",
    "update_option(",
    "add_option(",
    "delete_option(",
]:
    if forbidden in registry:
        raise SystemExit(f"read-only multi-authority projection contains forbidden evidence mutation/inference: {forbidden}")

print("mad4b.site-control-plane.multi-authority-registry.v1: PASS")
