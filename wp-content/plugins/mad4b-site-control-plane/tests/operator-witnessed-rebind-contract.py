#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
post = (ROOT / "includes/class-mad4b-scp-post-update-continuation.php").read_text(encoding="utf-8")
adaptive = (ROOT / "includes/class-mad4b-scp-adaptive-runtime-convergence.php").read_text(encoding="utf-8")
runtime = (ROOT / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")

# Master release trust remains strict and is not weakened for Hub/PR packages.
release_fn = post.split("private static function release_trusted", 1)[1].split("private static function release_identity", 1)[0]
for token in (
    "release_verdict_success",
    "release_root_trust_verified",
    "published_from_master",
    "release_verdict_run_id",
    "archive_sha256",
):
    assert token in release_fn, token

# Operator-witness trust is a separate Staging-only, short-lived, HMAC-sealed
# lifecycle proof. It never mutates grants or authorizes Production/Breakglass.
for token in (
    "OPERATOR_WITNESS_OPTION",
    "OPERATOR_WITNESS_CONTRACT",
    "OPERATOR_WITNESS_TTL",
    "record_operator_witnessed_replacement",
    "operator_witnessed_reconciliation_preflight",
    "prepare_operator_witnessed_update",
    "operator_witness_seal",
    "operator_witness_valid",
    "staging_operator_witnessed_replacement",
    "operator_witnessed_manual_replacement",
    "'zero_delta_required' => true",
    "'mutation_class' => 'candidate_binding_only'",
    "'production_allowed' => false",
    "'breakglass_allowed' => false",
    "'grant_mutation_allowed' => false",
):
    assert token in post, token

record = post.split("public static function record_operator_witnessed_replacement", 1)[1].split("private static function operator_witness_matches_permit", 1)[0]
assert "current_environment()" in record and "'staging'" in record
assert "self::breakglass_enabled()" in record
assert "self::baseline_valid" in record
assert "get_current_user_id()" in record
assert "user_can( $user, 'manage_options' )" in record
assert "user_can( $user, 'update_plugins' )" in record
assert "MAD4B_SCP_Site_Profile::user_is_enrolled" in record
assert "runtime_manifest_match" in record
assert "control_plane_version_mismatch" in record
assert "full_manifest_pre_restart_version_boundary" in record
assert "update_option( self::OPERATOR_WITNESS_OPTION" in record
assert "bind_candidate_identity" not in record

preflight = post.split("public static function operator_witnessed_reconciliation_preflight", 1)[1].split("public static function prepare_operator_witnessed_update", 1)[0]
for token in (
    "self::operator_witness_valid",
    "self::baseline_valid",
    "self::classify_current_delta",
    "current_build_skill_certification_pending",
    "'AUTO_REBIND'",
    "'REVIEW_REQUIRED'",
    "'DEFER'",
    "'HARD_BLOCK'",
):
    assert token in preflight, token
assert "bind_candidate_identity" not in preflight

prepare = post.split("public static function prepare_operator_witnessed_update", 1)[1].split("public static function prepare(", 1)[0]
assert "self::classify_current_delta" in prepare
assert "self::CLASS_ZERO" in prepare
assert "self::replace_permit" in prepare
assert "delete_option( self::OPERATOR_WITNESS_OPTION )" in prepare
assert "bind_candidate_identity" not in prepare

# Candidate binding remains owned by the existing evaluate-and-rebind primitive.
evaluate = post.split("public static function evaluate_and_rebind", 1)[1]
assert "MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity" in evaluate
assert "'grant_changes' => 0" in evaluate
assert "'production_mutation' => false" in evaluate
assert "'breakglass' => false" in evaluate

# WordPress updater hook records a witness only for the Control Plane itself.
hook = adaptive.split("public static function on_plugin_update", 1)[1].split("public static function on_activation", 1)[0]
assert "mad4b-site-control-plane" in hook
assert "record_operator_witnessed_replacement" in hook
assert "operator_witness_state" in hook

# Runtime convergence tries master trust first and only then the sealed Staging
# witness path. The trust path is included in the reconciliation decision.
assert "observed_release_target()" in runtime
assert "operator_witness_status" in runtime
assert "operator_witnessed_reconciliation_preflight" in runtime
assert "prepare_operator_witnessed_update" in runtime
assert "'staging_operator_witness'" in runtime
assert "$auto_reconciliation['trust_path']" in runtime

print("operator-witnessed rebind contract: PASS")
