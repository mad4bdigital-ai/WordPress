<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'MAD4B_SCP_Batch_Atomic_Mutex' ) ) require_once __DIR__ . '/class-mad4b-scp-batch-atomic-mutex.php';

/**
 * IMP03 review-only storage and approval of an exact source snapshot.
 * No post creation/update, provider dispatch or WP All Import execution.
 * Staging only. Active snapshot is stored in a nonautoloaded option with
 * authenticated encryption, so review is bound to EXACT source bytes.
 */
final class MAD4B_SCP_Activity_Import_Snapshot {
    const CONTRACT = 'mad4b.activity-import-snapshot.v1';
    private static function err( $code, $message ) { return new WP_Error( $code, $message ); }
    private static function digest( $v ) {
        $json = wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    private static function data_key() {
        if ( ! defined( 'MAD4B_ACTIVITY_IMPORT_DATA_KEY' ) ||
            ! is_string( MAD4B_ACTIVITY_IMPORT_DATA_KEY ) ||
            strlen( MAD4B_ACTIVITY_IMPORT_DATA_KEY ) < 32 ||
            ! function_exists( 'openssl_encrypt' ) ||
            ! function_exists( 'openssl_decrypt' ) ||
            ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) )
            return self::err( 'mad4b_import_data_key_unavailable',
                'A dedicated 32+ character host-managed import encryption key and AES-GCM are required.' );
        return hash( 'sha256', MAD4B_ACTIVITY_IMPORT_DATA_KEY . '|' .
            MAD4B_SCP_Site_Profile::site_uuid(), true );
    }
    public static function option_key( $profile_slug ) {
        return 'mad4b_activity_import_review_' . hash( 'sha256',
            MAD4B_SCP_Site_Profile::site_uuid() . '|' . $profile_slug );
    }
    private static function archive_key( $slug, $snapshot_sha ) {
        return 'mad4b_activity_import_archive_' . hash( 'sha256',
            MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug . '|' . $snapshot_sha );
    }
    private static function approval_key( $slug, $snapshot_sha ) {
        return 'mad4b_import_approval_' . hash( 'sha256',
            MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug . '|' . $snapshot_sha );
    }
    private static function environment_ready() {
        return class_exists( 'MAD4B_SCP_Site_Profile' ) &&
            MAD4B_SCP_Site_Profile::configured() &&
            MAD4B_SCP_Site_Profile::origin_enrolled() &&
            MAD4B_SCP_Site_Profile::site_urls_match_enrollment() &&
            MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) );
    }
    /**
     * One exact Profile source inbox reservation for append/approval/export
     * and archival. No output handler may exit before lock release.
     */
    private static function source_with_lock( $slug, $operation, $callback ) {
        if ( ! self::environment_ready() ||
            ( 'append' !== $operation && ! current_user_can( 'manage_options' ) ) )
            return self::err( 'mad4b_import_source_operation_denied',
                'Exact enrolled Staging source authority required.' );
        $lock = MAD4B_SCP_Batch_Atomic_Mutex::acquire( $slug, $operation );
        if ( is_wp_error( $lock ) ) return $lock;
        try {
            $result = call_user_func( $callback );
        } catch ( \Throwable $unexpected ) {
            return self::err( 'mad4b_import_source_operation_interrupted',
                'Review operation stopped unexpectedly. Lock retained for independent recovery.' );
        }
        $released = MAD4B_SCP_Batch_Atomic_Mutex::release( $lock );
        if ( is_wp_error( $released ) ) return $released;
        return $result;
    }
    public static function stage( $slug, $input, $preview, $source_mode, $source_identity = '' ) {
        return self::source_with_lock( $slug, 'append', function() use ( $slug, $input, $preview, $source_mode, $source_identity ) {
            return self::stage_unlocked( $slug, $input, $preview, $source_mode, $source_identity );
        } );
    }
    private static function stage_unlocked( $slug, $input, $preview, $source_mode, $source_identity = '' ) {
        if ( ! self::environment_ready() ) return self::err( 'mad4b_import_snapshot_staging_only', 'Staging enrolled site required.' );
        $key = self::data_key();
        if ( is_wp_error( $key ) ) return $key;
        if ( ! is_array( $input ) || ! is_array( $preview ) ||
            ! preg_match( '/^[a-z0-9_-]{2,48}$/D', (string) $slug ) ||
            ! isset( $preview['plan_sha256'], $preview['policy_sha256'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) $preview['plan_sha256'] ) )
            return self::err( 'mad4b_import_snapshot_invalid', 'Bounded source and policy-bound preview required.' );
        $json = wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || strlen( $json ) > 1048576 ||
            ! function_exists( 'random_bytes' ) )
            return self::err( 'mad4b_import_snapshot_size', 'Bounded JSON snapshot required.' );
        $data_sha = hash( 'sha256', $json );
        $aad = MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug . '|' .
            $data_sha . '|' . $preview['policy_sha256'];
        $nonce = random_bytes( 12 );
        $review_nonce = bin2hex( random_bytes( 16 ) );
        $tag = '';
        $cipher = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            $nonce, $tag, $aad, 16 );
        if ( ! is_string( $cipher ) || strlen( $tag ) !== 16 )
            return self::err( 'mad4b_import_snapshot_encrypt', 'Authenticated snapshot encryption failed.' );
        $record = array(
            'contract' => self::CONTRACT, 'state' => 'requires_review',
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_slug' => $slug, 'source' => $source_mode,
            'source_identity_sha256' => hash( 'sha256', (string) $source_identity ),
            'received_at' => gmdate( 'c' ),
            'payload_sha256' => $data_sha,
            'policy_sha256' => $preview['policy_sha256'],
            'snapshot_sha256' => hash( 'sha256', $aad . '|' . $preview['plan_sha256'] . '|' . $review_nonce ),
            'review_nonce' => $review_nonce,
            'plan' => $preview,
            'ciphertext' => base64_encode( $cipher ),
            'nonce' => base64_encode( $nonce ),
            'tag' => base64_encode( $tag )
        );
        $store = self::option_key( $slug );
        $inserted = MAD4B_SCP_Batch_Atomic_Mutex::insert_immutable( $store, $record );
        if ( is_wp_error( $inserted ) )
            return self::err( 'mad4b_import_review_pending',
                'Another immutable review is already staged or SQL readback failed.' );
        $persisted = get_option( $store, false );
        if ( ! is_array( $persisted ) || self::digest( $persisted ) !== self::digest( $record ) )
            return self::err( 'mad4b_import_snapshot_readback_failed', 'Staging receipt could not be independently read back.' );
        return array( 'contract' => self::CONTRACT, 'staged' => true,
            'snapshot_sha256' => $record['snapshot_sha256'],
            'payload_sha256' => $data_sha, 'plan_sha256' => $preview['plan_sha256'],
            'issue_count' => $preview['issue_count_observed'],
            'status' => 'requires_review', 'post_writes' => 0 );
    }
    public static function raw_snapshot( $slug, $expected_sha ) {
        if ( ! self::environment_ready() || ! current_user_can( 'manage_options' ) )
            return self::err( 'mad4b_import_snapshot_read_denied', 'Enrolled staging administrator required.' );
        $record = get_option( self::option_key( $slug ), false );
        if ( ! is_array( $record ) || ! isset( $record['contract'] ) ||
            self::CONTRACT !== $record['contract'] ||
            ! isset( $record['snapshot_sha256'] ) ||
            ! is_string( $expected_sha ) ||
            ! hash_equals( $record['snapshot_sha256'], $expected_sha ) )
            return self::err( 'mad4b_import_snapshot_stale', 'Exact snapshot receipt required.' );
        $key = self::data_key();
        if ( is_wp_error( $key ) ) return $key;
        $aad = MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug . '|' .
            $record['payload_sha256'] . '|' . $record['policy_sha256'];
        $expected_receipt = hash( 'sha256', $aad . '|' .
            $record['plan']['plan_sha256'] .
            ( isset( $record['review_nonce'] ) ? '|' . $record['review_nonce'] : '' ) );
        if ( ! hash_equals( $expected_receipt, $record['snapshot_sha256'] ) )
            return self::err( 'mad4b_import_snapshot_digest_mismatch',
                'Stored receipt identity differs from its source, policy or review nonce.' );
        $json = openssl_decrypt( base64_decode( $record['ciphertext'], true ),
            'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            base64_decode( $record['nonce'], true ), base64_decode( $record['tag'], true ), $aad );
        if ( ! is_string( $json ) || ! hash_equals( $record['payload_sha256'],
            hash( 'sha256', $json ) ) )
            return self::err( 'mad4b_import_snapshot_tampered', 'Snapshot authentication or source digest failed.' );
        $input = json_decode( $json, true );
        if ( ! is_array( $input ) )
            return self::err( 'mad4b_import_snapshot_json_invalid', 'Encrypted source JSON invalid.' );
        return array( 'input' => $input, 'receipt' => $record );
    }
    private static function check_current( $record ) {
        $slug = $record['profile_slug'];
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) )
            return self::err( 'mad4b_import_snapshot_profile_invalid', 'Profile disabled or missing.' );
        $contract = MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile );
        $policy = isset( $contract['validation'] ) ? $contract['validation'] : array();
        if ( ! $policy || self::digest( $policy ) !== $record['policy_sha256'] ||
            (string) $profile['revision'] !== (string) $record['plan']['profile_revision'] ||
            (string) $profile['authority_sha256'] !== (string) $record['plan']['authority_sha256'] )
            return self::err( 'mad4b_import_snapshot_authority_drift',
                'Profile or site-owned validation changed since intake.' );
        return true;
    }
    public static function approve( $slug, $expected_sha, $confirmed, $acknowledged_warning_count = 0 ) {
        return self::source_with_lock( $slug, 'approve', function() use ( $slug, $expected_sha, $confirmed, $acknowledged_warning_count ) {
            return self::approve_unlocked( $slug, $expected_sha, $confirmed, $acknowledged_warning_count );
        } );
    }
    private static function approve_unlocked( $slug, $expected_sha, $confirmed, $acknowledged_warning_count = 0 ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::environment_ready() ||
            true !== $confirmed )
            return self::err( 'mad4b_import_approval_denied', 'Exact administrator approval is required.' );
        $loaded = self::raw_snapshot( $slug, $expected_sha );
        if ( is_wp_error( $loaded ) ) return $loaded;
        $record = $loaded['receipt'];
        $current = self::check_current( $record );
        if ( is_wp_error( $current ) ) return $current;
        // Inspection is rerun against the SAME decrypted source before approval.
        $fresh = MAD4B_SCP_Activity_Import_Review::plan( $loaded['input'] );
        if ( is_wp_error( $fresh ) || ! hash_equals( $record['plan']['plan_sha256'],
            isset( $fresh['plan_sha256'] ) ? $fresh['plan_sha256'] : '' ) )
            return self::err( 'mad4b_import_snapshot_review_stale', 'Validation output changed since staging.' );
        $block_count = isset( $fresh['block_issue_count'] ) ? (int) $fresh['block_issue_count'] : -1;
        if ( $block_count !== 0 )
            return self::err( 'mad4b_import_approval_blocked',
                'Resolve all blocking issues before acceptance.' );
        $warning_count = isset( $fresh['review_issue_count'] ) ?
            (int) $fresh['review_issue_count'] : -1;
        if ( ! is_int( $acknowledged_warning_count ) ||
            $warning_count !== $acknowledged_warning_count )
            return self::err( 'mad4b_import_warning_acknowledgement_mismatch',
                'Confirm the exact full count of business warnings, including those beyond the first page.' );
        $approval = array(
            'contract' => 'mad4b.import-approval.v1',
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_slug' => $slug, 'snapshot_sha256' => $expected_sha,
            'payload_sha256' => $record['payload_sha256'],
            'plan_sha256' => $record['plan']['plan_sha256'],
            'policy_sha256' => $record['policy_sha256'],
            'approved_at' => gmdate( 'c' ),
            'reviewer_user_id' => (int) get_current_user_id(),
            'warning_count_acknowledged' => $acknowledged_warning_count,
            'authorization_scope' => 'manual_approved_snapshot_export_only',
            'wordpress_post_writes' => 0
        );
        $key = self::approval_key( $slug, $expected_sha );
        $inserted = MAD4B_SCP_Batch_Atomic_Mutex::insert_immutable( $key, $approval );
        if ( is_wp_error( $inserted ) )
            return self::err( 'mad4b_import_approval_already_exists',
                'Approval already exists or immutable SQL write was not independently verified.' );
        if ( self::digest( get_option( $key, false ) ) !== self::digest( $approval ) )
            return self::err( 'mad4b_import_approval_readback_failure', 'Independent approval readback failed.' );
        return $approval;
    }
    public static function approval( $slug, $snapshot_sha ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::environment_ready() )
            return self::err( 'mad4b_import_approval_read_denied', 'Staging administrator required.' );
        $loaded = self::raw_snapshot( $slug, $snapshot_sha );
        if ( is_wp_error( $loaded ) ) return $loaded;
        $current = self::check_current( $loaded['receipt'] );
        if ( is_wp_error( $current ) ) return $current;
        if ( is_array( get_option( self::archive_key(
            $slug, $snapshot_sha ), false ) ) )
            return self::err( 'mad4b_import_snapshot_archived',
                'An archived snapshot approval can never be reused.' );
        $approval = get_option( self::approval_key( $slug, $snapshot_sha ), false );
        if ( ! is_array( $approval ) || ! isset( $approval['snapshot_sha256'],
            $approval['policy_sha256'], $approval['payload_sha256'] ) ||
            ! hash_equals( $approval['snapshot_sha256'], $snapshot_sha ) ||
            ! hash_equals( $approval['policy_sha256'], $loaded['receipt']['policy_sha256'] ) ||
            ! hash_equals( $approval['payload_sha256'], $loaded['receipt']['payload_sha256'] ) )
            return self::err( 'mad4b_import_not_approved', 'Source snapshot is not approved.' );
        return $approval;
    }
    public static function approval_plan( $input = array() ) {
        if ( ! is_array( $input ) || ! isset( $input['profile_slug'], $input['snapshot_sha256'] ) )
            return self::err( 'mad4b_import_approval_request_invalid', 'Exact profile and snapshot are required.' );
        $slug = (string) $input['profile_slug'];
        $sha = (string) $input['snapshot_sha256'];
        $loaded = self::raw_snapshot( $slug, $sha );
        if ( is_wp_error( $loaded ) ) return $loaded;
        $current = self::check_current( $loaded['receipt'] );
        if ( is_wp_error( $current ) ) return $current;
        $fresh = MAD4B_SCP_Activity_Import_Review::plan( $loaded['input'] );
        if ( is_wp_error( $fresh ) ) return $fresh;
        $bound = hash_equals( $loaded['receipt']['plan']['plan_sha256'],
            $fresh['plan_sha256'] );
        return array( 'contract' => 'mad4b.import-approval-plan.v1',
            'profile_slug' => $slug, 'snapshot_sha256' => $sha,
            'plan_sha256' => $fresh['plan_sha256'],
            'policy_sha256' => $fresh['policy_sha256'],
            'block_issue_count' => $fresh['block_issue_count'],
            'review_issue_count' => $fresh['review_issue_count'],
            'review_list_truncated' => $fresh['issues_truncated'],
            'receipt_bound_exact' => $bound,
            'ready_for_manual_approval' => $bound &&
                0 === $fresh['block_issue_count'],
            'explicit_full_warning_count_ack_required' => true,
            'approval_is_not_import_execution' => true,
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function approve_ability( $input = array() ) {
        if ( ! is_array( $input ) || true !== ( isset( $input['confirmed'] ) ? $input['confirmed'] : false ) ||
            ! isset( $input['plan_sha256'], $input['snapshot_sha256'] ) )
            return self::err( 'mad4b_import_approve_confirmation_missing', 'Exact plan hash and confirmed=true required.' );
        $plan = self::approval_plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! $plan['ready_for_manual_approval'] ||
            ! is_string( $input['plan_sha256'] ) ||
            ! hash_equals( $plan['plan_sha256'], $input['plan_sha256'] ) )
            return self::err( 'mad4b_import_approve_plan_stale', 'Exact current approval plan required.' );
        $ack = isset( $input['acknowledged_warning_count'] ) ?
            $input['acknowledged_warning_count'] : null;
        if ( ! is_int( $ack ) || $ack !== (int) $plan['review_issue_count'] )
            return self::err( 'mad4b_import_warning_acknowledgement_mismatch',
                'Provide the exact count of all business warnings as part of the reviewed plan.' );
        return self::approve( $plan['profile_slug'], $plan['snapshot_sha256'],
            true, $ack );
    }
    public static function approval_receipt( $input = array() ) {
        if ( ! is_array( $input ) || ! isset( $input['profile_slug'], $input['snapshot_sha256'] ) )
            return self::err( 'mad4b_import_approval_receipt_invalid', 'Profile and snapshot required.' );
        return self::approval( (string) $input['profile_slug'],
            (string) $input['snapshot_sha256'] );
    }
    /**
     * Build the full export in an isolated bounded temporary stream before
     * issuing CSV response headers. A rejected late row must never leave
     * a partial, misleading "approved" download in the operator's browser.
     */
    public static function export_approved_csv( $slug, $snapshot_sha ) {
        return self::source_with_lock( $slug, 'export', function() use ( $slug, $snapshot_sha ) {
            return self::export_approved_csv_unlocked( $slug, $snapshot_sha );
        } );
    }
    /** Idempotent audited source cleanup; no WordPress post mutation. */
    public static function archive_exact_review( $slug, $expected_payload_sha, $snapshot_sha ) {
        return self::source_with_lock( $slug, 'archive',
            function() use ( $slug, $expected_payload_sha, $snapshot_sha ) {
                if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', (string) $slug ) ||
                    ! preg_match( '/^[a-f0-9]{64}$/D', (string) $expected_payload_sha ) ||
                    ! preg_match( '/^[a-f0-9]{64}$/D', (string) $snapshot_sha ) )
                    return self::err( 'mad4b_import_archive_identity_invalid',
                        'Exact review source identity and both hashes required.' );
                $archive = self::archive_key( $slug, $snapshot_sha );
                $prior = get_option( $archive, false );
                $store = self::option_key( $slug );
                $state = get_option( $store, false );
                if ( false !== $prior &&
                    ( ! is_array( $prior ) ||
                      ( $prior['site_uuid'] ?? '' ) !== MAD4B_SCP_Site_Profile::site_uuid() ||
                      ( $prior['profile_slug'] ?? '' ) !== $slug ||
                      ( $prior['payload_sha256'] ?? '' ) !== $expected_payload_sha ||
                      ( $prior['snapshot_sha256'] ?? '' ) !== $snapshot_sha ) )
                    return self::err( 'mad4b_import_archive_audit_mismatch',
                        'Existing archive intent does not match this exact source.' );
                if ( false === $state ) {
                    if ( ! is_array( $prior ) )
                        return self::err( 'mad4b_import_archive_unknown_source',
                            'No exact source or immutable archive record exists.' );
                    return array( 'archived' => true, 'audit_recorded' => true,
                        'already_cleaned' => true, 'post_writes' => 0 );
                }
                if ( ! is_array( $state ) ||
                    ! isset( $state['snapshot_sha256'], $state['payload_sha256'] ) ||
                    ! hash_equals( (string) $state['snapshot_sha256'], $snapshot_sha ) ||
                    ! hash_equals( (string) $state['payload_sha256'],
                        $expected_payload_sha ) )
                    return self::err( 'mad4b_import_archive_source_changed',
                        'Source is not the reviewed snapshot confirmed by the operator.' );
                if ( false === $prior ) {
                    $record = array(
                        'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
                        'profile_slug' => $slug,
                        'payload_sha256' => $expected_payload_sha,
                        'snapshot_sha256' => $snapshot_sha,
                        'archived_at' => gmdate( 'c' ),
                        'post_writes' => 0 );
                    $inserted = MAD4B_SCP_Batch_Atomic_Mutex::insert_immutable(
                        $archive, $record );
                    if ( is_wp_error( $inserted ) )
                        return self::err( 'mad4b_import_archive_audit_failed',
                            'Archive intent was not atomically persisted and read back.' );
                }
                delete_option( $store );
                if ( false !== get_option( $store, false ) )
                    return self::err( 'mad4b_import_archive_cleanup_unverified',
                        'Immutable audit remains but source cleanup could not be confirmed.' );
                return array( 'archived' => true, 'audit_recorded' => true,
                    'post_writes' => 0 );
            } );
    }
    private static function export_approved_csv_unlocked( $slug, $snapshot_sha ) {
        $approved = self::approval( $slug, $snapshot_sha );
        if ( is_wp_error( $approved ) ) return $approved;
        $loaded = self::raw_snapshot( $slug, $snapshot_sha );
        if ( is_wp_error( $loaded ) ) return $loaded;
        $fresh = MAD4B_SCP_Activity_Import_Review::plan( $loaded['input'] );
        if ( is_wp_error( $fresh ) ||
            ! isset( $fresh['plan_sha256'] ) ||
            ! hash_equals( $approved['plan_sha256'], $fresh['plan_sha256'] ) )
            return self::err( 'mad4b_import_export_policy_changed',
                'Revalidate and approve the exact current snapshot before exporting.' );
        $input = $loaded['input'];
        $headers = isset( $input['headers'] ) ? $input['headers'] : array();
        $rows = isset( $input['rows'] ) ? $input['rows'] : array();
        if ( ! is_array( $headers ) || ! is_array( $rows ) ||
            count( $rows ) < 1 || count( $rows ) > 500 ||
            count( $headers ) < 1 || count( $headers ) > 80 )
            return self::err( 'mad4b_import_export_invalid', 'Approved source is outside safe export limits.' );
        if ( headers_sent() )
            return self::err( 'mad4b_import_export_headers_sent',
                'Download must begin before any page content is sent.' );
        $out = fopen( 'php://temp/maxmemory:2097152', 'w+b' );
        if ( false === $out )
            return self::err( 'mad4b_import_export_failed', 'Temporary CSV validation stream unavailable.' );
        if ( false === fputcsv( $out, $headers ) ) {
            fclose( $out );
            return self::err( 'mad4b_import_export_write_failed', 'CSV header encoding failed.' );
        }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                fclose( $out );
                return self::err( 'mad4b_import_export_row_invalid',
                    'Source row is no longer structurally valid.' );
            }
            $ordered = array();
            foreach ( $headers as $key ) {
                $v = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
                if ( preg_match( '/^[=+@]/', ltrim( $v ) ) ||
                    preg_match( '/^-(?!\\d+(?:\\.\\d+)?$)/', ltrim( $v ) ) ||
                    preg_match( '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $v ) ) {
                    fclose( $out );
                    return self::err( 'mad4b_import_csv_formula_denied',
                        'A source cell is unsafe for downstream CSV consumers.' );
                }
                $ordered[] = $v;
            }
            if ( false === fputcsv( $out, $ordered ) ) {
                fclose( $out );
                return self::err( 'mad4b_import_export_write_failed', 'CSV row encoding failed.' );
            }
        }
        $size = ftell( $out );
        if ( ! is_int( $size ) || $size < 1 || $size > 2097152 ) {
            fclose( $out );
            return self::err( 'mad4b_import_export_size', 'Encoded source exceeds 2 MiB safe download budget.' );
        }
        rewind( $out );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="mad4b-approved-' .
            substr( $snapshot_sha, 0, 16 ) . '.csv"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Length: ' . $size );
        $written = fpassthru( $out );
        fclose( $out );
        if ( ! is_int( $written ) || $written !== $size )
            return self::err( 'mad4b_import_export_partial_transfer',
                'CSV download did not complete. Inspect transport logs before retrying.' );
        return true;
    }
}
