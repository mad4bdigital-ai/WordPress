from pathlib import Path

root = Path(__file__).resolve().parents[1]
parity = (root / 'includes' / 'class-mad4b-scp-remote-operation-parity.php').read_text(encoding='utf-8')
servers = (root / 'includes' / 'class-mad4b-scp-servers.php').read_text(encoding='utf-8')
abilities = (root / 'includes' / 'class-mad4b-scp-abilities.php').read_text(encoding='utf-8')
oauth = (root / 'includes' / 'class-mad4b-scp-oauth-resource-bridge.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
perf = (root / 'includes' / 'class-mad4b-scp-admin-query-performance.php').read_text(encoding='utf-8')
generalization = (root.parents[2] / 'specs' / '007-content-intelligence-workflow-platform' / 'contracts' / 'generalization-rules.md').read_text(encoding='utf-8')
contract = (root.parents[2] / 'specs' / '007-content-intelligence-workflow-platform' / 'contracts' / 'remote-operation-parity-discoverability.md').read_text(encoding='utf-8')

required_parity_markers = [
    "const CONTRACT = 'mad4b.remote-operation-parity.v1';",
    "const CATALOG_VERSION = 3;",
    "const WORK_COMPLETE_ABILITY = 'mad4b/remote-operation-work-complete';",
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
    "'remote_caller_role'",
    "registration_remote_caller_role_invalid",
    "array( 'operator', 'external_executor', 'owner', 'system' )",
    "apply_filters( 'mad4b_scp_remote_operation_catalog', $rows )",
    "$row['catalog_contract'] = self::CONTRACT;",
    "$row['catalog_version'] = self::CATALOG_VERSION;",
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
    "'external_executor_work_completion' => array(",
    "'remote_ability' => self::WORK_COMPLETE_ABILITY",
    "'remote_mode' => 'leased_semantic_work_completion'",
    "did_action( 'wp_abilities_api_init' ) > 0",
    "foreach ( self::catalog() as $row )",
    "public static function operator_enrollment_abilities()",
    "public static function operation_for_ability( $ability_name )",
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
    'mad4b/remote-operation-work-claim',
    'mad4b/remote-operation-work-complete',
]:
    if ability not in parity:
        raise SystemExit(f'{ability} missing from Remote Operation Parity enrollment inventory')

if "MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities()" not in servers:
    raise SystemExit('bounded enrollment server is not sourced from Remote Operation Parity enrollment inventory')

for marker in [
    "'mad4b/enrollment-discover'",
    "'mad4b/enrollment-info'",
    "'mad4b/enrollment-execute'",
    "governed_enrollment_operation",
    "governed_enrollment_target",
    "can_enrollment_dispatch",
    "MAD4B_SCP_Remote_Operation_Parity::operator_enrollment_abilities()",
    "MAD4B_SCP_Remote_Operation_Parity::operation_for_ability",
    "mad4b_enrollment_dispatch_target_not_allowlisted",
    "mad4b_enrollment_dispatch_recursion_denied",
    "mad4b_enrollment_dispatch_target_surface_mismatch",
    "mad4b_enrollment_dispatch_caller_role_denied",
    "mad4b_enrollment_dispatch_human_decision_denied",
    "mad4b_enrollment_dispatch_target_production_denied",
    "mad4b_enrollment_dispatch_surface_mismatch",
    "mad4b_enrollment_dispatch_contract_mismatch",
    "mad4b_enrollment_dispatch_generic_admin_denied",
    "mad4b_enrollment_dispatch_production_denied",
    "mad4b_enrollment_dispatch_registration_drift",
    "mad4b_enrollment_dispatch_policy_drift",
    "mad4b_enrollment_dispatch_schema_drift",
    "expected_registration_digest",
    "expected_dispatch_policy_digest",
    "expected_input_schema_sha256",
    "enrollment_dispatch_policy_digest",
    "AUTHORITY_STEP_UP_SCOPE",
    "verified_bearer_has_scope",
    "verified_bearer_client_is",
    "CHATGPT_CIMD_CLIENT_ID",
    "mutation_evidence_source",
    "target_result",
    "'authority_surface' => 'mad4b-enrollment'",
    "'production_mutation_allowed' => false",
]:
    if marker not in abilities:
        raise SystemExit(f'bounded Enrollment dispatcher invariant missing: {marker}')

for marker in [
    "'mad4b/enrollment-discover', 'mad4b/enrollment-info', 'mad4b/enrollment-execute'",
    "$direct_mutation_transport = array_merge( array( 'mad4b/write-execute' ), $step_up )",
    "$direct_mutation_transport[] = 'mad4b/enrollment-execute'",
    "bounded Remote Operation Parity enrollment operations",
]:
    if marker not in servers:
        raise SystemExit(f'compact ChatGPT Enrollment projection invariant missing: {marker}')

enrollment_operation = abilities.split("private function governed_enrollment_operation(", 1)[1].split("private function enrollment_dispatch_policy_digest(", 1)[0]
if "MAD4B_SCP_Remote_Operation_Parity::operator_enrollment_abilities()" not in enrollment_operation:
    raise SystemExit("ChatGPT Enrollment dispatcher must use the operator-only Remote Operation Parity inventory")
for forbidden in [
    "MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities()",
    "MAD4B_SCP_Servers::core_tools( 'mad4b-enrollment' )",
    "MAD4B_SCP_Servers::external_write_tools()",
    "MAD4B_SCP_Full_Staging_Authority",
    "MAD4B_SCP_Developer_Authority",
]:
    if forbidden in enrollment_operation:
        raise SystemExit(f'Enrollment operator allowlist widened beyond bounded operator parity inventory: {forbidden}')

enrollment_permission = abilities.split("public function can_enrollment_dispatch(", 1)[1].split("public function enrollment_discover(", 1)[0]
if "MAD4B_SCP_Policy::can_mutate()" in enrollment_permission:
    raise SystemExit("Enrollment dispatcher must not depend on normal governed-write mutation readiness")
for required in [
    "MAD4B_SCP_Remote_Operation_Parity::can_execute(",
    "verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE )",
    "verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID )",
    "MAD4B_SCP_Policy::can_breakglass()",
    "MAD4B_MCP_BREAKGLASS_ENABLED",
]:
    if required not in enrollment_permission:
        raise SystemExit(f'Enrollment dispatcher authority hardening missing: {required}')

claim_catalog = parity.split("'external_executor_work_claim' => array(", 1)[1].split("'external_executor_work_completion' => array(", 1)[0]
complete_catalog = parity.split("'external_executor_work_completion' => array(", 1)[1].split("'brand_context_materialization_reconciliation' => array(", 1)[0]
skills_catalog = parity.split("'managed_skills_reconciliation' => array(", 1)[1].split("'frontend_performance_sampling' => array(", 1)[0]
frontend_catalog = parity.split("'frontend_performance_sampling' => array(", 1)[1].split("'external_executor_work_claim' => array(", 1)[0]
if "'remote_caller_role' => 'external_executor'" not in claim_catalog or "'remote_caller_role' => 'external_executor'" not in complete_catalog:
    raise SystemExit('external browser work claim/completion must remain external-executor scoped')
if "'remote_caller_role' => 'operator'" not in skills_catalog or "'remote_caller_role' => 'operator'" not in frontend_catalog:
    raise SystemExit('operator-requested enrollment operations lost operator caller role')

for marker in [
    "private static function provider_for_enrollment_catalog_ability",
    "if ( 'mad4b-enrollment' === $server_id )",
    "self::provider_for_enrollment_catalog_ability( $ability_name )",
    "'certified_addon' !== $trust",
]:
    if marker not in servers:
        raise SystemExit(f'enrollment provider attribution invariant missing: {marker}')

for marker in [
    "authority_step_up_scope_available",
    "'mad4b/enrollment-execute'",
    "MAD4B_SCP_Servers::core_tools( 'mad4b-chatgpt' )",
]:
    if marker not in oauth:
        raise SystemExit(f'OAuth enrollment step-up advertisement invariant missing: {marker}')

core_write = servers.split("private static function core_write_candidates()", 1)[1].split("private static function catalog_cacheable()", 1)[0]
for dispatcher in [
    "mad4b/enrollment-discover",
    "mad4b/enrollment-info",
    "mad4b/enrollment-execute",
]:
    if dispatcher in core_write:
        raise SystemExit(f'{dispatcher} unexpectedly entered normal governed-write candidates')

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
