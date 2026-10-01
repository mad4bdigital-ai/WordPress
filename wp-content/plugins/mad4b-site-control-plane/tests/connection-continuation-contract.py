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
edge = (root.parents[3] / "tools" / "connection-edge-acceptance.mjs").read_text(encoding="utf-8")
edge_workflow = (root.parents[3] / ".github" / "workflows" / "connection-edge-acceptance.yml").read_text(encoding="utf-8")

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
    "explicit_subject_policy_conflict",
    "explicit_subject_binding_conflict",
    "site_profile_admin_owner_required",
    "connection_projection_drift",
):
    assert marker in resolver, marker
assert "class-mad4b-scp-connection-identity-resolver.php" in loader
assert "class-mad4b-scp-connection-doctor.php" in loader

# Tier-0 identity projection must precede the zero-touch short-circuit.
kernel_idx = plugin.index("MAD4B_SCP_Connection_Identity_Resolver::kernel();")
zero_touch_idx = plugin.index("current_request_is_zero_touch_surface()")
assert kernel_idx < zero_touch_idx

# Resolver is observational only: no persistence, constant mutation, or outbound I/O.
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
continue_idx = run_safe.index("MAD4B_SCP_Post_Update_Continuation::evaluate_and_rebind()")
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
    "/oauth/mcp/jwks",
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

print("connection + post-update continuation contract: PASS")
