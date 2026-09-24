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

final class MAD4B_SCP_Schema {
	const VERSION = 9;
	public static function status( $deep = false ) {
		return array(
			'expected_version' => 9,
			'installed_version' => 9,
			'ready' => true,
			'integrity_token_valid' => true,
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

try {
	MAD4B_SCP_Context_Authority::register_ability();
} catch ( Throwable $e ) {
	mad4b_contract_assert( false, 'Context Authority registration must not assign an unregistered category.', $e->getMessage() );
}
mad4b_contract_assert( isset( $GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY ] ), 'AI review ability must register after its category exists.' );
mad4b_contract_assert( 'mad4b-write' === $GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY ]['category'], 'AI review ability must remain bound to the unified governed write category.' );

$schema = $abilities->schema_status();
mad4b_contract_assert( is_array( $schema ) && 'mad4b.schema-status.v1' === $schema['contract'], 'Schema status must expose the stable read-only contract.', $schema );
mad4b_contract_assert( ! empty( $schema['read_only'] ) && empty( $schema['mutation_performed'] ), 'Schema status must be explicitly non-mutating.', $schema );
mad4b_contract_assert( 9 === (int) $schema['expected_version'] && 9 === (int) $schema['installed_version'], 'Schema status must distinguish and report expected/installed Schema v9.', $schema );
mad4b_contract_assert( ! empty( $schema['ready'] ) && ! empty( $schema['physical_integrity']['ready'] ), 'Deep physical schema integrity must participate in readiness.', $schema );
mad4b_contract_assert( array() === $schema['physical_integrity']['missing_durable_columns'] && array() === $schema['physical_integrity']['missing_durable_indexes'], 'Durable column/index gaps must remain explicit.', $schema );
mad4b_contract_assert( 6 === count( $schema['durable_tables'] ) && ! in_array( false, $schema['durable_tables'], true ), 'All six Feature 007 durable tables must be represented explicitly.', $schema['durable_tables'] );

$servers_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
mad4b_contract_assert( is_string( $servers_source ) && false !== strpos( $servers_source, "'mad4b/schema-status'" ), 'Schema status must be part of the governed ChatGPT full-catalog universe through mad4b-read.' );

echo "mad4b.site-control-plane.abilities-category-schema-status.contract.v1: PASS\n";
