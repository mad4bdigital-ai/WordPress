#!/usr/bin/env python3
"""Contract guard for the Feature 007 ContentJob domain service."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = (ROOT / "includes/class-mad4b-scp-content-jobs.php").read_text(encoding="utf-8")
MAIN = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
SCHEMA = (ROOT / "includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")

required = [
    "mad4b.content-job.v1",
    "mad4b.content-job-event.v1",
    "mad4b/content-job-list",
    "mad4b/content-job-get",
    "mad4b/content-job-events",
    "mad4b/content-job-create",
    "mad4b/content-job-transition",
    "mad4b/content-job-cancel",
    "public static function create_job",
    "public static function transition_job",
    "public static function cancel_job",
    "START TRANSACTION",
    "FOR UPDATE",
    "job_revision",
    "content_job_revision_conflict",
    "content_job_terminal_immutable",
    "previous_entry_sha256",
    "entry_sha256",
    "MAD4B_SCP_Audit::record",
    "MAD4B_SCP_Audit::transaction_committed",
    "MAD4B_SCP_Audit::transaction_rolled_back",
]
for marker in required:
    if marker not in SERVICE:
        raise SystemExit(f"ContentJob domain contract missing: {marker}")

if "includes/class-mad4b-scp-content-jobs.php" not in MAIN:
    raise SystemExit("ContentJob service is not loaded by plugin runtime")

for marker in (
    "'content_jobs' => $wpdb->prefix . 'mad4b_content_jobs'",
    "'content_job_events' => $wpdb->prefix . 'mad4b_content_job_events'",
    "UNIQUE KEY job_id (job_id)",
    "UNIQUE KEY job_sequence (job_id,sequence)",
):
    if marker not in SCHEMA:
        raise SystemExit(f"ContentJob durable schema marker missing: {marker}")

for forbidden in (
    "wp_insert_post(",
    "wp_update_post(",
    "wp_remote_get(",
    "wp_remote_post(",
    "wp_remote_request(",
    "curl_exec(",
    "FlowExecutor",
    "BitApps\\",
    "shell_exec(",
    "proc_open(",
):
    if forbidden in SERVICE:
        raise SystemExit(f"ContentJob domain service contains forbidden side effect path: {forbidden}")

if "'COMPLETED' => array()" not in SERVICE or "'CANCELLED' => array()" not in SERVICE:
    raise SystemExit("terminal ContentJob states are not immutable in transition map")

print("mad4b.content-job.v1: PASS")
