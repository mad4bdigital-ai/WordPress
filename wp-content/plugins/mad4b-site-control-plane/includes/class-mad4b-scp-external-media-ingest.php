<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Generic, one-item external image ingestion with exact idempotency.
 * No tourism-specific concepts, no autonomous crawling or publishing.
 */
final class MAD4B_SCP_External_Media_Ingest {
    const CONTRACT = 'mad4b.external-media-ingest.v1';
    const MAX_BYTES = 8388608;
    const KEY_META = '_mad4b_import_source_key';
    const URL_META = '_mad4b_import_source_url';
    const HASH_META = '_mad4b_import_file_sha256';
    private static function fail( $code, $message ) { return new WP_Error( $code, $message ); }
    public static function url_allowed( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 ) return false;
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! isset( $parts['host'], $parts['scheme'] )
            || 'https' !== strtolower( (string) $parts['scheme'] ) || isset( $parts['user'], $parts['pass'] ) ) return false;
        if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) return false;
        if ( preg_match( '/[?&](?:token|secret|api_?key|signature|password|access_token)=/i', $url ) ) return false;
        return function_exists( 'wp_http_validate_url' ) && false !== wp_http_validate_url( $url );
    }
    public static function plan( $input = array() ) {
        $url = isset( $input['source_url'] ) ? (string) $input['source_url'] : '';
        $key = isset( $input['operation_key'] ) ? (string) $input['operation_key'] : '';
        if ( ! self::url_allowed( $url ) ) return self::fail( 'mad4b_media_source_url_unsafe', 'Use a validated external HTTPS image source.' );
        if ( ! preg_match( '/^[A-Za-z0-9._:-]{12,128}$/', $key ) ) return self::fail( 'mad4b_media_operation_key_invalid', 'A stable idempotency operation key is required.' );
        $target = isset( $input['parent_post_id'] ) ? absint( $input['parent_post_id'] ) : 0;
        if ( $target && ( ! get_post( $target ) || ! current_user_can( 'edit_post', $target ) ) )
            return self::fail( 'mad4b_media_target_denied', 'Target WordPress post is missing or cannot be edited.' );
        $scope = hash( 'sha256', home_url( '/' ) . '|' . $url . '|' . $key );
        $found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit',
            'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => self::KEY_META,
            'meta_value' => $scope, 'suppress_filters' => true ) );
        if ( ! is_array( $found ) || count( $found ) > 1 ) return self::fail( 'mad4b_media_import_identity_ambiguous', 'Source import binding cannot be uniquely resolved.' );
        return array( 'contract' => self::CONTRACT, 'source_url' => $url, 'operation_key' => $key,
            'scope_sha256' => $scope, 'existing_attachment_id' => $found ? (int) $found[0] : 0,
            'parent_post_id' => $target, 'max_bytes' => self::MAX_BYTES,
            'supports_metadata_revision' => true,
            'next_ability' => 'media/import-external',
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function apply( $input = array() ) {
        if ( ! current_user_can( 'upload_files' ) ) return self::fail( 'mad4b_media_upload_denied', 'WordPress upload permission is required.' );
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        $expected = isset( $input['expected_scope_sha256'] ) ? (string) $input['expected_scope_sha256'] : '';
        if ( empty( $input['confirmed'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ||
            ! hash_equals( $expected, $plan['scope_sha256'] ) )
            return self::fail( 'mad4b_media_import_stale', 'Exact import plan checksum and confirmation are required.' );
        if ( ! empty( $plan['existing_attachment_id'] ) ) {
            return array( 'contract' => self::CONTRACT, 'attachment_id' => $plan['existing_attachment_id'],
                'already_imported' => true, 'mutation_performed' => false );
        }
        $lock = 'mad4b_ext_media_' . $plan['scope_sha256'];
        if ( ! add_option( $lock, array( 'created_at' => gmdate( 'c' ) ), '', false ) )
            return self::fail( 'mad4b_media_import_busy', 'Import is running or its result requires readback reconciliation.' );
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url( $plan['source_url'], 12 );
        if ( is_wp_error( $tmp ) ) return $tmp;
        $size = filesize( $tmp );
        if ( false === $size || $size <= 0 || $size > self::MAX_BYTES ) {
            @unlink( $tmp );
            return self::fail( 'mad4b_media_source_size_invalid', 'Source image is absent or exceeds the permitted upload size.' );
        }
        $info = function_exists( 'getimagesize' ) ? @getimagesize( $tmp ) : false;
        if ( ! is_array( $info ) || ! isset( $info['mime'] ) ||
            ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' ), true ) ) {
            @unlink( $tmp );
            return self::fail( 'mad4b_media_source_mime_invalid', 'Remote content is not a supported image.' );
        }
        $exts = array( 'image/jpeg' => '.jpg', 'image/png' => '.png',
            'image/webp' => '.webp', 'image/avif' => '.avif', 'image/gif' => '.gif' );
        $sha = hash_file( 'sha256', $tmp );
        $file = array( 'name' => 'import-' . substr( $sha, 0, 20 ) . $exts[ $info['mime'] ],
            'tmp_name' => $tmp, 'size' => $size, 'type' => $info['mime'], 'error' => 0 );
        $title = isset( $input['title'] ) && is_string( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
        $attach = media_handle_sideload( $file, $plan['parent_post_id'],
            '' === $title ? null : $title );
        if ( is_wp_error( $attach ) ) {
            if ( file_exists( $tmp ) ) @unlink( $tmp );
            return $attach; // Keep lock to force operator reconciliation.
        }
        $id = (int) $attach;
        update_post_meta( $id, self::KEY_META, $plan['scope_sha256'] );
        update_post_meta( $id, self::URL_META, esc_url_raw( $plan['source_url'] ) );
        update_post_meta( $id, self::HASH_META, $sha );
        if ( isset( $input['alt'] ) && is_string( $input['alt'] ) )
            update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
        $back = get_post_meta( $id, self::KEY_META, true );
        if ( ! hash_equals( $plan['scope_sha256'], (string) $back ) )
            return self::fail( 'mad4b_media_import_readback_failed', 'Uploaded image could not be bound to its exact source identity.' );
        update_option( $lock, array( 'completed' => true, 'attachment_id' => $id ), false );
        return array( 'contract' => self::CONTRACT, 'attachment_id' => $id,
            'source_file_sha256' => $sha, 'source_scope_sha256' => $plan['scope_sha256'],
            'upload_readback_confirmed' => true, 'mutation_performed' => true,
            'metadata_next_ability' => 'media/update-metadata' );
    }
}
