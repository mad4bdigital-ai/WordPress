#!/usr/bin/env python3
"""No-CI source-only acceptance boundary check for exact Staging planner."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
source = (root / "includes/class-mad4b-scp-staging-certification.php").read_text()
workspace = (root / "includes/class-mad4b-scp-runtime-recovery-workspace.php").read_text()
for token in (
    "public static function acceptance_evidence_actions( array $gates )",
    "context_owner_evidence_review",
    "browser_attestation_trust_review",
    "frontend_sample_evidence_review",
    "import_export_disposable_acceptance",
    "'wp-import-export/execution-readiness'",
    "'context/review-queue'",
    "'mad4b/browser-acceptance-capabilities'",
    "trusted_browser_public_key",
    "unique_nonce_replay_rejection",
    "import_dry_run_diff_and_rollback",
    "at_least_three_real_frontend_samples",
    "'certificate_issued' => false",
    "'grant_created' => false",
    "$google_refresh_pending",
    "'google_drive_token_health_review'",
    "'reconnect_automatically_required' => false",
):
    assert token in source, token
assert "'apply_ability' => 'mad4b/browser-acceptance-run'" not in source
assert "'apply_ability' => 'mad4b/frontend-performance-sample-run'" not in source
for marker in (
    "expected_independent_evidence",
    "Required independent evidence",
    "No evidence is certified by this checklist",
    "'mad4b-browser-acceptance'",
    "'mad4b-adapter-coverage'",
):
    assert marker in workspace, marker
start = source.index("public static function acceptance_evidence_actions(")
end = source.index("\n\tprivate static function safe_read(",start)
pure = source[start:end]
for mutation in ("update_option(", "delete_option(", "wp_remote_", "wp_schedule_", "shell_exec(", "proc_open(", "grant_ability(", "$wpdb", "wp_register_ability("):
    assert mutation not in pure, mutation
assert (root / "tests/staging-independent-evidence-runtime.php").exists()
print("PASS mad4b.staging-independent-evidence-contract.v1")
