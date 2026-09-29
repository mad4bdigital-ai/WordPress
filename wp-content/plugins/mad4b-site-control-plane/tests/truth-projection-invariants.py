#!/usr/bin/env python3
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]

def read(rel):
    return (ROOT / rel).read_text(encoding="utf-8")

def require(text, marker, label):
    if marker not in text:
        raise SystemExit(f"missing {label}: {marker}")

bootstrap = read("mad4b-site-control-plane.php")
projection = read("includes/class-mad4b-scp-truth-projection.php")
live_truth = read("includes/class-mad4b-scp-live-truth.php")
rest = read("includes/class-mad4b-scp-rest-compatibility.php")
observer = read("includes/class-mad4b-scp-live-acceptance-observer.php")
finalizer = read("includes/class-mad4b-scp-live-acceptance-finalizer.php")
read_consistency = read("includes/class-mad4b-scp-read-consistency.php")
skills = read("includes/class-mad4b-scp-skill-runtime-certification.php")
policy = read("includes/class-mad4b-scp-provider-diagnostic-policy.php")
qm_bridge = read("includes/class-mad4b-scp-query-monitor-evidence-bridge.php")
family = read("includes/adapters/class-mad4b-scp-repository-family-adapter.php")
catalog = json.loads(read("config/repository-plugin-artifacts.json"))

# 1. One canonical owner for external WPML truth.
require(bootstrap, "class-mad4b-scp-truth-projection.php", "truth projection loader")
for marker in (
    "final class MAD4B_SCP_Truth_Projection",
    "canonical_external_wpml_receipt",
    "MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status()",
    "MAD4B_SCP_WPML_Response_Contract::receipt_status()",
    "'receipt' => $receipt",
):
    require(projection, marker, "canonical WPML truth projection")

rest_projection = live_truth.split("public static function current_rest_compatibility()", 1)[1].split("public static function filter_persisted_certification", 1)[0]
for marker in (
    "MAD4B_SCP_Truth_Projection::external_wpml( true )",
    "$diagnostic['external_wpml_acceptance_verified'] = ! empty( $external_wpml['verified'] )",
    "$diagnostic['external_wpml_acceptance']",
):
    require(rest_projection, marker, "REST canonical WPML projection")
if "$diagnostic['external_wpml_acceptance_verified'] = false" in rest_projection:
    raise SystemExit("REST Live Truth still hardcodes external WPML false")

write_projection = live_truth.split("public static function current_write_certification()", 1)[1].split("public static function current_rest_compatibility()", 1)[0]
for marker in (
    "MAD4B_SCP_Truth_Projection::external_wpml( true )",
    "'local_external_wpml_claimed' => false",
    "'external_wpml_acceptance_verified' => ! empty( $external_wpml['verified'] )",
    "'external_wpml_acceptance' =>",
):
    require(write_projection, marker, "Write Runtime canonical WPML projection")
if "'external_wpml_acceptance_verified' => false" in write_projection:
    raise SystemExit("Write Runtime still hardcodes external WPML false")

observer_write = observer.split("public static function write_runtime_certification_status()", 1)[1].split("public static function observe_doing_it_wrong", 1)[0]
require(observer_write, "MAD4B_SCP_Truth_Projection::external_wpml( true )", "observer Write Runtime canonical projection")
require(observer, "MAD4B_SCP_WPML_Response_Contract::receipt_status()", "observer normalized WPML source")

# 2. Passive tri-state is never collapsed with !empty().
rest_status = rest.split("public static function status()", 1)[1]
require(rest_status, "MAD4B_SCP_Truth_Projection::tri_state( $wpml, 'query_parameters_preserved' )", "passive tri-state preservation")

# 3. Freshness participates in effective readiness everywhere.
for marker in (
    "gate_effective_ready",
    "'freshness_required'",
    "'effective_ready'",
    ": array_key_exists( 'fresh', $gate )",
):
    require(projection, marker, "truth gate contract")
aggregate = finalizer.split("public static function aggregate_ready", 1)[1].split("public static function production_receipt_status", 1)[0]
require(aggregate, "MAD4B_SCP_Truth_Projection::gate_effective_ready", "freshness-aware aggregate reducer")
observer_reducer = observer.split("$all_ready = true;", 1)[1].split("return array( 'contract' => self::AGGREGATE_CONTRACT", 1)[0]
require(observer_reducer, "MAD4B_SCP_Truth_Projection::gate_effective_ready", "observer effective reducer")
provider_gate = observer.split("'provider_projection' => self::gate(", 1)[1].split("'write_authority' =>", 1)[0]
require(provider_gate, "empty( $provider_execution_leaks ) && ! empty( $external['build_fingerprint_match'] )", "provider effective projection")
require(provider_gate, "external_projection_not_current", "provider stale blocker")

# 4. Session-safe execution success is distinct from effective subject truth.
for marker in (
    "'check_execution_state'",
    "'subject_state'",
    "'subject_ready'",
    "'persisted_authority_ready'",
    "'effective_authority_ready'",
    "'candidate_binding_match'",
    "'recorded_ready'",
    "'current_candidate_match'",
    "'effective_skill_ready'",
    "'subject_blockers'",
    "'valid_for_merge' => $valid_for_merge",
    "'subject_not_ready'",
):
    require(read_consistency, marker, "session-safe effective-state semantics")
authority_projection = read_consistency.split("private static function write_authority_projection()", 1)[1].split("private static function skills_projection()", 1)[0]
require(authority_projection, "$effective_ready = $persisted_ready && ( ! $binding_required || $binding_match )", "effective authority formula")
skills_projection = read_consistency.split("private static function skills_projection()", 1)[1].split("private static function update_projection()", 1)[0]
require(skills_projection, "'historical_evidence'", "historical Skills state")
require(skills_projection, "$effective_ready = $recorded_ready && $candidate_match", "effective Skills formula")
for marker in ("'source_commit_sha' => $source_commit_sha", "'build_fingerprint' => $build_fingerprint"):
    require(skills, marker, "persisted Skills exact-build identity")

# 5. Third-party admin AJAX is zero-touch; owned MAD4B actions use exact registry.
for marker in (
    "public static function mad4b_admin_ajax_actions()",
    "public static function current_request_is_mad4b_admin_ajax()",
    "public static function current_request_is_foreign_admin_ajax()",
    "self::current_request_is_foreign_admin_ajax()",
    "'foreign_admin_ajax'",
    "'mad4b_site_profile_save'",
    "'mad4b_context_save_profile'",
    "'mad4b_enable_production_readonly_oauth'",
    "'mad4b_disable_production_readonly_oauth'",
):
    require(policy, marker, "admin AJAX zero-touch classifier")
foreign_admin = policy.split("public static function current_request_is_foreign_wp_admin()", 1)[1].split("public static function current_request_is_zero_touch_surface()", 1)[0]
if "wp_doing_ajax() ) return false" in foreign_admin or "DOING_AJAX ) return false" in foreign_admin:
    raise SystemExit("foreign wp-admin still blanket-excludes AJAX")

# Performance evaluation helpers must survive query-monitor status refactors.
require(observer, "private static function valid_performance_sample( $sample )", "frontend performance sample validator")
require(observer, "self::valid_performance_sample( $sample )", "frontend performance validator use")

# 6. Query Monitor health and request-surface coverage are separate gates.
for marker in (
    "mad4b.request-surface-coverage.v1",
    "'collector_health_ready'",
    "'request_surface_coverage'",
    "'coverage_ready'",
    "'request_surface_coverage' => self::gate",
    "'frontend' => 3",
    "'rest' => 1",
    "'wp_admin' => 1",
    "'cron' => 1",
    "'wpml_admin' => 1",
    "'site_health_rest' => 1",
    "'wpml_external_rest' => 1",
    "'generic_cron' => 1",
):
    require(observer, marker, "request-surface coverage gate")

# 7. Zero-touch remains default; only a signed governed canary can opt in.
for marker in (
    "$acceptance_canary = self::request_acceptance_canary_kind()",
    "&& '' === $acceptance_canary",
    "if ( '' === self::request_frontend_probe_hash() ) return ''",
    "return 'generic_cron'",
    "return 'wpml_external_rest'",
    "return 'site_health_rest'",
    "return 'wpml_admin'",
    "'canary_coverage'",
    "'cron' => 0",
):
    require(qm_bridge, marker, "signed bounded canary capture")
require(observer, "MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface()", "passive observer zero-touch")
require(observer, "'canary_coverage' => array(", "observer telemetry schema parity")

# 8. Family version is descriptor-owned or explicitly absent for multi-component families.
for marker in (
    "private function primary_runtime_plugin()",
    "'primary_plugin_file'",
    "'primary_version'",
    "'version_semantics'",
    "'descriptor_primary_plugin'",
    "'multi_component_no_single_version'",
):
    require(family, marker, "repository family version authority")
wpml = catalog.get("families", {}).get("wpml", {})
if wpml.get("primary_plugin_file") != "sitepress-multilingual-cms/sitepress.php":
    raise SystemExit("WPML primary version authority is not sitepress-multilingual-cms/sitepress.php")
for family_id in ("jet-ecosystem", "wp-import-export"):
    descriptor = catalog.get("families", {}).get(family_id, {})
    if descriptor.get("primary_plugin_file"):
        raise SystemExit(f"{family_id} must not pretend to have a single family version without an explicit design decision")

# 9. No projection may reintroduce the original contradictory WPML hardcode.
for name, body in (
    ("live truth REST", rest_projection),
    ("live truth write", write_projection),
    ("observer write wrapper", observer_write),
):
    if "external_wpml_acceptance_verified' => false" in body or "external_wpml_acceptance_verified'] = false" in body:
        raise SystemExit(f"{name} reintroduced hardcoded external WPML false")

print("mad4b.truth-projection-invariants.v1: PASS")
