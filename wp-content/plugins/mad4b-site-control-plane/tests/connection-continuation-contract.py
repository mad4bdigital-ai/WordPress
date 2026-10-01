#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
inc = root / "includes"

loader = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
plugin = (inc / "class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
resolver = (inc / "class-mad4b-scp-connection-identity-resolver.php").read_text(encoding="utf-8")
doctor = (inc / "class-mad4b-scp-connection-doctor.php").read_text(encoding="utf-8")
status = (inc / "class-mad4b-scp-connection-status.php").read_text(encoding="utf-8")
local_oauth = (inc / "class-mad4b-scp-local-oauth-server.php").read_text(encoding="utf-8")
resource = (inc / "class-mad4b-scp-oauth-resource-bridge.php").read_text(encoding="utf-8")
continuation = (inc / "class-mad4b-scp-post-update-continuation.php").read_text(encoding="utf-8")
self_update = (inc / "class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
convergence = (inc / "class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")
authority = (inc / "class-mad4b-scp-staging-write-authority.php").read_text(encoding="utf-8")
edge = (root.parents[2] / "tools" / "connection-edge-acceptance.mjs").read_text(encoding="utf-8")
edge_workflow = (root.parents[2] / ".github" / "workflows" / "connection-edge-acceptance.yml").read_text(encoding="utf-8")

# Canonical connection identity exists once and is loaded before runtime surfaces.
for marker in (
    "final class MAD4B_SCP_Connection_Identity_Resolver",
    "mad4b.connection-contract.v1",
    "mad4b.connection-fingerprint.v1",
    "mad4b.connection-edge-contract.v1",
    "kernel_fingerprint",
    "connection_fingerprint",
    "explicit_wp_user_conflict",
    "explicit_external_oauth_issuer",
    "explicit_local_oauth_issuer_conflict",
    "explicit_advertised_issuer_conflict",
    "explicit_resource_policy_conflict",
    "explicit_subject_policy_conflict",
    "explicit_subject_binding_conflict",
    "MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER",
    "MAD4B_MCP_OAUTH_ADVERTISED_ISSUERS",
    "MAD4B_MCP_OAUTH_RESOURCE_POLICY_BY_ISSUER",
    "site_profile_admin_owner_required",
    "connection_projection_drift",
):
    assert marker in resolver, marker
assert "class-mad4b-scp-connection-identity-resolver.php" in loader
assert "class-mad4b-scp-connection-doctor.php" in loader

# Canonical identity must preserve the independent Portable read-only source
# instead of turning an intentionally unconfigured Site Profile into an OAuth blocker.
for marker in (
    "'exact_site_profile'",
    "'portable_readonly'",
    "MAD4B_SCP_Portable_Readonly_Connection::status()",
    "MAD4B_SCP_Portable_Readonly_Connection::connection_uuid()",
    "MAD4B_SCP_Portable_Readonly_Connection::connection_digest()",
    "'profile_authority_inherited' => false",
    "'portable_readonly' => $portable_effective",
):
    assert marker in resolver, marker

# Tier-0 identity projection must precede the zero-touch short-circuit.
projection_idx = plugin.index("MAD4B_SCP_Connection_Identity_Resolver::project_runtime_identity();")
zero_touch_idx = plugin.index("current_request_is_zero_touch_surface()")
assert projection_idx < zero_touch_idx
assert "public static function project_runtime_identity()" in resolver

oauth_autoconfig = (inc / "class-mad4b-scp-staging-oauth-autoconfig.php").read_text(encoding="utf-8")
# Resolver truth remains non-durable: the separate request-local projection may
# define in-process OAuth constants through autoconfig, but it cannot persist.
for forbidden in (
    "update_option(",
    "add_option(",
    "delete_option(",
    "define(",
    "wp_remote_get(",
    "wp_remote_post(",
    "wp_safe_remote_get(",
    "wp_safe_remote_post(",
):
    assert forbidden not in resolver, forbidden

oauth_projection_start = oauth_autoconfig.index("public static function project_request_identity()")
oauth_projection_end = oauth_autoconfig.index("private static function bootstrap_enrolled_nonproduction", oauth_projection_start)
oauth_projection = oauth_autoconfig[oauth_projection_start:oauth_projection_end]
for forbidden in ("update_option(", "add_option(", "delete_option(", "wp_remote_get(", "wp_remote_post("):
    assert forbidden not in oauth_projection, forbidden
assert "durable_mutation_performed' => false" in oauth_projection
assert "provider_discovery_performed' => false" in oauth_projection
assert "apply_local_oauth_configuration(" in oauth_projection

# Explicit override detector must mirror the runtime/autoconfig precedence that
# can make one otherwise identical site fail while another succeeds.
for marker in (
    "explicit_local_oauth_issuer_conflict",
    "explicit_wp_user_conflict",
    "explicit_advertised_issuer_conflict",
    "explicit_resource_policy_conflict",
    "MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER",
    "MAD4B_MCP_OAUTH_ADVERTISED_ISSUERS",
    "MAD4B_MCP_OAUTH_RESOURCE_POLICY_BY_ISSUER",
):
    assert marker in oauth_autoconfig, marker

# Generated package provenance is certification evidence, not a prerequisite
# for current-origin read-only OAuth/MCP endpoint preflight.
assert "package_identity_blocker" in resolver
assert "certification_blockers" in resolver
assert "if ( empty( $build['identity_ready'] ) )" in resolver
package_drift_idx = resolver.index("$package_identity_blocker = 'package_identity_drift';")
cert_merge_idx = resolver.index("$certification_blockers[] = $package_identity_blocker;")
assert package_drift_idx < cert_merge_idx
resolve_block = resolver.split("public static function resolve()", 1)[1].split("public static function fingerprint()", 1)[0]
assert "$blockers[] = 'package_identity_drift'" not in resolve_block
assert "connection_contract['certification_blockers']" in status

# Root cause is propagated before generic bridge symptoms.
assert "oauth_preflight_blockers( $oauth, $connection_contract )" in status
assert "$root = isset( $connection_contract['root_blocker'] )" in status
root_idx = status.index("if ( '' !== $root ) $blockers[] = $root;")
generic_idx = status.index("if ( empty( $oauth['configured'] ) ) $blockers[] = 'oauth_resource_bridge_not_configured';")
assert root_idx < generic_idx

# The same connection fingerprint is projected through both RFC/resource and AS metadata.
for text in (local_oauth, resource):
    assert "mad4b_connection_fingerprint" in text
assert "public static function public_key_fingerprint_status()" in local_oauth

# Differential doctor is read-only and never self-certifies the public edge.
for marker in (
    "final class MAD4B_SCP_Connection_Doctor",
    "mad4b/connection-doctor",
    "mad4b/connection-differential",
    "'readonly' => true",
    "'outbound_probe_performed' => false",
):
    assert marker in doctor, marker

# One-time continuation has explicit classification, TTL, CAS/generation and no
# grant/subject reconciliation primitive.
for marker in (
    "mad4b.post-update-continuation.v1",
    "ZERO_DELTA_CONTINUATION",
    "REVIEW_REQUIRED_DELTA",
    "HARD_BLOCK_DELTA",
    "const TTL = 900",
    "'one_time' => true",
    "'production_allowed' => false",
    "'breakglass_allowed' => false",
    "generation",
    "permit_digest",
    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
    "mad4b_post_update_continuation_replay_denied",
    "bind_candidate_identity(",
):
    assert marker in continuation, marker
for forbidden in (
    "Staging_Write_Grant_Reconciliation::",
    "Staging_Write_Authority::reconcile(",
    "Site_Profile::save",
    "define(",
):
    assert forbidden not in continuation, forbidden

# Transport delta is inventory/config identity only; package identity is fenced separately.
transport = continuation.split("private static function transport_snapshot()", 1)[1].split("private static function write_snapshot", 1)[0]
assert "connection_kernel_fingerprint" in transport
assert "connection_fingerprint" not in transport
assert "'registered'" not in transport
assert "'error'" not in transport

# Pre-claim grant fingerprint changes require review; only post-claim TOCTOU is hard.
assert "if ( $post_bind ) $hard[] = 'grant_rows_fingerprint_changed';" in continuation
assert "else $review[] = 'grant_rows_fingerprint_changed';" in continuation

# Permit is prepared before the filesystem replacement and cancelled on rollback paths.
apply_body = self_update.split("private static function apply_verified_archive", 1)[1]
prepare_idx = apply_body.index("MAD4B_SCP_Post_Update_Continuation::prepare")
upgrader_idx = apply_body.index("new Plugin_Upgrader")
assert prepare_idx < upgrader_idx
for marker in (
    "MAD4B_SCP_Post_Update_Continuation::cancel( 'install_failed'",
    "MAD4B_SCP_Post_Update_Continuation::cancel( 'maintenance_fence_lost'",
    "MAD4B_SCP_Post_Update_Continuation::cancel( 'readback_failed'",
    "MAD4B_SCP_Post_Update_Continuation::mark_readback_verified",
    "mark_post_update_pending( $readback_target",
):
    assert marker in self_update, marker

# Exact four-part package identity is present in self-update readback and convergence fence.
for text in (self_update, convergence):
    assert "artifact_identity" in text

# Current-build Skill certification is persisted before candidate continuation.
run_safe = convergence.split("private static function run_safe_phases", 1)[1]
observe_idx = run_safe.index("MAD4B_SCP_Skill_Runtime_Certification::observe( true )")
continue_idx = run_safe.index("MAD4B_SCP_Post_Update_Continuation::evaluate_and_rebind( $lock )")
assert observe_idx < continue_idx
assert "build_identity_current" in run_safe
assert "candidate_binding_auto_refresh_policy" in convergence
assert "post_update_zero_delta_continuation_only" in convergence

# Candidate binding accepts the bounded permit source only through the dedicated validator.
for marker in (
    "'self_update_continuation' ===",
    "MAD4B_SCP_Post_Update_Continuation::validate_binding_context",
    "self_update_continuation",
):
    assert marker in authority, marker

# External acceptance proves the four public edge paths and rejects HTML/WAF/basic-auth/redirect drift.
for marker in (
    "/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt",
    "/.well-known/oauth-authorization-server/oauth/mcp",
    'const jwks = issuer + "/jwks";',
    "/wp-json/mcp/mad4b-chatgpt",
    "edge_unexpected_redirect",
    "edge_private_basic_auth_gate",
    "edge_html_or_waf_challenge",
    "edge_discovery_content_type_invalid",
    "edge_pkce_s256_missing",
    "connection_projection_drift",
    "mad4b_connection_fingerprint",
):
    assert marker in edge, marker
assert "workflow_dispatch:" in edge_workflow
assert "actions/upload-artifact@v4" in edge_workflow

assert "permit_seal" in continuation and "hash_hmac" in continuation
assert "self::$executing_context_digest" in continuation
assert "consume_binding_context" in authority
assert "persisted_grant_records_fingerprint" in continuation
assert "transport_inventory_fingerprint" in continuation
assert "captured_actor_authority_revoked" in continuation
print("connection + post-update continuation contract: PASS")
