#!/usr/bin/env python3
"""Contract guard for Feature 007 durable execution primitives."""

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
DURABLE = (ROOT / "includes/class-mad4b-scp-durable-execution.php").read_text(encoding="utf-8")
DB_BOUNDARY = (ROOT / "includes/class-mad4b-scp-durable-db-boundary.php").read_text(encoding="utf-8")
STATE_VIEW = (ROOT / "includes/class-mad4b-scp-execution-state-view.php").read_text(encoding="utf-8")
SCHEMA = (ROOT / "includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")
MAIN = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

for marker in (
    "mad4b.durable-execution.v1",
    "write_topology_preflight",
    "same_writer_after_write",
    "database_write_failure",
    "authoritative_read_failure",
    "mad4b.execution-plane.v1",
    "mad4b.idempotency-record.v1",
    "mad4b.execution-outbox.v1",
    "mad4b.execution-inbox.v1",
    "public static function begin_idempotency",
    "public static function reclaim_idempotency",
    "public static function record_idempotency_reconciliation_observation",
    "mad4b.idempotency-reconciliation-observations.v1",
    "NO_EFFECT_MIN_OBSERVATION_SECONDS",
    "MAX_RECONCILIATION_OBSERVATIONS",
    "idempotency_observation",
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

for marker in (
    "mad4b.execution-state-view.v1",
    "public static function operation",
    "public static function idempotency",
    "public static function mutation_error",
    "normalize_journal_status",
    "normalize_resume_status",
    "normalize_mutation_evidence",
    "journal_orphan_candidate",
    "journal_terminal_outcome_missing",
    "idempotency_pending_requires_reconciliation",
    "mutation_outcome_requires_reconciliation",
    "'blind_retry_allowed' => false",
    "'authority_created' => false",
):
    if marker not in STATE_VIEW:
        raise SystemExit(f"execution state view contract missing: {marker}")
for forbidden in (
    "INSERT ",
    "UPDATE ",
    "DELETE ",
    "wp_remote_get(",
    "wp_remote_post(",
    "wp_remote_request(",
    "curl_exec(",
):
    if forbidden in STATE_VIEW:
        raise SystemExit(f"execution state view contains forbidden mutation/network path: {forbidden}")
state_view_load = "includes/class-mad4b-scp-execution-state-view.php"
if state_view_load not in MAIN:
    raise SystemExit("execution state view exists but is not loaded by the plugin runtime")
if MAIN.index(state_view_load) < MAIN.index("includes/class-mad4b-scp-operation-resume.php"):
    raise SystemExit("execution state view must load after its read-only source adapters")

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

# Idempotency status literals must fit the physical varchar(32) contract.
idempotency_surface = DURABLE[DURABLE.index("public static function begin_idempotency"):DURABLE.index("public static function scope_key")]
status_literals = sorted(set(re.findall(r"status='([^']+)'", idempotency_surface)))
oversized_statuses = [value for value in status_literals if len(value) > 32]
if oversized_statuses:
    raise SystemExit(f"idempotency status literal exceeds varchar(32): {oversized_statuses}")

# Expired work cannot become reusable merely because a clock elapsed.
idempotency_reclaim = DURABLE[DURABLE.index("public static function reclaim_idempotency"):]
idempotency_reclaim = idempotency_reclaim[: idempotency_reclaim.index("public static function scope_key")]
for marker in (
    "reconciliation_ref",
    "claim_epoch=%d",
    "status='pending'",
    "expires_at<=%s",
    "begin_owned_transaction",
    "FOR UPDATE",
    "commit_owned_transaction",
    "rollback_owned_transaction",
):
    if marker not in idempotency_reclaim:
        raise SystemExit(f"idempotency reclaim is not fail-closed: {marker}")

no_effect_release = DURABLE[DURABLE.index("public static function release_idempotency_after_verified_no_effect"):]
no_effect_release = no_effect_release[: no_effect_release.index("public static function reclaim_idempotency")]
for marker in (
    "begin_owned_transaction",
    "FOR UPDATE",
    "RECONCILIATION_OBSERVATIONS_CONTRACT",
    "count( $observations ) < 2",
    "count( array_unique( $generations ) ) < 2",
    "NO_EFFECT_MIN_OBSERVATION_SECONDS",
    "mad4b_idempotency_no_effect_observation_window_pending",
    "mad4b_idempotency_no_effect_observation_identity_drift",
    "mad4b_idempotency_no_effect_proof_identity_drift",
    "reconciliation_verified( 'idempotency_no_effect'",
    "status='released_verified_no_effect'",
    "claim_epoch=%d",
    "status='pending'",
    "commit_owned_transaction",
    "rollback_owned_transaction",
):
    if marker not in no_effect_release:
        raise SystemExit(f"verified no-effect idempotency release is not fail-closed: {marker}")

observation = DURABLE[DURABLE.index("public static function record_idempotency_reconciliation_observation"):]
observation = observation[: observation.index("public static function release_idempotency_after_verified_no_effect")]
for marker in (
    "begin_owned_transaction",
    "FOR UPDATE",
    "reconciliation_verified( 'idempotency_observation'",
    "provider_scan_generation",
    "observation_sha256",
    "observed_at_epoch",
    "MAX_RECONCILIATION_OBSERVATIONS",
    "status='pending'",
    "claim_epoch=%d",
    "commit_owned_transaction",
    "rollback_owned_transaction",
):
    if marker not in observation:
        raise SystemExit(f"idempotency reconciliation observation ledger is incomplete: {marker}")

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

# Durable transactions must use the central ownership/topology guard. Raw
# transaction statements would reintroduce caller-transaction commits.
for forbidden in (
    "$wpdb->query( 'START TRANSACTION' );",
    "$wpdb->query( 'COMMIT' );",
    "$wpdb->query( 'ROLLBACK' );",
):
    if forbidden in DURABLE:
        raise SystemExit(f"raw transaction ownership bypass remains: {forbidden}")
# Database ownership/failure semantics are implemented by the extracted
# Durable DB Boundary service. The legacy Durable Execution class must retain
# only stable delegation wrappers so callers keep the same API without
# reabsorbing database-boundary responsibility.
for marker in (
    "MAD4B_SCP_Database_Transaction_Guard::begin",
    "MAD4B_SCP_Database_Transaction_Guard::commit",
    "MAD4B_SCP_Database_Transaction_Guard::rollback",
    "MAD4B_SCP_Database_Failure_Semantics::classify",
    "mad4b_durable_persistence_uncertain",
    "blind_retry_allowed",
):
    if marker not in DB_BOUNDARY:
        raise SystemExit(f"durable DB boundary ownership contract missing: {marker}")

for marker in (
    "MAD4B_SCP_Durable_DB_Boundary::restore_epoch_preflight",
    "MAD4B_SCP_Durable_DB_Boundary::write_topology_preflight",
    "MAD4B_SCP_Durable_DB_Boundary::same_writer_after_write",
    "MAD4B_SCP_Durable_DB_Boundary::database_write_failure",
    "MAD4B_SCP_Durable_DB_Boundary::authoritative_read_failure",
    "MAD4B_SCP_Durable_DB_Boundary::begin_owned_transaction",
    "MAD4B_SCP_Durable_DB_Boundary::commit_owned_transaction",
    "MAD4B_SCP_Durable_DB_Boundary::rollback_owned_transaction",
    "MAD4B_SCP_Durable_DB_Boundary::transaction_failure",
    "class-mad4b-scp-durable-db-boundary.php",
):
    if marker not in DURABLE:
        raise SystemExit(f"durable DB boundary delegation missing: {marker}")

# The extracted service must not introduce a raw SQL transaction bypass or a
# provider/network execution path.
for forbidden in (
    "$wpdb->query( 'START TRANSACTION' );",
    "$wpdb->query( 'COMMIT' );",
    "$wpdb->query( 'ROLLBACK' );",
    "wp_remote_get(",
    "wp_remote_post(",
    "wp_remote_request(",
    "curl_exec(",
    "call_user_func(",
):
    if forbidden in DB_BOUNDARY:
        raise SystemExit(f"durable DB boundary contains forbidden side-effect path: {forbidden}")

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


# Single-statement durable writes and commit-fence reads must use the certified
# writer topology too; transaction-only coverage is insufficient.
for method, end, markers in (
    ("public static function begin_idempotency", "public static function complete_idempotency", ("write_topology_preflight", "same_writer_after_write", "authoritative_read_failure")),
    ("public static function complete_idempotency", "public static function complete_idempotency_from_reconciliation", ("write_topology_preflight", "same_writer_after_write", "mad4b_durable_persistence_uncertain")),
    ("public static function heartbeat", "public static function assert_fencing_token", ("write_topology_preflight", "same_writer_after_write", "database_write_failure")),
    ("public static function assert_fencing_token", "public static function complete_lease", ("write_topology_preflight", "assert_same_writer", "authoritative_read_failure")),
    ("public static function complete_lease", "public static function enqueue_outbox", ("write_topology_preflight", "same_writer_after_write", "mad4b_durable_persistence_uncertain")),
    ("public static function enqueue_outbox", "public static function accept_inbox", ("write_topology_preflight", "same_writer_after_write", "database_write_failure")),
    ("public static function accept_inbox", "private static function write_topology_preflight", ("write_topology_preflight", "same_writer_after_write", "database_write_failure")),
):
    surface = DURABLE[DURABLE.index(method):DURABLE.index(end)]
    for marker in markers:
        if marker not in surface:
            raise SystemExit(f"single-statement durable write is not topology-fenced: {method} missing {marker}")


# Restore/authority epoch quarantine must front every durable replay/mutation surface.
if "private static function restore_epoch_preflight" not in DURABLE:
    raise SystemExit("durable restore-epoch preflight helper missing")
for method in (
    "begin_idempotency","complete_idempotency","complete_idempotency_from_reconciliation",
    "record_idempotency_reconciliation_observation","release_idempotency_after_verified_no_effect",
    "reclaim_idempotency","acquire_lease","reclaim_lease","heartbeat","assert_fencing_token",
    "complete_lease","enqueue_outbox","accept_inbox",
):
    start = DURABLE.index(f"public static function {method}")
    tail = DURABLE[start:start+900]
    if "restore_epoch_preflight" not in tail:
        raise SystemExit(f"durable surface bypasses restore epoch quarantine: {method}")
