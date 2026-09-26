from pathlib import Path

root = Path(__file__).resolve().parents[1]
parity = (root / 'includes' / 'class-mad4b-scp-remote-operation-parity.php').read_text(encoding='utf-8')
servers = (root / 'includes' / 'class-mad4b-scp-servers.php').read_text(encoding='utf-8')
abilities = (root / 'includes' / 'class-mad4b-scp-abilities.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
perf = (root / 'includes' / 'class-mad4b-scp-admin-query-performance.php').read_text(encoding='utf-8')
generalization = (root.parents[2] / 'specs' / '007-content-intelligence-workflow-platform' / 'contracts' / 'generalization-rules.md').read_text(encoding='utf-8')
contract = (root.parents[2] / 'specs' / '007-content-intelligence-workflow-platform' / 'contracts' / 'remote-operation-parity-discoverability.md').read_text(encoding='utf-8')

required_parity_markers = [
    "const CONTRACT = 'mad4b.remote-operation-parity.v1';",
    "const STATUS_ABILITY = 'mad4b/remote-operation-parity-status';",
    "const DISCOVER_ABILITY = 'mad4b/operation-discover';",
    "const SKILLS_ABILITY = 'mad4b/reconcile-managed-skills';",
    "const FRONTEND_SAMPLE_ABILITY = 'mad4b/frontend-performance-sample-run';",
    "const PERFORMANCE_INDEX_ABILITY = 'mad4b/admin-query-performance-apply';",
    "const PERFORMANCE_RECONCILE_ABILITY = 'mad4b/admin-query-performance-reconcile';",
    "const PERFORMANCE_RECONCILE_CONFIRMATION = 'RECONCILE STALE STAGING PERFORMANCE INDEXES';",
    "'generic_remote_admin' => false",
    "'production_mutation_allowed' => false",
    "'raw_shell_exposed' => false",
    "'raw_sql_exposed' => false",
    "'future_feature_discovery' => true",
    "'requires_prior_ability_name' => false",
    "'ability_hints' => $ability_hints",
    "'discovery_federation' => array(",
    "'abilities' => 'mad4b/tool-discover'",
    "'addons' => 'mad4b/addon-registry-status'",
    "'capability_traits' => 'mad4b/capability-trait-resolve'",
    "'manual_only_count'",
    "'remote_parity_ready'",
    "'feature_id'",
    "'capability_tags'",
    "'status_ability'",
    "'authority_surface'",
    "'executor'",
    "'provider'",
    "'remote_mode'",
    "'production_policy'",
    "'human_decision_required'",
    "apply_filters( 'mad4b_scp_remote_operation_catalog', $rows )",
    "$row['catalog_contract'] = self::CONTRACT;",
    "$row['catalog_version'] = 2;",
    "'registration_digest'",
    "'registrar_id'",
    "'source_plugin'",
    "'trust_class'",
    "'executor_available'",
    "'executor_state'",
    "'rejected_registrations'",
    "'rejected_registration_count'",
    "'external_browser_agent'",
    "external_registration_core_impersonation_denied",
    "certified_addon_pair_not_current",
    "certified_addon_provider_binding_mismatch",
    "MAD4B_SCP_Addon_Registry::execution_binding",
    "'execution_eligible'",
    "'addon_pair_fingerprint'",
    "'addon_certification_fingerprint'",
    "'wordpress_cron_maintenance_worker'",
    "'checkpointed_convergence'",
    "'durable_external_executor_request'",
    "'durable_scheduled_operation'",
    "'synchronous_ddl' => false",
    "'pending_external_executor'",
    "'manual_interaction_required' => false",
    "const SKILLS_LOCK_TTL = 900;",
    "compare_and_swap_option",
    "refresh_skills_lock",
    "mad4b_remote_skill_lock_reclaim_raced",
    "mad4b_remote_skill_lock_heartbeat_raced",
]
for marker in required_parity_markers:
    if marker not in parity:
        raise SystemExit(f'missing remote parity/discoverability invariant: {marker}')

if "delete_option( self::SKILLS_LOCK_OPTION" in parity:
    raise SystemExit("direct delete_option Skills lock reclamation is ABA-unsafe; CAS option fencing is required")

for forbidden in [
    'shell_exec(',
    'exec(',
    'system(',
    'passthru(',
    'proc_open(',
    'eval(',
    'mad4b/database-raw-query',
    'wp_remote_get(',
]:
    if forbidden in parity:
        raise SystemExit(f'forbidden generic execution primitive in remote parity runtime: {forbidden}')

for ability in [
    'mad4b/remote-operation-parity-status',
    'mad4b/operation-discover',
]:
    if servers.count(ability) < 2:
        raise SystemExit(f'{ability} must be discoverable from read and ChatGPT surfaces')

for ability in [
    'mad4b/reconcile-managed-skills',
    'mad4b/frontend-performance-sample-run',
    'mad4b/admin-query-performance-apply',
    'mad4b/admin-query-performance-reconcile',
]:
    if ability not in parity:
        raise SystemExit(f'{ability} missing from Remote Operation Parity enrollment inventory')

if "MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities()" not in servers:
    raise SystemExit('bounded enrollment server is not sourced from Remote Operation Parity enrollment inventory')

if "public static function write_dispatch_bootstrap_abilities()" not in parity:
    raise SystemExit('bounded write-dispatch Enrollment allowlist is missing')
if "MAD4B_SCP_Remote_Operation_Parity::write_dispatch_bootstrap_abilities()" not in abilities:
    raise SystemExit('write dispatcher is not sourced from the bounded Enrollment bootstrap allowlist')
if "chatgpt_direct_enrollment_abilities" in parity or "chatgpt_direct_enrollment_abilities" in servers:
    raise SystemExit('Enrollment bootstrap must not be mounted as a direct compact ChatGPT mutation tool')

bootstrap_start = parity.find('public static function write_dispatch_bootstrap_abilities()')
bootstrap_end = parity.find('\n\t}', bootstrap_start)
bootstrap_section = parity[bootstrap_start:bootstrap_end] if bootstrap_end > bootstrap_start else ''
if 'self::SKILLS_ABILITY' not in bootstrap_section:
    raise SystemExit('Managed Skills reconciliation is missing from the write-dispatch bootstrap allowlist')
for forbidden_bootstrap in [
    'self::FRONTEND_SAMPLE_ABILITY',
    'self::PERFORMANCE_INDEX_ABILITY',
    'self::PERFORMANCE_RECONCILE_ABILITY',
    'self::WORK_CLAIM_ABILITY',
    'self::WORK_COMPLETE_ABILITY',
]:
    if forbidden_bootstrap in bootstrap_section:
        raise SystemExit(f'unsafe Enrollment operation leaked into write-dispatch bootstrap: {forbidden_bootstrap}')

write_start = servers.find("'mad4b-write' => array(")
admin_start = servers.find("'mad4b-admin' => array(", write_start + 1)
write_section = servers[write_start:admin_start] if write_start >= 0 and admin_start > write_start else ''
for ability in [
    'mad4b/reconcile-managed-skills',
    'mad4b/frontend-performance-sample-run',
    'mad4b/admin-query-performance-apply',
    'mad4b/admin-query-performance-reconcile',
]:
    if ability in write_section:
        raise SystemExit(f'{ability} unexpectedly entered normal governed-write inventory')

if "class-mad4b-scp-remote-operation-parity.php" not in main:
    raise SystemExit('remote operation parity runtime is not loaded by the plugin')

for marker in [
    'public static function enqueue_explicit(',
    'public static function run_scheduled_apply(',
    "const CRON_HOOK = 'mad4b_scp_admin_query_performance_async';",
    "MAD4B_SCP_Audit::record(",
    "wp_schedule_single_event(",
    "mad4b_admin_query_performance_cron_disabled",
    "const JOB_LOCK_OPTION = 'mad4b_scp_admin_query_performance_job_lock_v1';",
    "acquire_job_lock",
    "release_job_lock",
    "mad4b_admin_query_performance_lock_reclaim_raced",
    "mad4b_admin_query_performance_queue_required",
    "const JOB_RUNNING_TTL = 1800;",
    "public static function reconcile_stale_job(",
    "mad4b_admin_query_performance_reconciliation_required",
    "'blind_retry_allowed' => false",
]:
    if marker not in perf:
        raise SystemExit(f'performance maintenance lacks durable queued execution invariant: {marker}')

if "self::apply_explicit();" in perf.split("public static function handle_explicit_apply()", 1)[1].split("public static function maintenance_job_status()", 1)[0]:
    raise SystemExit('wp-admin performance action must enqueue maintenance instead of running DDL synchronously')

direct_apply = perf.split("public static function apply_explicit()", 1)[1].split("public static function maybe_ensure_staging_indexes()", 1)[0]
if "apply_indexes()" in direct_apply:
    raise SystemExit("legacy apply_explicit path must never execute DDL synchronously")
maybe_apply = perf.split("public static function maybe_ensure_staging_indexes()", 1)[1].split("private static function apply_indexes()", 1)[0]
if "apply_indexes()" in maybe_apply:
    raise SystemExit("maybe_ensure_staging_indexes must remain queue-only")
worker = perf.split("public static function run_scheduled_apply(", 1)[1].split("private static function audit_job(", 1)[0]
if "$result = self::apply_indexes();" not in worker:
    raise SystemExit("private performance DDL must be reachable only from the scheduled worker")

if "apply_indexes()" in perf.split("public static function reconcile_stale_job(", 1)[1].split("public static function enqueue_explicit(", 1)[0]:
    raise SystemExit("stale performance reconciliation must never execute or retry DDL")


if 'One service, many frontends' not in generalization:
    raise SystemExit('generalization contract lost one-service-many-frontends rule')

for marker in [
    'Remote parity by default',
    'No generic remote-admin fallback',
    'Discoverability',
    'Future feature onboarding',
    'Human decisions remain human',
]:
    if marker not in contract:
        raise SystemExit(f'remote parity contract missing policy section: {marker}')

print('mad4b.remote-operation-parity.v1: PASS')
