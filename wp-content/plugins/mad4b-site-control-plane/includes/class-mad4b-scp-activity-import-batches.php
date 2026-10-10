<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP08 multi-chunk, encrypted source review inbox. Not a writer, scheduler,
 * provider lease or globally transactional queue. One open batch per Profile.
 */
final class MAD4B_SCP_Activity_Import_Batches {
    const CONTRACT = 'mad4b.import-batch-review.v1';
    const MAX_CHUNKS = 10;
    private static function err( $code, $message ) {
        return new WP_Error( $code, $message );
    }
    private static function hash( $value ) {
        $json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    private static function environment() {
        return current_user_can( 'manage_options' ) &&
            MAD4B_SCP_Site_Profile::configured() &&
            MAD4B_SCP_Site_Profile::origin_enrolled() &&
            MAD4B_SCP_Site_Profile::site_urls_match_enrollment() &&
            MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) );
    }
    private static function key( $slug ) {
        if ( ! defined( 'MAD4B_ACTIVITY_IMPORT_DATA_KEY' ) ||
            ! is_string( MAD4B_ACTIVITY_IMPORT_DATA_KEY ) ||
            strlen( MAD4B_ACTIVITY_IMPORT_DATA_KEY ) < 32 ||
            ! function_exists( 'openssl_encrypt' ) ||
            ! function_exists( 'openssl_decrypt' ) ||
            ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) )
            return self::err( 'mad4b_batch_key_missing',
                'An approved Staging AES-GCM import key is required.' );
        return hash( 'sha256', MAD4B_ACTIVITY_IMPORT_DATA_KEY . '|' .
            MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug, true );
    }
    private static function names( $slug, $id ) {
        $base = hash( 'sha256', MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug );
        return array(
            'active' => 'mad4b_batch_active_' . $base,
            'manifest' => 'mad4b_batch_manifest_' . hash( 'sha256', $base . '|' . $id ),
            'chunk_prefix' => 'mad4b_batch_chunk_' . hash( 'sha256', $base . '|' . $id ) . '_',
            'approved' => 'mad4b_batch_approval_' . hash( 'sha256', $base . '|' . $id )
        );
    }
    private static function context( $slug ) {
        if ( ! self::environment() || ! preg_match( '/^[a-z0-9_-]{2,48}$/D', (string) $slug ) )
            return self::err( 'mad4b_batch_site_denied', 'Enrolled staging administrator required.' );
        $p = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $p ) || empty( $p['enabled'] ) )
            return self::err( 'mad4b_batch_profile_denied', 'Profile is unavailable.' );
        $c = MAD4B_SCP_Activity_Import_Authority::profile_contract( $p );
        if ( empty( $c['validation'] ) || empty( $c['enabled_modes'] ) ||
            ! MAD4B_SCP_Activity_Import_Authority::mode_allowed(
                $p, 'admin_csv_upload' ) )
            return self::err( 'mad4b_batch_policy_denied',
                'The Profile must explicitly permit reviewed CSV source chunks.' );
        $k = self::key( $slug );
        if ( is_wp_error( $k ) ) return $k;
        return array( 'profile' => $p, 'contract' => $c, 'key' => $k );
    }
    private static function read_manifest( $slug, $id, $context ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) )
            return self::err( 'mad4b_batch_identity_invalid', 'Exact batch ID is required.' );
        $names = self::names( $slug, $id );
        $active = get_option( $names['active'], false );
        $m = get_option( $names['manifest'], false );
        if ( ! is_string( $active ) || ! hash_equals( $active, $id ) ||
            ! is_array( $m ) || ! isset( $m['contract'], $m['profile_revision'],
                $m['profile_authority_sha256'], $m['policy_sha256'] ) ||
            self::CONTRACT !== $m['contract'] ||
            (string) $m['profile_revision'] !== (string) $context['profile']['revision'] ||
            ! hash_equals( (string) $m['profile_authority_sha256'],
                (string) $context['profile']['authority_sha256'] ) ||
            ! hash_equals( (string) $m['policy_sha256'],
                MAD4B_SCP_Activity_Import_Authority::digest(
                    $context['contract']['validation'] ) ) )
            return self::err( 'mad4b_batch_profile_changed',
                'Active batch is missing or governed Profile revision changed.' );
        return array( 'manifest' => $m, 'names' => $names );
    }
    /** Writes only a bounded, non-autoloaded staging manifest. */
    public static function begin( $input = array() ) {
        if ( ! is_array( $input ) || true !== ( $input['confirmed'] ?? false ) ||
            ! isset( $input['expected_chunks'] ) ||
            ! is_int( $input['expected_chunks'] ) ||
            $input['expected_chunks'] < 2 ||
            $input['expected_chunks'] > self::MAX_CHUNKS )
            return self::err( 'mad4b_batch_begin_invalid',
                'Explicitly confirm 2–10 chunks of at most 500 rows each.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $ctx = self::context( $slug );
        if ( is_wp_error( $ctx ) ) return $ctx;
        $id = bin2hex( random_bytes( 16 ) );
        $names = self::names( $slug, $id );
        if ( ! add_option( $names['active'], $id, '', false ) )
            return self::err( 'mad4b_batch_active',
                'An earlier source batch must be independently completed or archived.' );
        $manifest = array(
            'contract' => self::CONTRACT, 'batch_id' => $id,
            'profile_slug' => $slug, 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'expected_chunks' => $input['expected_chunks'],
            'profile_revision' => $ctx['profile']['revision'],
            'profile_authority_sha256' => $ctx['profile']['authority_sha256'],
            'policy_sha256' => MAD4B_SCP_Activity_Import_Authority::digest(
                $ctx['contract']['validation'] ),
            'created_at' => gmdate( 'c' ), 'state' => 'accepting_encrypted_chunks',
            'provider_write_authorized' => false, 'wordpress_post_writes' => 0 );
        if ( ! add_option( $names['manifest'], $manifest, '', false ) ||
            self::hash( get_option( $names['manifest'], false ) ) !== self::hash( $manifest ) ) {
            delete_option( $names['active'] );
            return self::err( 'mad4b_batch_manifest_failed',
                'Batch manifest was not independently persisted.' );
        }
        return array( 'contract' => self::CONTRACT, 'batch_id' => $id,
            'expected_chunks' => $manifest['expected_chunks'],
            'state' => $manifest['state'], 'post_writes' => 0 );
    }
    /** Append immutable, individually authenticated source rows, not content. */
    public static function append( $input = array() ) {
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $id = isset( $input['batch_id'] ) ? (string) $input['batch_id'] : '';
        $index = isset( $input['chunk_index'] ) ? $input['chunk_index'] : null;
        if ( ! is_array( $input ) || ! is_int( $index ) || $index < 0 )
            return self::err( 'mad4b_batch_chunk_index_invalid', 'Nonnegative integer chunk index required.' );
        $ctx = self::context( $slug );
        if ( is_wp_error( $ctx ) ) return $ctx;
        $read = self::read_manifest( $slug, $id, $ctx );
        if ( is_wp_error( $read ) ) return $read;
        if ( $index >= $read['manifest']['expected_chunks'] )
            return self::err( 'mad4b_batch_chunk_out_of_range', 'Chunk exceeds declared source plan.' );
        $chunk = isset( $input['source'] ) ? $input['source'] : null;
        if ( ! is_array( $chunk ) || ! isset( $chunk['profile_slug'] ) ||
            $chunk['profile_slug'] !== $slug )
            return self::err( 'mad4b_batch_chunk_profile_mismatch', 'Chunk belongs to another Profile.' );
        $preview = MAD4B_SCP_Activity_Import_Review::plan( $chunk );
        if ( is_wp_error( $preview ) ) return $preview;
        if ( $preview['policy_sha256'] !== $read['manifest']['policy_sha256'] )
            return self::err( 'mad4b_batch_chunk_policy_drift', 'Site policy differs from batch authority.' );
        $json = wp_json_encode( $chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || strlen( $json ) > 1048576 )
            return self::err( 'mad4b_batch_chunk_size', 'Each encrypted chunk must be at most 1 MiB.' );
        $digest = hash( 'sha256', $json );
        $aad = MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug . '|' .
            $id . '|' . $index . '|' . $read['manifest']['policy_sha256'] . '|' . $digest;
        $nonce = random_bytes( 12 );
        $tag = '';
        $cipher = openssl_encrypt( $json, 'aes-256-gcm', $ctx['key'],
            OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16 );
        if ( ! is_string( $cipher ) || strlen( $tag ) !== 16 )
            return self::err( 'mad4b_batch_chunk_encryption', 'Chunk encryption failed.' );
        $record = array( 'chunk_index' => $index,
            'payload_sha256' => $digest, 'plan_sha256' => $preview['plan_sha256'],
            'row_count' => count( $chunk['rows'] ),
            'nonce' => base64_encode( $nonce ), 'tag' => base64_encode( $tag ),
            'ciphertext' => base64_encode( $cipher ),
            'blocking_issue_count' => $preview['block_issue_count'],
            'warning_issue_count' => $preview['review_issue_count'] );
        $key = $read['names']['chunk_prefix'] . $index;
        if ( ! add_option( $key, $record, '', false ) )
            return self::err( 'mad4b_batch_chunk_already_staged',
                'Immutable chunk index already exists; refusing overwrite or blind retry.' );
        if ( self::hash( get_option( $key, false ) ) !== self::hash( $record ) )
            return self::err( 'mad4b_batch_chunk_readback_failed',
                'Encrypted chunk write/readback did not match.' );
        return array( 'contract' => self::CONTRACT, 'batch_id' => $id,
            'chunk_index' => $index, 'row_count' => $record['row_count'],
            'chunk_sha256' => $digest, 'blocking_issue_count' => $record['blocking_issue_count'],
            'warning_issue_count' => $record['warning_issue_count'],
            'post_writes' => 0, 'mutated_wordpress_posts' => false );
    }
    /**
     * Check all encrypted records and cross-chunk IDs without provider writes.
     * Full WPML groups must remain together in a chunk under configured policy.
     */
    public static function verify( $input = array() ) {
        if ( ! is_array( $input ) ) return self::err( 'mad4b_batch_input_invalid', 'Object required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $id = isset( $input['batch_id'] ) ? (string) $input['batch_id'] : '';
        $ctx = self::context( $slug );
        if ( is_wp_error( $ctx ) ) return $ctx;
        $read = self::read_manifest( $slug, $id, $ctx );
        if ( is_wp_error( $read ) ) return $read;
        $manifest = $read['manifest'];
        $policy = $ctx['contract']['validation'];
        $id_col = $policy['identity_field'];
        $seen_ids = array(); $seen_groups = array();
        $rows = 0; $blocks = 0; $warnings = 0; $chunks = array();
        $all_present = true; $collision_count = 0; $translation_cross_chunk = 0;
        for ( $i = 0; $i < $manifest['expected_chunks']; $i++ ) {
            $part = get_option( $read['names']['chunk_prefix'] . $i, false );
            if ( ! is_array( $part ) ) {
                $all_present = false;
                $chunks[] = array( 'index' => $i, 'state' => 'missing' );
                continue;
            }
            $aad = MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug . '|' .
                $id . '|' . $i . '|' . $manifest['policy_sha256'] . '|' .
                $part['payload_sha256'];
            $json = openssl_decrypt( base64_decode( $part['ciphertext'], true ),
                'aes-256-gcm', $ctx['key'], OPENSSL_RAW_DATA,
                base64_decode( $part['nonce'], true ),
                base64_decode( $part['tag'], true ), $aad );
            if ( ! is_string( $json ) ||
                ! hash_equals( $part['payload_sha256'], hash( 'sha256', $json ) ) )
                return self::err( 'mad4b_batch_chunk_tampered', 'Encrypted chunk failed independent readback.' );
            $data = json_decode( $json, true );
            if ( ! is_array( $data ) || ( $data['profile_slug'] ?? '' ) !== $slug )
                return self::err( 'mad4b_batch_chunk_decode', 'Encrypted chunk has no matching Profile.' );
            $preview = MAD4B_SCP_Activity_Import_Review::plan( $data );
            if ( is_wp_error( $preview ) ||
                ! hash_equals( (string) $part['plan_sha256'],
                    is_wp_error( $preview ) ? '' : $preview['plan_sha256'] ) )
                return self::err( 'mad4b_batch_chunk_stale', 'A source chunk no longer passes exact policy review.' );
            $count = count( $data['rows'] );
            if ( $count !== (int) $part['row_count'] )
                return self::err( 'mad4b_batch_chunk_count_changed', 'Source count readback differs.' );
            foreach ( $data['rows'] as $row ) {
                $identity = (string) $row[ $id_col ];
                $id_hash = hash( 'sha256', $identity );
                if ( isset( $seen_ids[ $id_hash ] ) ) $collision_count++;
                $seen_ids[ $id_hash ] = true;
                if ( ! empty( $policy['require_complete_wpml_groups'] ) ) {
                    $group_hash = hash( 'sha256', (string) $row['_wpml_import_translation_group'] );
                    if ( isset( $seen_groups[ $group_hash ] ) &&
                        $seen_groups[ $group_hash ] !== $i ) $translation_cross_chunk++;
                    $seen_groups[ $group_hash ] = $i;
                }
            }
            $rows += $count;
            $blocks += $preview['block_issue_count'];
            $warnings += $preview['review_issue_count'];
            $chunks[] = array( 'index' => $i, 'state' => 'staged',
                'row_count' => $count, 'source_sha256' => $part['payload_sha256'],
                'plan_sha256' => $preview['plan_sha256'] );
        }
        $plan = array(
            'contract' => self::CONTRACT, 'batch_id' => $id,
            'profile_slug' => $slug, 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_revision' => $manifest['profile_revision'],
            'profile_authority_sha256' => $manifest['profile_authority_sha256'],
            'policy_sha256' => $manifest['policy_sha256'],
            'expected_chunks' => $manifest['expected_chunks'],
            'chunks' => $chunks, 'total_rows' => $rows,
            'blocks' => $blocks, 'warnings' => $warnings,
            'cross_chunk_duplicate_id_count' => $collision_count,
            'cross_chunk_wpml_group_split_count' => $translation_cross_chunk,
            'all_chunks_present' => $all_present,
            'ready_for_manual_batch_review' =>
                $all_present && $blocks === 0 && $collision_count === 0 &&
                $translation_cross_chunk === 0,
            'provider_import_certified' => false,
            'cross_provider_write_fence_certified' => false,
            'rollback_certified' => false,
            'automatic_execution_allowed' => false,
            'read_only' => true, 'mutation_performed' => false );
        $plan['plan_sha256'] = self::hash( $plan );
        return $plan;
    }
    /**
     * Manual-only batch approval: binds every immutable page and complete
     * nonblocking warning count. Never authorizes WP All Import execution.
     */
    public static function approve( $input = array() ) {
        if ( ! is_array( $input ) || true !== ( $input['confirmed'] ?? false ) ||
            ! is_int( $input['acknowledged_warning_count'] ?? null ) ||
            ! is_string( $input['plan_sha256'] ?? null ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $input['plan_sha256'] ) )
            return self::err( 'mad4b_batch_approval_invalid',
                'Explicit exact plan hash, full warning count and confirmation required.' );
        $v = self::verify( $input );
        if ( is_wp_error( $v ) ) return $v;
        if ( ! $v['ready_for_manual_batch_review'] ||
            ! hash_equals( $v['plan_sha256'], $input['plan_sha256'] ) ||
            $v['warnings'] !== $input['acknowledged_warning_count'] )
            return self::err( 'mad4b_batch_approval_stale',
                'All source chunks, identity groups and warning totals must match the exact review plan.' );
        $names = self::names( $v['profile_slug'], $v['batch_id'] );
        $record = array(
            'contract' => 'mad4b.import-batch-manual-approval.v1',
            'batch_id' => $v['batch_id'], 'profile_slug' => $v['profile_slug'],
            'site_uuid' => $v['site_uuid'],
            'plan_sha256' => $v['plan_sha256'],
            'warning_count_acknowledged' => $input['acknowledged_warning_count'],
            'approved_at' => gmdate( 'c' ),
            'approved_by' => (int) get_current_user_id(),
            'authorization_scope' => 'manual_csv_chunk_export_only',
            'post_writes' => 0, 'provider_execution_authorized' => false );
        if ( ! add_option( $names['approved'], $record, '', false ) )
            return self::err( 'mad4b_batch_already_approved',
                'Manual source approval already exists; reread its immutable receipt.' );
        if ( self::hash( get_option( $names['approved'], false ) ) !==
            self::hash( $record ) )
            return self::err( 'mad4b_batch_approval_readback_failed',
                'Could not independently read back the approval record.' );
        return $record;
    }
    /**
     * Produce a validated bounded CSV page only after independent re-review,
     * exact manual approval and absence of blockers/cross-chunk duplicates.
     */
    public static function export_chunk( $slug, $id, $index ) {
        if ( ! is_int( $index ) || $index < 0 || $index >= self::MAX_CHUNKS )
            return self::err( 'mad4b_batch_export_index', 'Valid integer chunk required.' );
        $ctx = self::context( $slug );
        if ( is_wp_error( $ctx ) ) return $ctx;
        $v = self::verify( array( 'profile_slug' => $slug, 'batch_id' => $id ) );
        if ( is_wp_error( $v ) ) return $v;
        if ( ! $v['ready_for_manual_batch_review'] ||
            $index >= $v['expected_chunks'] )
            return self::err( 'mad4b_batch_export_not_ready',
                'Entire batch must pass review before exporting any chunk.' );
        $names = self::names( $slug, $id );
        $approved = get_option( $names['approved'], false );
        if ( ! is_array( $approved ) ||
            ! isset( $approved['plan_sha256'], $approved['authorization_scope'] ) ||
            'manual_csv_chunk_export_only' !== $approved['authorization_scope'] ||
            ! hash_equals( $approved['plan_sha256'], $v['plan_sha256'] ) ||
            $approved['warning_count_acknowledged'] !== $v['warnings'] )
            return self::err( 'mad4b_batch_export_unapproved',
                'Current full-batch source approval is absent or stale.' );
        $part = get_option( $names['chunk_prefix'] . $index, false );
        if ( ! is_array( $part ) ) return self::err( 'mad4b_batch_chunk_missing', 'Chunk not found.' );
        $aad = MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug . '|' .
            $id . '|' . $index . '|' . $v['policy_sha256'] . '|' .
            $part['payload_sha256'];
        $json = openssl_decrypt( base64_decode( $part['ciphertext'], true ),
            'aes-256-gcm', $ctx['key'], OPENSSL_RAW_DATA,
            base64_decode( $part['nonce'], true ),
            base64_decode( $part['tag'], true ), $aad );
        if ( ! is_string( $json ) ||
            ! hash_equals( $part['payload_sha256'], hash( 'sha256', $json ) ) )
            return self::err( 'mad4b_batch_export_tampered', 'Source ciphertext is invalid.' );
        $source = json_decode( $json, true );
        if ( ! is_array( $source ) || ! isset( $source['headers'], $source['rows'] ) )
            return self::err( 'mad4b_batch_export_shape', 'Source cannot be exported.' );
        if ( headers_sent() ) return self::err( 'mad4b_batch_export_headers_sent',
            'Download headers have already been sent.' );
        $stream = fopen( 'php://temp/maxmemory:2097152', 'w+b' );
        if ( !$stream ) return self::err( 'mad4b_batch_export_buffer', 'CSV buffer unavailable.' );
        if ( false === fputcsv( $stream, $source['headers'] ) ) {
            fclose( $stream );
            return self::err( 'mad4b_batch_export_encoding', 'Cannot encode CSV header.' );
        }
        foreach ( $source['rows'] as $row ) {
            $values = array();
            foreach ( $source['headers'] as $header ) {
                $value = isset( $row[ $header ] ) ? (string) $row[ $header ] : '';
                if ( preg_match( '/^[=+@]/', ltrim( $value ) ) ||
                    preg_match( '/^-(?!\\d+(?:\\.\\d+)?$)/', ltrim( $value ) ) ||
                    preg_match( '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $value ) ) {
                    fclose( $stream );
                    return self::err( 'mad4b_batch_csv_unsafe',
                        'Source cell contains a forbidden spreadsheet formula or control value.' );
                }
                $values[] = $value;
            }
            if ( false === fputcsv( $stream, $values ) ) {
                fclose( $stream );
                return self::err( 'mad4b_batch_export_encoding', 'Cannot encode a CSV row.' );
            }
        }
        $length = ftell( $stream );
        if ( ! is_int( $length ) || $length < 1 || $length > 2097152 ) {
            fclose( $stream );
            return self::err( 'mad4b_batch_export_oversize', 'Encoded CSV exceeds 2 MiB.' );
        }
        rewind( $stream );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="mad4b-approved-batch-' .
            substr( $id, 0, 10 ) . '-part-' . ( $index + 1 ) . '.csv"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Length: ' . $length );
        fpassthru( $stream );
        fclose( $stream );
        exit;
    }
    /**
     * Explicit archival creates a nonautoloaded audit tombstone before
     * releasing the per-Profile active slot. An archived approval cannot run.
     */
    public static function archive( $input = array() ) {
        if ( ! is_array( $input ) || true !== ( $input['confirmed'] ?? false ) )
            return self::err( 'mad4b_batch_archive_confirmation',
                'Explicit administrative archive confirmation required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $id = isset( $input['batch_id'] ) ? (string) $input['batch_id'] : '';
        $ctx = self::context( $slug );
        if ( is_wp_error( $ctx ) ) return $ctx;
        $read = self::read_manifest( $slug, $id, $ctx );
        if ( is_wp_error( $read ) ) return $read;
        $names = $read['names'];
        $tombstone_key = 'mad4b_batch_archive_' . hash( 'sha256',
            MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug . '|' . $id );
        $audit = array( 'batch_id_sha256' => hash( 'sha256', $id ),
            'profile_slug' => $slug, 'archived_at' => gmdate( 'c' ),
            'archived_by' => (int) get_current_user_id(),
            'manifest_sha256' => self::hash( $read['manifest'] ),
            'post_writes' => 0 );
        if ( ! add_option( $tombstone_key, $audit, '', false ) ||
            self::hash( get_option( $tombstone_key, false ) ) !== self::hash( $audit ) )
            return self::err( 'mad4b_batch_archive_audit_failed',
                'Audit could not be durably committed. Batch has not been released.' );
        for ( $i = 0; $i < $read['manifest']['expected_chunks']; $i++ )
            delete_option( $names['chunk_prefix'] . $i );
        delete_option( $names['approved'] );
        delete_option( $names['manifest'] );
        delete_option( $names['active'] );
        if ( false !== get_option( $names['active'], false ) )
            return self::err( 'mad4b_batch_archive_release_unverified',
                'Batch archival recorded but profile lock release is not verified.' );
        return array( 'contract' => self::CONTRACT, 'state' => 'archived',
            'batch_id_sha256' => hash( 'sha256', $id ),
            'audit_recorded' => true, 'wordpress_post_writes' => 0 );
    }

}
