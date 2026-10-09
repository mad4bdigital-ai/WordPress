#!/usr/bin/env python3
"""Guard the opt-in Staging PR/branch candidate lane without calling WordPress."""
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
SELF = (ROOT / "includes/class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
SOURCE = (ROOT / "includes/class-mad4b-scp-staging-source-selector.php").read_text(encoding="utf-8")
TEST = (ROOT / "tests/staging-source-selector-runtime.php").read_text(encoding="utf-8")

def expect(ok, msg):
    if not ok:
        raise AssertionError("STAGING_CANDIDATE_CONTRACT:" + msg)

permission = SELF.split("public static function can_upload_apply( $input = null ) {", 1)[1].split(
    "public static function can_native_apply( $input = null ) {", 1
)[0]
for marker in (
    "'staging_candidate_upload'",
    "current_user_can( 'manage_options' )",
    "user_is_enrolled( get_current_user_id() )",
    "verified_bearer_active()",
    "verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE )",
    "mad4b_self_update_staging_owner_step_up_required",
):
    expect(marker in permission, "owner Step-Up permission missing " + marker)

plan = SELF.split("public static function upload_plan( $input ) {", 1)[1].split(
    "public static function upload_apply( $input ) {", 1
)[0]
apply = SELF.split("public static function upload_apply( $input ) {", 1)[1].split(
    "public static function native_plan( $input ) {", 1
)[0]
schema = SELF.split("private static function plan_schema() {", 1)[1].split(
    "private static function digest( $value ) {", 1
)[0]

for marker in (
    "'staging_candidate_upload'",
    "MAD4B_SCP_STAGING_CANDIDATE_UPDATES_ENABLED",
    "explicit_wordpress_staging_environment_required",
    "wordpress_environment_explicit()",
    "same_origin_clone_protection",
    "deployment_binding_configured",
    "MAD4B_SCP_Staging_Source_Selector::resolve",
    "staging_candidate_source_sha_mismatch",
    "target_not_current_governed_release:",
    "self::fetch_manifest( true )",
    "'release_channel_bound' => ! $staging_candidate",
    "'production_allowed' => false",
    "sort( $plan['blockers'], SORT_STRING );",
):
    expect(marker in plan, "planning missing " + marker)
for marker in (
    "expected_plan_sha256",
    "hash_equals( $plan['plan_sha256'], $expected )",
    "if ( empty( $plan['eligible'] ) )",
    "INSTALL EXACT STAGING CANDIDATE",
    "mad4b_self_update_staging_owner_step_up_required",
    "verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE )",
    "self::verify_archive( $tmp, $plan['target'] )",
    "MAD4B_SCP_Staging_Source_Selector::resolve",
    "mad4b_self_update_staging_source_changed",
    "self::apply_verified_archive",
    "'governed_staging_candidate_upload'",
    "'governed_file_upload'",
    "@unlink( $tmp )",
):
    expect(marker in apply, "apply missing " + marker)
expect(apply.index("self::verify_archive") < apply.index("mad4b_self_update_staging_source_changed") < apply.index("self::apply_verified_archive"),
       "source not checked immediately prior to apply")
for marker in (
    "'candidate_source'",
    "'repository'",
    "'reference'",
    "'pull_request'",
    "'branch'",
    "'commit'",
    "'candidate_confirmation'",
    "'additionalProperties' => false",
):
    expect(marker in schema, "schema missing " + marker)
for marker in (
    "MAD4B_SCP_STAGING_SOURCE_REPOSITORIES",
    "mad4bdigital-ai/WordPress",
    "'pull_request', 'branch', 'commit'",
    "'https://api.github.com/repos/'",
    "'redirection' => 0",
    "'sslverify' => true",
    "rawurlencode( $reference )",
    "foreign_or_closed_pr",
    "lookup_failed",
    "source_sha_invalid",
):
    expect(marker in SOURCE, "selector missing " + marker)
expect("refs/pull/258/head" not in SOURCE and "'258'" not in SOURCE, "selector hard-coded to PR #258")
expect("wp_remote_post" not in SOURCE and "file_put_contents" not in SOURCE, "source resolver must be strictly read-only")
expect("wp_safe_remote_get" in SOURCE, "SSRF-safe GitHub ref verification required")
expect("MAD4B_DYNAMIC_STAGING_SOURCE" in TEST and "future PR is not hardcoded" in TEST,
       "runtime fixture missing dynamic-ref assertions")
print("MAD4B_STAGING_CANDIDATE_CONTRACT: PASS (dynamic selector, independent release policy, exact-hash apply guards)")
