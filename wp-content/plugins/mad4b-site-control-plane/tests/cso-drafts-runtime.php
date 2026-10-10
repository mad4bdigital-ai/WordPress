<?php
/** CSO synthetic database CAS, actor isolation and nonsecret-only draft tests. */
define( 'ABSPATH', '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function maybe_serialize( $v ) { return serialize( $v ); }
function maybe_unserialize( $v ) { return unserialize( $v ); }
function wp_cache_delete( $key, $group ) { return true; }
class FakeDraftDB {
    public $rows = array();
    public $options = 'wp_options';
    public $last_error = '';
    public function prepare( $sql, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
        return array( $sql, $args );
    }
    public function get_var( $prepared ) {
        return isset( $this->rows[ $prepared[1][0] ] ) ?
            $this->rows[ $prepared[1][0] ] : null;
    }
    public function query( $prepared ) {
        list( $sql, $args ) = $prepared;
        if ( 0 === strpos( $sql, 'UPDATE ' ) ) {
            list( $next, $key, $expected ) = $args;
            if ( ! isset( $this->rows[ $key ] ) || $this->rows[ $key ] !== $expected ) return 0;
            $this->rows[ $key ] = $next; return 1;
        }
        if ( 0 === strpos( $sql, 'DELETE ' ) ) {
            list( $key, $expected ) = $args;
            if ( ! isset( $this->rows[ $key ] ) || $this->rows[ $key ] !== $expected ) return 0;
            unset( $this->rows[ $key ] ); return 1;
        }
        throw new RuntimeException( 'Unknown draft SQL' );
    }
}
$GLOBALS['wpdb'] = new FakeDraftDB();
function add_option( $key, $value, $description = '', $autoload = false ) {
    if ( isset( $GLOBALS['wpdb']->rows[ $key ] ) ) return false;
    $GLOBALS['wpdb']->rows[ $key ] = serialize( $value );
    return true;
}
class MAD4B_SCP_CSO_Scope {
    public static function enabled( $k ) { return $k === 'forms'; }
    public static function first_party_session() { return $GLOBALS['cookie']; }
    public static function current() { return $GLOBALS['scope']; }
    public static function assert_current( $scope ) {
        return $scope === $GLOBALS['scope'] ? true : self::error( 'SCOPE_CHANGED' );
    }
    public static function error( $r ) { return new WP_Error( $r ); }
    public static function digest( $v ) { return hash( 'sha256', json_encode( $v ) ); }
    public static function safe_data( $v ) {
        if ( ! is_array( $v ) || isset( $v['api_key'] ) ) return false;
        return true;
    }
    public static function bounded( $v ) { return strlen( json_encode( $v ) ) <= 4096; }
    public static function seal( $record, $purpose ) {
        return array( 'material' => $record,
            'proof' => hash_hmac( 'sha256', $purpose . json_encode( $record ), 'fixture-signing-secret' ) );
    }
    public static function unseal( $v, $purpose ) {
        return isset( $v['material'], $v['proof'] ) &&
            hash_equals( hash_hmac( 'sha256', $purpose . json_encode( $v['material'] ),
                'fixture-signing-secret' ), $v['proof'] ) ? $v['material'] : self::error( 'TAMPERED' );
    }
}
class MAD4B_SCP_Policy { public static function can_mutate() { return $GLOBALS['mutate']; } }
class MAD4B_SCP_Operational_Integrity {
    public static function capture() { return array( 'fingerprint' => $GLOBALS['scope']['binding_sha256'] ); }
    public static function assert_unchanged( $c, $mutate = false ) {
        return $c['fingerprint'] === $GLOBALS['scope']['binding_sha256'] && $GLOBALS['mutate'] ?
            true : new WP_Error( 'REVOKED' );
    }
}
class MAD4B_SCP_CSO_Forms {
    public static function validate( $form, $v ) {
        return isset( $v['query'] ) && is_string( $v['query'] ) ?
            array( 'valid' => true ) : new WP_Error( 'BAD_VALUES' );
    }
}
$GLOBALS['cookie'] = true;
$GLOBALS['mutate'] = true;
$GLOBALS['scope'] = array( 'actor_sha256' => str_repeat( 'a', 64 ),
    'binding_sha256' => str_repeat( 'b', 64 ), 'site_uuid' => str_repeat( 'c', 36 ) );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-cso-drafts.php';
function ck( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
$form = array( 'ability_name' => 'mad4b/test',
    'expected_descriptor_sha256' => str_repeat( 'd', 64 ) );
$draft = MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Cairo' ) );
ck( ! is_wp_error( $draft ) && $draft['revision'] === 1, 'created' );
$id = $draft['id'];
ck( count( $GLOBALS['wpdb']->rows ) === 1, 'one fixed-name option' );
$loaded = MAD4B_SCP_CSO_Drafts::load( $id );
ck( ! is_wp_error( $loaded ) && $loaded['values']['query'] === 'Cairo', 'own draft load' );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::create(
    $form, array( 'query' => 'Cairo', 'api_key' => 'must-deny' ) ) ), 'secret rejected' );
$GLOBALS['scope']['actor_sha256'] = str_repeat( 'e', 64 );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::load( $id ) ), 'other actor denied' );
$GLOBALS['scope']['actor_sha256'] = str_repeat( 'a', 64 );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::save(
    $id, 2, $form, array( 'query' => 'Giza' ) ) ), 'stale revision denied' );
$updated = MAD4B_SCP_CSO_Drafts::save(
    $id, 1, $form, array( 'query' => 'Giza' ) );
ck( ! is_wp_error( $updated ) && $updated['revision'] === 2, 'CAS update' );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::save(
    $id, 1, $form, array( 'query' => 'Luxor' ) ) ), 'replay stale update denied' );
$GLOBALS['mutate'] = false;
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::delete( $id, 2 ) ), 'revoked policy blocks delete' );
$GLOBALS['mutate'] = true;
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::delete( $id, 1 ) ), 'delete must have latest revision' );
$removed = MAD4B_SCP_CSO_Drafts::delete( $id, 2 );
ck( ! is_wp_error( $removed ) && $removed['deleted'], 'CAS delete' );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::load( $id ) ), 'deleted draft absent' );
echo "PASS CSO private drafts / CAS / actor isolation / secret deny\n";
