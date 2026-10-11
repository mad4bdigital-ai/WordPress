#!/usr/bin/env python3
"""IMP14 source guard: true INSERT-ONLY mutex and whole-response export fence.
Static test ONLY. MySQL concurrent workers must also pass before release.
"""
from pathlib import Path
P = Path(__file__).resolve().parents[1]
get = lambda f:(P/"includes"/f).read_text(encoding="utf8")
atomic = get("class-mad4b-scp-batch-atomic-mutex.php")
batch = get("class-mad4b-scp-activity-import-batches.php")
snapshot = get("class-mad4b-scp-activity-import-snapshot.php")
review = get("class-mad4b-scp-activity-import-review.php")
fixture = (P/"tests"/"imp08-batch-review-runtime.php").read_text(encoding="utf8")
preflight = (P/"tests"/"feature007-manual-preflight.py").read_text(encoding="utf8")
mysql_test = (P/"tests"/"imp14-batch-mysql-concurrency.php").read_text(encoding="utf8")
observer = get("class-mad4b-scp-activity-wpai-observer.php")
observer_fixture = (P/"tests"/"imp05-wpai-observer-runtime.php").read_text(encoding="utf8")
def require(ok, msg):
    if not ok: raise AssertionError(msg)
for guard in (
    "INSERT IGNORE INTO", "1 !== $inserted",
    "SELECT option_value FROM", "get_var(",
    "hash_equals( $value, $observed )",
    "DELETE FROM", "BINARY option_value = BINARY %s LIMIT 1",
    "1 !== $deleted", "null !== self::read( $db, $key )",
    "'begin', 'append', 'approve', 'archive', 'export'",
):
    require(guard in atomic, "Atomic lock proof missing: "+guard)
require("ON DUPLICATE KEY UPDATE" not in atomic.split("public static function acquire")[1],
        "Mutex reservation overwrites an existing owner")
require("add_option( $lock_key" not in batch,
        "WordPress add_option UPSERT is not an atomic reservation")
require("self::source_with_lock( $slug, 'append'" in snapshot and
        "self::source_with_lock( $slug, 'approve'" in snapshot and
        "self::source_with_lock( $slug, 'export'" in snapshot and
        "self::source_with_lock( $slug, 'archive'" in snapshot and
        "function archive_exact_review(" in snapshot and
        "insert_immutable(" in snapshot and
        "public static function approved_csv_download()" in review and
        "if ( true === $result ) exit;" in review,
        "Single snapshot review/export/archive does not share the exact Profile lock")
require("exit;" not in snapshot.split("private static function export_approved_csv_unlocked(")[1],
        "Single-source CSV download exits before releasing the exact owner mutex")
single_fixture = (P/"tests"/"imp14-single-snapshot-atomic-runtime.php").read_text(encoding="utf8")
require("imp14-single-snapshot-atomic-runtime.php" in preflight and
        "archive_exact_review(" in single_fixture and
        "reserve_signed_nonce(" in single_fixture,
        "Missing snapshot archive/export/nonce native regression fixture")
require("MAD4B_SCP_Batch_Atomic_Mutex::insert_immutable( $store, $record )" in snapshot and
        "MAD4B_SCP_Batch_Atomic_Mutex::insert_immutable( $key, $approval )" in snapshot and
        "add_option( $store, $record" not in snapshot and
        "add_option( $key, $approval" not in snapshot,
        "Concurrent snapshot/approval can overwrite immutable source receipts")
require("MAD4B_SCP_Batch_Atomic_Mutex::reserve_signed_nonce(" in review and
        "add_option( $nonce_key" not in review and
        "reserve_signed_nonce(" in atomic,
        "Signed webhook replay protection is not atomic")
for guard in (
    "MAD4B_SCP_Batch_Atomic_Mutex::acquire( $slug, $operation )",
    "MAD4B_SCP_Batch_Atomic_Mutex::release( $lease )",
    "MAD4B_SCP_Batch_Atomic_Mutex::acquire( $slug, 'export' )",
    "self::export_chunk_unlocked( $slug, $id, $index )",
    "public static function export_chunk( $slug, $id, $index )",
    "return true;", "mad4b_batch_export_partial_transfer"
):
    require(guard in batch, "Unfenced/partial CSV export path: "+guard)
section = batch.split("private static function export_chunk_unlocked(")[1].split(
    "public static function status(",1)[0]
require("exit;" not in section, "CSV response exits before mutex release")
require("function reserve_observation(" in atomic and
        "MAD4B_SCP_Batch_Atomic_Mutex::reserve_observation( $key, $record )" in observer and
        "add_option( $key, $record" not in observer and
        "Stale Options cache cannot overwrite the observation SQL reservation" in observer_fixture,
        "IMP05 observation arming still permits a racing Options UPSERT")
for guard in (
    "--expected-head", "--expected-site-uuid",
    "source_manifest_head_matches_requested",
    "host_attestation_certified' => false",
    "plugin_filesystem_hash_verified' => false",
    "'--expected-head=' . $expected_head",
    "'--expected-site-uuid=' . $expected_site",
):
    require(guard in mysql_test, "Missing exact-identity Staging MySQL gate: "+guard)
for guard in (
    "IMP14_SQL_Mutex_DB",
    "INSERT IGNORE INTO",
    "MAD4B_SCP_Batch_Atomic_Mutex::acquire( 'pricing', 'export' )",
    "MAD4B_SCP_Batch_Atomic_Mutex::acquire( 'pricing', 'archive' )",
    "MAD4B_SCP_Batch_Atomic_Mutex::release( $bad_owner )",
    "mad4b_batch_mutation_locked"
):
    require(guard in fixture, "Missing native mutex negative fixture: "+guard)
for path in ("class-mad4b-scp-batch-atomic-mutex.php",
             "imp14-batch-atomic-source-contract.py"):
    require(path in preflight, "Exact-HEAD preflight lacks: "+path)
require("proc_open(" in mysql_test and "contenders_refused" in mysql_test and "Staging" in mysql_test, "Missing real DB test harness")
print("PASS IMP14 source reservation/owner release and export/archive fencing (STATIC ONLY)")
