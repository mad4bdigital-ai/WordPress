<?php
define( 'ABSPATH', '/tmp/mad4b-feature007-ability-contract/' );

$GLOBALS['mad4b_test_categories'] = array();
$GLOBALS['mad4b_test_abilities'] = array();

function wp_register_ability_category( $slug, $args ) {
	$GLOBALS['mad4b_test_categories'][ (string) $slug ] = is_array( $args ) ? $args : array();
	return true;
}
function wp_has_ability( $name ) {
	return isset( $GLOBALS['mad4b_test_abilities'][ (string) $name ] );
}
function wp_register_ability( $name, $args ) {
	$category = is_array( $args ) && isset( $args['category'] ) ? (string) $args['category'] : '';
	if ( '' === $category || ! isset( $GLOBALS['mad4b_test_categories'][ $category ] ) ) {
		throw new RuntimeException( 'Ability category is not registered: ' . $category . ' for ' . (string) $name );
	}
	$GLOBALS['mad4b_test_abilities'][ (string) $name ] = $args;
	return true;
}
function add_action() { return true; }
function do_action() { return true; }

final class MAD4B_SCP_Schema {
	const VERSION = 9;
	public static function status( $deep = false ) {
		return array(
			'expected_version' => 9,
			'installed_version' => 9,
			'ready' => true,
			'integrity_token_valid' => true,
			'migration' => array(
				'contract' => array(
					'contract' => 'mad4b.schema-migration.v1',
					'migration_id' => '20260924-feature007-durable-execution-v9',
					'target_schema_version' => 9,
					'prerequisite_schema_versions' => array( 0, 6, 7, 8, 9 ),
					'forward_operation' => 'dbdelta_additive_mad4b_tables_columns_and_indexes',
					'rollback_or_forward_fix' => 'forward_fix_only_preserve_additive_schema_old_code_ignores_new_surfaces',
					'destructive' => false,
					'authority_widening' => false,
				),
				'contract_sha256' => str_repeat( 'a', 64 ),
				'preflight' => array(
					'contract' => 'mad4b.schema-migration-preflight.v1',
					'migration_id' => '20260924-feature007-durable-execution-v9',
					'installed_version' => 9,
					'target_version' => 9,
					'fresh_install' => false,
					'repair_run' => true,
					'contract_sha256' => str_repeat( 'a', 64 ),
					'blockers' => array(),
					'ready' => true,
					'read_only' => true,
					'mutation_performed' => false,
				),
				'receipt' => array(
					'contract' => 'mad4b.schema-migration-receipt.v1',
					'migration_id' => '20260924-feature007-durable-execution-v9',
					'from_version' => 6,
					'to_version' => 9,
					'run_type' => 'upgrade',
					'contract_sha256' => str_repeat( 'a', 64 ),
					'target_integrity_token' => str_repeat( 'b', 64 ),
					'physical_integrity_sha256' => str_repeat( 'c', 64 ),
					'physical_verified' => true,
					'readiness_finalized' => true,
					'destructive' => false,
					'authority_widened' => false,
					'completed_at' => '2026-09-24T00:00:00+00:00',
				),
				'receipt_valid' => true,
			),
			'tables' => array(
				'content_jobs' => 'wp_mad4b_content_jobs',
				'content_job_events' => 'wp_mad4b_content_job_events',
				'work_leases' => 'wp_mad4b_work_leases',
				'idempotency' => 'wp_mad4b_idempotency',
				'outbox' => 'wp_mad4b_execution_outbox',
				'inbox' => 'wp_mad4b_execution_inbox',
			),
			'physical_integrity' => $deep ? array(
				'contract' => 'mad4b.schema-integrity.v4',
				'ready' => true,
				'missing_tables' => array(),
				'missing_approval_columns' => array(),
				'missing_durable_columns' => array(),
				'missing_durable_indexes' => array(),
			) : array(),
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-abilities.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';

function mad4b_contract_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, 'FAIL abilities-category-schema-status: ' . $message . PHP_EOL );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}

$abilities = new MAD4B_SCP_Abilities();
$abilities->register_categories();
mad4b_contract_assert( isset( $GLOBALS['mad4b_test_categories']['mad4b-write'] ), 'mad4b-write category must be registered by the core category lifecycle.', $GLOBALS['mad4b_test_categories'] );

$abilities->register_abilities();
foreach ( array( 'mad4b/enrollment-discover', 'mad4b/enrollment-info', 'mad4b/enrollment-execute' ) as $dispatcher_ability ) {
	mad4b_contract_assert( isset( $GLOBALS['mad4b_test_abilities'][ $dispatcher_ability ] ), 'Bounded Enrollment dispatcher ability must register in the core compact catalog.', $dispatcher_ability );
}
mad4b_contract_assert( 'mad4b-read' === $GLOBALS['mad4b_test_abilities']['mad4b/enrollment-discover']['category'], 'Enrollment discovery must remain read-only.' );
mad4b_contract_assert( 'mad4b-read' === $GLOBALS['mad4b_test_abilities']['mad4b/enrollment-info']['category'], 'Enrollment info must remain read-only.' );
mad4b_contract_assert( 'mad4b-admin' === $GLOBALS['mad4b_test_abilities']['mad4b/enrollment-execute']['category'], 'Enrollment execute is a bounded mutation dispatcher, not a read tool.' );
$enrollment_meta = $GLOBALS['mad4b_test_abilities']['mad4b/enrollment-execute']['meta'];
mad4b_contract_assert( isset( $enrollment_meta['annotations']['readonly'] ) && false === $enrollment_meta['annotations']['readonly'], 'Enrollment execute must be explicitly annotated as mutating.' );
mad4b_contract_assert( isset( $enrollment_meta['mcp']['public'] ) && false === $enrollment_meta['mcp']['public'], 'Enrollment execute must remain non-public and only reach ChatGPT through the compact governed projection.' );

$enrollment_schema = $GLOBALS['mad4b_test_abilities']['mad4b/enrollment-execute']['input_schema'];
$enrollment_required = isset( $enrollment_schema['required'] ) && is_array( $enrollment_schema['required'] ) ? $enrollment_schema['required'] : array();
foreach ( array( 'ability_name', 'expected_registration_digest', 'expected_dispatch_policy_digest', 'expected_input_schema_sha256' ) as $required_field ) {
	mad4b_contract_assert( in_array( $required_field, $enrollment_required, true ), 'Enrollment execute must bind exact operation registration, policy and schema identity before mutation.', array( 'missing' => $required_field, 'required' => $enrollment_required ) );
}

$abilities_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-abilities.php' );
mad4b_contract_assert( is_string( $abilities_source ), 'Unable to read Ability source for bounded Enrollment dispatcher invariants.' );
$operation_start = strpos( $abilities_source, 'private function governed_enrollment_operation(' );
$policy_start = strpos( $abilities_source, 'private function enrollment_dispatch_policy_digest(', $operation_start );
$target_start = strpos( $abilities_source, 'private function governed_enrollment_target(', $policy_start );
$permission_start = strpos( $abilities_source, 'public function can_enrollment_dispatch(', $target_start );
$discover_start = strpos( $abilities_source, 'public function enrollment_discover(', $permission_start );
$execute_start = strpos( $abilities_source, 'public function enrollment_execute(', $discover_start );
$filesystem_start = strpos( $abilities_source, 'public function filesystem_list(', $execute_start );
mad4b_contract_assert( false !== $operation_start && false !== $policy_start && false !== $target_start && false !== $permission_start && false !== $discover_start && false !== $execute_start && false !== $filesystem_start, 'Bounded Enrollment dispatcher implementation sections are missing.' );
$operation_source = substr( $abilities_source, $operation_start, $policy_start - $operation_start );
$target_source = substr( $abilities_source, $target_start, $permission_start - $target_start );
$permission_source = substr( $abilities_source, $permission_start, $discover_start - $permission_start );
$execute_source = substr( $abilities_source, $execute_start, $filesystem_start - $execute_start );

mad4b_contract_assert( false !== strpos( $operation_source, 'MAD4B_SCP_Remote_Operation_Parity::operator_enrollment_abilities()' ), 'ChatGPT Enrollment target allowlist must come from the operator-only Remote Operation Parity inventory.' );
mad4b_contract_assert( false === strpos( $operation_source, 'MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities()' ), 'ChatGPT Enrollment target must not inherit external-executor or owner operations.' );
mad4b_contract_assert( false === strpos( $operation_source, "MAD4B_SCP_Servers::core_tools( 'mad4b-enrollment' )" ), 'Enrollment dispatcher must not inherit the broader low-level Enrollment server inventory.' );
foreach ( array( "'operator' !==", 'human_decision_required', "'deny' !==", 'remote_parity_ready' ) as $policy_marker ) {
	mad4b_contract_assert( false !== strpos( $operation_source, $policy_marker ), 'Enrollment target policy must fail closed on caller-role/human-decision/Production/readiness drift.', $policy_marker );
}
mad4b_contract_assert( false !== strpos( $target_source, "MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-enrollment'" ), 'Enrollment target must verify its actual bounded server mount.' );
mad4b_contract_assert( false === strpos( $permission_source, 'MAD4B_SCP_Policy::can_mutate()' ), 'Enrollment dispatcher must remain usable before normal Write Authority convergence.' );
foreach ( array(
	'MAD4B_SCP_Remote_Operation_Parity::can_execute(',
	'MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE',
	'verified_bearer_has_scope',
	'verified_bearer_client_is',
	'MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID',
	'MAD4B_SCP_Policy::can_breakglass()',
	'MAD4B_MCP_BREAKGLASS_ENABLED',
) as $authority_marker ) {
	mad4b_contract_assert( false !== strpos( $permission_source, $authority_marker ), 'Enrollment dispatcher authority hardening is incomplete.', $authority_marker );
}
foreach ( array( 'expected_registration_digest', 'expected_dispatch_policy_digest', 'expected_input_schema_sha256', 'mad4b_enrollment_dispatch_registration_drift', 'mad4b_enrollment_dispatch_policy_drift', 'mad4b_enrollment_dispatch_schema_drift', 'mutation_evidence_source', 'target_result' ) as $execution_marker ) {
	mad4b_contract_assert( false !== strpos( $execute_source, $execution_marker ), 'Enrollment execute must preserve exact plan identity and mutation evidence.', $execution_marker );
}

try {
	MAD4B_SCP_Context_Authority::register_ability();
} catch ( Throwable $e ) {
	mad4b_contract_assert( false, 'Context Authority registration must not assign an unregistered category.', $e->getMessage() );
}
mad4b_contract_assert( isset( $GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY ] ), 'AI review ability must register after its category exists.' );
mad4b_contract_assert( 'mad4b-admin' === $GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY ]['category'], 'AI review ability must use the registered governance Ability category; mad4b-write remains the dedicated MCP write transport/server.' );

$schema = $abilities->schema_status();
mad4b_contract_assert( is_array( $schema ) && 'mad4b.schema-status.v1' === $schema['contract'], 'Schema status must expose the stable read-only contract.', $schema );
mad4b_contract_assert( ! empty( $schema['read_only'] ) && empty( $schema['mutation_performed'] ), 'Schema status must be explicitly non-mutating.', $schema );
mad4b_contract_assert( 9 === (int) $schema['expected_version'] && 9 === (int) $schema['installed_version'], 'Schema status must distinguish and report expected/installed Schema v9.', $schema );
mad4b_contract_assert( 'mad4b.schema-migration.v1' === $schema['migration']['contract']['contract'], 'Schema status must expose the declared v9 migration contract.', $schema['migration'] );
mad4b_contract_assert( '20260924-feature007-durable-execution-v9' === $schema['migration']['preflight']['migration_id'] && ! empty( $schema['migration']['preflight']['ready'] ), 'Schema migration preflight must be visible and ready.', $schema['migration'] );
mad4b_contract_assert( ! empty( $schema['migration']['preflight']['read_only'] ) && empty( $schema['migration']['preflight']['mutation_performed'] ), 'Schema migration preflight evidence must remain read-only.', $schema['migration']['preflight'] );
mad4b_contract_assert( 'mad4b.schema-migration-receipt.v1' === $schema['migration']['receipt']['contract'] && ! empty( $schema['migration']['receipt']['readiness_finalized'] ), 'Schema status must expose a finalized durable migration receipt.', $schema['migration']['receipt'] );
mad4b_contract_assert( ! empty( $schema['migration']['receipt_valid'] ) && empty( $schema['migration']['receipt']['destructive'] ) && empty( $schema['migration']['receipt']['authority_widened'] ), 'Migration evidence must be valid, additive, and non-authorizing.', $schema['migration'] );
mad4b_contract_assert( ! empty( $schema['ready'] ) && ! empty( $schema['physical_integrity']['ready'] ), 'Deep physical schema integrity must participate in readiness.', $schema );
mad4b_contract_assert( array() === $schema['physical_integrity']['missing_durable_columns'] && array() === $schema['physical_integrity']['missing_durable_indexes'], 'Durable column/index gaps must remain explicit.', $schema );
mad4b_contract_assert( 6 === count( $schema['durable_tables'] ) && ! in_array( false, $schema['durable_tables'], true ), 'All six Feature 007 durable tables must be represented explicitly.', $schema['durable_tables'] );

$servers_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
mad4b_contract_assert( is_string( $servers_source ) && false !== strpos( $servers_source, "'mad4b/schema-status'" ), 'Schema status must be part of the governed ChatGPT full-catalog universe through mad4b-read.' );

echo "mad4b.site-control-plane.abilities-category-schema-status.contract.v1: PASS\n";
