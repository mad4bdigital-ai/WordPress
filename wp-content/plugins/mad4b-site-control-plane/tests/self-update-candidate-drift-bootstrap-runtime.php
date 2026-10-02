<?php
define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}
function sanitize_text_field( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = (string) $code;
		$this->message = (string) $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

final class MAD4B_SCP_Site_Profile {
	public static $environment = 'staging';
	public static $configured = true;
	public static $origin_enrolled = true;
	public static $urls_match = true;
	public static $write_enabled = true;
	public static function configured() { return self::$configured; }
	public static function current_environment() { return self::$environment; }
	public static function origin_enrolled() { return self::$origin_enrolled; }
	public static function site_urls_match_enrollment() { return self::$urls_match; }
	public static function write_enabled() { return self::$write_enabled; }
}
final class MAD4B_SCP_Policy {
	public static $breakglass = false;
	public static function can_breakglass() { return self::$breakglass; }
}
final class MAD4B_SCP_Staging_Write_Authority {
	public static $checkpoint = array();
	public static $plan = array();
	public static $binding = array();
	public static $effective = false;
	public static function persistence_checkpoint() { return self::$checkpoint; }
	public static function reconciliation_plan() { return self::$plan; }
	public static function candidate_binding_status() { return self::$binding; }
	public static function effective() { return self::$effective; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php';

function check( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
}
function bootstrap_policy() {
	$method = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'bootstrap_candidate_drift_policy' );
	$method->setAccessible( true );
	return $method->invoke( null );
}
function setup_clean_candidate_drift() {
	MAD4B_SCP_Site_Profile::$environment = 'staging';
	MAD4B_SCP_Site_Profile::$configured = true;
	MAD4B_SCP_Site_Profile::$origin_enrolled = true;
	MAD4B_SCP_Site_Profile::$urls_match = true;
	MAD4B_SCP_Site_Profile::$write_enabled = true;
	MAD4B_SCP_Policy::$breakglass = false;
	MAD4B_SCP_Staging_Write_Authority::$effective = false;
	MAD4B_SCP_Staging_Write_Authority::$checkpoint = array(
		'contract' => 'mad4b.governed-write-authority-persistence-checkpoint.v1',
		'exists' => true,
		'status' => array(
			'ready' => true,
			'state' => 'ready',
			'blocker' => '',
			'write_inventory_fingerprint' => str_repeat( 'a', 64 ),
		),
	);
	MAD4B_SCP_Staging_Write_Authority::$plan = array(
		'eligible' => true,
		'current_ready' => true,
		'agent_present' => true,
		'exact_grants_missing_count' => 0,
		'stale_allow_grants_count' => 0,
		'unreviewed_stale_allow_grants_count' => 0,
		'broad_environment_grants_count' => 0,
		'duplicate_exact_allow_grants_count' => 0,
		'current_agent_wildcard_grants' => 0,
		'global_registry_wildcard_grants' => 0,
		'grant_blockers' => array(),
		'write_inventory_fingerprint' => str_repeat( 'b', 64 ),
		'grant_rows_fingerprint' => str_repeat( 'c', 64 ),
		'persisted_grant_records_fingerprint' => str_repeat( 'd', 64 ),
	);
	$sha = str_repeat( '1', 40 );
	MAD4B_SCP_Staging_Write_Authority::$binding = array(
		'required' => true,
		'match' => false,
		'current_source_commit_sha' => $sha,
		'current_build_fingerprint' => str_repeat( '2', 64 ),
		'current_package_manifest_digest' => str_repeat( '3', 64 ),
		'current_artifact_identity' => 'mad4b-site-control-plane-0.4.0-rc.88-' . $sha,
	);
}

setup_clean_candidate_drift();
$ready = bootstrap_policy();
check( is_array( $ready ) && ! empty( $ready['eligible'] ), 'clean candidate-only drift must be bootstrap eligible' );
check( 'candidate_drift_only' === $ready['mode'], 'bootstrap mode mismatch' );
check( false === $ready['authority_carry_forward'], 'bootstrap must not carry authority across replacement' );
check( false === $ready['authority_mutation_allowed'], 'bootstrap must not mutate authority' );
check( false === $ready['grant_mutation_allowed'], 'bootstrap must not mutate grants' );
check( false === $ready['candidate_binding_mutation_allowed'], 'bootstrap must not bind candidate during replacement' );
check( true === $ready['post_update_candidate_rebind_required'], 'bootstrap must require explicit post-update candidate rebind' );
check( false === $ready['production_mutation_allowed'], 'bootstrap must never authorize Production mutation' );
check( false === $ready['mutation_performed'], 'bootstrap policy must remain read-only' );

$cases = array(
	'prior authority effective' => static function () { MAD4B_SCP_Staging_Write_Authority::$effective = true; },
	'candidate already current' => static function () { MAD4B_SCP_Staging_Write_Authority::$binding['match'] = true; },
	'candidate binding not required' => static function () { MAD4B_SCP_Staging_Write_Authority::$binding['required'] = false; },
	'missing exact grant' => static function () { MAD4B_SCP_Staging_Write_Authority::$plan['exact_grants_missing_count'] = 1; },
	'stale allow grant' => static function () { MAD4B_SCP_Staging_Write_Authority::$plan['stale_allow_grants_count'] = 1; },
	'unreviewed stale authority' => static function () { MAD4B_SCP_Staging_Write_Authority::$plan['unreviewed_stale_allow_grants_count'] = 1; },
	'broad environment grant' => static function () { MAD4B_SCP_Staging_Write_Authority::$plan['broad_environment_grants_count'] = 1; },
	'duplicate exact grant' => static function () { MAD4B_SCP_Staging_Write_Authority::$plan['duplicate_exact_allow_grants_count'] = 1; },
	'agent wildcard grant' => static function () { MAD4B_SCP_Staging_Write_Authority::$plan['current_agent_wildcard_grants'] = 1; },
	'global wildcard grant' => static function () { MAD4B_SCP_Staging_Write_Authority::$plan['global_registry_wildcard_grants'] = 1; },
	'grant blocker' => static function () { MAD4B_SCP_Staging_Write_Authority::$plan['grant_blockers'] = array( 'fixture_blocker' ); },
	'invalid persisted grant fingerprint' => static function () { MAD4B_SCP_Staging_Write_Authority::$plan['persisted_grant_records_fingerprint'] = ''; },
	'invalid current candidate provenance' => static function () { MAD4B_SCP_Staging_Write_Authority::$binding['current_build_fingerprint'] = 'invalid'; },
	'bad artifact identity' => static function () { MAD4B_SCP_Staging_Write_Authority::$binding['current_artifact_identity'] = 'wrong-artifact'; },
	'missing checkpoint' => static function () { MAD4B_SCP_Staging_Write_Authority::$checkpoint['exists'] = false; },
	'checkpoint not ready' => static function () { MAD4B_SCP_Staging_Write_Authority::$checkpoint['status']['ready'] = false; },
	'breakglass active' => static function () { MAD4B_SCP_Policy::$breakglass = true; },
	'production environment' => static function () { MAD4B_SCP_Site_Profile::$environment = 'production'; },
	'write profile disabled' => static function () { MAD4B_SCP_Site_Profile::$write_enabled = false; },
	'origin drift' => static function () { MAD4B_SCP_Site_Profile::$origin_enrolled = false; },
);

foreach ( $cases as $label => $mutate ) {
	setup_clean_candidate_drift();
	$mutate();
	$result = bootstrap_policy();
	check( is_array( $result ) && empty( $result['eligible'] ), $label . ' must fail closed' );
	check( ! empty( $result['blockers'] ), $label . ' must expose a bounded blocker' );
	check( false === $result['authority_mutation_allowed'], $label . ' must never widen authority' );
	check( false === $result['production_mutation_allowed'], $label . ' must never widen Production authority' );
	check( false === $result['mutation_performed'], $label . ' policy probe must remain read-only' );
}

echo "mad4b.self-update-bootstrap-candidate-drift-policy.v1: PASS\n";
