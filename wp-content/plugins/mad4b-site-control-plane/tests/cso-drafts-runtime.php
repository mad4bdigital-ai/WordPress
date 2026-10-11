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
function wp_cache_delete( $key, $group ) { $GLOBALS['cache_deletes'][] = array( $key, $group ); return true; }
function get_current_blog_id() { return $GLOBALS['blog_id'] ?? 1; }
function get_current_user_id() { return $GLOBALS['user_id'] ?? 7; }
class FakeDraftDB {
    public $rows = array();
    public $options = 'wp_options';
    public $last_error = '';
    public function prepare( $sql, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
        return array( $sql, $args );
    }
    public function get_blog_prefix( $blog ) { return $blog === 1 ? 'wp_' : 'wp_' . $blog . '_'; }
    public function get_var( $prepared ) {
        $this->last_error = '';
        $raw = $this->rows[ $prepared[1][0] ] ?? null;
        return is_string( $raw ) && strlen( $raw ) > 65536 ? '' : $raw;
    }
    public function query( $prepared ) {
        list( $sql, $args ) = $prepared;
        $this->last_error = '';
        if ( 0 === strpos( $sql, 'INSERT INTO ' ) ) {
            ck( strpos( $sql, 'ON DUPLICATE' ) === false && strpos( $sql, 'IGNORE' ) === false, 'draft SQL INSERT ONLY' );
            list( $key, $raw ) = $args;
            if ( ! empty( $GLOBALS['draft_insert_race'] ) ) {
                unset( $GLOBALS['draft_insert_race'] );
                $winner = unserialize( $raw )['material'];
                $winner['values']['query'] = 'Concurrent insert winner';
                $this->rows[ $key ] = serialize( MAD4B_SCP_CSO_Scope::seal( $winner, MAD4B_SCP_CSO_Drafts::CONTRACT ) );
                $GLOBALS['winner_key'] = $key; $GLOBALS['winner_raw'] = $this->rows[ $key ];
            }
            if ( isset( $this->rows[ $key ] ) ) { $this->last_error = 'duplicate key'; return false; }
            $this->rows[ $key ] = $raw;
            if ( ! empty( $GLOBALS['draft_insert_ack_lost'] ) ) {
                unset( $GLOBALS['draft_insert_ack_lost'] ); $this->last_error = 'lost insert ACK'; return false;
            }
            return 1;
        }
        if ( 0 === strpos( $sql, 'UPDATE ' ) ) {
            list( $next, $key, $expected ) = $args;
            if ( ! empty( $GLOBALS['draft_reclaim_race'] ) ) {
                unset( $GLOBALS['draft_reclaim_race'] );
                $winner = unserialize( $next )['material'];
                $winner['values']['query'] = 'Concurrent reclaim winner';
                $this->rows[ $key ] = serialize( MAD4B_SCP_CSO_Scope::seal( $winner, MAD4B_SCP_CSO_Drafts::CONTRACT ) );
                $GLOBALS['winner_key'] = $key; $GLOBALS['winner_raw'] = $this->rows[ $key ];
            }
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
    $GLOBALS['option_adds'] = ( $GLOBALS['option_adds'] ?? 0 ) + 1;
    // WordPress UPSERT after a stale get_option precheck can overwrite rows.
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
        if ( is_array( $v ) ) {
            foreach ( $v as $key => $value ) {
                if ( $key === 'api_key' || ! self::safe_data( $value ) ) return false;
            }
            return true;
        }
        return is_scalar( $v ) || null === $v;
    }
    public static function bounded( $v ) { $json = json_encode( $v ); return is_string( $json ) && strlen( $json ) <= 131072; }
    public static function seal( $record, $purpose ) {
        if ( strlen( json_encode( $record ) ) > 32768 ) return self::error( 'SEAL_BOUNDS' );
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
        if ( ! empty( $GLOBALS['revoke_during_validation'] ) ) { unset( $GLOBALS['revoke_during_validation'] ); $GLOBALS['mutate'] = false; }
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
    $GLOBALS['checks'] = ( $GLOBALS['checks'] ?? 0 ) + 1;
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

function draft_key( $id ) { return 'mad4b_cso_draft_slot_v2_' . explode( '.', $id )[1]; }
function expire_draft( $id ) {
    $key = draft_key( $id );
    $record = unserialize( $GLOBALS['wpdb']->rows[ $key ] )['material'];
    $record['updated_at'] = time() - MAD4B_SCP_CSO_Drafts::TTL - 10;
    $record['created_at'] = $record['updated_at'];
    $record['expires_at'] = $record['updated_at'] + MAD4B_SCP_CSO_Drafts::TTL;
    $GLOBALS['wpdb']->rows[ $key ] = serialize( MAD4B_SCP_CSO_Scope::seal( $record, MAD4B_SCP_CSO_Drafts::CONTRACT ) );
}
// Signed, expired rows may be explicitly removed by the exact owner/revision,
// but never loaded/saved or silently deleted by an ordinary read request.
$expires = MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Retained' ) );
ck( ! is_wp_error( $expires ), 'expiry fixture created' );
expire_draft( $expires['id'] ); $expired_raw = $GLOBALS['wpdb']->rows;
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::load( $expires['id'] ) ) &&
    $GLOBALS['wpdb']->rows === $expired_raw, 'expired load is denied and projection only' );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::save( $expires['id'], 1, $form, array( 'query' => 'Revived' ) ) ), 'expired save cannot revive row' );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::delete( $expires['id'], 2 ) ), 'expired delete still requires exact revision' );
$GLOBALS['scope']['actor_sha256'] = str_repeat( 'e', 64 );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::delete( $expires['id'], 1 ) ), 'expired row remains actor bound for delete' );
$GLOBALS['scope']['actor_sha256'] = str_repeat( 'a', 64 );
$GLOBALS['mutate'] = false;
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::delete( $expires['id'], 1 ) ), 'expired delete requires current mutation grant' );
$GLOBALS['mutate'] = true;
$expired_delete = MAD4B_SCP_CSO_Drafts::delete( $expires['id'], 1 );
ck( ! is_wp_error( $expired_delete ) && $expired_delete['deleted'] && count( $GLOBALS['wpdb']->rows ) === 0,
    'exact owner explicitly deletes expired v2 row with CAS' );
// Per-actor admission has eight finite native slots, not one option per random ID.
$bank = array();
for ( $i = 0; $i < MAD4B_SCP_CSO_Drafts::ACTOR_SLOTS; $i++ ) {
    $bank[] = MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Quota ' . $i ) );
    ck( ! is_wp_error( end( $bank ) ), 'actor slot allocated' );
}
$quota_snapshot = $GLOBALS['wpdb']->rows;
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Overflow' ) ) ) &&
    $GLOBALS['wpdb']->rows === $quota_snapshot, 'actor ninth draft denied without extra physical row' );
$old_scope = $GLOBALS['scope'];
$GLOBALS['scope']['binding_sha256'] = str_repeat( 'f', 64 );
$GLOBALS['scope']['actor_sha256'] = str_repeat( 'e', 64 );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'New deployment' ) ) ) &&
    $GLOBALS['wpdb']->rows === $quota_snapshot, 'deployment/source drift cannot reset stable actor slot quota' );
$GLOBALS['scope'] = $old_scope;
expire_draft( $bank[0]['id'] );
$reclaimed = MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'After TTL' ) );
ck( ! is_wp_error( $reclaimed ) && $reclaimed['id'] !== $bank[0]['id'] &&
    count( $GLOBALS['wpdb']->rows ) === 8 && draft_key( $reclaimed['id'] ) === draft_key( $bank[0]['id'] ),
    'expired slot replaced via exact old-byte CAS without growing physical rows' );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::load( $bank[0]['id'] ) ), 'old opaque handle cannot access replacement draft' );
expire_draft( $bank[1]['id'] );
$GLOBALS['draft_reclaim_race'] = true;
$loser = MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Losing reclaim' ) );
ck( is_wp_error( $loser ) && $GLOBALS['wpdb']->rows[ $GLOBALS['winner_key'] ] === $GLOBALS['winner_raw'],
    'expired-row CAS race preserves new winner and denies loser' );
// Every actor bucket is physically bounded; collisions share capacity and deny.
$GLOBALS['wpdb']->rows = array();
$users = array();
for ( $user = 1; $user < 1000 && count( $users ) < 8; $user++ ) {
    $bucket = hexdec( substr( hash( 'sha256', '1:' . $user ), 0, 2 ) ) % 8;
    if ( ! isset( $users[ $bucket ] ) ) $users[ $bucket ] = $user;
}
ck( count( $users ) === 8, 'all eight bucket owners selected in fixture' );
foreach ( $users as $user ) {
    $GLOBALS['user_id'] = $user; $GLOBALS['scope']['actor_sha256'] = hash( 'sha256', 'fixture-actor:' . $user );
    for ( $i = 0; $i < 8; $i++ )
        ck( ! is_wp_error( MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Site quota' ) ) ), 'site slot allocated' );
}
ck( count( $GLOBALS['wpdb']->rows ) === 64, 'new namespace has exactly64 physical rows at full site capacity' );
$all_slots = $GLOBALS['wpdb']->rows;
$GLOBALS['user_id'] = 1001; $GLOBALS['scope']['actor_sha256'] = hash( 'sha256', 'fixture-actor:1001' );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'No space' ) ) ) &&
    $GLOBALS['wpdb']->rows === $all_slots, 'site capacity rejects new actor without spillover options' );
// Isolated fixture scenarios for racing genesis and ambiguous persistence ACK.
$GLOBALS['wpdb']->rows = array(); $GLOBALS['user_id'] = 7; $GLOBALS['scope'] = $old_scope;
$GLOBALS['draft_insert_race'] = true;
$race = MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Lost insertion' ) );
ck( is_wp_error( $race ) && count( $GLOBALS['wpdb']->rows ) === 1 &&
    $GLOBALS['wpdb']->rows[ $GLOBALS['winner_key'] ] === $GLOBALS['winner_raw'],
    'duplicate insert cannot upsert another draft or report success' );
$GLOBALS['wpdb']->rows = array(); $GLOBALS['draft_insert_ack_lost'] = true;
$ack_lost = MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Uncertain save' ) );
ck( is_wp_error( $ack_lost ) && count( $GLOBALS['wpdb']->rows ) === 1, 'lost insert ACK retains bounded row without false success' );
$stored_key = array_keys( $GLOBALS['wpdb']->rows )[0]; $stored = $GLOBALS['wpdb']->rows[ $stored_key ];
class DraftWakeup { public function __wakeup() { $GLOBALS['draft_woke'] = true; } }
$with_object = unserialize( $stored ); $with_object['material']['values']['query'] = new DraftWakeup();
$GLOBALS['wpdb']->rows[ $stored_key ] = serialize( $with_object );
$stored_id = unserialize( $stored )['material']['id'];
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::load( $stored_id ) ) && empty( $GLOBALS['draft_woke'] ),
    'stored object denied without __wakeup before MAC or value disclosure' );
$GLOBALS['wpdb']->rows[ $stored_key ] = str_repeat( 'x', 65537 );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::load( $stored_id ) ), 'stored row byte budget enforced before deserialization' );
$GLOBALS['wpdb']->rows[ $stored_key ] = $stored;
$tampered = unserialize( $stored ); $tampered['material']['values']['query'] = 'Tampered';
$GLOBALS['wpdb']->rows[ $stored_key ] = serialize( $tampered );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::load( $stored_id ) ), 'MAC tamper never discloses draft values' );
$GLOBALS['wpdb']->rows[ $stored_key ] = $stored;
$GLOBALS['wpdb']->options = 'wp_2_options'; $before = $GLOBALS['wpdb']->rows;
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Foreign storage' ) ) ) &&
    $GLOBALS['wpdb']->rows === $before, 'mismatched current-blog options table denied' );
$GLOBALS['wpdb']->options = 'wp_options';
$GLOBALS['revoke_during_validation'] = true;
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'Revoked before SQL' ) ) ) &&
    $GLOBALS['wpdb']->rows === $before, 'current mutation grant rechecked after field validation' );
$GLOBALS['mutate'] = true; $GLOBALS['cookie'] = false;
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::create( $form, array( 'query' => 'No session' ) ) ), 'no first-party session cannot persist' );
$GLOBALS['cookie'] = true;
// Existing random namespaces are neither rewritten nor deleted by v2.
$legacy_id = 'csod.' . str_repeat( '9', 64 ); $legacy_key = 'mad4b_cso_draft_' . hash( 'sha256', $legacy_id );
$legacy = array( 'contract' => MAD4B_SCP_CSO_Drafts::CONTRACT, 'id' => $legacy_id,
    'scope_sha256' => MAD4B_SCP_CSO_Scope::digest( $GLOBALS['scope'] ),
    'actor_sha256' => $GLOBALS['scope']['actor_sha256'],
    'form' => array( 'expected_descriptor_sha256' => $form['expected_descriptor_sha256'], 'ability_name' => $form['ability_name'] ),
    'values' => array( 'query' => 'Legacy read only' ), 'revision' => 1, 'expires_at' => time() + 300 );
$GLOBALS['wpdb']->rows[ $legacy_key ] = serialize( MAD4B_SCP_CSO_Scope::seal( $legacy, MAD4B_SCP_CSO_Drafts::CONTRACT ) );
$legacy_raw = $GLOBALS['wpdb']->rows[ $legacy_key ]; $legacy_read = MAD4B_SCP_CSO_Drafts::load( $legacy_id );
ck( ! is_wp_error( $legacy_read ) && $legacy_read['legacy_read_only'] &&
    $legacy_read['values']['query'] === 'Legacy read only', 'legacy owner can load with explicit recreate-for-edit status' );
ck( is_wp_error( MAD4B_SCP_CSO_Drafts::save( $legacy_id, 1, $form, array( 'query' => 'Migrated' ) ) ) &&
    is_wp_error( MAD4B_SCP_CSO_Drafts::delete( $legacy_id, 1 ) ) &&
    $GLOBALS['wpdb']->rows[ $legacy_key ] === $legacy_raw, 'legacy rows never migrated or swept' );
ck( empty( $GLOBALS['option_adds'] ), 'draft persistence never calls upserting add_option' );
ck( in_array( array( 'notoptions','options' ), $GLOBALS['cache_deletes'], true ) &&
    in_array( array( 'alloptions','options' ), $GLOBALS['cache_deletes'], true ), 'all WordPress option caches invalidated' );

echo "PASS CSO private drafts / CAS / actor isolation / secret deny\n";
