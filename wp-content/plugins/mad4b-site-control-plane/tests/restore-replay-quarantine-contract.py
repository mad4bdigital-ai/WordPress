from pathlib import Path

root = Path(__file__).resolve().parents[1]
restore = (root / "includes/class-mad4b-scp-restore-epoch.php").read_text(encoding="utf-8")
durable = (root / "includes/class-mad4b-scp-durable-execution.php").read_text(encoding="utf-8")

restore_markers = (
    "restore_epoch_site_identity_mismatch",
    "restore_epoch_database_snapshot_detected",
    "public static function acknowledge_restore_after_quarantine",
    "SET status='revoked' WHERE status IN ('pending','approved','executing')",
    "SET status='pending',expires_at=%s,reconciliation_ref=%s,updated_at=%s WHERE status IN ('pending','completed','released_verified_no_effect')",
    "SET status='failed',reconciliation_ref=%s,updated_at=%s WHERE status='active'",
    "mad4b_restore_projection_quarantine_failed",
    "'projection_cleared' => true",
)
for marker in restore_markers:
    if marker not in restore:
        raise SystemExit("RESTORE_REPLAY_QUARANTINE_INVARIANT_MISSING:" + marker)

durable_surfaces = (
    "begin_idempotency",
    "complete_idempotency",
    "complete_idempotency_from_reconciliation",
    "record_idempotency_reconciliation_observation",
    "release_idempotency_after_verified_no_effect",
    "reclaim_idempotency",
    "acquire_lease",
    "reclaim_lease",
    "heartbeat",
    "assert_fencing_token",
    "complete_lease",
    "enqueue_outbox",
    "accept_inbox",
)
for surface in durable_surfaces:
    marker = "self::restore_epoch_preflight( '" + surface + "' )"
    if marker not in durable:
        raise SystemExit("RESTORE_PREFLIGHT_SURFACE_MISSING:" + surface)

for marker in (
    "private static function restore_epoch_preflight",
    "MAD4B_SCP_Restore_Epoch::ensure_bound()",
    "mad4b_durable_restore_epoch_quarantined",
    "'blind_retry_allowed' => false",
):
    if marker not in durable:
        raise SystemExit("RESTORE_DURABLE_FAIL_CLOSED_MISSING:" + marker)

print("mad4b.restore-replay-quarantine.contract.v1: PASS")
