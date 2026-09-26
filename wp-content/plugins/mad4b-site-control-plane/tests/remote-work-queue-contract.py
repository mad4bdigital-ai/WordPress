from pathlib import Path

root = Path(__file__).resolve().parents[1]
queue = (root / "includes" / "class-mad4b-scp-remote-work-queue.php").read_text(encoding="utf-8")
parity = (root / "includes" / "class-mad4b-scp-remote-operation-parity.php").read_text(encoding="utf-8")
servers = (root / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

for marker in [
    "const CONTRACT = 'mad4b.remote-work-queue.v1';",
    "'frontend_performance_sampling'",
    "const LOCK_OPTION = 'mad4b_scp_remote_work_queue_lock_v1';",
    "add_option( self::LOCK_OPTION",
    "delete_option_if_unchanged",
    "$wpdb->delete(",
    "mad4b_remote_work_queue_lock_reclaim_raced",
    "prune_reclaimable_jobs",
    "mad4b_remote_work_queue_capacity_exhausted",
    "no active work was discarded",
    "'lease_token_sha256'",
    "'claim_generation'",
    "'lease_expired'",
    "identity_matches",
    "public static function claim(",
    "public static function complete(",
    "unset( $job['lease_token_sha256'] )",
]:
    if marker not in queue:
        raise SystemExit(f"remote work queue invariant missing: {marker}")

if "delete_option( self::LOCK_OPTION" in queue:
    raise SystemExit("direct delete_option lock reclamation is ABA-unsafe; CAS delete is required")

if "array_slice( $jobs, -self::MAX_JOBS" in queue:
    raise SystemExit("silent MAX_JOBS slicing may evict active remote work")

for forbidden in [
    "shell_exec(",
    "exec(",
    "system(",
    "passthru(",
    "proc_open(",
    "eval(",
    "wp_remote_get(",
    "wp_remote_post(",
    "$wpdb->query(",
]:
    if forbidden in queue:
        raise SystemExit(f"remote work queue may not execute arbitrary work: {forbidden}")

for marker in [
    "const WORK_QUEUE_ABILITY = 'mad4b/remote-operation-work-queue';",
    "const WORK_CLAIM_ABILITY = 'mad4b/remote-operation-work-claim';",
    "const WORK_COMPLETE_ABILITY = 'mad4b/remote-operation-work-complete';",
    "MAD4B_SCP_Remote_Work_Queue::enqueue(",
    "MAD4B_SCP_Remote_Work_Queue::claim(",
    "MAD4B_SCP_Remote_Work_Queue::complete(",
    "mad4b_remote_work_evidence_not_observed",
    "query_monitor_frontend_probe_telemetry",
    "matched_frontend_probe_samples",
    "'probe_hash'",
    "'claimed_at'",
]:
    if marker not in parity:
        raise SystemExit(f"remote parity work-queue integration missing: {marker}")

if parity.find("frontend_performance_status()") > parity.find("MAD4B_SCP_Remote_Work_Queue::complete("):
    raise SystemExit("remote work completion must verify Frontend telemetry before committing completion")

completion = parity[parity.index("public static function complete_remote_work("):parity.index("public static function reconcile_managed_skills(")]
if "baseline_sample_count" in completion or "max( 0, $current_count - $baseline )" in completion:
    raise SystemExit("remote browser completion may not trust a general sample-count delta")
for marker in (
    "frontend_probe_hash",
    "probe_hash",
    "claimed_at",
    "matched_frontend_probe_samples",
    "mad4b_remote_work_probe_binding_missing",
):
    if marker not in completion:
        raise SystemExit("remote browser completion lacks exact probe correlation: " + marker)

if "mad4b/remote-operation-work-queue" not in servers:
    raise SystemExit("remote work queue read ability is not exposed on a governed read server surface")

enrollment_start = parity.index("public static function enrollment_abilities()")
enrollment_end = parity.index("public static function register_abilities()", enrollment_start)
enrollment = parity[enrollment_start:enrollment_end]
for marker in (
    "self::WORK_CLAIM_ABILITY",
    "self::WORK_COMPLETE_ABILITY",
):
    if marker not in enrollment:
        raise SystemExit("remote external-executor mutation ability is not projected through enrollment_abilities(): " + marker)

if "'mad4b-enrollment' => array_values( array_unique( array_merge(" not in servers:
    raise SystemExit("mad4b-enrollment governed server surface is missing")
if "MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities()" not in servers:
    raise SystemExit("mad4b-enrollment server does not consume Remote Operation Parity enrollment abilities")

queue_load = main.find("class-mad4b-scp-remote-work-queue.php")
parity_load = main.find("class-mad4b-scp-remote-operation-parity.php")
if queue_load < 0 or parity_load < 0 or queue_load > parity_load:
    raise SystemExit("Remote Work Queue must load before Remote Operation Parity")

print("mad4b.remote-work-queue.v1: PASS")
