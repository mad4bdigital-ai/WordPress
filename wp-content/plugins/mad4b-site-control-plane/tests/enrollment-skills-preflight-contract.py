#!/usr/bin/env python3
"""Fail-closed enrollment discovery safety contract; source-only, non-authorizing."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
src = (root / 'includes/class-mad4b-scp-enrollment-dispatch.php').read_text()
checks = (
    "public static function managed_skills_preflight_from_signals( $signals )",
    "'managed_skills_editor_disabled'",
    "'managed_skills_operation_in_progress'",
    "'exact_build_identity_unverified'",
    "'authorize_host_to_enable_staging_skills_editor'",
    "'read_existing_operation_checkpoint_no_retry'",
    "'permission_evaluated' => false",
    "'execution_performed' => false",
    "'mutation_performed' => false",
    "'production_mutation_allowed' => false",
    "'per_request_oauth_step_up_unverified' => true",
    "'execution_preflight' => self::live_managed_skills_preflight()",
    "mad4b_scp_remote_skills_reconciliation_lock_v1",
    "class_exists( 'MAD4B_SCP_Skill_Registry', false )",
    "MAD4B_SCP_Skill_Registry::editor_enabled()",
)
for check in checks:
    assert check in src, check
start = src.index('public static function managed_skills_preflight_from_signals(')
end = src.index('\n\tprivate static function live_managed_skills_preflight(', start)
pure = src[start:end]
for forbidden in ('update_option(', 'delete_option(', 'wp_remote_', 'wp_schedule_', 'grant_ability(', 'wp_register_ability(', '$wpdb', 'shell_exec('):
    assert forbidden not in pure, forbidden
assert (root / 'tests/enrollment-skills-preflight-runtime.php').exists()
print('mad4b.enrollment-skills-preflight-contract: PASS')
