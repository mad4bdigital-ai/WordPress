#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
audit = (root / "includes/class-mad4b-scp-audit.php").read_text(encoding="utf-8")
integrity = (root / "includes/class-mad4b-scp-audit-integrity.php").read_text(encoding="utf-8")
schema = (root / "includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")

def require(src, marker, label):
    if marker not in src:
        raise SystemExit(f"FAIL {label}: missing {marker}")

for marker in (
    "const SCALE_CONTRACT = 'mad4b.audit-scale.v1';",
    "const HIGH_VOLUME_THRESHOLD_EVENTS = 100000;",
    "'append_integrity_mode' =",
):
    pass

require(audit, "const SCALE_CONTRACT = 'mad4b.audit-scale.v1';", "scale-contract")
require(audit, "const HIGH_VOLUME_THRESHOLD_EVENTS = 100000;", "scale-threshold")
require(audit, "'append_integrity_mode'] = 'committed_count_head_tail_consistency';", "append-integrity")
require(audit, "'append_integrity_event_count_guard'] = true;", "count-guard")
require(audit, "'append_count_index'] = 'chain_sequence';", "count-index")
require(audit, "'full_chain_verification_mode'] = 'explicit_batched';", "verify-mode")
require(audit, "'full_chain_verification_batch_events'] = self::VERIFY_BATCH;", "verify-batch")
require(audit, "'full_chain_verification_on_append'] = false;", "append-hotpath")
require(audit, "'high_volume_load_test_certified'] = false;", "no-overclaim")
require(audit, "'high_volume_load_test_required'] =", "load-qualification")

record = audit.split("public static function record(", 1)[1].split("public static function ensure_head_initialized", 1)[0]
if "verify_chain(" in record:
    raise SystemExit("FAIL audit-scale-hotpath: full chain verification returned to append hot path")

snapshot = integrity.split("private static function committed_snapshot", 1)[1].split("public static function verify_chain", 1)[0]
require(snapshot, "SELECT COUNT(*) FROM {$events_table} WHERE chain_name = %s", "count-consistency")
require(snapshot, "ORDER BY sequence DESC LIMIT 1", "tail-consistency")

verify = integrity.split("public static function verify_chain", 1)[1].split("public static function entry_hash", 1)[0]
require(verify, "MAD4B_SCP_Audit::VERIFY_BATCH", "bounded-chain-verification")
require(verify, "sequence >= %d AND sequence <= %d", "indexed-sequence-window")
require(schema, "UNIQUE KEY chain_sequence (chain_name,sequence)", "chain-sequence-index")

print("mad4b.audit-scale.v1: PASS")
