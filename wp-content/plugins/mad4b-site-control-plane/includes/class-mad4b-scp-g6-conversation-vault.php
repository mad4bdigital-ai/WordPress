<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g6-contracts.php';

/**
 * Optional private, encrypted, per-user and per-site conversation vault.
 * Never exposes raw content through an MCP/REST ability. Server configuration
 * must provide a dedicated 32-byte key; WordPress auth salts are not a KMS.
 */
final class MAD4B_SCP_G6_Conversation_Vault {
    const CONTRACT = 'mad4b.g6-private-conversation-vault.v1';
    const KIND = 'conversations_v1';
    const MAX_THREADS = 16;
    const MAX_MESSAGES = 64;
    const MAX_TEXT_BYTES = 8192;
    const MAX_RETENTION_DAYS = 30;

    private static function key() {
        if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
            || ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) )
            return MAD4B_SCP_G6_Contracts::error( 'vault_crypto_missing', 'A certified libsodium runtime is required.' );
        $raw = getenv( 'MAD4B_G6_VAULT_KEY_BASE64' );
        $id = getenv( 'MAD4B_G6_VAULT_KEY_ID' );
        if ( ! is_string( $raw ) || ! is_string( $id ) || ! MAD4B_SCP_G6_Contracts::id( $id ) )
            return MAD4B_SCP_G6_Contracts::error( 'vault_key_missing', 'Dedicated vault key and key ID must be configured in the server environment.' );
        $bytes = base64_decode( $raw, true );
        if ( ! is_string( $bytes ) || strlen( $bytes ) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES )
            return MAD4B_SCP_G6_Contracts::error( 'vault_key_invalid', 'Vault key is unavailable or malformed.' );
        return array( 'secret' => $bytes, 'id' => $id );
    }

    private static function owner_scope() {
        $user = MAD4B_SCP_G6_Contracts::owner();
        if ( is_wp_error( $user ) ) return $user;
        $site = MAD4B_SCP_G6_Contracts::site();
        if ( is_wp_error( $site ) ) return $site;
        return array( 'owner' => $user, 'site' => $site );
    }

    /** Reject corrupted thread identities and incomplete crypto-erasure tombstones. */
    private static function valid_thread( $key, $thread ) {
        if ( ! MAD4B_SCP_G6_Contracts::id( $key ) || ! is_array( $thread )
            || ! isset( $thread['thread_id'], $thread['classification'], $thread['messages'], $thread['expires_at'], $thread['deleted'] )
            || $thread['thread_id'] !== $key || ! is_array( $thread['messages'] )
            || count( $thread['messages'] ) > self::MAX_MESSAGES
            || ! is_int( $thread['expires_at'] ) || $thread['expires_at'] <= 0
            || ! is_bool( $thread['deleted'] )
            || ! in_array( $thread['classification'], array( 'public', 'internal' ), true )
            || ( $thread['deleted'] && $thread['messages'] ) )
            return MAD4B_SCP_G6_Contracts::error( 'vault_corrupt', 'Conversation registry contains invalid or non-erased thread data.' );
        foreach ( $thread['messages'] as $index => $message ) {
            if ( ! is_array( $message ) || ! isset( $message['aad_version'] )
                || 2 !== $message['aad_version'] || ! isset( $message['retention_ceiling'], $message['message_index'] )
                || ! is_int( $message['retention_ceiling'] ) || ! is_int( $message['message_index'] )
                || $message['message_index'] !== $index
                || $message['retention_ceiling'] < $thread['expires_at'] )
                return MAD4B_SCP_G6_Contracts::error( 'vault_corrupt', 'Vault retention or message ordering has been altered.' );
        }
        return true;
    }

    private static function inspect_thread( array $thread ) {
        return array(
            'thread_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $thread['thread_id'] ),
            'message_count' => count( $thread['messages'] ),
            'classification' => $thread['classification'],
            'expires_at' => $thread['expires_at'],
            'deleted' => $thread['deleted'],
            'retention_purge_required' => ! $thread['deleted'] && $thread['expires_at'] <= time(),
            'content_exposed' => false,
        );
    }

    /** Read only encrypted metadata. This method never returns prompts or ciphertext. */
    public static function status( $input = array() ) {
        $scope = self::owner_scope(); if ( is_wp_error( $scope ) ) return $scope;
        if ( ! is_array( $input ) || $input ) return MAD4B_SCP_G6_Contracts::error( 'vault_status_schema', 'Status accepts no caller material.' );
        $record = MAD4B_SCP_G6_Contracts::load( self::KIND, $scope['owner'] );
        if ( is_wp_error( $record ) ) return $record;
        $threads = array();
        if ( count( $record['items'] ) > self::MAX_THREADS )
            return MAD4B_SCP_G6_Contracts::error( 'vault_corrupt', 'Thread capacity is inconsistent.' );
        foreach ( $record['items'] as $thread_id => $thread ) {
            $valid = self::valid_thread( $thread_id, $thread );
            if ( is_wp_error( $valid ) ) return $valid;
            $threads[] = self::inspect_thread( $thread );
        }
        return array(
            'contract' => self::CONTRACT, 'registry_revision' => (int) $record['revision'],
            'site_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $scope['site'] ),
            'owner_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $scope['owner'] ),
            'threads' => $threads, 'encryption_required' => true,
            'content_exposed' => false, 'model_execution_performed' => false,
            'production_storage_certified' => false, 'authorizing' => false,
        );
    }

    /**
     * Internal-only append; no externally exposed ability. The ciphertext is
     * bound to site, owner, thread, message ID and key ID as associated data.
     */
    public static function append( $input ) {
        $scope = self::owner_scope(); if ( is_wp_error( $scope ) ) return $scope;
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'thread_id', 'expected_revision', 'classification', 'role', 'text', 'retention_days' ) ) )
            return MAD4B_SCP_G6_Contracts::error( 'vault_schema', 'Only bounded conversation append inputs are admitted.' );
        $id = isset( $input['thread_id'] ) ? $input['thread_id'] : null;
        if ( ! MAD4B_SCP_G6_Contracts::id( $id ) ) return MAD4B_SCP_G6_Contracts::error( 'vault_thread', 'Invalid conversation identity.' );
        if ( ! isset( $input['expected_revision'] ) || ! is_int( $input['expected_revision'] ) || $input['expected_revision'] < 0 )
            return MAD4B_SCP_G6_Contracts::error( 'vault_revision', 'Exact registry revision is required.' );
        $classification = isset( $input['classification'] ) ? $input['classification'] : '';
        if ( ! in_array( $classification, array( 'public', 'internal' ), true ) )
            return MAD4B_SCP_G6_Contracts::error( 'vault_class', 'Restricted information requires a separately certified vault and policy.' );
        $role = isset( $input['role'] ) ? $input['role'] : '';
        if ( ! in_array( $role, array( 'user', 'assistant', 'system_note' ), true ) )
            return MAD4B_SCP_G6_Contracts::error( 'vault_role', 'Untrusted roles cannot become tools or authority.' );
        $text = isset( $input['text'] ) ? $input['text'] : null;
        if ( ! is_string( $text ) || '' === trim( $text ) || strlen( $text ) > self::MAX_TEXT_BYTES )
            return MAD4B_SCP_G6_Contracts::error( 'vault_text', 'Conversation text exceeds the protected bound.' );
        $days = isset( $input['retention_days'] ) ? $input['retention_days'] : null;
        if ( ! is_int( $days ) || $days < 1 || $days > self::MAX_RETENTION_DAYS )
            return MAD4B_SCP_G6_Contracts::error( 'vault_retention', 'Retention must be 1-30 days.' );
        $key = self::key(); if ( is_wp_error( $key ) ) return $key;
        $before = MAD4B_SCP_G6_Contracts::load( self::KIND, $scope['owner'] );
        if ( is_wp_error( $before ) ) return $before;
        if ( (int) $before['revision'] !== $input['expected_revision'] )
            return MAD4B_SCP_G6_Contracts::error( 'vault_revision_conflict', 'Reload private registry before appending.' );
        $items = $before['items'];
        if ( ! isset( $items[ $id ] ) && count( $items ) >= self::MAX_THREADS )
            return MAD4B_SCP_G6_Contracts::error( 'vault_thread_budget', 'Private vault thread limit reached.' );
        $now = time();
        $thread = isset( $items[ $id ] ) ? $items[ $id ] : array(
            'thread_id' => $id, 'classification' => $classification,
            'expires_at' => $now + $days * DAY_IN_SECONDS, 'deleted' => false,
            'messages' => array(),
        );
        $valid = self::valid_thread( $id, $thread );
        if ( is_wp_error( $valid ) ) return $valid;
        if ( ! empty( $thread['deleted'] ) || $thread['expires_at'] <= $now || $thread['classification'] !== $classification )
            return MAD4B_SCP_G6_Contracts::error( 'vault_thread_ineligible', 'Deleted, expired or reclassified threads cannot be reopened by append.' );
        if ( ! isset( $thread['messages'] ) || ! is_array( $thread['messages'] ) || count( $thread['messages'] ) >= self::MAX_MESSAGES )
            return MAD4B_SCP_G6_Contracts::error( 'vault_message_budget', 'Conversation message budget reached.' );
        $message_id = bin2hex( random_bytes( 16 ) );
        // AAD v2 binds authority-relevant cleartext metadata to the ciphertext.
        // Existing v1 records require explicit migration; never silently relabel them.
        $retention_ceiling = min( $thread['expires_at'], $now + $days * DAY_IN_SECONDS );
        $message_index = count( $thread['messages'] );
        $aad = MAD4B_SCP_G6_Contracts::digest( array( $scope['site'], $scope['owner'], $id, $message_id, $key['id'], $role, $classification, $now, $retention_ceiling, $message_index ) );
        $nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $text, $aad, $nonce, $key['secret'] );
        $thread['expires_at'] = $retention_ceiling;
        $thread['messages'][] = array( 'id' => $message_id, 'role' => $role,
            'aad_version' => 2, 'key_id' => $key['id'],
            'retention_ceiling' => $retention_ceiling, 'message_index' => $message_index,
            'nonce' => base64_encode( $nonce ),
            'ciphertext' => base64_encode( $cipher ),
            'aad_sha256' => hash( 'sha256', $aad ), 'created_at' => $now );
        $items[ $id ] = $thread;
        $after = MAD4B_SCP_G6_Contracts::save( self::KIND, $scope['owner'], $before, array( 'revision' => $before['revision'], 'items' => $items ) );
        if ( is_wp_error( $after ) ) return $after;
        return array( 'contract' => self::CONTRACT, 'revision' => $after['revision'],
            'thread_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $id ),
            'message_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $message_id ),
            'expires_at' => $thread['expires_at'], 'plaintext_exposed' => false,
            'encrypted_at_rest' => true, 'authorizing' => false );
    }

    /** Explicit internal owner export; not mounted as an ability. No silent key rotation. */
    public static function export( $thread_id, $expected_revision ) {
        $scope = self::owner_scope(); if ( is_wp_error( $scope ) ) return $scope;
        if ( ! MAD4B_SCP_G6_Contracts::id( $thread_id ) || ! is_int( $expected_revision ) )
            return MAD4B_SCP_G6_Contracts::error( 'vault_export_schema', 'Exact thread and registry revision required.' );
        $key = self::key(); if ( is_wp_error( $key ) ) return $key;
        $record = MAD4B_SCP_G6_Contracts::load( self::KIND, $scope['owner'] );
        if ( is_wp_error( $record ) ) return $record;
        if ( (int) $record['revision'] !== $expected_revision )
            return MAD4B_SCP_G6_Contracts::error( 'vault_revision_conflict', 'Reload before export.' );
        $thread = isset( $record['items'][ $thread_id ] ) ? $record['items'][ $thread_id ] : null;
        if ( is_array( $thread ) ) {
            $valid = self::valid_thread( $thread_id, $thread );
            if ( is_wp_error( $valid ) ) return $valid;
        }
        if ( ! is_array( $thread ) || ! empty( $thread['deleted'] ) || $thread['expires_at'] <= time() )
            return MAD4B_SCP_G6_Contracts::error( 'vault_unavailable', 'Thread is missing, expired or deleted.' );
        $messages = array();
        foreach ( $thread['messages'] as $position => $m ) {
            if ( ! isset( $m['key_id'], $m['id'], $m['nonce'], $m['ciphertext'], $m['aad_sha256'], $m['role'], $m['created_at'] )
                || ! hash_equals( $key['id'], $m['key_id'] ) )
                return MAD4B_SCP_G6_Contracts::error( 'vault_rekey_required', 'Encrypted messages require current reviewed key material.' );
            if ( ! isset( $m['aad_version'] ) || 2 !== $m['aad_version'] )
                return MAD4B_SCP_G6_Contracts::error( 'vault_aad_version_required', 'Legacy encrypted metadata requires reviewed migration before export.' );
            if ( ! is_int( $m['created_at'] ) || $m['created_at'] <= 0
                || ! isset( $m['retention_ceiling'], $m['message_index'] )
                || ! is_int( $m['retention_ceiling'] ) || $m['retention_ceiling'] <= $m['created_at']
                || ! is_int( $m['message_index'] ) || $m['message_index'] !== $position
                || $thread['expires_at'] > $m['retention_ceiling']
                || ! in_array( $m['role'], array( 'user', 'assistant', 'system_note' ), true ) )
                return MAD4B_SCP_G6_Contracts::error( 'vault_message_corrupt', 'Authenticated message sequence or retention metadata is invalid.' );
            $aad = MAD4B_SCP_G6_Contracts::digest( array( $scope['site'], $scope['owner'], $thread_id, $m['id'], $m['key_id'], $m['role'], $thread['classification'], $m['created_at'], $m['retention_ceiling'], $m['message_index'] ) );
            if ( ! hash_equals( hash( 'sha256', $aad ), $m['aad_sha256'] ) )
                return MAD4B_SCP_G6_Contracts::error( 'vault_aad_mismatch', 'Message ownership binding changed.' );
            $nonce = base64_decode( $m['nonce'], true );
            $cipher = base64_decode( $m['ciphertext'], true );
            if ( ! is_string( $nonce ) || strlen( $nonce ) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES || ! is_string( $cipher ) )
                return MAD4B_SCP_G6_Contracts::error( 'vault_cipher_invalid', 'Encrypted message is invalid.' );
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $cipher, $aad, $nonce, $key['secret'] );
            if ( false === $plain ) return MAD4B_SCP_G6_Contracts::error( 'vault_decrypt_failed', 'Message authentication failed.' );
            $messages[] = array( 'role' => $m['role'], 'text' => $plain, 'created_at' => $m['created_at'] );
        }
        return array( 'contract' => self::CONTRACT, 'revision' => $record['revision'],
            'messages' => $messages, 'owner_only_internal_export' => true, 'authorizing' => false );
    }

    /**
     * Explicit owner-only expiry purge. This is intentionally not a public Ability,
     * scheduled worker or read-side effect: the caller must supply a fresh CAS
     * revision. Ciphertexts are erased from live user meta while stable tombstones
     * prevent silent resurrection. Backup/replica erasure is a separate gate.
     */
    public static function purge_expired( $expected_revision ) {
        $scope = self::owner_scope();
        if ( is_wp_error( $scope ) ) return $scope;
        if ( ! is_int( $expected_revision ) || $expected_revision < 0 )
            return MAD4B_SCP_G6_Contracts::error( 'vault_purge_schema', 'Exact registry revision is required for retention purge.' );
        $before = MAD4B_SCP_G6_Contracts::load( self::KIND, $scope['owner'] );
        if ( is_wp_error( $before ) ) return $before;
        if ( (int) $before['revision'] !== $expected_revision )
            return MAD4B_SCP_G6_Contracts::error( 'vault_revision_conflict', 'Private registry changed before purge.' );
        if ( count( $before['items'] ) > self::MAX_THREADS )
            return MAD4B_SCP_G6_Contracts::error( 'vault_corrupt', 'Thread count exceeds governed retention limits.' );

        $items = $before['items'];
        $now = time();
        $purged = 0;
        foreach ( $items as $thread_id => $thread ) {
            $valid = self::valid_thread( $thread_id, $thread );
            if ( is_wp_error( $valid ) ) return $valid;
            if ( $thread['expires_at'] > $now && empty( $thread['deleted'] ) ) continue;
            if ( empty( $thread['messages'] ) && ! empty( $thread['deleted'] ) ) continue;
            $items[ $thread_id ] = array(
                'thread_id' => $thread_id,
                'classification' => $thread['classification'],
                'expires_at' => min( $thread['expires_at'], $now ),
                'deleted' => true,
                'messages' => array(),
            );
            ++$purged;
        }
        if ( 0 === $purged ) return array(
            'contract' => self::CONTRACT, 'revision' => (int) $before['revision'],
            'purged_threads' => 0, 'registry_changed' => false,
            'backup_erasure_certified' => false, 'authorizing' => false,
        );
        $after = MAD4B_SCP_G6_Contracts::save(
            self::KIND, $scope['owner'], $before,
            array( 'revision' => $before['revision'], 'items' => $items )
        );
        if ( is_wp_error( $after ) ) return $after;
        return array(
            'contract' => self::CONTRACT, 'revision' => (int) $after['revision'],
            'purged_threads' => $purged, 'registry_changed' => true,
            'live_ciphertext_erased' => true, 'backup_erasure_certified' => false,
            'scheduled_retention_certified' => false, 'authorizing' => false,
        );
    }

    /** Exact tombstone with ciphertext erasure from live registry; backups need independent policy. */
    public static function delete( $thread_id, $expected_revision ) {
        $scope = self::owner_scope(); if ( is_wp_error( $scope ) ) return $scope;
        if ( ! MAD4B_SCP_G6_Contracts::id( $thread_id ) || ! is_int( $expected_revision ) )
            return MAD4B_SCP_G6_Contracts::error( 'vault_delete_schema', 'Exact thread and revision required.' );
        $before = MAD4B_SCP_G6_Contracts::load( self::KIND, $scope['owner'] );
        if ( is_wp_error( $before ) ) return $before;
        if ( (int) $before['revision'] !== $expected_revision || ! isset( $before['items'][ $thread_id ] ) )
            return MAD4B_SCP_G6_Contracts::error( 'vault_revision_conflict', 'Thread missing or changed.' );
        $items = $before['items'];
        $valid = self::valid_thread( $thread_id, $items[ $thread_id ] );
        if ( is_wp_error( $valid ) ) return $valid;
        $items[ $thread_id ] = array( 'thread_id' => $thread_id, 'classification' => $items[ $thread_id ]['classification'],
            'expires_at' => time(), 'deleted' => true, 'messages' => array() );
        $after = MAD4B_SCP_G6_Contracts::save( self::KIND, $scope['owner'], $before, array( 'revision' => $before['revision'], 'items' => $items ) );
        if ( is_wp_error( $after ) ) return $after;
        return array( 'contract' => self::CONTRACT, 'revision' => $after['revision'],
            'thread_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $thread_id ),
            'live_ciphertext_erased' => true, 'backup_erasure_certified' => false, 'authorizing' => false );
    }
}
