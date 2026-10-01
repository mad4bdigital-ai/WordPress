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

# 0. Registration readiness is reduced by the canonical Truth Projection.
registration_bridge = read("includes/class-mad4b-scp-mcp-registration-bridge.php")
for marker in (
    "public static function mcp_registration_identity( array $fact )",
    "'deferred_identity_ready'",
    "'registration_error'",
    "'blocking_registration_error'",
    "'deep_registration_deferred'",
):
    require(projection, marker, "canonical MCP registration truth projection")
registration_fact = registration_bridge.split("public static function server_registration_identity_status( $server_id )", 1)[1].split("public static function status()", 1)[0]
require(registration_fact, "MAD4B_SCP_Truth_Projection::mcp_registration_identity( $fact )", "registration source delegates projection")
for forbidden in ("$identity_ready =", "$blocking_error =", "'deferred_identity_ready'"):
    if forbidden in registration_fact:
        raise SystemExit("registration source class re-derived projected readiness instead of delegating Truth Projection")

# 0b. Candidate-bound persisted truth is reduced canonically.
for marker in (
    "public static function candidate_identity_bound_ready( array $persisted, array $current",
    "public static function candidate_binding_bound_ready( array $persisted, array $binding",
    "array_key_exists( 'historical_ready', $persisted )",
    "'current_candidate_match'",
    "'effective_ready'",
    "'recorded_package_manifest_digest'",
    "'recorded_artifact_identity'",
    "'current_package_manifest_digest'",
    "'current_artifact_identity'",
):
    require(projection, marker, "canonical candidate-bound truth projection")
read_consistency = read("includes/class-mad4b-scp-read-consistency.php")
skills_projection = read_consistency.split("private static function skills_projection()", 1)[1].split("private static function deep_skills_projection()", 1)[0]
write_projection = read_consistency.split("private static function write_authority_projection()", 1)[1].split("private static function deep_write_authority_projection()", 1)[0]
require(skills_projection, "MAD4B_SCP_Truth_Projection::candidate_identity_bound_ready", "skills projection delegates candidate truth")
if "if ( empty( $projection ) ) return $result;" in skills_projection:
    raise SystemExit("Skills projection may not fail open to persisted certification when Truth Projection is unavailable")
for marker in (
    "$result['recorded_ready'] = array_key_exists( 'historical_ready', $result )",
    "$result['effective_skill_ready'] = false;",
    "$result['candidate_identity_bound_ready'] = false;",
    "$result['ready'] = null;",
    "$result['state'] = 'truth_projection_unavailable';",
    "'truth_projection_unavailable'",
):
    require(skills_projection, marker, "Skills projection fail-closed fallback")
require(skills_projection, "'historical_ready'", "skills projection preserves persisted historical result")
require(skills_projection, "'build_identity_current'", "skills projection exposes persisted build freshness")
require(skills_projection, "'stale_reasons'", "skills projection exposes persisted freshness reasons")
for marker in (
    "$result['effective_skill_ready_scope'] = 'candidate_identity_bound_checkpoint_only';",
    "$result['candidate_identity_bound_ready'] = ! empty( $projection['effective_ready'] );",
    "$result['live_skill_ready'] = null;",
    "$result['ready'] = null;",
    "$result['live_skill_evaluation_deferred'] = true;",
):
    if marker not in skills_projection:
        raise SystemExit("session-safe Skills projection must distinguish candidate-bound checkpoint evidence from live readiness: " + marker)
if "$result['ready'] = ! empty( $projection['effective_ready'] )" in skills_projection:
    raise SystemExit("session-safe Skills projection must not promote candidate-bound persisted evidence to live ready")
skill_freshness = skills.split("private static function project_persisted_freshness( array $stored )", 1)[1].split("private static function evaluate()", 1)[0]
require(skill_freshness, "$stored['historical_ready']", "persisted Skill freshness preserves historical ready")
require(skill_freshness, "array_key_exists( 'historical_ready', $stored )", "persisted Skill historical ready is idempotent")
require(write_projection, "MAD4B_SCP_Truth_Projection::candidate_binding_bound_ready", "write projection delegates candidate truth")
if "if ( empty( $projection ) ) return $result;" in write_projection:
    raise SystemExit("write projection may not fail open to persisted authority when Truth Projection is unavailable")
for marker in (
    "$result['persisted_authority_ready'] = ! empty( $result['ready'] );",
    "$result['effective_authority_ready'] = false;",
    "$result['ready'] = false;",
    "$result['state'] = 'truth_projection_unavailable';",
    "'truth_projection_unavailable'",
):
    require(write_projection, marker, "write projection fail-closed fallback")
for forbidden in ("hash_equals( $recorded_sha", "$effective_ready = $recorded_ready && $candidate_match"):
    if forbidden in skills_projection:
        raise SystemExit("Read Consistency re-derived Skills candidate readiness")
for forbidden in ("$effective_ready = $persisted_ready &&", "$binding_match = ! empty"):
    if forbidden in write_projection:
        raise SystemExit("Read Consistency re-derived write candidate readiness")

# 0bb. Governed Write current readiness is live grant-snapshot truth, not the persisted checkpoint.
for marker in (
    "public static function governed_write_grant_snapshot( array $fact )",
    "'persisted_write_authority_not_ready'",
    "'unreviewed_stale_write_authority'",
    "'current_ready' => empty( $blockers )",
):
    require(projection, marker, "canonical governed-write grant snapshot truth")
write_authority_source = read("includes/class-mad4b-scp-staging-write-authority.php")
write_plan = write_authority_source.split("public static function reconciliation_plan()", 1)[1].split("public static function reconcile()", 1)[0]
require(write_plan, "MAD4B_SCP_Truth_Projection::governed_write_grant_snapshot", "write reconciliation delegates current readiness")
if "'current_ready' => $persisted_ready" in write_plan:
    raise SystemExit("write reconciliation still aliases current_ready to persisted_ready")
require(write_plan, "'current_readiness_blockers'", "write reconciliation exposes current readiness blockers")

# 0c. Session-safe identity truth remains strict and deferred readiness stays tri-state.
for marker in (
    "public static function session_connection_identity( array $fact )",
    "public static function session_reconnect_identity( array $fact )",
    "'chatgpt_actual_registered'",
    "'mcp_chatgpt_not_registered'",
):
    require(projection, marker, "canonical session-safe identity truth")
read_consistency = read("includes/class-mad4b-scp-read-consistency.php")
session_connection = read_consistency.split("private static function session_safe_connection_projection()", 1)[1].split("private static function session_safe_reconnect_projection()", 1)[0]
session_reconnect = read_consistency.split("private static function session_safe_reconnect_projection()", 1)[1].split("private static function connection_projection()", 1)[0]
require(session_connection, "MAD4B_SCP_Truth_Projection::session_connection_identity( $fact )", "session connection delegates truth")
require(session_reconnect, "MAD4B_SCP_Truth_Projection::session_reconnect_identity( $fact )", "session reconnect delegates truth")
if "'ready' => null" not in read_consistency:
    raise SystemExit("deferred diagnostic checks must preserve unknown readiness as null")
if "MAD4B_SCP_Truth_Projection::tri_state( $summary, 'ready' )" not in read_consistency:
    raise SystemExit("compact diagnostics must preserve tri-state readiness without boolean coercion")

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

# 1b. Lightweight and full provenance share the same artifact/source identity validator.
for marker in (
    "private static function artifact_identity_matches_source( $artifact_identity, $source_commit_sha )",
    "artifact_identity_invalid",
):
    require(observer, marker, "shared artifact identity validation")
identity_provenance = observer.split("public static function build_provenance_identity_status()", 1)[1].split("public static function build_provenance_status()", 1)[0]
full_provenance = observer.split("public static function build_provenance_status()", 1)[1].split("private static function provenance_manifest()", 1)[0]
require(identity_provenance, "self::artifact_identity_matches_source", "lightweight provenance artifact validation")
require(full_provenance, "self::artifact_identity_matches_source", "full provenance artifact validation")

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
require(projection, "&& empty( $blockers )", "truth gate blocker consistency")
if "empty( $gate['blockers'] )" not in finalizer:
    raise SystemExit("live acceptance finalizer fallback may not ignore blockers")
if "empty( $gate['blockers'] )" not in observer:
    raise SystemExit("live acceptance observer fallback may not ignore blockers")
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
    "'subject_live_validation_deferred' => array( 'skills_runtime' )",
    "'valid_for_merge' => $valid_for_session_evidence_merge",
    "'valid_for_session_evidence_merge' => $valid_for_session_evidence_merge",
    "'valid_for_release_merge' => false",
    "'merge_scope' => 'session_safe_subject_evidence_only'",
    "'deep_acceptance_required' => true",
    "'release_acceptance_deferred_checks' => $deep_checks_deferred",
    "'subject_not_ready'",
):
    require(read_consistency, marker, "session-safe effective-state semantics")
authority_projection = read_consistency.split("private static function write_authority_projection()", 1)[1].split("private static function skills_projection()", 1)[0]
require(authority_projection, "MAD4B_SCP_Truth_Projection::candidate_binding_bound_ready", "effective authority canonical projection")
skills_projection = read_consistency.split("private static function skills_projection()", 1)[1].split("private static function update_projection()", 1)[0]
require(skills_projection, "MAD4B_SCP_Truth_Projection::candidate_identity_bound_ready", "effective Skills canonical projection")
require(projection, "$effective_ready = $persisted_ready && ( ! $binding_required || $binding_match )", "effective authority formula owner")
require(projection, "$effective_ready = $recorded_ready && $candidate_match", "effective Skills formula owner")
require(projection, "$effective_ready = $effective_ready && empty( $blockers );", "effective readiness blocker consistency")
require(projection, "'persisted_authority_not_ready'", "blocked persisted authority canonical reason")
require(projection, "elseif ( ! empty( $blockers ) ) $state = 'blocked';", "candidate identity ready/blocker consistency")
require(projection, "historical_evidence", "historical Skills state owner")
for marker in (
    "'source_commit_sha' => $source_commit_sha",
    "'build_fingerprint' => $build_fingerprint",
    "'package_manifest_digest' => $package_manifest_digest",
    "'artifact_identity' => $artifact_identity",
    "'build_provenance_identity_ready'",
    "'build_provenance_identity_unavailable'",
):
    require(skills, marker, "persisted Skills exact-package identity")
for marker in (
    "'package_manifest_digest', 'artifact_identity'",
    "'recorded_package_manifest_digest'",
    "'recorded_artifact_identity'",
    "'current_package_manifest_digest'",
    "'current_artifact_identity'",
):
    require(read_consistency, marker, "Skills read-consistency four-part candidate identity")

# 4b. Deep Write Live Truth consumes the same canonical grant snapshot as reconciliation.
current_authority_truth = live_truth.split("public static function current_authority_status()", 1)[1].split("public static function current_write_certification()", 1)[0]
require(current_authority_truth, "MAD4B_SCP_Staging_Write_Authority::reconciliation_plan()", "Live Truth canonical grant snapshot")
require(current_authority_truth, "'grant_snapshot_current_ready'", "Live Truth grant readiness evidence")
if "MAD4B_SCP_Agent_Registry::exact_grant(" in current_authority_truth:
    raise SystemExit("Live Truth must not maintain a parallel exact-grant readiness reducer")

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
    "'mad4b_oauth_grant_projection'",
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
