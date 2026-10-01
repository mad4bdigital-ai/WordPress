#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
resilience = (ROOT / "includes/class-mad4b-scp-connector-resilience.php").read_text(encoding="utf-8")
read_consistency = (ROOT / "includes/class-mad4b-scp-read-consistency.php").read_text(encoding="utf-8")
connection = (ROOT / "includes/class-mad4b-scp-connection-ability.php").read_text(encoding="utf-8")
abilities = (ROOT / "includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
staging = (ROOT / "includes/class-mad4b-scp-staging-certification.php").read_text(encoding="utf-8")
parity = (ROOT / "includes/class-mad4b-scp-remote-operation-parity.php").read_text(encoding="utf-8")
servers = (ROOT / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
main = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
contract = (ROOT.parents[2] / "specs/007-content-intelligence-workflow-platform/contracts/connector-resilience.md").read_text(encoding="utf-8")

def require(text, marker, label):
    if marker not in text:
        raise SystemExit(f"missing {label}: {marker}")

for marker in [
    "const CONTRACT = 'mad4b.connector-resilience.v2'",
    "const DEFAULT_READ_ATTEMPTS = 2",
    "const DEFAULT_REQUEST_BUDGET_MS = 12000",
    "public static function safe_read(",
    "public static function generation_fenced_compact_read(",
    "mad4b_generation_fenced_read_generation_changed",
    "mad4b_generation_fenced_read_oversized",
    "mad4b_generation_fenced_read_encoding_failed",
    "'source_error_code' =>",
    "for ( $i = 0; $i < 4; $i++ )",
    "public static function execute_read(",
    "public static function execute_mutation(",
    "public static function run_checks(",
    "public static function client_guidance()",
    "public static function classify_exception( Throwable $e )",
    "'category' => 'runtime_restart'",
    "'category' => 'runtime_maintenance'",
    "'client_action' => 'retry_after_runtime_maintenance'",
    "'runtime_maintenance_honors_retry_after' => true",
    "'runtime_maintenance_immediate_auto_retry_allowed' => false",
    "'client_action' => 'retry_after_restart_grace'",
    "'runtime_restart_honors_retry_after' => true",
    "'runtime_restart_immediate_auto_retry_allowed' => false",
    "'category' => 'rate_limit'",
    "'category' => 'timeout'",
    "'category' => 'transport'",
    "'category' => 'upstream_unavailable'",
    "'category' => 'authorization'",
    "'category' => 'contract_or_validation'",
    "'category' => 'request_budget'",
    "'client_action' => 'reduce_scope_then_retry_preflight'",
    "'supported_error_categories' => array( 'runtime_restart', 'runtime_maintenance', 'rate_limit', 'timeout', 'session_terminated', 'transport', 'upstream_unavailable', 'authorization', 'contract_or_validation', 'request_budget', 'internal', 'unknown' )",
    "const SESSION_TERMINATION_BUDGET = 2",
    "'category' => 'session_terminated'",
    "'client_action' => 'reconnect_snapshot_then_resume'",
    "'session_breaker_scope' => 'request_local'",
    "'metadata_micro_read_preferred' => true",
    "'metadata_envelope_ability' => 'mad4b/read-metadata-envelope'",
    "'session_safe_diagnostics_ability' => 'mad4b/session-safe-diagnostics'",
    "'full_staging_authority_handshake_ability' => 'mad4b/full-staging-authority-handshake'",
    "'full_staging_authority_direct_status_plan_fanout_allowed' => false",
    "'full_staging_authority_handshake_response_budget_bytes' => 8192",
    "'single_request_composite_diagnostics_preferred' => true",
    "'direct_composite_fanout_allowed' => false",
    "'session_safe_report_max_bytes' => 16384",
    "'resume_after_reconnect_requires_generation_match' => true",
    "'skipped_session_breaker'",
    "'auto_retry' => false",
    "'client_action' => 'backoff_then_retry'",
    "private static function safe_error_code( WP_Error $error )",
    "substr( $code, 0, 96 )",
    "private static function retry_after_seconds_from_wp_error",
    "'automatic_retry_allowed'",
    "'automatic_retry_performed'",
    "'automatic_retry_exhausted'",
    "'client_action' => $retry_exhausted && 'session_terminated' !==",
    "'no_immediate_retry_after_automatic_retry_exhausted' => true",
    "'mutation_state' => 'unknown'",
    "'reconciliation_required' => true",
    "'blind_retry_allowed' => false",
    "'automatic_retry_performed' => false",
    "'_dispatch_uncertain_remote_error'",
    "'original_error_code'",
    "'client_action' => 'reconcile_then_replan'",
    "'raw_error_message_exposed' => false",
    "'data' => $value",
    "array_key_exists( 'data', $result )",
    "'_dispatch_target_error'",
    "'_dispatch_not_started'",
    "'mutation_state' => $dispatch_not_started ? 'not_started' : 'unknown'",
    "'client_action' => $dispatch_not_started ? 'repair_dispatch_then_replan' : 'reconcile_then_replan'",
    "private static function wp_error_proves_mutation_not_started",
    "'mad4b_write_dispatch_target_not_started'",
    "'persistent_circuit_breaker_used' => false",
    "'persistent_catalog_cache_used' => false",
    "'skipped_budget'",
]:
    require(resilience, marker, "shared resilience invariant")

# Mutation execution must remain single-shot. It may call the target callback
# exactly once and must not contain retry loops.
mutation_body = resilience.split("public static function execute_mutation(", 1)[1].split("public static function run_checks(", 1)[0]
if mutation_body.count("call_user_func( $callback )") != 1:
    raise SystemExit("mutation callback must have exactly one call site")
for forbidden in ["do {", "while (", "for (", "DEFAULT_READ_ATTEMPTS"]:
    if forbidden in mutation_body:
        raise SystemExit(f"mutation execution unexpectedly contains retry construct: {forbidden}")

read_body = resilience.split("public static function safe_read(", 1)[1].split("public static function execute_read(", 1)[0]
require(read_body, "$max_attempts", "bounded read retry budget")
require(read_body, "$attempt < $max_attempts", "bounded read retry condition")
require(read_body, "! empty( $classification['auto_retry'] )", "auto retry only classifier-approved transients")

for marker in [
    "const PREFLIGHT_ABILITY = 'mad4b/connector-preflight'",
    "const PREFLIGHT_CONTRACT = 'mad4b.connector-preflight.v1'",
    "'budget_ms' => array( 'type' => 'integer', 'minimum' => 1000, 'maximum' => 30000, 'default' => 12000 )",
    "MAD4B_SCP_Connector_Resilience::run_checks",
    "MAD4B_SCP_Connector_Resilience::client_guidance()",
    "'include_operation_discovery'",
    "'limit' => 10",
    "'include_ability_hints' => false",
]:
    require(connection, marker, "compact connector preflight")

for marker in [
    "MAD4B_SCP_Connector_Resilience::execute_read",
    "mad4b.chatgpt-read-execute.v1",
    "MAD4B_SCP_Connector_Resilience::execute_mutation",
    "mad4b.chatgpt-write-execute.v1",
    "array_key_exists( 'result', $execution )",
]:
    require(abilities, marker, "dispatcher resilience integration")

# Read retries are allowed only through the shared resilience service.
for forbidden in [
    "private function transient_execution_exception",
    "private function execution_exception_error",
]:
    if forbidden in abilities:
        raise SystemExit(f"duplicated dispatcher resilience logic returned: {forbidden}")

require(staging, "MAD4B_SCP_Connector_Resilience::safe_read", "staging certification shared resilience")
require(staging, "'payload_profile' => 'compact'", "staging compact payload")
require(parity, "MAD4B_SCP_Connector_Resilience::classify_exception", "operation discovery shared taxonomy")
require(parity, "'include_ability_hints' => array( 'type' => 'boolean', 'default' => false )", "ability hints opt-in")
require(parity, "'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 )", "discovery result budget")
require(parity, "'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 500, 'default' => 0 )", "discovery stateless pagination")
require(parity, "'next_offset' => $next_offset", "discovery continuation metadata")
require(parity, "'total_match_count' => $total_match_count", "discovery total match metadata")
require(parity, "'contract' => 'mad4b.operation-discovery.v2'", "discovery v2 contract")

if servers.count("'mad4b/connector-preflight'") != 1:
    raise SystemExit("connector preflight must remain on the full read surface only; ChatGPT composite diagnostics use the session-safe single-call path")

if servers.count("'mad4b/session-safe-diagnostics'") < 2:
    raise SystemExit("session-safe diagnostics must be mounted on read and ChatGPT surfaces")

chatgpt_core = servers.split("'mad4b-chatgpt' => array_merge( array(", 1)[1].split("), $governed_status", 1)[0]
require(chatgpt_core, "'mad4b/full-staging-authority-handshake'", "compact ChatGPT authority handshake")

for forbidden_direct in [
    "'mad4b/diagnostics-health'",
    "'mad4b/runtime-authority-status'",
    "'mad4b/multi-authority-registry-status'",
    "'mad4b/connection-status'",
    "'mad4b/connector-preflight'",
    "'mad4b/read-snapshot-header'",
    "'mad4b/read-diagnostic-bundle'",
    "'mad4b/control-plane-update-status'",
    "'mad4b/remote-operation-parity-status'",
]:
    if forbidden_direct in chatgpt_core:
        raise SystemExit("composite diagnostic fan-out primitive leaked into direct ChatGPT core catalog: " + forbidden_direct)

for marker in [
    "public static function chatgpt_direct_read_transport_tools()",
    "'mad4b/session-safe-diagnostics'",
    "$direct_read_transport = self::chatgpt_direct_read_transport_tools()",
    "in_array( $ability_name, $direct_read_transport, true )",
]:
    require(servers, marker, "direct ChatGPT read allowlist invariant")

direct_helper = servers.split("public static function chatgpt_direct_read_transport_tools()", 1)[1].split("public static function chatgpt_dispatch_transport_tools()", 1)[0]
require(direct_helper, "MAD4B_SCP_Full_Staging_Authority::HANDSHAKE_ABILITY", "session-safe full authority direct tool")
for forbidden in ["MAD4B_SCP_Full_Staging_Authority::PLAN_ABILITY", "MAD4B_SCP_Full_Staging_Authority::STATUS_ABILITY"]:
    if forbidden in direct_helper:
        raise SystemExit("deep Full Staging Authority read leaked into direct ChatGPT catalog: " + forbidden)


for marker in [
    "const SESSION_SAFE_REPORT_ABILITY = 'mad4b/session-safe-diagnostics'",
    "const MAX_SESSION_SAFE_REPORT_BYTES = 16384",
    "public static function register_session_safe_report()",
    "public static function session_safe_diagnostics( $input = array() )",
    "'external_mcp_calls_required' => 1",
    "'server_sequential_execution' => true",
    "'direct_parallel_fanout_required' => false",
    "'direct_composite_fanout_allowed' => false",
    "private static function compact_bundle_result",
    "private static function compact_status_data",
    "private static function bound_session_safe_report",
    "'runtime_changed_during_session_safe_report'",
    "'valid_for_session_evidence_merge'",
    "'valid_for_release_merge' => false",
    "'merge_scope' => 'session_safe_subject_evidence_only'",
    "'deep_acceptance_required' => true",
    "'release_acceptance_deferred_checks'",
]:
    require(read_consistency, marker, "session-safe diagnostic invariant")

reduced_report = read_consistency.split("$minimal = array(", 1)[1].split("return $minimal;", 1)[0]
for marker in (
    "'valid_for_session_evidence_merge'",
    "'valid_for_release_merge' => false",
    "'merge_scope' => 'session_safe_subject_evidence_only'",
    "'deep_acceptance_required' => true",
    "'subject_blockers'",
    "'release_acceptance_deferred_checks'",
):
    require(reduced_report, marker, "reduced session-safe merge-scope invariant")
generation_envelope = read_consistency.split("private static function generation_changed_envelope(", 1)[1].split("private static function request_metrics()", 1)[0]
bundle_merge = read_consistency.split("$result['generation_match'] = true;", 1)[1].split("return $result;", 1)[0]
for marker in (
    "'valid_for_bundle_evidence_merge'",
    "'valid_for_release_merge'",
    "'merge_scope'",
    "'generation_bound_bundle_evidence_only'",
    "'deep_acceptance_required'",
):
    require(bundle_merge, marker, "bundle evidence merge-scope invariant")

for marker in (
    "$session_report = 'session_safe_diagnostics' === (string) $bundle;",
    "'valid_for_bundle_evidence_merge' => false",
    "'valid_for_session_evidence_merge' => false",
    "'valid_for_release_merge' => false",
    "'session_safe_subject_evidence_only'",
    "'generation_bound_bundle_evidence_only'",
    "'deep_acceptance_required' => true",
):
    require(generation_envelope, marker, "generation-changed merge-scope invariant")

require(main, "class-mad4b-scp-connector-resilience.php", "resilience runtime include")

for forbidden in [
    "shell_exec(",
    "exec(",
    "system(",
    "passthru(",
    "proc_open(",
    "eval(",
    "wp_remote_get(",
    "wp_remote_post(",
    "update_option(",
    "set_transient(",
    "get_transient(",
]:
    if forbidden in resilience:
        raise SystemExit(f"resilience layer may not introduce side effects or generic execution: {forbidden}")

for marker in [
    "Read-only callbacks MAY be retried automatically",
    "automatic_retry_exhausted=true",
    "automatic_retry_allowed=false",
    "Mutation and enrollment callbacks MUST execute at most once",
    "mutation_state=unknown",
    "reconciliation_required=true",
    "blind_retry_allowed=false",
    "Partial diagnostics",
    "Request budget",
    "Result preservation",
    "Raw mutation error messages must",
    "Payload discipline",
    "Error taxonomy",
    "- `internal`;",
    "reduce_scope_then_retry_preflight",
    "Future connector onboarding",
    "fault-injection",
    "Full Staging Authority session-safe handshake",
    "mad4b/full-staging-authority-handshake",
    "8 KiB response budget",
]:
    require(contract, marker, "normative resilience contract")




for marker in [
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    "MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status()",
    "MAD4B_SCP_Staging_Write_Authority::persisted_status()",
    "MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()",
    "'full_runtime_hash_validation_deferred' => true",
    "'deep_authority_scan_deferred' => true",
]:
    require(connection, marker, "bounded compact preflight truth")

preflight_body = connection.split("public static function preflight(", 1)[1]
if "MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status()" in preflight_body:
    raise SystemExit("compact preflight must not perform full package provenance hashing")
if "MAD4B_SCP_Live_Truth::current_authority_status()" in preflight_body:
    raise SystemExit("compact preflight must not perform deep authority/grant scans")

for marker in [
    "MAD4B_SCP_Remote_Operation_Parity::reconciliation_status( $operation_id )",
    "mad4b.chatgpt-enrollment-mutation-reconciliation.v1",
    "'blind_retry_allowed' => false",
]:
    require(abilities, marker, "durable enrollment mutation reconciliation")

print("mad4b.connector-resilience.contract.v2: PASS")

if "MAD4B_SCP_Staging_Write_Authority::persisted_status()" not in connection:
    raise SystemExit("compact connector preflight must use persisted authority evidence")
