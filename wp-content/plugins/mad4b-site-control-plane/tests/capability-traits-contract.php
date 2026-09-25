<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );

function add_action( $hook, $callback, $priority = 10 ) {}
function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', str_replace( '.', '_', $value ) );
}
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

final class MAD4B_SCP_Provider_Compatibility_Certification {
	public static function capability_certification( $input = array() ) {
		$provider = isset( $input['provider_id'] ) ? (string) $input['provider_id'] : '';
		$capability = isset( $input['capability_id'] ) ? (string) $input['capability_id'] : '';
		if ( 'bit_pi' !== $provider ) {
			return array( 'contract' => 'mad4b.provider-capability-certification-result.v1', 'capabilities' => array(), 'match_count' => 0, 'authorizing' => false );
		}
		if ( 'flow.execute' === $capability ) {
			return array(
				'contract' => 'mad4b.provider-capability-certification-result.v1',
				'capabilities' => array(
					'flow.execute' => array(
						'certification_level' => 'REVERSIBLE_WRITE_CERTIFIED',
						'activation_stage' => 'canary',
						'read_eligible' => false,
						'write_eligible' => false,
						'canary_eligible' => true,
					),
				),
				'match_count' => 1,
				'authorizing' => false,
			);
		}
		if ( 'flows.read' === $capability ) {
			return array(
				'contract' => 'mad4b.provider-capability-certification-result.v1',
				'capabilities' => array(
					'flows.read' => array(
						'certification_level' => 'READ_COMPATIBLE',
						'activation_stage' => 'active',
						'read_eligible' => true,
						'write_eligible' => false,
						'canary_eligible' => true,
					),
				),
				'match_count' => 1,
				'authorizing' => false,
			);
		}
		return array( 'contract' => 'mad4b.provider-capability-certification-result.v1', 'capabilities' => array(), 'match_count' => 0, 'authorizing' => false );
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-capability-traits.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL capability-traits-contract: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$profile = MAD4B_SCP_Capability_Traits::profile( 'bit_pi', 'flow.execute' );
$check( 'mad4b.capability-profile.v1' === $profile['contract'], 'profile contract mismatch' );
$check( 'bit_pi' === $profile['provider_id'], 'provider mismatch' );
$check( 'flow.execute' === $profile['capability_id'], 'capability mismatch' );
$check( false === $profile['authorizing'], 'profile became authorizing' );
$check( false === $profile['mutation_performed'], 'profile reported mutation' );
$check( false === $profile['traits']['durable_wait'], 'Bit Flows durable_wait declaration mismatch' );
$check( 'none' === $profile['traits']['cancellation'], 'Bit Flows cancellation declaration mismatch' );
$check( 'mad4b_guarded' === $profile['traits']['idempotency_model'], 'Bit Flows idempotency declaration mismatch' );
$check( 'flow_history_readback' === $profile['traits']['callback_model'], 'Bit Flows callback declaration mismatch' );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', $profile['profile_fingerprint'] ), 'profile fingerprint invalid' );

$profile2 = MAD4B_SCP_Capability_Traits::profile( 'bit_pi', 'flow.execute' );
$check( hash_equals( $profile['profile_fingerprint'], $profile2['profile_fingerprint'] ), 'profile fingerprint is non-deterministic' );

$eligible = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'flow.execute',
	'required_traits' => array(
		'durable_wait' => false,
		'cancellation' => 'none',
		'local_or_remote' => 'local_wordpress',
	),
) );
$check( 'mad4b.capability-trait-resolution.v1' === $eligible['contract'], 'resolution contract mismatch' );
$check( true === $eligible['non_authorizing'], 'resolution became authorizing' );
$check( false === $eligible['mutation_performed'], 'resolution reported mutation' );
$eligible_ids = array_map( static function( $row ) { return $row['provider_id']; }, $eligible['eligible'] );
$check( in_array( 'bit_pi', $eligible_ids, true ), 'matching Bit Flows capability was not eligible' );

$canary = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'flow.execute',
	'required_traits' => array( 'local_or_remote' => 'local_wordpress' ),
	'release_ring' => 'canary',
	'require_certified' => true,
) );
$canary_ids = array_map( static function( $row ) { return $row['provider_id']; }, $canary['eligible'] );
$check( in_array( 'bit_pi', $canary_ids, true ), 'certified Bit Flows canary was not eligible' );
$bit_canary = array_values( array_filter( $canary['eligible'], static function( $row ) { return 'bit_pi' === $row['provider_id']; } ) );
$check( 1 === count( $bit_canary ), 'Bit Flows canary resolution count mismatch' );
$check( 'canary' === $bit_canary[0]['activation_stage'], 'Bit Flows activation stage mismatch' );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', $bit_canary[0]['certification_fingerprint'] ), 'certification fingerprint missing' );
$check( true === $canary['non_authorizing'], 'release-ring resolution became authorizing' );

$active_write = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'flow.execute',
	'required_traits' => array( 'local_or_remote' => 'local_wordpress' ),
	'release_ring' => 'active',
	'require_certified' => true,
) );
$active_write_rejected = array_values( array_filter( $active_write['rejected'], static function( $row ) { return 'bit_pi' === $row['provider_id']; } ) );
$check( 1 === count( $active_write_rejected ), 'non-active write provider was not rejected from active ring' );
$ring_reasons = array_map( static function( $v ) { return $v['reason_code']; }, $active_write_rejected[0]['violations'] );
$check( in_array( 'provider_not_active_eligible', $ring_reasons, true ), 'active ring rejection reason missing' );

$active_read = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'flows.read',
	'required_traits' => array( 'idempotency_model' => 'read_repeatable' ),
	'release_ring' => 'active',
	'require_certified' => true,
) );
$active_read_ids = array_map( static function( $row ) { return $row['provider_id']; }, $active_read['eligible'] );
$check( in_array( 'bit_pi', $active_read_ids, true ), 'active certified read provider was not eligible' );

$mismatch = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'flow.execute',
	'required_traits' => array( 'durable_wait' => true ),
) );
$bit_rejected = array_values( array_filter( $mismatch['rejected'], static function( $row ) {
	return 'bit_pi' === $row['provider_id'];
} ) );
$check( 1 === count( $bit_rejected ), 'Bit Flows mismatch was not rejected' );
$check( 'trait_mismatch' === $bit_rejected[0]['violations'][0]['reason_code'], 'trait mismatch reason code missing' );

$unknown = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'flow.execute',
	'required_traits' => array( 'max_runtime_seconds' => array( 'gte' => 1 ) ),
) );
$bit_unknown = array_values( array_filter( $unknown['rejected'], static function( $row ) {
	return 'bit_pi' === $row['provider_id'];
} ) );
$check( 1 === count( $bit_unknown ), 'unknown Bit Flows trait was not rejected' );
$check( 'trait_unknown' === $bit_unknown[0]['violations'][0]['reason_code'], 'unknown trait did not fail closed' );

$catalog_fixture=json_decode(file_get_contents(dirname(__DIR__).'/config/provider-capability-contracts.json'),true);
$catalog_fixture['providers']['alt_pi']=$catalog_fixture['providers']['bit_pi'];
$rp=new ReflectionProperty('MAD4B_SCP_Capability_Traits','catalog');
$rp->setAccessible(true);
$rp->setValue(null,$catalog_fixture);

$ambiguous=MAD4B_SCP_Capability_Traits::resolve(array(
	'capability_id'=>'flow.execute',
	'required_traits'=>array('local_or_remote'=>'local_wordpress'),
));
$check(true===$ambiguous['ambiguous'],'multiple eligible providers were silently tie-broken');
$check(true===$ambiguous['raw_ambiguous'],'raw ambiguity evidence missing');
$check(''===$ambiguous['selected_provider'],'ambiguous resolver selected provider implicitly');
$check(false===$ambiguous['implicit_tie_breaking'],'implicit tie-breaking flag changed');

$preferred=MAD4B_SCP_Capability_Traits::resolve(array(
	'capability_id'=>'flow.execute',
	'required_traits'=>array('local_or_remote'=>'local_wordpress'),
	'preferred_provider'=>'alt_pi',
));
$check(false===$preferred['ambiguous'],'explicit eligible preference did not resolve ambiguity');
$check('alt_pi'===$preferred['selected_provider'],'preferred provider was not selected');
$check(true===$preferred['preference_applied'],'preference application evidence missing');
$check(true===$preferred['non_authorizing'],'preference resolution became authorizing');

$bad_preference=MAD4B_SCP_Capability_Traits::resolve(array(
	'capability_id'=>'flow.execute',
	'required_traits'=>array('local_or_remote'=>'local_wordpress'),
	'preferred_provider'=>'not_eligible',
));
$check(true===$bad_preference['ambiguous'],'ineligible preference silently resolved ambiguity');
$check(''===$bad_preference['selected_provider'],'ineligible preference fell back silently');
$check('preferred_provider_not_eligible'===$bad_preference['preference_reason'],'ineligible preference reason missing');
MAD4B_SCP_Capability_Traits::clear_cache();

$missing = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'does.not.exist',
	'required_traits' => array( 'durable_wait' => true ),
) );
$check( 0 === count( $missing['eligible'] ), 'unknown capability produced an eligible provider' );
$check( false === $missing['ambiguous'], 'unknown capability became ambiguous' );

$read = MAD4B_SCP_Capability_Traits::profile( 'bit_pi', 'flows.read' );
$check( 'read_repeatable' === $read['traits']['idempotency_model'], 'Bit Flows read idempotency declaration mismatch' );
$check( false === $read['traits']['durable_wait'], 'Bit Flows read durable_wait declaration mismatch' );

$main_source = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$servers_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
$check( false !== strpos( $main_source, 'class-mad4b-scp-capability-traits.php' ), 'capability trait service is not loaded by plugin runtime' );
foreach ( array( 'mad4b/capability-trait-profile', 'mad4b/capability-trait-resolve' ) as $ability ) {
	$check( false !== strpos( $servers_source, "'" . $ability . "'" ), 'capability trait ability is not mounted on read plane: ' . $ability );
}
$check( false === strpos( $servers_source, "array( 'mad4b/capability-trait-profile', 'mad4b/capability-trait-resolve' )," ), 'capability trait mount guard ambiguity' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-capability-traits.php' );
foreach ( array( 'wp_insert_post(', 'wp_update_post(', '$wpdb->insert', '$wpdb->update', 'shell_exec(', 'exec(', 'proc_open(' ) as $forbidden ) {
	$check( false === strpos( $source, $forbidden ), 'capability trait resolver contains mutation primitive: ' . $forbidden );
}

echo "mad4b.capability-traits.v1: PASS\n";
