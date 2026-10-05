from pathlib import Path

root = Path(__file__).resolve().parents[1]
queue = (root / "includes" / "class-mad4b-scp-remote-work-queue.php").read_text(encoding="utf-8")
parity = (root / "includes" / "class-mad4b-scp-remote-operation-parity.php").read_text(encoding="utf-8")
servers = (root / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

for marker in [
    "const CONTRACT = 'mad4b.remote-work-queue.v1';",
    "'frontend_performance_sampling'",
    "'browser_acceptance_execution'",
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
    "reconcile_provider_state_before_any_retry",
    "reconciliation_completion_valid",
    "mad4b_remote_work_reconciliation_required",
    "'reconciling'",
    "'cancelled_no_effect'",
    "public static function cancel(",
    "public static function cancellation_signal(",
    "public static function provider_checkpoint(",
    "public static function acknowledge_cancellation(",
    "'provider_checkpoint'",
    "'provider_side_effect_possible'",
    "'provider_cancel_required'",
    "mad4b_remote_work_cancel_before_provider_entry",
    "mad4b_remote_work_cancel_ack_generation_mismatch",
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
    "const BROWSER_ACCEPTANCE_ABILITY = 'mad4b/browser-acceptance-run';",
    "const BROWSER_ACCEPTANCE_CONFIRMATION = 'RUN BROWSER ACCEPTANCE';",
    "MAD4B_SCP_Remote_Work_Queue::enqueue(",
    "MAD4B_SCP_Remote_Work_Queue::claim(",
    "MAD4B_SCP_Remote_Work_Queue::complete(",
    "MAD4B_SCP_Remote_Work_Queue::cancel(",
    "MAD4B_SCP_Remote_Work_Queue::cancellation_signal(",
    "MAD4B_SCP_Remote_Work_Queue::provider_checkpoint(",
    "MAD4B_SCP_Remote_Work_Queue::acknowledge_cancellation(",
    "mad4b_remote_work_evidence_not_observed",
    "query_monitor_frontend_probe_telemetry",
    "matched_frontend_probe_samples",
    "'probe_hash'",
    "'claimed_at'",
    "queue_browser_acceptance",
    "MAD4B_SCP_Browser_Acceptance_Core::plan(",
    "MAD4B_SCP_Browser_Acceptance_Core::result(",
    "mad4b_remote_browser_acceptance_not_verified",
    "browser_runtime_parity_verified",
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

browser_branch = completion.split("if ( 'browser_acceptance_execution' === $operation_id )", 1)[1].split("if ( 'frontend_performance_sampling' !== $operation_id )", 1)[0]
for marker in (
    "complete_browser_acceptance_work",
):
    if marker not in browser_branch:
        raise SystemExit("browser acceptance completion dispatch missing: " + marker)
browser_helper = parity.split("private static function complete_browser_acceptance_work", 1)[1].split("public static function reconcile_managed_skills", 1)[0]
for marker in (
    "MAD4B_SCP_Browser_Acceptance_Core::result(",
    "'PASS' ===",
    "browser_runtime_parity_verified",
    "mad4b_remote_browser_acceptance_not_verified",
    "MAD4B_SCP_Remote_Work_Queue::complete(",
    "'browser_result' => $browser_result",
):
    if marker not in browser_helper:
        raise SystemExit("browser acceptance completion lacks server-owned verification: " + marker)
if browser_helper.index("MAD4B_SCP_Browser_Acceptance_Core::result(") > browser_helper.index("MAD4B_SCP_Remote_Work_Queue::complete("):
    raise SystemExit("browser acceptance evidence must be reduced before durable work completion")

if "mad4b/remote-operation-work-queue" not in servers:
    raise SystemExit("remote work queue read ability is not exposed on a governed read server surface")

enrollment_start = parity.index("public static function enrollment_abilities()")
enrollment_end = parity.index("public static function register_abilities()", enrollment_start)
enrollment = parity[enrollment_start:enrollment_end]
for marker in (
    "self::WORK_CLAIM_ABILITY",
    "self::WORK_COMPLETE_ABILITY",
    "self::WORK_CANCEL_ABILITY",
    "self::WORK_CANCEL_SIGNAL_ABILITY",
    "self::WORK_PROVIDER_CHECKPOINT_ABILITY",
    "self::WORK_CANCEL_ACK_ABILITY",
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


# Cancellation/replay safety.
if "'claimed' === $status && $expired" in queue:
    raise SystemExit("claimed expired work must not be treated as safely reclaimable")
if "in_array( $status, array( 'completed', 'cancelled_no_effect' )" not in queue:
    raise SystemExit("only completed/cancelled-no-effect work may be terminally reclaimed")
if "reconciliation_completion_valid" not in queue or "postcondition_verified" not in queue:
    raise SystemExit("reconciling work lacks explicit postcondition-proof completion gate")
if "WORK_CANCEL_ABILITY" not in parity or "cancel_remote_work" not in parity:
    raise SystemExit("governed remote cancellation surface is missing")


# Provider-boundary cancellation propagation must be enforceable by the governed executor surface.
for marker in (
    "const WORK_CANCEL_SIGNAL_ABILITY = 'mad4b/remote-operation-work-cancel-signal';",
    "const WORK_PROVIDER_CHECKPOINT_ABILITY = 'mad4b/remote-operation-work-provider-checkpoint';",
    "const WORK_CANCEL_ACK_ABILITY = 'mad4b/remote-operation-work-cancel-ack';",
    "remote_work_cancellation_signal",
    "provider_checkpoint_remote_work",
    "acknowledge_remote_work_cancellation",
    "work_provider_checkpoint_schema",
    "work_cancel_ack_schema",
):
    if marker not in parity:
        raise SystemExit("provider-boundary cancellation surface missing: " + marker)

if "'applied' === $effect && ! $provider_entry_proven" not in queue:
    raise SystemExit("applied cancellation reconciliation can terminalize without durable provider-entry proof")
