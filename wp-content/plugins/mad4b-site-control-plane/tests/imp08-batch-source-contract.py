#!/usr/bin/env python3
"""IMP08 static review of encrypted batch API and manual-only safety. Not runtime certification."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def source(name):
    return (ROOT/"includes"/name).read_text(encoding="utf8")
batch=source("class-mad4b-scp-activity-import-batches.php")
profiles=source("class-mad4b-scp-content-experience-profiles.php")
ux=source("class-mad4b-scp-activity-import-experience.php")
fixture=(ROOT/"tests"/"imp08-batch-review-runtime.php").read_text(encoding="utf8")
preflight=(ROOT/"tests"/"feature007-manual-preflight.py").read_text(encoding="utf8")
def require(ok,why):
    if not ok: raise AssertionError(why)
for marker in (
    "const MAX_CHUNKS = 10", "$input['expected_chunks'] < 2",
    "'admin_csv_upload'", "MAD4B_SCP_Activity_Import_Authority::mode_allowed",
    "add_option( $names['active']", "add_option( $names['manifest']",
    "'chunk_index' => $index", "aes-256-gcm", "OPENSSL_RAW_DATA",
    "hash_equals( $part['payload_sha256']", "MAD4B_SCP_Activity_Import_Review::plan",
    "cross_chunk_duplicate_id_count", "cross_chunk_wpml_group_split_count",
    "ready_for_manual_batch_review", "manual_csv_chunk_export_only",
    "acknowledged_warning_count", "fpassthru( $stream )",
    "mad4b_batch_csv_unsafe", "check_admin_referer(",
    "mad4b_batch_archive_audit_failed", "provider_execution_authorized' => false",
    "automatic_execution_allowed' => false", "wordpress_post_writes' => 0"
):
    require(marker in batch,"Missing guarded import batching feature: "+marker)
for operation in ("batch-begin","batch-append","batch-verify","batch-approve","batch-archive"):
    label="'mad4b/business-activity-import-"+operation+"'"
    require(profiles.count(label)>=2,
            "Missing governed batch ability declaration/inventory "+operation)
require("class-mad4b-scp-activity-import-batches.php" in profiles,
        "Batch runtime missing in WordPress bootstrap")
require("approved_for_manual_chunk_export" in ux and
        "Download approved part" in ux,
        "WordPress manual-only bulk handoff missing")
require("imp08_many( 1, 250, 'grp' )" in fixture and
        "total_rows'] === 502" in fixture and
        "cross_chunk_duplicate_id_count" in fixture and
        "manual_csv_chunk_export_only" in fixture,
        "No realistic >500 rows / collision / approval negative simulation")
for name in ("class-mad4b-scp-activity-import-batches.php",
             "imp08-batch-review-runtime.php","imp08-batch-source-contract.py"):
    require(name in preflight,"Manual native preflight omitted "+name)
require("wp_insert_post(" not in batch and "wp_update_post(" not in batch and
        "wp_delete_post(" not in batch,
        "Batched review must never mutate WordPress posts")
print("PASS IMP08 encrypted chunk source contracts, full batch approval, isolation, cross-chunk identity and manual-only export (STATIC ONLY)")
