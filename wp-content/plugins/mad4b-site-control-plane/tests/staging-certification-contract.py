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

require(main, "Version: 0.4.0-rc.47", "rc.47 plugin header")
require(main, "define( 'MAD4B_SCP_VERSION', '0.4.0-rc.47' );", "rc.47 runtime constant")
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
    "mad4b.frontend-performance-evidence.v3",
    "'baseline_only' => false",
    "'budget_evaluated' => $minimum_samples_met",
    "'budget_pass' => $budget_pass",
    "'evaluation_window' => array(",
    "'server_elapsed_strategy' => 'median'",
    "'db_queries_strategy' => 'max'",
    "'peak_memory_strategy' => 'max'",
    "'insufficient_frontend_samples'",
    "'server_elapsed_ms_max'",
    "'db_queries_max'",
    "'peak_memory_bytes_max'",
    "performance_budget_exceeded",
]:
    require(perf, marker, "performance budget invariant")

# Grant drift is inspectable before any operator-authorized reconciliation.
plan = authority.split("public static function reconciliation_plan", 1)[1].split("public static function reconcile", 1)[0]
for marker in [
    "mad4b.governed-write-authority-reconciliation-plan.v2",
    "'read_only' => true",
    "'mutation_performed' => false",
    "'exact_grants_missing_count'",
    "'stale_allow_grants_count'",
    "'duplicate_exact_allow_grants_count'",
    "'current_agent_wildcard_grants'",
    "'global_registry_wildcard_grants'",
    "'grant_lookup_strategy' => 'bulk_agent_grant_snapshot'",
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
    "'admin_query_performance'",
    "'query_monitor_db_attribution'",
    "'admin_query_performance_status'",
    "'query_monitor_db_attribution_status'",
    "'oauth_live_authority_projection'",
    "'rollback_candidate'",
    "'wp_import_export_exact_artifact'",
]:
    require(cert, marker, "staging certification invariant")
for marker in [
    "MAD4B_SCP_Admin_Query_Performance::status()",
    "MAD4B_SCP_Query_Monitor_Evidence_Bridge::db_attribution_status()",
    "MAD4B_SCP_Local_OAuth_Server::consent_grant_projection()",
    "mad4b.oauth-consent-grant-projection.v3",
    "'oauth_scope_changed'",
    "'write_authority_granted_by_consent'",
    "'projection_consistent'",
    "'caller_component_trace_expected'",
]:
    require(cert, marker, "Staging query-performance/attribution evidence")

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
    "$non_managed_ready = in_array( $mode, array( 'dedicated_google', 'custom_credentials' ), true )",
    "$mode_known = $applicable || $non_managed_ready",
    "google_auth_mode_unresolved",
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
    "source_commit_sha": "6127dd9890a2dbdc62247402a337042b418e29d2",
    "control_plane_version": "0.4.0-rc.46",
    "build_fingerprint": "045fa56a05985d75feac72d3660b4d04dd9e157446b7ff29c64bbe032aed77ca",
    "package_manifest_digest": "9dfd4e1c58bfbee9d9adfcd4368495880134ee2b09aabdb35a2c7749f463a8a6",
    "artifact_sha256": "9637854abc4901573065981fc0e1dc56bf8360309d8f0fc77f1e9281886dfece",
    "artifact_name": "mad4b-site-control-plane-0.4.0-rc.46.zip",
}
for key, value in expected_rollback.items():
    if rollback.get(key) != value:
        raise SystemExit(f"rollback candidate drift for {key}: {rollback.get(key)!r}")
if rollback.get("artifact_retention_verified_by_package") is not True:
    raise SystemExit("rc.47 rollback candidate must be bound to the CI-verifiable rc.46 retention receipt")

receipt_path = root / "MAD4B-ROLLBACK-RETENTION-RECEIPT.json"
if not receipt_path.is_file():
    raise SystemExit("rollback retention receipt missing")
receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
for key, value in {
    "contract": "mad4b.rollback-retention-receipt.v1",
    "verification_source": "github_actions_artifact_api",
    "rollback_source_commit_sha": "6127dd9890a2dbdc62247402a337042b418e29d2",
    "rollback_control_plane_version": "0.4.0-rc.46",
    "plugin_artifact_sha256": "9637854abc4901573065981fc0e1dc56bf8360309d8f0fc77f1e9281886dfece",
    "distribution_artifact_id": 10716798794,
    "distribution_artifact_sha256": "4ddc05ee4dede5d5eb13b7fb74cf1d4873e36239c88101696ddf005bd318575c",
}.items():
    if receipt.get(key) != value:
        raise SystemExit(f"rollback retention receipt drift for {key}: {receipt.get(key)!r}")

for marker in [
    "MAD4B-ROLLBACK-RETENTION-RECEIPT.json",
    "ci_verified_packaged_receipt",
    "github_actions_artifact_api",
    "rollback_artifact_retention_unverified",
]:
    require(cert, marker, "rollback retention receipt invariant")

print("MAD4B staging post-deployment certification contract PASS")
