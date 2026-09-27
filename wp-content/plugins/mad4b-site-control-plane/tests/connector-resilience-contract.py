#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
resilience = (ROOT / "includes/class-mad4b-scp-connector-resilience.php").read_text(encoding="utf-8")
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
    "const CONTRACT = 'mad4b.connector-resilience.v1'",
    "const DEFAULT_READ_ATTEMPTS = 2",
    "const DEFAULT_REQUEST_BUDGET_MS = 12000",
    "public static function safe_read(",
    "public static function execute_read(",
    "public static function execute_mutation(",
    "public static function run_checks(",
    "public static function client_guidance()",
    "public static function classify_exception( Throwable $e )",
    "'category' => 'rate_limit'",
    "'category' => 'timeout'",
    "'category' => 'transport'",
    "'category' => 'upstream_unavailable'",
    "'category' => 'authorization'",
    "'category' => 'contract_or_validation'",
    "'category' => 'request_budget'",
    "'client_action' => 'reduce_scope_then_retry_preflight'",
    "'supported_error_categories' => array( 'rate_limit', 'timeout', 'session_terminated', 'transport', 'upstream_unavailable', 'authorization', 'contract_or_validation', 'request_budget', 'internal', 'unknown' )",
    "const SESSION_TERMINATION_BUDGET = 2",
    "'category' => 'session_terminated'",
    "'client_action' => 'reconnect_snapshot_then_resume'",
    "'session_breaker_scope' => 'request_local'",
    "'metadata_micro_read_preferred' => true",
    "'metadata_envelope_ability' => 'mad4b/read-metadata-envelope'",
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

if servers.count("'mad4b/connector-preflight'") < 2:
    raise SystemExit("connector preflight must be mounted on read and ChatGPT surfaces")

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
]:
    require(contract, marker, "normative resilience contract")




for marker in [
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    "MAD4B_SCP_Live_Truth::current_authority_status()",
]:
    require(connection, marker, "authoritative compact preflight truth")

for marker in [
    "MAD4B_SCP_Remote_Operation_Parity::reconciliation_status( $operation_id )",
    "mad4b.chatgpt-enrollment-mutation-reconciliation.v1",
    "'blind_retry_allowed' => false",
]:
    require(abilities, marker, "durable enrollment mutation reconciliation")

print("mad4b.connector-resilience.contract.v1: PASS")
