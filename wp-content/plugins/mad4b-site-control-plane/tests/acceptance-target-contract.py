#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / "includes/adapters/class-mad4b-scp-acceptance-target-adapter.php"
BASE = ROOT / "includes/adapters/class-mad4b-scp-adapter-base.php"

source = ADAPTER.read_text(encoding="utf-8")
base = BASE.read_text(encoding="utf-8")


def require(fragment: str, message: str) -> None:
    if fragment not in source:
        raise AssertionError(message)


if "class-mad4b-scp-acceptance-target-adapter.php" not in base:
    raise AssertionError("acceptance target adapter is not loaded by the adapter base bootstrap")

require("'staging' !== MAD4B_SCP_Site_Profile::current_environment()", "provisioner must be staging-only")
require("MAD4B_SCP_Site_Profile::origin_enrolled()", "exact enrolled origin guard is required")
require("MAD4B_SCP_Site_Profile::site_urls_match_enrollment()", "site URL enrollment guard is required")
require("protected function certified_provider_key() { return 'core'; }", "provisioner must use core provider authority")
require("protected function mutation_requires_certification() { return false; }", "core provisioner must not depend on provider certification")
require("const STATUS_ABILITY = 'mad4b/acceptance-target-status';", "read-only target discovery ability is required")
require("const PROVISION_ABILITY = 'mad4b/acceptance-target-provision';", "governed provision ability is required")
require("const RESTORE_CONTRACT = 'mad4b.rollback.acceptance-target.v1';", "certified cleanup contract is required")
require("'read' => array( self::STATUS_ABILITY )", "target status must be mounted read-only")
require("'write' => array( self::PROVISION_ABILITY )", "provisioner must be projected onto mad4b-write")
require("MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status()", "live build provenance must bind provisioning")
require("'site_profile_revision' => (int) MAD4B_SCP_Site_Profile::revision()", "profile revision must bind provisioning")
require("'site_profile_digest' => strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() )", "profile digest must bind provisioning")
require("'post_type' => 'post'", "fixture must use the fixed post type")
require("'post_status' => 'draft'", "fixture must be draft-only")
require("'post_content' => ''", "fixture content must be fixed and empty")
require("'post_excerpt' => ''", "fixture excerpt must start empty")
require("META_BINDING", "exact candidate binding metadata is required")
require("META_RECORD", "bounded target marker metadata is required")
require("count( $ids ) > 1", "duplicate exact-bound fixtures must fail closed")
require("wp_delete_post( $post_id, true )", "cleanup must hard-delete only the exact isolated fixture")
require("mad4b_acceptance_target_recorded_binding_stale", "cleanup must reject stale candidate bindings")

# The remote caller may assert only immutable profile/build identity. Target shape is server-owned.
schema_match = re.search(
    r"self::PROVISION_ABILITY,.*?\$this->schema\(\s*array\((.*?)\),\s*array\(\s*'expected_revision'.*?'expected_build_fingerprint'\s*\)\s*\)",
    source,
    re.S,
)
if not schema_match:
    raise AssertionError("unable to locate bounded provision input schema")
props = re.findall(r"'([a-z0-9_]+)'\s*=>\s*array\(", schema_match.group(1))
expected = {
    "expected_revision",
    "expected_profile_digest",
    "expected_source_commit_sha",
    "expected_build_fingerprint",
}
if set(props) != expected or len(props) != 4:
    raise AssertionError(f"provision schema widened unexpectedly: {props}")

for forbidden in ("post_id", "post_type", "post_status", "post_title", "post_content", "post_excerpt", "agent_public_id", "server_id", "provider", "raw_sql", "breakglass"):
    if forbidden in props:
        raise AssertionError(f"caller-selectable target/authority input is forbidden: {forbidden}")

# The logical reversible snapshot intentionally excludes post_modified_gmt so a
# content-update/undo cycle can restore fields and still permit fixture cleanup.
state_block = re.search(r"private function state_for_binding\(.*?\n\s*}\n\n\s*private function fixed_title", source, re.S)
if not state_block:
    raise AssertionError("target state function missing")
if "post_modified_gmt" in state_block.group(0):
    raise AssertionError("provision cleanup snapshot must not bind WordPress modified timestamp")

if "hostinger-ai-assistant" in source.lower():
    raise AssertionError("generic Hostinger writes must not be used by the canonical provisioner")

print("acceptance target source contract: PASS")
