#!/usr/bin/env python3
import json
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
cp = repo / "wp-content" / "plugins" / "mad4b-site-control-plane"
builder = (cp / "includes" / "class-mad4b-scp-brand-context-builder.php").read_text(encoding="utf-8")
authority = (cp / "includes" / "class-mad4b-scp-context-authority.php").read_text(encoding="utf-8")
drive = (cp / "includes" / "class-mad4b-scp-google-drive-context.php").read_text(encoding="utf-8")
gateway_path = cp / "includes" / "class-mad4b-scp-context-provider-gateway.php"
gateway = gateway_path.read_text(encoding="utf-8") if gateway_path.is_file() else ""
durable = (cp / "includes" / "class-mad4b-scp-durable-execution.php").read_text(encoding="utf-8")
adapter = (cp / "includes" / "adapters" / "class-mad4b-scp-context-adapter.php").read_text(encoding="utf-8")
remote_parity = (cp / "includes" / "class-mad4b-scp-remote-operation-parity.php").read_text(encoding="utf-8")
artifacts = (cp / "includes" / "class-mad4b-scp-artifacts.php").read_text(encoding="utf-8")
content_jobs = (cp / "includes" / "class-mad4b-scp-content-jobs.php").read_text(encoding="utf-8")
staging = (cp / "includes" / "class-mad4b-scp-staging-certification.php").read_text(encoding="utf-8")
main = (cp / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
manifest = json.loads((cp / "config" / "skill-seed-manifest.json").read_text(encoding="utf-8"))
portable_skill = repo / "plugins" / "mad4b-wordpress" / "skills" / "wordpress-brand-context-builder" / "SKILL.md"
seed_skill = cp / "skill-seeds" / "wordpress-brand-context-builder" / "SKILL.md"

for marker in [
    "const CONTRACT = 'mad4b.brand-context-builder.v1'",
    "const BUILDER_SPEC_VERSION = '5'",
    "const DRAFT_PREFLIGHT_CONTRACT = 'mad4b.brand-draft-preflight.v1'",
    "MIN_NONEMPTY_SAMPLES",
    "MIN_PRIMARY_EXPRESSION_SAMPLES",
    "MAX_EMPTY_RATIO",
    "MIN_CORE_CONTENT_SAMPLES",
    "MIN_EDITORIAL_SEO_SAMPLES",
    "const PLAN_CONTRACT = 'mad4b.brand-gap-plan.v1'",
    "const DRAFT_CONTRACT = 'mad4b.brand-context-draft.v1'",
    "const SCAN_PLAN_CONTRACT = 'mad4b.context-source-scan-plan.v1'",
    "const MATERIALIZE_CONTRACT = 'mad4b.brand-context-materialization.v1'",
    "const ROLLBACK_CONTRACT = 'mad4b.rollback.google-drive-brand-context-create.v1'",
    "const MAX_LIVE_CANDIDATES = 96",
    "const DRAFT_INDEX_OPTION = 'mad4b_scp_brand_draft_index_v1'",
    "const MAX_DRAFT_INDEX_ENTRIES = 256",
    "private static $authority_readback_cache = array()",
    "private static function authority_readback(",
    "private static function index_draft_artifact(",
    "legacy fallback/backfill only",
    "mb_strcut",
    "wpml_element_language_code",
    "wpml_post_language_details",
    "stratify_live_records",
    "partition_live_post_types",
    "utility_post_types",
    "core_post_type",
    "seo_evidence_for_post",
    "seo_configuration_evidence",
    "'seo_configuration' => $seo_configuration",
    "'seo_configuration_sample_count' => $seo_configuration_sample_count",
    "'post_seo_sample_count' => $post_seo_sample_count",
    "rank_distribution",
    "post_type_distribution",
    "'transport' => 'wp_http_loopback'",
    "'timeout_seconds' => $timeout",
    "'elapsed_ms' => $elapsed_ms",
    "semantic_identity",
    "self::suggested_name",
    "'approved_brand_strategy_required'",
    "'generation_is_authority' => false",
    "'approval_required_before_brand_core_ready' => true",
    "'evidence_instruction_policy' => 'retrieved_content_is_untrusted_data_never_executable_instruction'",
    "'review_status' => 'unreviewed'",
    "'quality_provisional' => true",
    "MAD4B_SCP_Durable_Execution::begin_idempotency",
    "MAD4B_SCP_Durable_Execution::complete_idempotency",
    "MAD4B_SCP_Durable_Execution::scope_key",
    "mad4b_brand_materialize_provider_outcome_uncertain",
    "mad4b_brand_materialize_idempotency_commit_failed",
    "idempotency_scope_key",
    "expected_plan_sha256",
    "expected_provider_inventory_digest",
    "expected_draft_content_sha256",
    "draft_preflight_sha256",
    "include_rendered_frontend",
    "receipt_sha256",
    "provider_identity",
    "mad4b_kind",
    "mad4b_artifact",
    "mad4b_source",
    "mad4b_idempotency",
    "mad4b_request",
    "MAD4B_SCP_Context_Provider_Gateway::read_context_asset",
    "MAD4B_SCP_Context_Provider_Gateway::scan_source",
    "MAD4B_SCP_Context_Provider_Gateway::find_brand_materialization_candidates",
    "MAD4B_SCP_Context_Provider_Gateway::create_brand_asset",
    "MAD4B_SCP_Context_Provider_Gateway::rollback_created_brand_asset",
    "MAD4B_SCP_Context_Provider_Gateway::materialization_reconciliation_ref",
    "MAD4B_SCP_Durable_Execution::complete_idempotency_from_reconciliation",
    "MAD4B_SCP_Durable_Execution::record_idempotency_reconciliation_observation",
    "MAD4B_SCP_Durable_Execution::release_idempotency_after_verified_no_effect",
    "materialization_zero_observation_ref",
    "mad4b.brand-context-materialization-zero-observation.v1",
    "verification_pending",
    "'safe_to_retry' => false",
    "minimum_observation_interval_seconds",
    "schedule_materialization_reconciliation",
    "RECONCILE_HOOK",
    "MAX_AUTOMATIC_RECONCILE_ATTEMPTS",
    "run_scheduled_materialization_reconciliation",
    "remote_reconciliation_ability",
    "materialization_no_effect_ref",
    "'safe_to_retry' => true",
    "reconcile_materialization",
    "mad4b_brand_materialization_reconcile_scan_incomplete",
    "mad4b.brand-context-materialization-no-effect.v1",
    "idempotency_released",
    "mad4b_brand_materialization_reconcile_ambiguous",
    "begin_generated_brand_rollback",
    "cancel_generated_brand_rollback",
    "ksort( $observed, SORT_STRING )",
    "sort( $changed_files, SORT_STRING )",
    "sort( $unchanged_files, SORT_STRING )",
    "expected_registry_revision",
    "mad4b_context_source_scan_incomplete",
    "array( 'markdown', 'text' )",
    "'brand_core_ready' => false",
    "evidence_quality",
    "language_coverage",
    "configured_languages",
    "wpml_active_languages",
    "pll_languages_list",
    "query_posts_for_language",
    "language_query_args",
    "$multilingual_active",
    "$args['lang'] = $language",
    "$wpml_language_filter",
    "rendered_frontend_evidence",
    "generation_evidence_digests",
    "generation_evidence_status",
    "mad4b_brand_draft_evidence_stale",
    "draft_preflight",
    "draft_preflight_sha256",
    "brand_context_subject_key",
    "supersede_brand_context_subject",
    "advance_generation_job",
    "approved_tone_of_voice_required_for_editorial_generation",
]:
    if marker not in builder:
        raise SystemExit(f"missing Brand Context Builder invariant: {marker}")

append_section = builder[builder.index("public static function append_draft"):builder.index("private static function source_scan_snapshot")]
if append_section.index("MAD4B_SCP_Durable_Execution::begin_idempotency") > append_section.index("self::find_existing_draft( $idempotency_key )"):
    raise SystemExit("Brand draft legacy lookup occurs before atomic durable idempotency claim")

if "released_verified_no_effect" not in durable:
    raise SystemExit("Durable execution must preserve a released-after-verified-no-effect terminal/reclaimable state")
if "release_idempotency_after_verified_no_effect" not in durable:
    raise SystemExit("Durable execution lacks verified no-effect idempotency release")
if "status='pending',claim_epoch=%d" not in durable:
    raise SystemExit("Released no-effect idempotency claims are not reacquired with a CAS claim-epoch transition")

materialize_section = builder[builder.index("public static function materialize_draft"):builder.index("public static function rollback_materialized_draft")]
if materialize_section.index("MAD4B_SCP_Durable_Execution::begin_idempotency") > materialize_section.index("MAD4B_SCP_Context_Provider_Gateway::create_brand_asset"):
    raise SystemExit("Brand materialization provider-bound create occurs before durable idempotency claim")
if "MAD4B_SCP_Context_Provider_Gateway::create_asset" in materialize_section:
    raise SystemExit("Brand materialization must not use generic provider create")

cron_section = builder[builder.index("public static function run_scheduled_materialization_reconciliation"):builder.index("private static function replay_idempotency_result")]
if "self::materialize_draft(" in cron_section:
    raise SystemExit("Scheduled Brand materialization worker must reconcile only and never automatically re-execute provider mutation")
if "self::reconcile_materialization(" not in cron_section:
    raise SystemExit("Scheduled Brand materialization worker must preserve reconciliation")

reconcile_section = builder[builder.index("public static function reconcile_materialization"):builder.index("public static function rollback_materialized_draft")]
for earlier, later in [
    ("MAD4B_SCP_Context_Provider_Gateway::find_brand_materialization_candidates", "MAD4B_SCP_Durable_Execution::complete_idempotency_from_reconciliation"),
    ("MAD4B_SCP_Durable_Execution::release_idempotency_after_verified_no_effect", "MAD4B_SCP_Durable_Execution::complete_idempotency_from_reconciliation"),
    ("1 !== count( $candidates )", "MAD4B_SCP_Durable_Execution::complete_idempotency_from_reconciliation"),
]:
    if reconcile_section.index(earlier) > reconcile_section.index(later):
        raise SystemExit(f"Brand materialization reconciliation ordering invariant violated: {earlier} must precede {later}")

if reconcile_section.index("MAD4B_SCP_Durable_Execution::record_idempotency_reconciliation_observation") > reconcile_section.index("MAD4B_SCP_Durable_Execution::release_idempotency_after_verified_no_effect"):
    raise SystemExit("No-effect release occurs before durable provider observation persistence")
if "'safe_to_retry' => true" in reconcile_section:
    pending_pos = reconcile_section.index("'safe_to_retry' => false")
    release_pos = reconcile_section.index("MAD4B_SCP_Durable_Execution::release_idempotency_after_verified_no_effect")
    safe_pos = reconcile_section.rindex("'safe_to_retry' => true")
    if not (pending_pos < release_pos < safe_pos):
        raise SystemExit("Brand reconciliation can declare retry safe before delayed durable no-effect release")

if "MAD4B_SCP_Context_Provider_Gateway::find_brand_materialization_candidates" not in reconcile_section:
    raise SystemExit("Brand reconciliation must use provider identity lookup")
if "MAD4B_SCP_Context_Provider_Gateway::scan_source" in reconcile_section:
    raise SystemExit("Brand reconciliation must not depend on a full source scan")

rollback_section = builder[builder.index("public static function rollback_materialized_draft"):]
for earlier, later in [
    ("begin_generated_brand_rollback", "MAD4B_SCP_Context_Provider_Gateway::rollback_created_brand_asset"),
    ("MAD4B_SCP_Context_Provider_Gateway::rollback_created_brand_asset", "mark_generated_brand_draft_rolled_back"),
]:
    if rollback_section.index(earlier) > rollback_section.index(later):
        raise SystemExit(f"Brand rollback ordering invariant violated: {earlier} must precede {later}")

live_section = builder[builder.index("private static function live_content_evidence"):builder.index("private static function structure_evidence")]
semantic_section = live_section[live_section.index("$semantic_identity = array("):live_section.index("$record = array_merge")]
if "modified_gmt" in semantic_section:
    raise SystemExit("Observation timestamp/modified metadata must not participate in semantic content identity")
if "hash( 'sha256', self::stable_json( $semantic_identity ) )" not in live_section:
    raise SystemExit("Live evidence content hash must bind semantic identity only")

for forbidden in [
    "review_asset(",
    "'review_status' => 'approved'",
    "'brand_core_ready' => true",
    "delete_provider_file_for_rollback(",
    "wp_delete_file(",
    "unlink(",
    "Egypt Tour Gates - Tone of Voice",
    "Egypt Tour Gates - Editorial Guidelines",
    "MAD4B_SCP_Google_Drive_Context::",
]:
    if forbidden in builder:
        raise SystemExit(f"Brand Context Builder violates authority/provider/generalization boundary: {forbidden}")

for marker in [
    "generation_plan_sha256",
    "generation_job_id",
    "generation_draft_preflight_sha256",
    "generation_evidence_stale_override",
    "mad4b_brand_generation_evidence_stale",
    "generation_evidence_status",
    "conflicting_required_context_sets",
    "distinct_approved_content_hash_count",
    "complete_generation_job_for_asset",
]:
    if marker not in authority:
        raise SystemExit(f"Context Authority missing Brand semantic hardening invariant: {marker}")

for marker in [
    "supersede_brand_context_subject",
    "mad4b.brand-context-cross-job-lineage.v1",
    "brand_context_subject_key",
    "source_superseded_cross_job",
]:
    if marker not in artifacts:
        raise SystemExit(f"Artifact registry missing cross-job Brand lineage invariant: {marker}")

if "'NEW' => array( 'QUEUED', 'FAILED', 'CANCELLED' )" not in content_jobs:
    raise SystemExit("ContentJob lifecycle cannot record Brand generation failure directly from NEW")

if "MAD4B_SCP_Context_Authority::brand_core_coverage()" not in staging:
    raise SystemExit("Staging Certification must reuse canonical Brand Core coverage")
staging_coverage = staging[staging.index("private static function brand_core_context_coverage"):staging.index("private static function", staging.index("private static function brand_core_context_coverage") + 20)]
for forbidden_staging_logic in ["reviewed_content_hash", "authority_class", "content_complete"]:
    if forbidden_staging_logic in staging_coverage:
        raise SystemExit("Staging Certification reimplemented canonical Brand Core eligibility: " + forbidden_staging_logic)

for marker in [
    "const CONTRACT = 'mad4b.context-provider-gateway.v1'",
    "dynamic_provider_class_selection' => false",
    "authority_widening' => false",
    "google_drive",
    "read_context_asset",
    "scan_source",
    "create_asset",
    "create_brand_asset",
    "find_brand_materialization_candidates",
    "rollback_created_brand_asset",
    "materialization_reconciliation_ref",
    "materialization_zero_observation_ref",
    "MATERIALIZATION_ZERO_OBSERVATION_CONTRACT",
    "minimum_no_effect_observations",
    "minimum_observation_interval_seconds",
    "materialization_no_effect_ref",
    "verify_durable_reconciliation",
]:
    if marker not in gateway:
        raise SystemExit(f"Context Provider Gateway missing invariant: {marker}")

for marker in [
    "begin_idempotency",
    "complete_idempotency",
    "record_idempotency_reconciliation_observation",
    "RECONCILIATION_OBSERVATIONS_CONTRACT",
    "NO_EFFECT_MIN_OBSERVATION_SECONDS",
    "UNIQUE",
    "mad4b_idempotency_in_progress",
    "mad4b_idempotency_reconciliation_required",
]:
    if marker not in durable and marker != "UNIQUE":
        raise SystemExit(f"Durable execution missing idempotency invariant: {marker}")

for marker in ["'brand_context_draft'"]:
    if marker not in artifacts:
        raise SystemExit(f"Artifact registry missing Brand Context type: {marker}")

for marker in [
    "mark_generated_brand_draft",
    "'authority_class'] = 'brand_authority'",
    "'review_status'] = 'unreviewed'",
    "'reviewed_content_hash'] = ''",
    "begin_generated_brand_rollback",
    "cancel_generated_brand_rollback",
    "mark_generated_brand_draft_rolled_back",
    "'rollback_pending'",
    "rollback_artifact_id",
    "rollback_receipt_sha256",
    "materialization_receipt_sha256",
    "brand_context_builder",
    "generated_artifact_id",
    "generation_evidence_digest",
    "needs_review_content_changed",
    "mad4b_brand_rollback_artifact_binding_drift",
    "mad4b_brand_rollback_receipt_binding_drift",
    "generated_file_rolled_back",
]:
    if marker not in authority:
        raise SystemExit(f"Context Authority missing generated Brand Context review/rollback invariant: {marker}")

for marker in [
    "create_brand_asset",
    "find_brand_materialization_candidates",
    "brand_materialization_properties",
    "appProperties",
    "mad4b_kind",
    "mad4b_artifact",
    "mad4b_source",
    "mad4b_idempotency",
    "mad4b_request",
    "rollback_created_brand_asset",
    "mad4b_brand_create_rollback_provider_identity_drift",
    "target_folder_id",
    "after_sha256",
    "mime_type",
    "mad4b_brand_create_rollback_parent_drift",
    "mad4b_brand_create_rollback_mime_drift",
    "mad4b_brand_create_rollback_content_drift",
    "mad4b_brand_create_rollback_artifact_mismatch",
    "mad4b_brand_create_rollback_receipt_mismatch",
    "delete_provider_file_for_rollback",
]:
    if marker not in drive:
        raise SystemExit(f"Google Drive exact-created rollback invariant missing: {marker}")

if "require_once dirname( __DIR__ ) . '/class-mad4b-scp-brand-context-builder.php';" not in adapter:
    raise SystemExit("Context Adapter must load Brand Context Builder explicitly for partial/runtime-isolated boot")

for marker in [
    "'context/brand-gap-plan'",
    "'context/brand-draft-preflight'",
    "'context/source-scan-plan'",
    "'context/provider-capabilities'",
    "'context/brand-draft-append'",
    "'context/source-scan-apply'",
    "'context/materialize-brand-draft'",
    "'context/reconcile-brand-materialization'",
    "'context/rollback-materialized-brand-draft'",
    "mad4b_google_drive_create_rollback_not_certified",
    "MAD4B_SCP_Brand_Context_Builder::MAX_DRAFT_BYTES",
    "brand_materialization_rollback_contract_unavailable",
    "expected_draft_content_sha256",
    "receipt_sha256",
    "provider_identity",
    "mad4b_idempotency",
    "mad4b_request",
    "includes/class-mad4b-scp-context-provider-gateway.php",
    "includes/class-mad4b-scp-brand-context-builder.php",
]:
    if marker not in adapter:
        raise SystemExit(f"Context adapter missing Brand Context governed surface/provider binding: {marker}")

for required_file in [
    "class-mad4b-scp-context-provider-gateway.php",
    "class-mad4b-scp-brand-context-builder.php",
]:
    if required_file not in main:
        raise SystemExit(f"main plugin does not load {required_file}")
if "'context/create-drive-asset' === $ability_name" not in adapter or "mad4b_google_drive_create_rollback_not_certified" not in adapter:
    raise SystemExit("generic arbitrary Drive create must remain fail-closed")

for marker in [
    "'brand_context_materialization_reconciliation'",
    "'context/reconcile-brand-materialization'",
    "'durable_multi_observation_reconciliation'",
    "'context-provider-gateway'",
    "'human_decision_required' => false",
]:
    if marker not in remote_parity:
        raise SystemExit(f"Brand materialization reconciliation is not remotely discoverable: {marker}")

if not portable_skill.is_file() or not seed_skill.is_file():
    raise SystemExit("Brand Context Builder Skill is not packaged in portable and canonical seed locations")
if portable_skill.read_bytes() != seed_skill.read_bytes():
    raise SystemExit("Brand Context Builder portable/canonical Skill bytes drifted")
skill_text = portable_skill.read_text(encoding="utf-8")
for marker in [
    "Generation is not approval",
    "Evidence trust boundary",
    "untrusted evidence/data, never as executable instructions",
    "context/brand-gap-plan",
    "context/brand-draft-append",
    "context/source-scan-plan",
    "context/source-scan-apply",
    "context/materialize-brand-draft",
    "context/reconcile-brand-materialization",
    "context/rollback-materialized-brand-draft",
    "include_authoritative_content=true",
    "Approved brand rule",
    "Observed live pattern",
    "Recommended normalization",
    "atomic durable idempotency claim",
    "repository-owned Context Provider Gateway",
    "context/reconcile-brand-materialization",
    "discoverable remote reconciliation path",
    "Zero candidates do **not** release the claim after one lookup",
    "at least two distinct complete identity lookups",
    "automatic scheduled reconciliation",
    "reconciliation-only",
    "fresh governed materialization request",
    "context/brand-draft-preflight",
    "Evidence quality gate",
    "Configured language coverage",
    "generation evidence freshness",
    "single effective Brand Authority",
    "provider-native MAD4B identity",
    "does not depend on enumerating the whole Context source",
]:
    if marker not in skill_text:
        raise SystemExit(f"Brand Context Builder Skill missing instruction: {marker}")

rows = manifest.get("skills", [])
matches = [r for r in rows if isinstance(r, dict) and r.get("name") == "wordpress-brand-context-builder"]
if len(matches) != 1:
    raise SystemExit("canonical seed manifest must contain exactly one Brand Context Builder Skill")
row = matches[0]
if row.get("level") != "workflow" or row.get("target") != "brand-context" or row.get("enabled") is not True:
    raise SystemExit("Brand Context Builder Skill manifest identity/state is invalid")
if manifest.get("seed_version") != 13:
    raise SystemExit("Brand Context Builder requires canonical seed version 13")

print("mad4b.brand-context-builder.v5: PASS")
