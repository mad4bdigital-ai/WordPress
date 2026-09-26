<?php
/**
 * Real MariaDB Intent Registry reconciliation/versioning regression.
 */

$root = dirname( __DIR__ );
require_once $root . '/includes/class-mad4b-scp-schema.php';

if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
	final class MAD4B_SCP_Site_Profile {
		public static $uuid = '11111111-2222-4333-8444-555555555555';
		public static function site_uuid() { return self::$uuid; }
	}
}
if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) {
	final class MAD4B_SCP_Audit {
		public static function record( $ability, $summary, $status, $mutation ) { return array( 'recorded' => true ); }
		public static function transaction_committed() {}
		public static function transaction_rolled_back() {}
	}
}

require_once $root . '/includes/class-mad4b-scp-intent-registry.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL intent-registry-runtime: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) {
	if ( ! $condition ) $fail( $message );
};
$error_code = static function ( $value ) {
	return is_wp_error( $value ) ? $value->get_error_code() : '';
};

$status = MAD4B_SCP_Schema::status( true );
$check( ! empty( $status['ready'] ), 'schema is not ready' );
$check( MAD4B_SCP_Schema::VERSION >= 11, 'Intent Registry schema v11 is not active' );

$scope = array(
	'intent_id' => 'intent:egypt-visa',
	'locale' => 'en',
	'market' => 'eg',
);

$empty = MAD4B_SCP_Intent_Registry::current( array_merge( $scope, array( 'limit' => 100 ) ) );
$check( is_array( $empty ), 'empty scope read failed' );
$check( 0 === $empty['relation_count'], 'fixture scope is not empty' );
$check( 'ABSENT' === $empty['scope_sha256'], 'empty scope SHA is not ABSENT' );

$desired = array(
	array(
		'content_id' => 'post:100',
		'role' => 'PRIMARY_OWNER',
		'confidence' => 0.95,
		'evidence_refs' => array( 'bootstrap:post:100', 'serp:fixture-a' ),
		'source' => 'operator',
		'analysis_signals' => array(
			'serp_overlap' => false,
			'same_page_purpose' => true,
			'indexable' => true,
			'canonical_competes' => false,
			'performance_overlap' => false,
		),
	),
	array(
		'content_id' => 'post:101',
		'role' => 'SUPPORTING',
		'confidence' => 0.82,
		'evidence_refs' => array( 'bootstrap:post:101' ),
		'source' => 'observed',
		'analysis_signals' => array(),
	),
);

$create = MAD4B_SCP_Intent_Registry::reconcile( array_merge(
	$scope,
	array(
		'expected_scope_sha256' => 'ABSENT',
		'relations' => $desired,
		'reason' => 'create Intent Registry runtime fixture',
	)
) );
$check( is_array( $create ) && true === $create['mutation_performed'], 'initial reconcile did not mutate' );
$check( 2 === $create['relation_count'], 'initial relation count mismatch' );
$check( 64 === strlen( $create['scope_sha256'] ), 'initial scope SHA invalid' );
$check( 'HEALTHY_SUPPORT' === $create['conflict_analysis'][0]['outcome'], 'primary+supporting ownership not healthy' );
$scope_sha_v1 = $create['scope_sha256'];

$current = MAD4B_SCP_Intent_Registry::current( array_merge( $scope, array( 'limit' => 100 ) ) );
$check( 2 === $current['relation_count'], 'current relation count mismatch' );
$check( hash_equals( $scope_sha_v1, $current['scope_sha256'] ), 'current scope SHA does not match reconcile result' );
$by_content = array();
foreach ( $current['relations'] as $row ) $by_content[ $row['content_id'] ] = $row;
$check( 1 === $by_content['post:100']['revision'], 'primary revision is not 1' );
$check( 1 === $by_content['post:101']['revision'], 'support revision is not 1' );

// Stale expected state fails before mutation.
$stale = MAD4B_SCP_Intent_Registry::reconcile( array_merge(
	$scope,
	array(
		'expected_scope_sha256' => 'ABSENT',
		'relations' => $desired,
		'reason' => 'stale reconcile must fail',
	)
) );
$check( 'mad4b_intent_scope_stale' === $error_code( $stale ), 'stale scope did not fail closed' );

// Exact semantic replay is idempotent: no new revision.
$same = MAD4B_SCP_Intent_Registry::reconcile( array_merge(
	$scope,
	array(
		'expected_scope_sha256' => $scope_sha_v1,
		'relations' => $desired,
		'reason' => 'semantic replay should remain unchanged',
	)
) );
$check( is_array( $same ) && false === $same['mutation_performed'], 'semantic replay created mutation' );
$check( 2 === count( $same['unchanged_relation_ids'] ), 'semantic replay did not preserve both relations' );
$check( hash_equals( $scope_sha_v1, $same['scope_sha256'] ), 'semantic replay scope SHA drifted' );

// Change one semantic field: only that relation gets a new revision; history remains.
$desired_v2 = $desired;
$desired_v2[0]['confidence'] = 0.91;
$desired_v2[0]['evidence_refs'][] = 'operator:review-2';

$update = MAD4B_SCP_Intent_Registry::reconcile( array_merge(
	$scope,
	array(
		'expected_scope_sha256' => $scope_sha_v1,
		'relations' => $desired_v2,
		'reason' => 'revise primary owner confidence/evidence',
	)
) );
$check( is_array( $update ) && true === $update['mutation_performed'], 'semantic change did not mutate' );
$check( 1 === count( $update['inserted_relation_ids'] ), 'semantic change inserted unexpected relation count' );
$check( 1 === count( $update['closed_relation_ids'] ), 'semantic change did not close exactly one prior relation' );
$scope_sha_v2 = $update['scope_sha256'];
$check( ! hash_equals( $scope_sha_v1, $scope_sha_v2 ), 'semantic change did not change scope SHA' );

$current_v2 = MAD4B_SCP_Intent_Registry::current( array_merge( $scope, array( 'limit' => 100 ) ) );
$by_content = array();
foreach ( $current_v2['relations'] as $row ) $by_content[ $row['content_id'] ] = $row;
$check( 2 === $by_content['post:100']['revision'], 'changed primary relation did not increment revision' );
$check( 1 === $by_content['post:101']['revision'], 'unchanged supporting relation revision drifted' );

// Verify historical row remains and only one active current row exists for each content identity.
global $wpdb;
$t = MAD4B_SCP_Schema::tables();
$history = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT relation_id,revision,valid_to,current_relation_key FROM {$t['intent_relations']} WHERE site_uuid=%s AND locale=%s AND market=%s AND intent_id=%s AND content_id=%s ORDER BY revision ASC,id ASC",
		MAD4B_SCP_Site_Profile::site_uuid(),
		$scope['locale'],
		$scope['market'],
		$scope['intent_id'],
		'post:100'
	),
	ARRAY_A
);
$check( 2 === count( $history ), 'historical revision was not preserved' );
$check( '' !== (string) $history[0]['valid_to'] && null === $history[0]['current_relation_key'], 'old revision was not closed' );
$check( '' === (string) $history[1]['valid_to'] && 64 === strlen( (string) $history[1]['current_relation_key'] ), 'new revision is not active/current' );
$check( $history[0]['relation_id'] === $history[1]['relation_id'], 'logical relation identity changed across revisions' );

// Multiple owner roles are not automatically cannibalization without enough explicit signals.
$overlap = MAD4B_SCP_Intent_Registry::analyze( array(
	'relations' => array(
		array(
			'intent_id' => 'intent:hotel',
			'content_id' => 'post:200',
			'locale' => 'en',
			'market' => 'eg',
			'role' => 'PRIMARY_OWNER',
			'confidence' => 0.92,
			'evidence_refs' => array( 'evidence:a' ),
			'source' => 'operator',
			'analysis_signals' => array(),
		),
		array(
			'intent_id' => 'intent:hotel',
			'content_id' => 'post:201',
			'locale' => 'en',
			'market' => 'eg',
			'role' => 'INFORMATIONAL_OWNER',
			'confidence' => 0.89,
			'evidence_refs' => array( 'evidence:b' ),
			'source' => 'operator',
			'analysis_signals' => array( 'serp_overlap' => true ),
		),
	),
) );
$check( is_array( $overlap ), 'overlap analysis failed' );
$check( 'POSSIBLE_OVERLAP' === $overlap['outcomes'][0]['outcome'], 'overlap became automatic cannibalization' );

echo "mad4b.intent-registry.runtime.v11: PASS\n";
