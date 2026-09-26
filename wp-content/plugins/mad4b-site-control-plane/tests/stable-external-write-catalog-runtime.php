<?php
if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "FAIL: PHP 7.4+ required\n" );
    exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

final class MAD4B_Test_Request {
    private $route;
    public function __construct( $route ) { $this->route = (string) $route; }
    public function get_route() { return $this->route; }
}

final class MAD4B_SCP_Staging_Write_Authority {
    public static $effective = true;
    public static $eligible = array();
    public static function effective() { return (bool) self::$effective; }
    public static function is_write_ability( $ability_name ) { return in_array( (string) $ability_name, self::$eligible, true ); }
}

final class MAD4B_SCP_Servers {
    public static $chatgpt = array();
    public static $write = array();
    public static $external = array();
    public static function expected_server_ids() { return array( 'mad4b-read', 'mad4b-chatgpt', 'mad4b-enrollment', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ); }
    public static function is_external_write_candidate( $ability_name ) { return in_array( (string) $ability_name, self::$external, true ); }
    public static function ability_is_mounted( $server_id, $ability_name ) {
        if ( 'mad4b-chatgpt' === $server_id ) return in_array( (string) $ability_name, self::$chatgpt, true );
        if ( 'mad4b-write' === $server_id ) return in_array( (string) $ability_name, self::$write, true );
        return false;
    }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-transport-context.php';

function mad4b_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

$core = 'mad4b/content-update-post';
$provider = 'jetsmartfilters/update-filter-meta';
$read = 'mad4b/site-info';
$enrollment = 'mad4b/reconcile-managed-skills';
$raw = 'mad4b/database-raw-query';
MAD4B_SCP_Servers::$external = array( $core, $provider );
MAD4B_SCP_Servers::$chatgpt = array( $core, $provider, $read, $enrollment );
MAD4B_SCP_Servers::$write = array( $core );
MAD4B_SCP_Staging_Write_Authority::$eligible = array( $core );

$request = new MAD4B_Test_Request( '/mcp/mad4b-chatgpt' );
$bound = MAD4B_SCP_Transport_Context::bind( 'mad4b-chatgpt', $request );
mad4b_assert( true === $bound, 'ChatGPT transport should bind for exact route.' );

// Unified discovery is intentionally available before mutation authority. A
// visible write must fail before grant lookup/approval/mutation while authority
// itself is not ready.
MAD4B_SCP_Staging_Write_Authority::$effective = false;
$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $core );
mad4b_assert( is_wp_error( $result ), 'Visible write must fail closed while Staging write authority is unavailable.' );
mad4b_assert( 'mad4b_write_authority_not_ready' === $result->get_error_code(), 'Unavailable write authority returned the wrong error.' );
// Bounded Enrollment maintenance is mounted directly on the compact ChatGPT
// transport and is deliberately not a normal external write candidate. It must
// remain executable through its own native permission/exact-build contract even
// before normal governed Write Authority is reconciled.
$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $enrollment );
mad4b_assert( 'mad4b-chatgpt' === $result, 'Bounded Managed Skills Enrollment operation must remain on ChatGPT transport.' );

MAD4B_SCP_Staging_Write_Authority::$effective = true;

// A registered provider write may be externally discoverable while gated, but
// it must fail before authority rebinding, approval claim, or mutation.
$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $provider );
mad4b_assert( is_wp_error( $result ), 'Gated provider write must fail closed.' );
mad4b_assert( 'mad4b_write_capability_not_eligible' === $result->get_error_code(), 'Gated provider write returned the wrong error.' );

// Promotion changes execution eligibility only; the same external ability now
// delegates to mad4b-write without changing the ChatGPT catalog.
MAD4B_SCP_Servers::$write[] = $provider;
MAD4B_SCP_Staging_Write_Authority::$eligible[] = $provider;
$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $provider );
mad4b_assert( 'mad4b-write' === $result, 'Certified provider write must delegate to mad4b-write.' );
mad4b_assert( in_array( $provider, MAD4B_SCP_Servers::$chatgpt, true ), 'Provider write unexpectedly left stable external catalog.' );

$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $core );
mad4b_assert( 'mad4b-write' === $result, 'Core governed write must delegate to mad4b-write.' );

$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $read );
mad4b_assert( 'mad4b-chatgpt' === $result, 'Read ability must remain on ChatGPT transport.' );

$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $raw );
mad4b_assert( is_wp_error( $result ), 'Raw SQL must remain unavailable on ChatGPT transport.' );
mad4b_assert( 'mad4b_transport_ability_not_mounted' === $result->get_error_code(), 'Raw SQL failed with unexpected error.' );

$status = MAD4B_SCP_Transport_Context::status();
mad4b_assert( 'mad4b.mcp-transport-context.v3' === $status['contract'], 'Transport v3 contract missing.' );
mad4b_assert( 'stable_unified_catalog_fail_closed_execution' === $status['chatgpt_write_discovery_model'], 'Unified fail-closed discovery model status missing.' );
mad4b_assert( empty( $status['credential_material_stored'] ), 'Transport status must never claim credential persistence.' );

echo "mad4b.stable-external-write-catalog.runtime.v2: PASS\n";
