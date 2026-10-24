<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explicitly enrolled Staging attachment transfer via WordPress REST, intended
 * for a ChatGPT MCP client able to send base64 chunks. Never fetches ChatGPT URLs.
 * No automatic publishing and no arbitrary filesystem target.
 */
final class MAD4B_SCP_ChatGPT_File_Transfer {
    const VERSION = 'mad4b.chatgpt-file-transfer.v1';
    const MAX_BYTES = 16777216;
    const CHUNK_BYTES = 131072;
    const TTL = 3600;
    const NS = 'mad4b-chatgpt-transfer/v1';
    const ABILITY = 'mad4b/attachment-transfer';
    const CAPABILITY = 'mad4b_attachment_transfer';

    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 45 );
    }
    /** Separate grant from broad MAD4B mutation authority, but never bypass the global kill switch. */
    public static function attachment_transfer_granted() {
        if ( ! current_user_can( self::CAPABILITY ) ) return false;
        $gate = MAD4B_SCP_Policy::mutation_gate_status();
        return is_array( $gate ) && ! empty( $gate['effective'] ) && empty( $gate['explicit_kill_switch'] );
    }
    public static function permitted() {
        return is_user_logged_in() && current_user_can( 'upload_files' )
            && class_exists( 'MAD4B_SCP_Policy', false )
            && true === MAD4B_SCP_Policy::can_read()
            && self::attachment_transfer_granted()
            && true === MAD4B_SCP_Policy::can_connect_user( get_current_user_id() )
            && class_exists( 'MAD4B_SCP_Site_Profile', false )
            && MAD4B_SCP_Site_Profile::configured()
            && 'staging' === sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
            && MAD4B_SCP_Site_Profile::origin_enrolled()
            && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
    }
    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) || wp_has_ability( self::ABILITY ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'ChatGPT Attachment Transfer',
            'description' => 'Scoped, authenticated Staging file transfer into WordPress Media Library; no publishing.',
            'category' => 'mad4b-write',
            'execute_callback' => array( __CLASS__, 'execute_ability' ),
            'permission_callback' => array( __CLASS__, 'permitted' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(
                'action' => array( 'type' => 'string', 'enum' => array( 'begin', 'chunk', 'status', 'finish', 'abort' ) ),
                'transfer_id' => array( 'type' => 'string' ),
                'filename' => array( 'type' => 'string' ),
                'size' => array( 'type' => 'integer' ),
                'sha256' => array( 'type' => 'string' ),
                'offset' => array( 'type' => 'integer' ),
                'data_base64' => array( 'type' => 'string' ),
            ), 'required' => array( 'action' ), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'write' ),
                'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) ),
        ) );
    }
    public static function execute_ability( $input ) {
        if ( ! self::permitted() ) return self::error( 'mad4b_transfer_denied', 'Scoped file transfer grant required.', 403 );
        if ( ! is_array( $input ) ) return self::error( 'mad4b_transfer_input', 'Invalid input.' );
        $action = isset( $input['action'] ) ? $input['action'] : '';
        if ( ! in_array( $action, array( 'begin', 'chunk', 'status', 'finish', 'abort' ), true ) ) return self::error( 'mad4b_transfer_action', 'Invalid action.' );
        $request = new WP_REST_Request( 'POST', '/' . self::NS . '/' . $action );
        foreach ( $input as $key => $value ) {
            if ( 'action' !== $key ) $request->set_param( $key, $value );
        }
        return self::$action( $request );
    }
    public static function register_routes() {
        foreach ( array( 'begin', 'chunk', 'finish', 'status', 'abort' ) as $method ) {
            register_rest_route( self::NS, '/' . $method, array(
                'methods' => 'POST', 'callback' => array( __CLASS__, $method ),
                'permission_callback' => array( __CLASS__, 'permitted' ),
                'args' => array(),
            ) );
        }
    }
    private static function error( $code, $message, $status = 400 ) {
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }
    private static function root() {
        $base = realpath( sys_get_temp_dir() );
        if ( false === $base || ! is_dir( $base ) || ! is_writable( $base ) ) return false;
        $root = $base . '/mad4b-chatgpt-transfer-v1';
        if ( ! is_dir( $root ) && ! @mkdir( $root, 0700 ) && ! is_dir( $root ) ) return false;
        if ( is_link( $root ) || ! is_dir( $root ) ) return false;
        @chmod( $root, 0700 );
        return $root;
    }
    private static function key( $id ) { return 'mad4b_transfer_' . hash( 'sha256', $id ); }
    private static function state( $request ) {
        $id = (string) $request->get_param( 'transfer_id' );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) return self::error( 'mad4b_transfer_id_invalid', 'Invalid transfer ID.' );
        $s = get_transient( self::key( $id ) );
        if ( ! is_array( $s ) || (int) $s['user'] !== get_current_user_id()
            || ! hash_equals( (string) $s['site'], hash( 'sha256', home_url( '/' ) ) ) )
            return self::error( 'mad4b_transfer_unknown', 'Transfer does not exist for this site and user.', 404 );
        return $s;
    }
    public static function begin( $request ) {
        $size = $request->get_param( 'size' );
        $name = $request->get_param( 'filename' );
        $hash = strtolower( (string) $request->get_param( 'sha256' ) );
        if ( ! is_int( $size ) || $size < 1 || $size > self::MAX_BYTES )
            return self::error( 'mad4b_transfer_size', 'File size is outside the allowed range.' );
        if ( ! is_string( $name ) || strlen( $name ) > 180 || $name !== sanitize_file_name( $name ) || '' === $name )
            return self::error( 'mad4b_transfer_filename', 'A safe filename is required.' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) )
            return self::error( 'mad4b_transfer_digest', 'Expected SHA-256 is required.' );
        $type = wp_check_filetype( $name );
        if ( empty( $type['type'] ) || ! in_array( $type['type'], get_allowed_mime_types(), true )
            || preg_match( '/\.(?:php[0-9]?|phtml|phar|js|html?|svg|exe|sh|bat)$/i', $name ) )
            return self::error( 'mad4b_transfer_type', 'This file type is not permitted by WordPress.' );
        $root = self::root();
        if ( false === $root ) return self::error( 'mad4b_transfer_temp', 'Private temporary storage is unavailable.', 503 );
        $id = bin2hex( random_bytes( 16 ) );
        $path = $root . '/' . $id . '.part';
        $handle = @fopen( $path, 'x+b' );
        if ( false === $handle ) return self::error( 'mad4b_transfer_temp', 'Cannot allocate private staging file.', 503 );
        @chmod( $path, 0600 );
        fclose( $handle );
        $s = array( 'id' => $id, 'user' => get_current_user_id(), 'site' => hash( 'sha256', home_url( '/' ) ),
            'size' => $size, 'sha256' => $hash, 'filename' => $name, 'offset' => 0,
            'created' => time(), 'status' => 'receiving' );
        set_transient( self::key( $id ), $s, self::TTL );
        return array( 'contract' => self::VERSION, 'transfer_id' => $id,
            'next_offset' => 0, 'chunk_max_bytes' => self::CHUNK_BYTES, 'expires_in_seconds' => self::TTL );
    }
    private static function part( $s ) {
        $root = self::root();
        if ( false === $root ) return false;
        $path = $root . '/' . $s['id'] . '.part';
        if ( is_link( $path ) || ! is_file( $path ) ) return false;
        return $path;
    }
    public static function status( $request ) {
        $s = self::state( $request );
        if ( is_wp_error( $s ) ) return $s;
        return array( 'contract' => self::VERSION, 'transfer_id' => $s['id'],
            'status' => $s['status'], 'next_offset' => (int) $s['offset'], 'size' => (int) $s['size'] );
    }
    public static function chunk( $request ) {
        $s = self::state( $request );
        if ( is_wp_error( $s ) ) return $s;
        $offset = $request->get_param( 'offset' );
        $encoded = $request->get_param( 'data_base64' );
        if ( 'receiving' !== $s['status'] || ! is_int( $offset ) || $offset !== (int) $s['offset'] )
            return self::error( 'mad4b_transfer_offset', 'Chunk offset differs from committed offset.', 409 );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > 175000 || ! preg_match( '/^[A-Za-z0-9+\/=]+$/D', $encoded ) )
            return self::error( 'mad4b_transfer_chunk', 'Invalid base64 chunk.' );
        $bytes = base64_decode( $encoded, true );
        if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::CHUNK_BYTES
            || base64_encode( $bytes ) !== $encoded || $offset + strlen( $bytes ) > (int) $s['size'] )
            return self::error( 'mad4b_transfer_chunk', 'Chunk exceeds declared bounds.' );
        $path = self::part( $s );
        if ( false === $path ) return self::error( 'mad4b_transfer_temp', 'Transfer bytes unavailable.', 404 );
        $f = @fopen( $path, 'c+b' );
        if ( false === $f ) return self::error( 'mad4b_transfer_temp', 'Transfer storage unavailable.', 503 );
        if ( ! flock( $f, LOCK_EX | LOCK_NB ) ) { fclose( $f ); return self::error( 'mad4b_transfer_busy', 'Another chunk is committing.', 409 ); }
        // Recheck persisted offset under the file lock; never accept replay writes.
        $latest = get_transient( self::key( $s['id'] ) );
        $stat = fstat( $f );
        if ( ! is_array( $latest ) || (int) $latest['offset'] !== $offset || ! is_array( $stat ) || (int) $stat['size'] !== $offset ) {
            flock( $f, LOCK_UN ); fclose( $f );
            return self::error( 'mad4b_transfer_conflict', 'Transfer state changed; query status.', 409 );
        }
        fseek( $f, $offset );
        $written = fwrite( $f, $bytes );
        fflush( $f );
        if ( $written !== strlen( $bytes ) ) {
            ftruncate( $f, $offset ); flock( $f, LOCK_UN ); fclose( $f );
            return self::error( 'mad4b_transfer_write', 'Could not commit complete chunk.', 503 );
        }
        $s['offset'] += $written;
        set_transient( self::key( $s['id'] ), $s, self::TTL );
        flock( $f, LOCK_UN ); fclose( $f );
        return array( 'contract' => self::VERSION, 'transfer_id' => $s['id'], 'next_offset' => $s['offset'] );
    }
    public static function finish( $request ) {
        $s = self::state( $request );
        if ( is_wp_error( $s ) ) return $s;
        if ( 'receiving' !== $s['status'] || (int) $s['offset'] !== (int) $s['size'] )
            return self::error( 'mad4b_transfer_incomplete', 'Transfer is incomplete.', 409 );
        $path = self::part( $s );
        if ( false === $path ) return self::error( 'mad4b_transfer_temp', 'Transfer bytes unavailable.', 404 );
        $f = fopen( $path, 'c+b' );
        if ( ! $f || ! flock( $f, LOCK_EX | LOCK_NB ) ) { if ($f) fclose($f); return self::error( 'mad4b_transfer_busy', 'Transfer is busy.', 409 ); }
        $latest = get_transient( self::key( $s['id'] ) );
        if ( ! is_array( $latest ) || 'receiving' !== $latest['status'] || filesize( $path ) !== (int) $s['size']
            || ! hash_equals( $s['sha256'], hash_file( 'sha256', $path ) ) ) {
            flock( $f, LOCK_UN ); fclose( $f );
            return self::error( 'mad4b_transfer_integrity', 'Transfer size or SHA-256 mismatch.', 409 );
        }
        $check = wp_check_filetype_and_ext( $path, $s['filename'] );
        if ( empty( $check['type'] ) || ! in_array( $check['type'], get_allowed_mime_types(), true ) ) {
            flock( $f, LOCK_UN ); fclose( $f );
            return self::error( 'mad4b_transfer_mime', 'Content MIME does not match an allowed upload type.' );
        }
        $s['status'] = 'committing';
        set_transient( self::key( $s['id'] ), $s, self::TTL );
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        // WordPress declares the file argument by reference: a temporary
        // variable is required (passing an inline array causes a PHP fatal).
        $sideload_file = array( 'name' => $s['filename'], 'type' => $check['type'],
            'tmp_name' => $path, 'error' => 0, 'size' => $s['size'] );
        $upload = wp_handle_sideload( $sideload_file, array( 'test_form' => false ) );
        if ( ! is_array( $upload ) || isset( $upload['error'] ) ) {
            $s['status'] = 'receiving'; set_transient( self::key( $s['id'] ), $s, self::TTL );
            flock( $f, LOCK_UN ); fclose( $f );
            return self::error( 'mad4b_transfer_media', 'WordPress refused the verified upload.', 503 );
        }
        $id = wp_insert_attachment( array( 'post_mime_type' => $upload['type'],
            'post_title' => sanitize_text_field( pathinfo( $s['filename'], PATHINFO_FILENAME ) ),
            'post_status' => 'inherit' ), $upload['file'], 0, true );
        if ( is_wp_error( $id ) || ! $id ) {
            @unlink( $upload['file'] );
            delete_transient( self::key( $s['id'] ) );
            flock( $f, LOCK_UN ); fclose( $f );
            return self::error( 'mad4b_transfer_attachment', 'WordPress attachment creation failed.', 503 );
        }
        $meta = wp_generate_attachment_metadata( $id, $upload['file'] );
        if ( is_array( $meta ) ) wp_update_attachment_metadata( $id, $meta );
        update_post_meta( $id, '_mad4b_chatgpt_transfer_sha256', $s['sha256'] );
        delete_transient( self::key( $s['id'] ) );
        if ( is_file( $path ) ) @unlink( $path );
        flock( $f, LOCK_UN ); fclose( $f );
        return array( 'contract' => self::VERSION, 'verified' => true, 'attachment_id' => (int) $id,
            'sha256' => $s['sha256'], 'bytes' => $s['size'],
            'attachment_url' => wp_get_attachment_url( $id ), 'publication_performed' => false );
    }
    public static function abort( $request ) {
        $s = self::state( $request );
        if ( is_wp_error( $s ) ) return $s;
        $path = self::part( $s );
        if ( $path ) @unlink( $path );
        delete_transient( self::key( $s['id'] ) );
        return array( 'aborted' => true, 'publication_performed' => false );
    }
}
