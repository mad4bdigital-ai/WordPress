from pathlib import Path

root = Path(__file__).resolve().parents[1]
transport = (root / "includes" / "class-mad4b-scp-transport-context.php").read_text(encoding="utf-8")
abilities = (root / "includes" / "class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
servers = (root / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")

required_transport = [
    "private static $write_dispatch_target = '';",
    "private static $write_dispatch_schema_sha256 = '';",
    "public static function with_write_dispatch_target",
    "'mad4b-chatgpt' !== self::current_server_id()",
    "mad4b_write_dispatch_nested_recursion_denied",
    "array( 'mad4b/write-execute', 'mad4b/enrollment-execute', 'mad4b/database-raw-query' )",
    "MAD4B_SCP_Servers::is_external_write_candidate( $ability_name )",
    "MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name )",
    "hash_equals( hash( 'sha256', $encoded_schema ), $expected_schema_sha256 )",
    "$nested_dispatch = self::write_dispatch_target_matches( $ability_name );",
    "! $nested_dispatch && ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability_name )",
    "} finally {",
    "'write_dispatch_target_bound' => '' !== self::$write_dispatch_target",
]
for marker in required_transport:
    if marker not in transport:
        raise SystemExit("nested write-dispatch transport invariant missing: " + marker)

required_dispatch = [
    "$target_entered = false;",
    "$execute_target = static function () use ( $ability, $params, $ability_name, $actual_schema_sha256, &$target_entered )",
    "$target_entered = true;",
    "if ( ! $target_entered ) {",
    "mad4b_write_dispatch_target_not_started",
    "'mutation_state' => 'not_started'",
    "'target_execution_entered' => false",
    "MAD4B_SCP_Transport_Context::with_write_dispatch_target(",
    "$planner_result = $execute_target();",
    "static function () use ( $execute_target )",
    "return $execute_target();",
    "private static $write_dispatch_governance_envelope = array();",
    "capture_write_dispatch_governance_envelope",
    "forward_write_dispatch_governance_envelope",
    "MAD4B_SCP_Identity_Context::bind_approval_ticket_for_request",
    "mad4b_write_dispatch_governance_envelope_conflict",
    "mad4b_write_dispatch_approval_binding_conflict",
    "if ( empty( $present ) ) return true;",
    "mad4b_write_dispatch_governance_envelope_rebind_conflict",
    "_mad4b_approval_ticket_id",
    "_mad4b_context_receipt",
]
for marker in required_dispatch:
    if marker not in abilities:
        raise SystemExit("write dispatcher target binding/governance invariant missing: " + marker)

dispatch_catalog = servers.split("public static function chatgpt_dispatch_transport_tools", 1)[1].split("public static function chatgpt_tools", 1)[0]
if "mad4b/approval-plan" in dispatch_catalog:
    raise SystemExit("approval-plan must remain hidden behind write-execute, not mounted as a direct ChatGPT mutation transport")
if "array( 'mad4b/write-execute', 'mad4b/developer-execute', 'mad4b/enrollment-execute' )" not in dispatch_catalog:
    raise SystemExit("compact ChatGPT mutation transport set drifted unexpectedly")
if "mad4b/developer-breakglass" in dispatch_catalog:
    raise SystemExit("Developer Breakglass must never enter the compact ChatGPT dispatcher inventory")

print("mad4b.write-dispatch-nested-transport.contract.v3: PASS")
