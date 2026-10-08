<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_current_user_id() { return $GLOBALS['g6_owner']; }
function current_user_can( $name ) { return $GLOBALS['g6_owner'] > 0 && 'manage_options' === $name; }
function get_user_meta( $id, $key, $single = false ) {
    $record = isset( $GLOBALS['g6_store'][$id][$key] ) ? $GLOBALS['g6_store'][$id][$key] : null;
    return $single ? ( null === $record ? '' : $record ) : ( null === $record ? array() : array( $record ) );
}
function add_user_meta( $id, $key, $value, $unique = false ) {
    if ( isset( $GLOBALS['g6_store'][$id][$key] ) ) return false;
    $GLOBALS['g6_store'][$id][$key] = $value; return true;
}
function update_user_meta( $id, $key, $value, $before ) {
    if ( ! isset( $GLOBALS['g6_store'][$id][$key] ) || $GLOBALS['g6_store'][$id][$key] !== $before ) return false;
    $GLOBALS['g6_store'][$id][$key] = $value; return true;
}
function wp_cache_delete( $id, $group ) { return true; }
class MAD4B_SCP_Site_Profile {
    public static function site_uuid() { return $GLOBALS['g6_site']; }
}
class MAD4B_SCP_Distributed_Lock {
    public static function catalog_name( $name ) { return $name; }
    public static function acquire( $name ) { return true; }
    public static function owns( $name ) { return true; }
    public static function release( $name ) { return true; }
}
$GLOBALS['g6_site'] = '12345678-1234-1234-1234-123456789abc';
$GLOBALS['g6_owner'] = 17;
$GLOBALS['g6_store'] = array();

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g6-conversation-vault.php';
function g6_vault_assert( $v, $msg ) { if ( ! $v ) { fwrite( STDERR, 'FAIL: ' . $msg . PHP_EOL ); exit( 1 ); } }
function g6_vault_error( $value, $code ) { g6_vault_assert( is_wp_error( $value ) && $value->get_error_code() === $code, 'expected ' . $code ); }
$input = array( 'thread_id' => 'private-thread', 'expected_revision' => 0,
    'classification' => 'internal', 'role' => 'user', 'text' => 'PRIVATE-CONTENT-NOT-IN-USER-META-7722',
    'retention_days' => 7 );
putenv( 'MAD4B_G6_VAULT_KEY_BASE64' );
putenv( 'MAD4B_G6_VAULT_KEY_ID' );
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::append( $input ), 'mad4b_g6_vault_key_missing' );
$key = base64_encode( random_bytes( 32 ) );
putenv( 'MAD4B_G6_VAULT_KEY_BASE64=' . $key );
putenv( 'MAD4B_G6_VAULT_KEY_ID=fixture-v1' );
$created = MAD4B_SCP_G6_Conversation_Vault::append( $input );
g6_vault_assert( ! is_wp_error( $created ) && $created['revision'] === 1 && $created['encrypted_at_rest'], 'encrypted append revision 1' );
$status = MAD4B_SCP_G6_Conversation_Vault::status();
g6_vault_assert( ! is_wp_error( $status ) && $status['threads'][0]['message_count'] === 1, 'private thread visible as metadata' );
g6_vault_assert( false === strpos( json_encode( $GLOBALS['g6_store'] ), $input['text'] ), 'plaintext never stored in user meta' );
g6_vault_assert( false === strpos( json_encode( $status ), $input['text'] ), 'plaintext never returned in read-only status' );
$export = MAD4B_SCP_G6_Conversation_Vault::export( 'private-thread', 1 );
g6_vault_assert( ! is_wp_error( $export ) && $export['messages'][0]['text'] === $input['text'], 'owner-only exact export decrypts authenticated ciphertext' );
$crypto_meta_key = array_keys( $GLOBALS['g6_store'][17] )[0];
$untampered_record = $GLOBALS['g6_store'][17][$crypto_meta_key];
$GLOBALS['g6_store'][17][$crypto_meta_key]['items']['private-thread']['messages'][0]['role'] = 'system_note';
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::export( 'private-thread', 1 ), 'mad4b_g6_vault_aad_mismatch' );
$GLOBALS['g6_store'][17][$crypto_meta_key] = $untampered_record;
$GLOBALS['g6_store'][17][$crypto_meta_key]['items']['private-thread']['messages'][0]['created_at'] += 3600;
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::export( 'private-thread', 1 ), 'mad4b_g6_vault_aad_mismatch' );
$GLOBALS['g6_store'][17][$crypto_meta_key] = $untampered_record;
unset( $GLOBALS['g6_store'][17][$crypto_meta_key]['items']['private-thread']['messages'][0]['aad_version'] );
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::export( 'private-thread', 1 ), 'mad4b_g6_vault_aad_version_required' );
$GLOBALS['g6_store'][17][$crypto_meta_key] = $untampered_record;
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::append( $input ), 'mad4b_g6_vault_revision_conflict' );
$input['expected_revision'] = 1; $input['text'] = 'second message'; $input['role'] = 'assistant';
$second = MAD4B_SCP_G6_Conversation_Vault::append( $input );
g6_vault_assert( ! is_wp_error( $second ) && $second['revision'] === 2, 'append CAS revision 2' );
$injected = $input; $injected['expected_revision'] = 2; $injected['role'] = 'tool';
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::append( $injected ), 'mad4b_g6_vault_role' );
$restricted = $input; $restricted['expected_revision'] = 2; $restricted['classification'] = 'restricted';
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::append( $restricted ), 'mad4b_g6_vault_class' );
$foreign = $GLOBALS['g6_owner']; $GLOBALS['g6_owner'] = 18;
$noaccess = MAD4B_SCP_G6_Conversation_Vault::export( 'private-thread', 2 );
g6_vault_error( $noaccess, 'mad4b_g6_vault_revision_conflict' );
$GLOBALS['g6_owner'] = $foreign;
// The public storage helper itself must reject cross-owner direct calls.
$GLOBALS['g6_owner'] = 18;
g6_vault_error( MAD4B_SCP_G6_Contracts::load( MAD4B_SCP_G6_Conversation_Vault::KIND, 17 ), 'mad4b_g6_store_owner_scope' );
g6_vault_error( MAD4B_SCP_G6_Contracts::save( MAD4B_SCP_G6_Conversation_Vault::KIND, 17, array( 'revision' => 0, 'items' => array() ), array( 'revision' => 0, 'items' => array() ) ), 'mad4b_g6_store_owner_scope' );
$GLOBALS['g6_owner'] = $foreign;
$original_site = $GLOBALS['g6_site']; $GLOBALS['g6_site'] = '88888888-8888-8888-8888-888888888888';
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::export( 'private-thread', 2 ), 'mad4b_g6_vault_revision_conflict' );
$GLOBALS['g6_site'] = $original_site;
putenv( 'MAD4B_G6_VAULT_KEY_BASE64=' . base64_encode( random_bytes( 32 ) ) );
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::export( 'private-thread', 2 ), 'mad4b_g6_vault_decrypt_failed' );
putenv( 'MAD4B_G6_VAULT_KEY_BASE64=' . $key );
$none = MAD4B_SCP_G6_Conversation_Vault::purge_expired( 2 );
g6_vault_assert( ! is_wp_error( $none ) && 0 === $none['purged_threads'] && 2 === $none['revision'], 'no-expiry purge is read-only' );
$expiring = array(
    'thread_id' => 'expired-thread', 'expected_revision' => 2,
    'classification' => 'internal', 'role' => 'user',
    'text' => 'PRIVATE-EXPIRED-CIPHERTEXT-DO-NOT-LEAK',
    'retention_days' => 1,
);
$created_expiring = MAD4B_SCP_G6_Conversation_Vault::append( $expiring );
g6_vault_assert( ! is_wp_error( $created_expiring ) && 3 === $created_expiring['revision'], 'expiry fixture stored encrypted' );
// Simulate the passage of time in the in-memory fixture without a public clock override.
foreach ( $GLOBALS['g6_store'][17] as &$stored_record ) {
    if ( isset( $stored_record['items']['expired-thread'] ) )
        $stored_record['items']['expired-thread']['expires_at'] = time() - 1;
}
unset( $stored_record );
putenv( 'MAD4B_G6_VAULT_KEY_BASE64' );
$purged = MAD4B_SCP_G6_Conversation_Vault::purge_expired( 3 );
g6_vault_assert( ! is_wp_error( $purged ) && 1 === $purged['purged_threads'] && 4 === $purged['revision'], 'expiry purge erases ciphertext even when encryption key is unavailable' );
g6_vault_assert( false === strpos( json_encode( $GLOBALS['g6_store'] ), 'PRIVATE-EXPIRED-CIPHERTEXT-DO-NOT-LEAK' ), 'expired plaintext never entered storage' );
g6_vault_assert( 0 === count( array_filter( $GLOBALS['g6_store'][17], function ( $record ) {
    return isset( $record['items']['expired-thread'] ) &&
        ! empty( $record['items']['expired-thread']['messages'] );
} ) ), 'expired ciphertext erased from current live registry' );
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::purge_expired( 3 ), 'mad4b_g6_vault_revision_conflict' );
putenv( 'MAD4B_G6_VAULT_KEY_BASE64=' . $key );
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::export( 'expired-thread', 4 ), 'mad4b_g6_vault_unavailable' );
$expired_retry = $expiring; $expired_retry['expected_revision'] = 4;
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::append( $expired_retry ), 'mad4b_g6_vault_thread_ineligible' );
$deleted = MAD4B_SCP_G6_Conversation_Vault::delete( 'private-thread', 4 );
g6_vault_assert( ! is_wp_error( $deleted ) && $deleted['revision'] === 5 && ! $deleted['backup_erasure_certified'], 'deletion tombstones thread and declares backup caveat' );
g6_vault_assert( false === strpos( json_encode( $GLOBALS['g6_store'] ), '"ciphertext"' ), 'live ciphertext erased' );
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::export( 'private-thread', 5 ), 'mad4b_g6_vault_unavailable' );
$last_status = MAD4B_SCP_G6_Conversation_Vault::status();
g6_vault_assert( ! is_wp_error( $last_status ) && count( $last_status['threads'] ) === 2, 'well-formed tombstones remain visible as metadata' );
$meta_key = array_keys( $GLOBALS['g6_store'][17] )[0];
$original_record = $GLOBALS['g6_store'][17][$meta_key];
$GLOBALS['g6_store'][17][$meta_key]['items']['private-thread']['messages'][] = array( 'ciphertext' => 'forged' );
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::status(), 'mad4b_g6_vault_corrupt' );
g6_vault_error( MAD4B_SCP_G6_Conversation_Vault::purge_expired( 5 ), 'mad4b_g6_vault_corrupt' );
$GLOBALS['g6_store'][17][$meta_key] = $original_record;
g6_vault_assert( ! is_wp_error( MAD4B_SCP_G6_Conversation_Vault::status() ), 'corruption rejection never persisted a mutation' );
echo "mad4b.g6-conversation-vault-runtime.v1: PASS\n";
