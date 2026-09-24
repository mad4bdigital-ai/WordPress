#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
guard = (root / "includes" / "class-mad4b-scp-execution-commit-guard.php").read_text(encoding="utf-8")
auth = (root / "includes" / "class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

for marker in [
    "mad4b.execution-commit-guard.v1",
    "mad4b.execution-commit-guard-receipt.v1",
    "'COMMIT_ALLOWED'",
    "'REPLAN_REQUIRED'",
    "'REAPPROVAL_REQUIRED'",
    "'RECERTIFICATION_REQUIRED'",
    "'TARGET_CHANGED'",
    "'KILL_SWITCHED'",
    "'DENIED'",
    "mad4b_scp_execution_commit_guard_plan_sha256",
    "mad4b_scp_execution_commit_guard_target_state",
    "mad4b_scp_execution_commit_guard_rights_dependencies",
    "mad4b_scp_execution_commit_guard_data_processing_dependencies",
    "mad4b_scp_execution_kill_switch_state",
    "grant_fingerprint",
    "decision_fingerprint",
    "candidate_binding",
    "authority_snapshot_sha256",
    "provider_material",
    "package_manifest_digest",
]:
    if marker not in guard:
        raise SystemExit(f"execution commit guard contract missing: {marker}")

capture = "MAD4B_SCP_Execution_Commit_Guard::capture( $decision, $input )"
revalidate = "MAD4B_SCP_Execution_Commit_Guard::revalidate( $claim, $input )"
callback = "call_user_func( $original, $input )"
if capture not in auth:
    raise SystemExit("authorization claim does not capture a commit-guard snapshot")
if revalidate not in auth:
    raise SystemExit("execution boundary does not revalidate the commit guard")
if auth.index(revalidate) > auth.index(callback, auth.index(revalidate)):
    raise SystemExit("commit guard does not execute before the governed mutation callback")
if "finalize_execution_claim( $claim, $commit_guard )" not in auth:
    raise SystemExit("commit-guard denial does not terminalize the claimed approval")
if "MAD4B_SCP_Approval_Tickets::finalize_claim( $decision['approval_ticket_id'], 'failed' )" not in auth:
    raise SystemExit("post-claim pre-callback failures can leave an approval executing")

if "class-mad4b-scp-execution-commit-guard.php" not in main:
    raise SystemExit("main plugin does not load execution commit guard")
if main.index("class-mad4b-scp-execution-commit-guard.php") > main.index("class-mad4b-scp-authorization.php"):
    raise SystemExit("execution commit guard must load before authorization")

if "call_user_func( $original, $input )" in guard:
    raise SystemExit("commit guard must not execute the governed side effect")
if "update_option(" in guard or "$wpdb->insert" in guard or "$wpdb->update" in guard:
    raise SystemExit("commit guard must remain a read/revalidate boundary")

print("mad4b.execution-commit-guard.v1: PASS")
