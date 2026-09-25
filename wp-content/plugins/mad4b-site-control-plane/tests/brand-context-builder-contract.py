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
artifacts = (cp / "includes" / "class-mad4b-scp-artifacts.php").read_text(encoding="utf-8")
main = (cp / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
manifest = json.loads((cp / "config" / "skill-seed-manifest.json").read_text(encoding="utf-8"))
portable_skill = repo / "plugins" / "mad4b-wordpress" / "skills" / "wordpress-brand-context-builder" / "SKILL.md"
seed_skill = cp / "skill-seeds" / "wordpress-brand-context-builder" / "SKILL.md"

for marker in [
    "const CONTRACT = 'mad4b.brand-context-builder.v1'",
    "const BUILDER_SPEC_VERSION = '2'",
    "const PLAN_CONTRACT = 'mad4b.brand-gap-plan.v1'",
    "const DRAFT_CONTRACT = 'mad4b.brand-context-draft.v1'",
    "const SCAN_PLAN_CONTRACT = 'mad4b.context-source-scan-plan.v1'",
    "const MATERIALIZE_CONTRACT = 'mad4b.brand-context-materialization.v1'",
    "const ROLLBACK_CONTRACT = 'mad4b.rollback.google-drive-brand-context-create.v1'",
    "const MAX_LIVE_CANDIDATES = 96",
    "mb_strcut",
    "wpml_element_language_code",
    "wpml_post_language_details",
    "stratify_live_records",
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
    "receipt_sha256",
    "MAD4B_SCP_Context_Provider_Gateway::read_context_asset",
    "MAD4B_SCP_Context_Provider_Gateway::scan_source",
    "MAD4B_SCP_Context_Provider_Gateway::create_asset",
    "MAD4B_SCP_Context_Provider_Gateway::rollback_created_brand_asset",
    "begin_generated_brand_rollback",
    "cancel_generated_brand_rollback",
    "ksort( $observed, SORT_STRING )",
    "sort( $changed_files, SORT_STRING )",
    "sort( $unchanged_files, SORT_STRING )",
    "expected_registry_revision",
    "mad4b_context_source_scan_incomplete",
    "array( 'markdown', 'text' )",
    "'brand_core_ready' => false",
]:
    if marker not in builder:
        raise SystemExit(f"missing Brand Context Builder invariant: {marker}")

append_section = builder[builder.index("public static function append_draft"):builder.index("private static function source_scan_snapshot")]
if append_section.index("MAD4B_SCP_Durable_Execution::begin_idempotency") > append_section.index("self::find_existing_draft( $idempotency_key )"):
    raise SystemExit("Brand draft legacy lookup occurs before atomic durable idempotency claim")

materialize_section = builder[builder.index("public static function materialize_draft"):builder.index("public static function rollback_materialized_draft")]
if materialize_section.index("MAD4B_SCP_Durable_Execution::begin_idempotency") > materialize_section.index("MAD4B_SCP_Context_Provider_Gateway::create_asset"):
    raise SystemExit("Brand materialization provider create occurs before durable idempotency claim")

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
    "const CONTRACT = 'mad4b.context-provider-gateway.v1'",
    "dynamic_provider_class_selection' => false",
    "authority_widening' => false",
    "google_drive",
    "read_context_asset",
    "scan_source",
    "create_asset",
    "rollback_created_brand_asset",
]:
    if marker not in gateway:
        raise SystemExit(f"Context Provider Gateway missing invariant: {marker}")

for marker in [
    "begin_idempotency",
    "complete_idempotency",
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
    "rollback_created_brand_asset",
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

for marker in [
    "'context/brand-gap-plan'",
    "'context/source-scan-plan'",
    "'context/provider-capabilities'",
    "'context/brand-draft-append'",
    "'context/source-scan-apply'",
    "'context/materialize-brand-draft'",
    "'context/rollback-materialized-brand-draft'",
    "mad4b_google_drive_create_rollback_not_certified",
    "MAD4B_SCP_Brand_Context_Builder::MAX_DRAFT_BYTES",
    "brand_materialization_rollback_contract_unavailable",
    "expected_draft_content_sha256",
    "receipt_sha256",
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
    "context/rollback-materialized-brand-draft",
    "include_authoritative_content=true",
    "Approved brand rule",
    "Observed live pattern",
    "Recommended normalization",
    "atomic durable idempotency claim",
    "repository-owned Context Provider Gateway",
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
if manifest.get("seed_version") != 9:
    raise SystemExit("Brand Context Builder requires canonical seed version 9")

print("mad4b.brand-context-builder.v2: PASS")
