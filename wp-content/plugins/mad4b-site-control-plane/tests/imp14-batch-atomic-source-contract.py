#!/usr/bin/env python3
"""IMP14 source guard: true INSERT-ONLY mutex and whole-response export fence.
Static test ONLY. MySQL concurrent workers must also pass before release.
"""
from pathlib import Path
P = Path(__file__).resolve().parents[1]
get = lambda f:(P/"includes"/f).read_text(encoding="utf8")
atomic = get("class-mad4b-scp-batch-atomic-mutex.php")
batch = get("class-mad4b-scp-activity-import-batches.php")
fixture = (P/"tests"/"imp08-batch-review-runtime.php").read_text(encoding="utf8")
preflight = (P/"tests"/"feature007-manual-preflight.py").read_text(encoding="utf8")
mysql_test = (P/"tests"/"imp14-batch-mysql-concurrency.php").read_text(encoding="utf8")
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
