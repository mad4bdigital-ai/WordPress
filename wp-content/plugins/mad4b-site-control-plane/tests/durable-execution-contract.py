#!/usr/bin/env python3
"""Contract guard for Feature 007 durable execution primitives."""

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
DURABLE = (ROOT / "includes/class-mad4b-scp-durable-execution.php").read_text(encoding="utf-8")
SCHEMA = (ROOT / "includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")
MAIN = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

for marker in (
    "mad4b.durable-execution.v1",
    "mad4b.execution-plane.v1",
    "mad4b.idempotency-record.v1",
    "mad4b.execution-outbox.v1",
    "mad4b.execution-inbox.v1",
    "public static function begin_idempotency",
    "public static function reclaim_idempotency",
    "public static function release_idempotency_after_verified_no_effect",
    "released_verified_no_effect",
    "reclaimed_after_verified_no_effect",
    "idempotency_no_effect",
    "mad4b_idempotency_reconciliation_required",
    "mad4b_idempotency_hash_conflict",
    "claim_epoch",
    "mad4b_scp_durable_reconciliation_verified",
    "mad4b_reconciliation_unverified",
    "SELECT * FROM {$t['idempotency']} WHERE scope_key=%s AND idempotency_key=%s FOR UPDATE",
    "reconciliation_ref=%s",
    "public static function acquire_lease",
    "public static function reclaim_lease",
    "mad4b_lease_reconciliation_required",
    "mad4b_lease_terminal_reclaim_denied",
    "public static function assert_fencing_token",
    "mad4b_fence_epoch_stale",
    "mad4b_fence_epoch_unknown",
    "mad4b_fence_worker_mismatch",
    "mad4b_fence_lease_expired",
    "mad4b_fence_revision_mismatch",
    "public static function enqueue_outbox",
    "public static function accept_inbox",
    "mad4b_outbox_idempotency_conflict",
    "mad4b_outbox_job_invalid",
    "mad4b_outbox_revision_invalid",
    "mad4b_outbox_provider_invalid",
    "mad4b_outbox_capability_invalid",
    "mad4b_outbox_idempotency_key_invalid",
    "mad4b_outbox_available_at_invalid",
    "mad4b_inbox_event_conflict",
    "mad4b_inbox_job_conflict",
    "mad4b_inbox_execution_ref_conflict",
):
    if marker not in DURABLE:
        raise SystemExit(f"durable execution contract missing: {marker}")

load_marker = "includes/class-mad4b-scp-durable-execution.php"
if load_marker not in MAIN:
    raise SystemExit("durable execution class exists but is not loaded by the plugin runtime")
if MAIN.index(load_marker) > MAIN.index("includes/class-mad4b-scp-authorization.php"):
    raise SystemExit("durable execution must be loaded before governed execution is authorized")

version_match = re.search(r"const VERSION = (\d+);", SCHEMA)
if not version_match or int(version_match.group(1)) < 9:
    raise SystemExit("durable schema version must remain >= 9")
schema_version = int(version_match.group(1))
if f"mad4b_scp_schema_integrity_v{schema_version}" not in SCHEMA:
    raise SystemExit("durable schema integrity option does not match current schema version")

for marker in (
    "'work_leases' => $wpdb->prefix . 'mad4b_work_leases'",
    "'idempotency' => $wpdb->prefix . 'mad4b_idempotency'",
    "'outbox' => $wpdb->prefix . 'mad4b_execution_outbox'",
    "'inbox' => $wpdb->prefix . 'mad4b_execution_inbox'",
    "claim_epoch bigint(20) unsigned NOT NULL DEFAULT 1",
    "reconciliation_ref varchar(191) NOT NULL DEFAULT ''",
    "UNIQUE KEY scope_idempotency (scope_key,idempotency_key)",
    "UNIQUE KEY work_id (work_id)",
    "UNIQUE KEY provider_idempotency (provider_id,idempotency_key)",
    "UNIQUE KEY provider_event (provider_id,provider_event_id)",
    "private static function required_durable_columns()",
    "private static function required_durable_indexes()",
    "'missing_durable_columns'",
    "'missing_durable_indexes'",
    "'contract' => 'mad4b.schema-integrity.v4'",
):
    if marker not in SCHEMA:
        raise SystemExit(f"durable schema contract missing: {marker}")

# Expired work cannot become reusable merely because a clock elapsed.
idempotency_reclaim = DURABLE[DURABLE.index("public static function reclaim_idempotency"):]
idempotency_reclaim = idempotency_reclaim[: idempotency_reclaim.index("public static function scope_key")]
for marker in (
    "reconciliation_ref",
    "claim_epoch=%d",
    "status='pending'",
    "expires_at<=%s",
    "START TRANSACTION",
    "FOR UPDATE",
    "COMMIT",
    "ROLLBACK",
):
    if marker not in idempotency_reclaim:
        raise SystemExit(f"idempotency reclaim is not fail-closed: {marker}")

no_effect_release = DURABLE[DURABLE.index("public static function release_idempotency_after_verified_no_effect"):]
no_effect_release = no_effect_release[: no_effect_release.index("public static function reclaim_idempotency")]
for marker in (
    "START TRANSACTION",
    "FOR UPDATE",
    "reconciliation_verified( 'idempotency_no_effect'",
    "status='released_verified_no_effect'",
    "claim_epoch=%d",
    "status='pending'",
    "COMMIT",
    "ROLLBACK",
):
    if marker not in no_effect_release:
        raise SystemExit(f"verified no-effect idempotency release is not fail-closed: {marker}")

begin = DURABLE[DURABLE.index("public static function begin_idempotency"):]
begin = begin[: begin.index("public static function complete_idempotency")]
for marker in (
    "'released_verified_no_effect' === (string) $row['status']",
    "claim_epoch=%d",
    "status='pending'",
    "reconciliation_ref=''",
    "reclaimed_after_verified_no_effect",
):
    if marker not in begin:
        raise SystemExit(f"verified no-effect retry CAS is incomplete: {marker}")

lease_reclaim = DURABLE[DURABLE.index("public static function reclaim_lease"):]
lease_reclaim = lease_reclaim[: lease_reclaim.index("public static function heartbeat")]
if "'active' !== (string) $row['status']" not in lease_reclaim:
    raise SystemExit("terminal leases can be resurrected by reclaim")
if "reconciliation_ref" not in lease_reclaim or "lease_epoch=%d" not in lease_reclaim:
    raise SystemExit("lease reclaim lacks reconciliation evidence or epoch CAS")
if "reconciliation_verified(" not in lease_reclaim:
    raise SystemExit("lease reclaim does not require independently verified readback")

fence = DURABLE[DURABLE.index("public static function assert_fencing_token"):]
fence = fence[: fence.index("public static function complete_lease")]
for marker in (
    "lease_epoch <",
    "lease_epoch >",
    "worker_id",
    "status",
    "expires_at",
    "expected_aggregate_revision",
):
    if marker not in fence:
        raise SystemExit(f"zombie-worker fencing dependency missing: {marker}")

completion = DURABLE[DURABLE.index("public static function complete_idempotency"):]
completion = completion[: completion.index("public static function reclaim_idempotency")]
for marker in ("claim_epoch=%d", "expires_at>%s"):
    if marker not in completion:
        raise SystemExit(f"stale idempotency worker is not fenced at completion: {marker}")

lease_completion = DURABLE[DURABLE.index("public static function complete_lease"):]
lease_completion = lease_completion[: lease_completion.index("public static function enqueue_outbox")]
for marker in ("lease_epoch=%d", "status='active'", "expires_at>%s"):
    if marker not in lease_completion:
        raise SystemExit(f"expired lease can terminalize work: missing {marker}")

outbox = DURABLE[DURABLE.index("public static function enqueue_outbox"):]
outbox = outbox[: outbox.index("public static function accept_inbox")]
for marker in (
    "expected_job_revision < 1",
    "provider_id",
    "capability_id",
    "idempotency_key",
    "workflow_plan_sha256",
    "request_sha256",
    "mad4b_outbox_idempotency_conflict",
):
    if marker not in outbox:
        raise SystemExit(f"outbox identity validation missing: {marker}")

# Durable persistence primitives must not become an alternate provider/network
# execution surface.
for forbidden in (
    "wp_remote_get(",
    "wp_remote_post(",
    "wp_remote_request(",
    "curl_exec(",
    "call_user_func(",
):
    if forbidden in DURABLE:
        raise SystemExit(f"durable execution contains forbidden side-effect path: {forbidden}")

print("mad4b.durable-execution.v1: PASS")
