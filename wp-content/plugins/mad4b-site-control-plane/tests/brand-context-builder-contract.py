#!/usr/bin/env python3
import json
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
cp = repo / "wp-content" / "plugins" / "mad4b-site-control-plane"
builder = (cp / "includes" / "class-mad4b-scp-brand-context-builder.php").read_text(encoding="utf-8")
authority = (cp / "includes" / "class-mad4b-scp-context-authority.php").read_text(encoding="utf-8")
drive = (cp / "includes" / "class-mad4b-scp-google-drive-context.php").read_text(encoding="utf-8")
adapter = (cp / "includes" / "adapters" / "class-mad4b-scp-context-adapter.php").read_text(encoding="utf-8")
artifacts = (cp / "includes" / "class-mad4b-scp-artifacts.php").read_text(encoding="utf-8")
main = (cp / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
manifest = json.loads((cp / "config" / "skill-seed-manifest.json").read_text(encoding="utf-8"))
portable_skill = repo / "plugins" / "mad4b-wordpress" / "skills" / "wordpress-brand-context-builder" / "SKILL.md"
seed_skill = cp / "skill-seeds" / "wordpress-brand-context-builder" / "SKILL.md"

for marker in [
    "const CONTRACT = 'mad4b.brand-context-builder.v1'",
    "const PLAN_CONTRACT = 'mad4b.brand-gap-plan.v1'",
    "const DRAFT_CONTRACT = 'mad4b.brand-context-draft.v1'",
    "const SCAN_PLAN_CONTRACT = 'mad4b.context-source-scan-plan.v1'",
    "const MATERIALIZE_CONTRACT = 'mad4b.brand-context-materialization.v1'",
    "const ROLLBACK_CONTRACT = 'mad4b.rollback.google-drive-brand-context-create.v1'",
    "'tone_of_voice'",
    "'editorial_guidelines'",
    "'approved_brand_strategy_required'",
    "'generation_is_authority' => false",
    "'approval_required_before_brand_core_ready' => true",
    "'review_status' => 'unreviewed'",
    "'quality_provisional' => true",
    "idempotency_key",
    "evidence_digest",
    "expected_plan_sha256",
    "expected_provider_inventory_digest",
    "expected_registry_revision",
    "mad4b_context_source_scan_incomplete",
    "array( 'markdown', 'text' )",
    "'brand_core_ready' => false",
]:
    if marker not in builder:
        raise SystemExit(f"missing Brand Context Builder invariant: {marker}")

for forbidden in [
    "review_asset(",
    "'review_status' => 'approved'",
    "'brand_core_ready' => true",
    "delete_provider_file_for_rollback(",
    "wp_delete_file(",
    "unlink(",
]:
    if forbidden in builder:
        raise SystemExit(f"Brand Context Builder may not self-authorize or delete arbitrary provider/filesystem state: {forbidden}")

for marker in [
    "'brand_context_draft'",
]:
    if marker not in artifacts:
        raise SystemExit(f"Artifact registry missing Brand Context type: {marker}")

for marker in [
    "mark_generated_brand_draft",
    "'authority_class'] = 'brand_authority'",
    "'review_status'] = 'unreviewed'",
    "'reviewed_content_hash'] = ''",
    "mark_generated_brand_draft_rolled_back",
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
    "delete_provider_file_for_rollback",
]:
    if marker not in drive:
        raise SystemExit(f"Google Drive exact-created rollback invariant missing: {marker}")

for marker in [
    "'context/brand-gap-plan'",
    "'context/source-scan-plan'",
    "'context/brand-draft-append'",
    "'context/source-scan-apply'",
    "'context/materialize-brand-draft'",
    "'context/rollback-materialized-brand-draft'",
    "mad4b_google_drive_create_rollback_not_certified",
    "MAD4B_SCP_Brand_Context_Builder::MAX_DRAFT_BYTES",
    "MAD4B_SCP_Brand_Context_Builder::ROLLBACK_CONTRACT",
    "includes/class-mad4b-scp-brand-context-builder.php",
]:
    if marker not in adapter:
        raise SystemExit(f"Context adapter missing Brand Context governed surface/provider binding: {marker}")

if "class-mad4b-scp-brand-context-builder.php" not in main:
    raise SystemExit("main plugin does not load Brand Context Builder")
if "'context/create-drive-asset' === $ability_name" not in adapter or "mad4b_google_drive_create_rollback_not_certified" not in adapter:
    raise SystemExit("generic arbitrary Drive create must remain fail-closed")

if not portable_skill.is_file() or not seed_skill.is_file():
    raise SystemExit("Brand Context Builder Skill is not packaged in portable and canonical seed locations")
if portable_skill.read_bytes() != seed_skill.read_bytes():
    raise SystemExit("Brand Context Builder portable/canonical Skill bytes drifted")
skill_text = portable_skill.read_text(encoding="utf-8")
for marker in [
    "Generation is not approval",
    "context/brand-gap-plan",
    "context/brand-draft-append",
    "context/source-scan-plan",
    "context/source-scan-apply",
    "context/materialize-brand-draft",
    "context/rollback-materialized-brand-draft",
    "Approved brand rule",
    "Observed live pattern",
    "Recommended normalization",
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
if manifest.get("seed_version") != 8:
    raise SystemExit("Brand Context Builder requires canonical seed version 8")

print("mad4b.brand-context-builder.v1: PASS")
