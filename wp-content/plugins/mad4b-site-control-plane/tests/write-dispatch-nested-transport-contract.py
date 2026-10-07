from pathlib import Path
import subprocess
import tempfile
import textwrap

root = Path(__file__).resolve().parents[1]
transport = (root / "includes" / "class-mad4b-scp-transport-context.php").read_text(encoding="utf-8")
abilities = (root / "includes" / "class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
servers = (root / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
authorization = (root / "includes" / "class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
execution_fence = (root / "includes" / "class-mad4b-scp-execution-fence.php").read_text(encoding="utf-8")
planning_guard = (root / "includes" / "class-mad4b-scp-staging-write-planning-guard.php").read_text(encoding="utf-8")

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
    "MAD4B_SCP_Authorization::begin_execution_callback_observation( $ability_name );",
    "MAD4B_SCP_Authorization::execution_callback_started( $ability_name )",
    "MAD4B_SCP_Authorization::clear_execution_callback_observation( $ability_name )",
    "if ( ! $target_entered ) {",
    "mad4b_write_dispatch_target_not_started",
    "'mutation_state' => 'not_started'",
    "'target_execution_entered' => false",
    "MAD4B_SCP_Transport_Context::with_write_dispatch_target(",
    "$planner_result = $with_approval_scope( $execute_target );",
    "static function () use ( $ability_name, $execute_target )",
    "return $execute_target();",
    "private static $write_dispatch_governance_envelope = array();",
    "private static $write_dispatch_governance_binding = '';",
    "write_dispatch_preparation_binding",
    "MAD4B_SCP_Ability_Contract_Inspector::digest( 'mad4b.write-dispatch-governance-value.v1'",
    "$identity['target_input_sha256'] = $target_input_sha256;",
    "mad4b_write_dispatch_governance_target_conflict",
    "self::$write_dispatch_governance_binding = '';",
    "capture_write_dispatch_governance_envelope",
    "forward_write_dispatch_governance_envelope",
    "MAD4B_SCP_Identity_Context::with_approval_ticket_for_request",
    "mad4b_write_dispatch_governance_envelope_conflict",
    "mad4b_write_dispatch_approval_scope_unavailable",
    "approval_plan_dispatch_preflight_failure",
    "mad4b_approval_plan_guard_unavailable",
    "MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_remote_plan_input",
    "MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input",
    "'mutation_state' => 'not_started'",
    "'reconciliation_required' => false",
    "'client_action' => 'correct_plan_input_or_authority_then_replan'",
    "if ( empty( $present ) ) {",
    "mad4b_write_dispatch_governance_envelope_rebind_conflict",
    "_mad4b_approval_ticket_id",
    "_mad4b_context_receipt",
    "MAX_WRITE_DISPATCH_CONTEXT_RECEIPT_BYTES = 65536",
    "'_mad4b_approval_ticket_id' => array( 'type' => 'string'",
    "'_mad4b_context_receipt' => array( 'type' => 'object', 'additionalProperties' => true )",
    "mad4b_write_dispatch_context_receipt_oversized",
]
for marker in required_dispatch:
    if marker not in abilities:
        raise SystemExit("write dispatcher target binding/governance invariant missing: " + marker)

write_schema = abilities.split("$this->add( 'mad4b/write-execute'", 1)[1].split("$this->add( 'mad4b/developer-discover'", 1)[0]
for marker in [
    "'_mad4b_approval_ticket_id' => array( 'type' => 'string'",
    "'_mad4b_context_receipt' => array( 'type' => 'object', 'additionalProperties' => true )",
]:
    if marker not in write_schema:
        raise SystemExit("write dispatcher transport schema is missing reviewed governance field: " + marker)

for marker in [
    "if ( 'mad4b/write-execute' === (string) $name )",
    "$mcp_meta['surface'] = 'write-dispatch';",
    "$mcp_meta['generic_remote_admin'] = false;",
    "$mcp_meta['breakglass_allowed'] = false;",
]:
    if marker not in abilities:
        raise SystemExit("write-execute transport-only registration invariant missing: " + marker)

staging_write_authority = (root / "includes" / "class-mad4b-scp-staging-write-authority.php").read_text(encoding="utf-8")
if "array( 'enrollment', 'developer-dispatch', 'write-dispatch' )" not in staging_write_authority:
    raise SystemExit("governed write augmentation no longer exempts the write dispatcher transport surface")
if "private function schema( array $properties" not in abilities or "'additionalProperties' => false" not in abilities:
    raise SystemExit("write dispatcher must retain the shared top-level additionalProperties=false schema boundary")

required_authorization_boundary = [
    "private static $execution_callback_started = array();",
    "public static function permission_result_from_authorization",
    "return true === $result['allowed'] && 'preflight_allowed' === (string) $result['reason_code'];",
    "MAD4B_SCP_Authorization::permission_result_from_authorization( $result )",
    "public static function begin_execution_callback_observation",
    "public static function mark_execution_callback_started",
    "public static function execution_callback_started",
    "public static function clear_execution_callback_observation",
    "MAD4B_SCP_Authorization::mark_execution_callback_started( $name );",
]
for marker in required_authorization_boundary:
    if marker not in authorization:
        raise SystemExit("execution callback boundary invariant missing: " + marker)

for marker in [
    "private static function remember_trusted_execution_boundary",
    "public static function propagate_trusted_execution_boundary",
    "in_array( $inner_callback, self::$trusted_execution_boundaries[ $ability_name ], true )",
]:
    if marker not in authorization:
        raise SystemExit("trusted execution provenance invariant missing: " + marker)

for source_name, source in (("execution fence", execution_fence), ("undo post-boundary wrapper", planning_guard)):
    if "MAD4B_SCP_Authorization::propagate_trusted_execution_boundary" not in source:
        raise SystemExit(source_name + " does not propagate reviewed execution-boundary provenance")

# Execute the real central permission wrapper against the WordPress 7.1
# bool|WP_Error contract. This reproduces the live failure without booting a
# complete WordPress site and proves that only the exact successful preflight
# decision becomes true; every other non-boolean value fails closed.
with tempfile.TemporaryDirectory() as tmp:
    harness = Path(tmp) / "authorization-permission-wrapper-runtime.php"
    harness.write_text(textwrap.dedent(r"""<?php
define( 'ABSPATH', __DIR__ );

class WP_Error {
    private $code;
    public function __construct( $code = 'error' ) { $this->code = (string) $code; }
    public function get_error_code() { return $this->code; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) {
    return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $value ) );
}

require $argv[1];

function wrapped_permission_result( $result ) {
    $args = array(
        'execute_callback' => static function () { return true; },
        'permission_callback' => static function ( $input = null ) use ( $result ) { return $result; },
        'category' => 'mad4b-write',
        'meta' => array(
            'annotations' => array( 'readonly' => false ),
            'mcp' => array(
                'mad4b_governed_write_authority' => 'runtime-test',
                'surface' => 'write',
            ),
        ),
    );
    $wrapped = MAD4B_SCP_Authorization::wrap_execution_boundary( $args, 'mad4b/plugin-package-apply' );
    return call_user_func( $wrapped['permission_callback'], array() );
}

if ( true !== wrapped_permission_result( array( 'allowed' => true, 'reason_code' => 'preflight_allowed' ) ) ) exit( 10 );
if ( false !== wrapped_permission_result( array( 'allowed' => true, 'reason_code' => 'execution_claimed' ) ) ) exit( 11 );
if ( false !== wrapped_permission_result( array( 'allowed' => false, 'reason_code' => 'preflight_allowed' ) ) ) exit( 12 );
if ( false !== wrapped_permission_result( array( 'allowed' => true ) ) ) exit( 13 );
if ( true !== wrapped_permission_result( true ) ) exit( 14 );
if ( false !== wrapped_permission_result( false ) ) exit( 15 );
$error = new WP_Error( 'denied' );
if ( $error !== wrapped_permission_result( $error ) ) exit( 16 );
if ( false !== wrapped_permission_result( 'truthy-but-invalid' ) ) exit( 17 );

$base = array(
    'execute_callback' => static function () { return true; },
    'permission_callback' => static function () { return true; },
    'category' => 'mad4b-write',
    'meta' => array(
        'annotations' => array( 'readonly' => false ),
        'mcp' => array(
            'mad4b_governed_write_authority' => 'runtime-test',
            'surface' => 'write',
        ),
    ),
);
$trusted = MAD4B_SCP_Authorization::wrap_execution_boundary( $base, 'mad4b/provenance-fixture' );
$inner = $trusted['execute_callback'];
$outer = static function ( $input = null ) use ( $inner ) { return call_user_func( $inner, $input ); };
if ( true !== MAD4B_SCP_Authorization::propagate_trusted_execution_boundary( 'mad4b/provenance-fixture', $outer, $inner ) ) exit( 18 );

class ProvenanceAbilityFixture {
    private $execute_callback;
    public function __construct( $callback ) { $this->execute_callback = $callback; }
    public function get_name() { return 'mad4b/provenance-fixture'; }
}
if ( ! MAD4B_SCP_Authorization::execution_boundary_verified( new ProvenanceAbilityFixture( $outer ) ) ) exit( 19 );

$spoof_inner = static function () { return true; };
$spoof_outer = static function () use ( $spoof_inner ) { return call_user_func( $spoof_inner ); };
if ( MAD4B_SCP_Authorization::propagate_trusted_execution_boundary( 'mad4b/provenance-fixture', $spoof_outer, $spoof_inner ) ) exit( 20 );
if ( MAD4B_SCP_Authorization::execution_boundary_verified( new ProvenanceAbilityFixture( $spoof_outer ) ) ) exit( 21 );

echo "mad4b.authorization-permission-wrapper.runtime.v2: PASS\\n";
"""), encoding="utf-8")
    subprocess.run(
        ["php", str(harness), str(root / "includes" / "class-mad4b-scp-authorization.php")],
        check=True,
    )

if "'mad4b_approval_replay_denied' !== (string) $error->get_error_code()" in authorization:
    raise SystemExit("remote permission denial audit must not hide non-replay denial reason codes")

dispatch_catalog = servers.split("public static function chatgpt_dispatch_transport_tools", 1)[1].split("public static function chatgpt_tools", 1)[0]
if "mad4b/approval-plan" in dispatch_catalog:
    raise SystemExit("approval-plan must remain hidden behind write-execute, not mounted as a direct ChatGPT mutation transport")
if "array( 'mad4b/write-execute', 'mad4b/developer-execute', 'mad4b/enrollment-execute' )" not in dispatch_catalog:
    raise SystemExit("compact ChatGPT mutation transport set drifted unexpectedly")
if "mad4b/developer-breakglass" in dispatch_catalog:
    raise SystemExit("Developer Breakglass must never enter the compact ChatGPT dispatcher inventory")

print("mad4b.write-dispatch-nested-transport.contract.v8: PASS")
