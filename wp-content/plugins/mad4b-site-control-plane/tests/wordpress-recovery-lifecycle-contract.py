#!/usr/bin/env python3
"""Static safety checks; native PHP runtime test lives in runtime-recovery-workspace-runtime.php."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
runtime = (root / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")
cert = (root / "includes/class-mad4b-scp-staging-certification.php").read_text(encoding="utf-8")
view = (root / "includes/class-mad4b-scp-runtime-recovery-workspace.php").read_text(encoding="utf-8")
lifecycle = (root / "includes/class-mad4b-scp-recovery-lifecycle.php").read_text(encoding="utf-8")
operator = (root / "includes/class-mad4b-scp-operator-workspace.php").read_text(encoding="utf-8")
entry = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
skills = (root / "includes/class-mad4b-scp-skill-runtime-certification.php").read_text(encoding="utf-8")
scenarios = (root / "includes/class-mad4b-scp-auto-reconcile-scenarios.php").read_text(encoding="utf-8")

def require(body, needle):
    if needle not in body:
        raise SystemExit("WordPress recovery lifecycle invariant missing: " + needle)

for marker in (
    "MAD4B_SCP_Skill_Runtime_Certification::current_status()",
    "'skills_pending' => $skills_pending",
    "'skills_certification_drift'",
    "'persisted_build_identity_current'",
    "'persisted_ready'",
    "$checkpoint_signals['site_profile_drift'] = $site_profile_drift;",
    "$site_profile_drift = empty( $site_profile['authority_ready'] );",
):
    require(runtime, marker)
for marker in (
    "'staging' !== $effective || empty( $profile['authority_ready'] )",
    "'not_an_authorized_staging_target'",
    "Do not change an actual Production site to Staging",
    "'wordpress_environment_alignment'",
    "'managed_skills_runtime_refresh'",
    "'developer_host_isolation_preflight'",
    "'provider_capability_review'",
    "'provider_mapping_review'",
    "'provider_actions_overflow_review'",
    "'readiness_domains' => $readiness_domains",
    "'runtime_scheduler_delivery_review'",
    "$developer_requested && empty( $host['normal_no_network_execution_ready'] )",
    "'developer_isolation_certified_by_this_plan' => false",
    "empty( $candidate_plan['execution_eligible'] )",
    "'zero_delta_probe_first' => true",
    "'recovery_scope' => 'exact_site_staging_only'",
    "'mutation_performed' => false",
):
    require(cert, marker)
for marker in (
    "'mad4b.staging-convergence-plan.v1' === $plan['contract']",
    "empty( $plan['mutation_performed'] )",
    "empty( $plan['production_mutation_performed'] )",
    "self::MAX_ACTIONS",
    "isset( $seen[ $id ] )",
    "current_user_can( 'manage_options' )",
    "esc_html(",
    "MAD4B_SCP_Admin_Workspace::link(",
    "'authorizing' => false",
    "'automatic_executions_performed' => 0",
):
    require(view, marker)
for forbidden in ("update_option(", "delete_option(", "grant_ability(", "proc_open(", "shell_exec(", "wp_remote_post(", "wp_schedule_event(", "$_POST", "$_GET"):
    if forbidden in view:
        raise SystemExit("WordPress recovery UI cannot dispatch a mutation: " + forbidden)
for marker in (
    "'boot_provenance_sha256' => $boot_provenance_sha256",
    "'boot_provenance_sha256_mismatch'",
    "MAD4B_SCP_BOOT_PROVENANCE_SHA256",
):
    require(skills, marker)
require(scenarios, "|| ! empty( $context['skills_pending'] )")
require(entry, "class-mad4b-scp-runtime-recovery-workspace.php")
require(entry, "class-mad4b-scp-recovery-lifecycle.php")
require(operator, "MAD4B_SCP_Runtime_Recovery_Workspace::render()")
for marker in (
    "MAD4B_SCP_Recovery_Lifecycle::capture_identity()",
    "MAD4B_SCP_Recovery_Lifecycle::compile(",
    "empty( $lifecycle['identity_bound'] )",
    "'lifecycle_stage'",
):
    require(view, marker)
for marker in (
    "public static function compile( $plan, $start_identity, $end_identity )",
    "'identity_changed_during_planning'",
    "'dependency_missing_or_cyclic'",
    "'plan_contract_invalid_or_oversized'",
    "'staging_identity_unverified'",
    "'identity_bound' => $admitted",
    "'authorizing' => false",
    "'execution_performed' => false",
    "'certification_issued' => false",
):
    require(lifecycle, marker)
for forbidden in ("update_option(", "delete_option(", "wp_schedule_", "wp_register_ability(", "proc_open(", "shell_exec(", "wp_remote_post(", "$_GET", "$_POST"):
    if forbidden in lifecycle:
        raise SystemExit("Recovery lifecycle admission cannot dispatch mutations: " + forbidden)
print("MAD4B WordPress recovery lifecycle static contract PASS")
