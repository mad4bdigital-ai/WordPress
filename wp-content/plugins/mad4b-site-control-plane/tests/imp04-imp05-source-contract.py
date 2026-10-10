#!/usr/bin/env python3
"""IMP04/05 review-only evidence assertions. Never a native runtime PASS."""
from pathlib import Path
P=Path(__file__).resolve().parents[1]
repo=P.parent.parent.parent
get=lambda x:(P/"includes"/x).read_text(encoding="utf8")
profile=get("class-mad4b-scp-content-experience-profiles.php")
policy=get("class-mad4b-scp-activity-import-authority.php")
review=get("class-mad4b-scp-activity-import-review.php")
identity=get("class-mad4b-scp-activity-import-reconciliation.php")
observer=get("class-mad4b-scp-activity-wpai-observer.php")
test=(P/"tests"/"imp04-reconciliation-runtime.php").read_text(encoding="utf8")
hooks=(P/"tests"/"imp05-wpai-observer-runtime.php").read_text(encoding="utf8")
mutex=get("class-mad4b-scp-batch-atomic-mutex.php")
spec=(repo/"specs/007-content-intelligence-workflow-platform/extensions/imp04-imp05-import-reconciliation-and-provider-observation.md").read_text(encoding="utf8")
def insist(ok,label):
    if not ok: raise AssertionError(label)
for marker in (
    "'destination_identity_meta_key'",
    "'mad4b_import_identity_destination_not_allowed'",
    "'mad4b_import_duplicate_destination_mapping'",
):
    insist(marker in policy,"Imported identity policy not constrained: "+marker)
for marker in (
    "function plan(", "get_posts(", "get_post_meta(",
    "'meta_key' => $meta_key", "'fields' => 'ids'",
    "'numberposts' => 2", "destination_identity_not_exact",
    "source_identity_sha256", "page_size > 25",
    "provider_execution_provenance_verified' => false",
    "multi_provider_write_fence_verified' => false",
    "ready_for_automatic_import' => false",
):
    insist(marker in identity, "Missing scoped readback/refusal: "+marker)
for marker in (
    "function issues_page(", "inspect( $loaded['input'], $offset )",
    "mad4b_import_issues_source_stale", "'issue_offset' => $issue_offset",
    "wp_all_import_plan", "mad4b_wpai_identity_mismatch",
):
    insist(marker in review,"Missing full conflict pagination/site mapping: "+marker)
for marker in (
    "pmxi_before_xml_import", "pmxi_saved_post", "pmxi_after_xml_import",
    "'external_import_end_observed_unverified'",
    "configured_import_verified' => false",
    "approved_file_source_config_verified' => false",
    "provider_write_fence_verified' => false",
    "job_execution_authorized' => false",
    "mad4b_wpai_observation_active",
):
    insist(marker in observer,"Unbounded or unverified external job observation: "+marker)
for suffix in (
    "business-activity-import-reconciliation-plan",
    "business-activity-import-issues-page",
    "business-activity-wpai-observation-plan",
    "business-activity-wpai-observation-status",
    "business-activity-wpai-observation-arm",
    "business-activity-import-approval-plan",
    "business-activity-import-approval-receipt",
    "business-activity-import-approve",
):
    insist(profile.count("'mad4b/"+suffix+"'")>=2,
           "MCP ability inventory or definition missing: "+suffix)
for marker in (
    "mad4b_import_identity_registry_not_configured",
    "counts']['ambiguous']", "get_post_meta",
):
    insist(marker in test,"Native fixture missing negative case: "+marker)
for marker in (
    "external_import_end_observed_unverified",
    "mad4b_wpai_observation_active",
    "source_provenance_verified",
):
    insist(marker in hooks,"Hook fixture incorrectly certifies success: "+marker)
insist("MAD4B_SCP_Batch_Atomic_Mutex::reserve_observation( $key, $record )" in observer,
       "WP All Import observer must reserve a unique option atomically")
insist("add_option( $key, $record" not in observer,
       "WPAI observation still uses an UPSERT-prone WordPress Options reservation")
insist("function reserve_observation(" in mutex and
       "INSERT IGNORE INTO" in mutex and
       "'mad4b_wpai_observation_active'" in mutex and
       "hash_equals( $raw, (string) self::read( $db, $name ) )" in mutex,
       "Atomic WPAI observer reservation/readback missing")
insist("Stale Options cache cannot overwrite the observation SQL reservation" in hooks,
       "Missing concurrent-like stale cache observation regression")
insist("not delivered" in spec.lower() and "not authorized" in spec.lower(),
       "Spec must explicitly distinguish source code from operational authority")
print("PASS IMP04/05 read-only identity reconciliation, full issue pages, WP All Import hook observation and no-false-certification source contracts (STATIC ONLY)")
