<?php
/** Offline Staging attestor dynamic mount contract; no keys enrolled or writes. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action( $name, $callback, $priority = 10, $accepted = 1 ) {
    $GLOBALS['attestor_hooks'][ $name ] = true;
}
function current_user_can( $cap ) { return true; }
function get_current_user_id() { return 77; }
function wp_has_ability( $ability ) { return isset( $GLOBALS['attestor_abilities'][ $ability ] ); }
function wp_register_ability( $ability, $definition ) {
    $GLOBALS['attestor_abilities'][ $ability ] = $definition;
}
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
abstract class MAD4B_SCP_Adapter_Base {
    public function provider_key() { return $this->certified_provider_key(); }
}
final class MAD4B_SCP_Adapter_Registry {
    private static $instance;
    private $adapters = array();
    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    public function register( $adapter ) {
        if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) return false;
        $this->adapters[ $adapter->id() ] = $adapter;
        return true;
    }
    public function ability_names( $surface ) {
        $out = array();
        foreach ( $this->adapters as $adapter ) {
            $map = $adapter->ability_names();
            foreach ( $map[ $surface ] ?? array() as $name ) $out[] = $name;
        }
        return $out;
    }
}
class MAD4B_SCP_Site_Profile {
    public static $site = array(
        'configured_environment' => 'staging',
        'wordpress_environment' => 'staging',
        'site_uuid' => 'fixture-site',
        'profile_digest' => 'fixture-profile',
        'origin_match' => true,
        'authority_ready' => true,
    );
    public static function status() { return self::$site; }
    public static function user_is_enrolled( $id ) { return $id === 77; }
}
class MAD4B_SCP_Policy {
    public static function can_mutate() { return true; }
    public static function can_read() { return true; }
}
class MAD4B_SCP_Local_OAuth_Server {
    const CHATGPT_CIMD_CLIENT_ID = 'trusted-fixture-chatgpt';
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
    public static function verified_bearer_active() { return true; }
    public static function verified_bearer_has_scope( $scope ) {
        return $scope === self::AUTHORITY_STEP_UP_SCOPE;
    }
    public static function verified_bearer_client_is( $client ) {
        return $client === MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID;
    }
}
class MAD4B_SCP_Authorization {
    public static $allowed = true;
    public static function authorize_mutation( $ability, $lane, $provider, $input ) {
        if ( $ability !== MAD4B_SCP_CI_Outage_Attestation::ENROLL_APPLY ||
            $lane !== 'mad4b-admin' || $provider !== 'core' )
            return new WP_Error( 'fixture_wrong_authority_target' );
        return array( 'allowed' => self::$allowed );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-ci-outage-attestation.php';

function attestor_expect( $ok, $why ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $why . "\n" ); exit( 1 ); }
}
MAD4B_SCP_CI_Outage_Attestation::boot();
MAD4B_SCP_CI_Outage_Attestation::register_abilities();
$registry = MAD4B_SCP_Adapter_Registry::instance();
$name = MAD4B_SCP_CI_Outage_Attestation::ENROLL_APPLY;
attestor_expect( $registry->ability_names( 'admin' ) === array( $name )
    && $registry->ability_names( 'read' ) === array()
    && $registry->ability_names( 'content' ) === array()
    && $registry->ability_names( 'write' ) === array(),
    'Attestor enrolls only its declared Admin Ability dynamically' );
MAD4B_SCP_CI_Outage_Attestation::register_adapter( $registry );
attestor_expect( $registry->ability_names( 'admin' ) === array( $name ),
    'Duplicate registration cannot widen the governed Admin catalog' );
$adapter = new MAD4B_SCP_CI_Outage_Attestor_Adapter();
attestor_expect( $adapter->provider_key() === 'core',
    'Trusted first-party authorizer keeps its original core provider' );
attestor_expect( true === $adapter->mutation_ability_runtime_eligibility( $name ),
    'Staging attestor is structurally mountable with Ed25519 support' );
attestor_expect( is_wp_error( $adapter->mutation_ability_runtime_eligibility( 'untrusted/apply' ) ),
    'Unknown mutation must not mount via attestor registration' );
attestor_expect( true === MAD4B_SCP_CI_Outage_Attestation::can_enroll( array() ),
    'Central allowed-decision object must normalize to permission true' );
MAD4B_SCP_Authorization::$allowed = false;
attestor_expect( is_wp_error( MAD4B_SCP_CI_Outage_Attestation::can_enroll( array() ) ),
    'Denied central authorization remains a hard error' );
MAD4B_SCP_Site_Profile::$site['configured_environment'] = 'production';
attestor_expect( is_wp_error( $adapter->mutation_ability_runtime_eligibility( $name ) ),
    'No Production mount for offline Staging attestor' );
$server = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
attestor_expect( is_string( $server ) &&
    strpos( $server, "'" . $name . "'" ) === false,
    'Attestor Ability must never appear as a fixed central Server entry' );
echo "PASS: dynamic offline attestor registration and exact owner authority refusal\n";
