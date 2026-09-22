#!/usr/bin/env python3
from pathlib import Path
import json
import re

ROOT = Path(__file__).resolve().parents[1]
main = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
observer = (ROOT / "includes/class-mad4b-scp-live-acceptance-observer.php").read_text(encoding="utf-8")
authority = (ROOT / "includes/class-mad4b-scp-staging-write-authority.php").read_text(encoding="utf-8")
cert = (ROOT / "includes/class-mad4b-scp-staging-certification.php").read_text(encoding="utf-8")
rollback = json.loads((ROOT / "MAD4B-ROLLBACK-CANDIDATE.json").read_text(encoding="utf-8"))
certified_providers = json.loads((ROOT / "config/certified-providers.json").read_text(encoding="utf-8"))

def require(text, marker, label):
    if marker not in text:
        raise SystemExit(f"missing {label}: {marker}")

require(main, "Version: 0.4.0-rc.42", "rc.42 plugin header")
require(main, "define( 'MAD4B_SCP_VERSION', '0.4.0-rc.42' );", "rc.42 runtime constant")
require(main, "class-mad4b-scp-staging-certification.php", "staging certification include")
require(main, "MAD4B_SCP_Staging_Certification::boot();", "staging certification boot")

# Aggregate live acceptance must use the same direct live authority truth as
# mad4b/write-authority-status instead of a stale cached projection.
live_body = observer.split("public static function live_acceptance_status", 1)[1]
require(live_body, "MAD4B_SCP_Live_Truth::current_authority_status()", "live authority source")
require(live_body, "'write_authority' => self::gate", "write authority gate")

# Performance is no longer baseline-only: it evaluates explicit bounded budgets.
perf = observer.split("public static function frontend_performance_status", 1)[1].split("public static function build_provenance_status", 1)[0]
for marker in [
    "mad4b.frontend-performance-evidence.v2",
    "'baseline_only' => false",
    "'budget_evaluated' => $sample_valid",
    "'budget_pass' => $budget_pass",
    "'server_elapsed_ms_max'",
    "'db_queries_max'",
    "'peak_memory_bytes_max'",
    "performance_budget_exceeded",
]:
    require(perf, marker, "performance budget invariant")

# Grant drift is inspectable before any operator-authorized reconciliation.
plan = authority.split("public static function reconciliation_plan", 1)[1].split("public static function reconcile", 1)[0]
for marker in [
    "mad4b.governed-write-authority-reconciliation-plan.v1",
    "'read_only' => true",
    "'mutation_performed' => false",
    "'exact_grants_missing_count'",
    "'stale_allow_grants_count'",
    "'apply_requires_explicit_operator_action' => true",
]:
    require(plan, marker, "read-only reconciliation plan")
for forbidden in [
    "grant_ability(",
    "revoke_allow_grant_by_id(",
    "update_option(",
    "bind_subject(",
    "set_subject_status(",
]:
    if forbidden in plan:
        raise SystemExit(f"reconciliation plan may not mutate authority: {forbidden}")

require(authority, "'mad4b/write-authority-reconciliation-plan'", "reconciliation-plan ability")
require(authority, "'annotations' => array( 'readonly' => true", "read-only ability annotation")

# Unified staging status is observation-only and may never execute remediation.
for marker in [
    "mad4b.staging-certification-status.v1",
    "'read_only' => true",
    "'mutation_performed' => false",
    "'production_mutation_performed' => false",
    "'seo_publication_authorized' => false",
    "'production_activation_authorized' => false",
    "'managed_google_broker'",
    "'external_skill_snapshot'",
    "'write_authority'",
    "'browser_runtime'",
    "'performance_budget'",
    "'rollback_candidate'",
    "'wp_import_export_exact_artifact'",
]:
    require(cert, marker, "staging certification invariant")
for forbidden in [
    "::reconcile(",
    "::review_asset(",
    "::set_auth_mode(",
    "::save_credentials(",
    "::disconnect(",
    "::bind_candidate_identity(",
]:
    if forbidden in cert:
        raise SystemExit(f"staging certification must remain observation-only: {forbidden}")


# Managed Google is an optional auth mode, not a universal Staging requirement.
# Dedicated/custom connections must not be blocked merely because the managed
# broker is intentionally unconfigured.
for marker in [
    "MAD4B_SCP_Google_Drive_Context::auth_mode_status()",
    "private static function managed_google_gate_evidence",
    "'managed_google' === $mode",
    "$ready = ! $applicable ||",
    "'selected_auth_mode' => $mode",
    "'applicable' => $applicable",
    "'managed_broker_status' => $managed",
    "'authorizing' => false",
    "'mutation_performed' => false",
    "'managed_google_broker_status' => $managed",
]:
    require(cert, marker, "mode-aware Managed Google certification")

for marker in [
    "mad4b.wp-import-export-exact-remediation.v1",
    "config/certified-providers.json",
    "expected_import",
    "archive_sha256",
    "install_exact_repository_import_artifact",
    "'automatic_install_performed' => false",
    "'production_mutation_performed' => false",
]:
    require(cert, marker, "WP Import/Export remediation invariant")

wp_import = certified_providers["providers"]["wp-import-export"]["components"]["import"]
if wp_import["version"] != "5.0.8":
    raise SystemExit("WP All Import certified version drifted")
if wp_import["archive"] != "wp-all-import-pro.zip":
    raise SystemExit("WP All Import certified archive drifted")
if wp_import["archive_sha256"] != "eca6af2f5ecaa4119d051a0108045f61543d966e4765cfd219e49a60a7a50de3":
    raise SystemExit("WP All Import certified archive SHA-256 drifted")

# Brand Core coverage must be explicit and based on approved governed assets.
for marker in [
    "mad4b.brand-core-context-coverage.v1",
    "'brand_strategy'",
    "'tone_of_voice'",
    "'editorial_guidelines'",
    "'approved' === $summary['review_status']",
    "'brand_authority' === $summary['authority_class']",
    "'governed' === $summary['source_mode']",
    "required_context_set_missing:",
]:
    require(cert, marker, "Brand Core coverage invariant")

require(cert, "'client_snapshot_token'", "external evidence boundary")

require(cert, "'rollback_artifact_sha256'", "external evidence boundary")

require(cert, "'artifact_retention_verified'", "external evidence boundary")

require(cert, "'external_facts_self_certified' => false", "external evidence boundary")

require(cert, "candidate_identity_ready'] ) && ! empty( $rollback['artifact_retention_verified", "rollback gate requires retained artifact evidence")

# Browser view may reduce existing/fresh evidence but cannot create an executor
# or claim browser parity by itself.
require(cert, "browser_runtime_not_observed", "browser observation gap")
if "browser_runtime_parity_verified' => true" in cert:
    raise SystemExit("staging certification may not self-assert browser parity")

expected_rollback = {
    "contract": "mad4b.rollback-candidate.v1",
    "source_commit_sha": "f5d090b7cffd4231ac18daa1eab5a54c46425af1",
    "control_plane_version": "0.4.0-rc.41",
    "build_fingerprint": "d106fc153ec557fe49a9ed4108dfd9668031ee033dddaaf8abb71421a093e14f",
    "package_manifest_digest": "afc2ceb99375be88b6f9c394049e446436329007ff84fb7a9d5aab72e2aaa4f8",
    "artifact_sha256": "e9001b3c8107bfcafd820605653a49013166e137790579d1e3b3095bfb07c78c",
    "artifact_name": "mad4b-site-control-plane-0.4.0-rc.41.zip",
}
for key, value in expected_rollback.items():
    if rollback.get(key) != value:
        raise SystemExit(f"rollback candidate drift for {key}: {rollback.get(key)!r}")
if rollback.get("artifact_retention_verified_by_package") is not False:
    raise SystemExit("package may not self-certify external rollback artifact retention")

print("MAD4B staging post-deployment certification contract PASS")
