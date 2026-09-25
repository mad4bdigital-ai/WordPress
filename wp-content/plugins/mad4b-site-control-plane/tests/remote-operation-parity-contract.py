from pathlib import Path

root = Path(__file__).resolve().parents[1]
parity = (root / 'includes' / 'class-mad4b-scp-remote-operation-parity.php').read_text(encoding='utf-8')
servers = (root / 'includes' / 'class-mad4b-scp-servers.php').read_text(encoding='utf-8')
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
    "'wordpress_cron_maintenance_worker'",
    "'checkpointed_convergence'",
    "'durable_external_executor_request'",
    "'durable_scheduled_operation'",
    "'synchronous_ddl' => false",
    "'pending_external_executor'",
    "'manual_interaction_required' => false",
]
for marker in required_parity_markers:
    if marker not in parity:
        raise SystemExit(f'missing remote parity/discoverability invariant: {marker}')

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
]:
    if ability not in servers:
        raise SystemExit(f'{ability} missing from bounded enrollment surface')
    # Remote parity operations are bootstrap/maintenance tools, not normal governed-write inventory.
    write_section = servers.split("'mad4b-admin' => array(", 1)[0]
    if ability in write_section and "'mad4b-enrollment'" not in servers:
        raise SystemExit(f'{ability} unexpectedly entered normal write surface')

if "class-mad4b-scp-remote-operation-parity.php" not in main:
    raise SystemExit('remote operation parity runtime is not loaded by the plugin')

for marker in [
    'public static function enqueue_explicit(',
    'public static function run_scheduled_apply(',
    "const CRON_HOOK = 'mad4b_scp_admin_query_performance_async';",
    "MAD4B_SCP_Audit::record(",
    "wp_schedule_single_event(",
    "mad4b_admin_query_performance_cron_disabled",
]:
    if marker not in perf:
        raise SystemExit(f'performance maintenance lacks durable queued execution invariant: {marker}')

if "self::apply_explicit();" in perf.split("public static function handle_explicit_apply()", 1)[1].split("public static function maintenance_job_status()", 1)[0]:
    raise SystemExit('wp-admin performance action must enqueue maintenance instead of running DDL synchronously')

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
