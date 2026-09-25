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
    "query_monitor_frontend_telemetry",
]:
    if marker not in parity:
        raise SystemExit(f"remote parity work-queue integration missing: {marker}")

if parity.find("frontend_performance_status()") > parity.find("MAD4B_SCP_Remote_Work_Queue::complete("):
    raise SystemExit("remote work completion must verify Frontend telemetry before committing completion")

for ability in [
    "mad4b/remote-operation-work-queue",
    "mad4b/remote-operation-work-claim",
    "mad4b/remote-operation-work-complete",
]:
    if ability not in servers:
        raise SystemExit(f"remote work ability is not exposed on its governed server surface: {ability}")

queue_load = main.find("class-mad4b-scp-remote-work-queue.php")
parity_load = main.find("class-mad4b-scp-remote-operation-parity.php")
if queue_load < 0 or parity_load < 0 or queue_load > parity_load:
    raise SystemExit("Remote Work Queue must load before Remote Operation Parity")

print("mad4b.remote-work-queue.v1: PASS")
