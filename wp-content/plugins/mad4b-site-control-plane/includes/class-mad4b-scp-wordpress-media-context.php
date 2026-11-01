<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Explicit, bounded and read-only WordPress attachment Context source across environments. */
final class MAD4B_SCP_WordPress_Media_Context {
    const ROOT = 'wordpress-media-library';
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_mad4b_context_wp_media_sync', array( __CLASS__, 'submit' ) );
    }
    private static function allowed() {
        return current_user_can( 'manage_options' ) && current_user_can( 'upload_files' )
            && class_exists( 'MAD4B_SCP_Context_Authority' )
            && class_exists( 'MAD4B_SCP_Policy' )
            && MAD4B_SCP_Policy::can_connect_user( get_current_user_id() );
    }
    public static function menu() {
        if ( self::allowed() ) add_management_page( 'MAD4B WordPress Media Context', 'MAD4B Media Context', 'manage_options', 'mad4b-wp-media-context', array( __CLASS__, 'page' ) );
    }
    public static function page() {
        if ( ! self::allowed() ) wp_die( 'Access denied', '', array( 'response' => 403 ) );
        echo '<div class="wrap"><h1>WordPress Media Library → Brand Context</h1>';
        echo '<p>Complete inventory of selected file types. Content is read-only; review and approval remain separate.</p>';
        if ( isset( $_GET['mad4b_media_result'] ) )
            echo '<p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['mad4b_media_result'] ) ) ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="mad4b_context_wp_media_sync">';
        echo '<input type="hidden" name="media_mime_scope" value="1">';
        wp_nonce_field( 'mad4b_context_wp_media_sync' );
        foreach ( array( 'application/pdf' => 'PDF', 'text/plain' => 'TXT', 'text/markdown' => 'Markdown', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel XLSX', 'application/vnd.ms-excel.sheet.macroEnabled.12' => 'Excel XLSM', 'text/csv' => 'Excel CSV' ) as $mime => $name )
            echo '<p><label><input type="checkbox" name="mime_types[]" value="' . esc_attr( $mime ) . '" checked> ' . esc_html( $name ) . '</label></p>';
        submit_button( 'Scan all matching media files' );
        echo '</form></div>';
    }
    public static function attachment_record( $id ) {
        $post = get_post( (int) $id );
        if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status ) return new WP_Error( 'mad4b_media_missing', 'Attachment unavailable.' );
        $path = get_attached_file( (int) $id );
        $mime = (string) get_post_mime_type( (int) $id );
        if ( ! in_array( $mime, array( 'application/pdf', 'text/plain', 'text/markdown', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel.sheet.macroEnabled.12', 'text/csv' ), true ) ) return new WP_Error( 'mad4b_media_mime_unsupported', 'Unsupported Context MIME type.' );
        if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > 10485760 ) return new WP_Error( 'mad4b_media_file_unavailable', 'Attachment must be readable and at most 10 MiB.' );
        $hash = hash_file( 'sha256', $path );
        if ( ! is_string( $hash ) ) return new WP_Error( 'mad4b_media_hash_failed', 'Cannot hash attachment.' );
        return array( 'file_id' => (string) $id, 'parent_folder_id' => self::ROOT, 'title' => (string) $post->post_title,
            'path' => (string) get_post_meta( $id, '_wp_attached_file', true ), 'mimeType' => $mime, 'content_hash' => $hash,
            'content_complete' => false, 'modifiedTime' => get_post_modified_time( 'c', true, $post ) );
    }
    /** Normalize raw, bounded local bytes. Never treat metadata or OCR-needed PDFs as complete. */
    public static function normalize_bytes( $mime, $binary ) {
        $mime = (string) $mime;
        $binary = (string) $binary;
        if ( '' === $binary ) return new WP_Error( 'mad4b_media_empty_content', 'Empty Context attachments require review.' );
        if ( in_array( $mime, array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel.sheet.macroEnabled.12' ), true ) ) {
            if ( ! class_exists( 'MAD4B_SCP_Google_Drive_Context' ) || ! method_exists( 'MAD4B_SCP_Google_Drive_Context', 'normalize_local_spreadsheet_bytes' ) )
                return new WP_Error( 'mad4b_media_excel_parser_unavailable', 'Shared local workbook normalizer unavailable.' );
            $parsed = MAD4B_SCP_Google_Drive_Context::normalize_local_spreadsheet_bytes( $mime, $binary );
            if ( is_wp_error( $parsed ) ) return $parsed;
            if ( ! is_array( $parsed ) || true !== ( $parsed['complete'] ?? null ) || '' === trim( (string) ( $parsed['content'] ?? '' ) ) )
                return new WP_Error( 'mad4b_media_excel_incomplete', 'Excel workbook normalization is incomplete.' );
            return array( 'normalized_text' => (string) $parsed['content'], 'content_complete' => true,
                'content_bytes' => strlen( $binary ), 'normalization_status' => 'ready',
                'normalization_reason' => 'local_excel_structured' );
        }
        if ( 'text/csv' === $mime ) {
            if ( strlen( $binary ) > 262144 || false !== strpos( $binary, "\x00" ) || !preg_match('//u', $binary) )
                return new WP_Error( 'mad4b_media_csv_invalid', 'CSV must be bounded UTF-8 text.' );
            $text = preg_replace( '/^\xEF\xBB\xBF/', '', $binary );
            $stream = fopen( 'php://temp', 'w+' );
            if ( ! $stream ) return new WP_Error( 'mad4b_media_csv_stream_failed', 'CSV stream unavailable.' );
            fwrite( $stream, $text );
            rewind( $stream );
            $lines = array();
            $line = 0;
            while ( false !== ( $cells = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
                ++$line;
                if ( $line > 2000 || count($cells) > 64 ) { fclose($stream); return new WP_Error( 'mad4b_media_csv_capacity', 'CSV exceeds safe table limits.' ); }
                $lines[] = $line . "\t" . implode( "\t", array_map( static function ($cell) { return trim( str_replace( array("\r","\n","\t"), ' ', (string)$cell ) ); }, $cells ) );
            }
            fclose($stream);
            if ( ! $lines ) return new WP_Error('mad4b_media_csv_empty', 'CSV has no rows.');
            $csv_normalized = "[Sheet: CSV; rows=" . count($lines) . "]\nRow\tA..\n" . implode("\n",$lines);
            if ( strlen($csv_normalized) > 262144 ) return new WP_Error('mad4b_media_csv_output_limit','Normalized CSV exceeds 256 KiB.');
            return array( 'normalized_text' => $csv_normalized,
                'content_complete' => true, 'content_bytes' => strlen($binary),
                'normalization_status' => 'ready', 'normalization_reason' => 'local_csv' );
        }
        if ( 'application/pdf' === $mime ) {
            if ( ! class_exists( 'MAD4B_SCP_Google_Drive_Context' ) || ! method_exists( 'MAD4B_SCP_Google_Drive_Context', 'normalize_local_pdf_bytes' ) )
                return new WP_Error( 'mad4b_media_pdf_parser_unavailable', 'Shared local PDF text normalizer unavailable.' );
            $parsed = MAD4B_SCP_Google_Drive_Context::normalize_local_pdf_bytes( $binary );
            if ( is_wp_error( $parsed ) ) return $parsed;
            if ( ! is_array( $parsed ) || true !== ( $parsed['complete'] ?? null ) || '' === trim( (string) ( $parsed['content'] ?? '' ) ) )
                return new WP_Error( 'mad4b_media_pdf_extractor_required', 'PDF text is incomplete or OCR is required.' );
            return array( 'normalized_text' => (string) $parsed['content'], 'content_complete' => true,
                'content_bytes' => strlen( $binary ), 'normalization_status' => 'ready',
                'normalization_reason' => 'local_pdf_text' );
        }
        if ( ! in_array( $mime, array( 'text/plain', 'text/markdown' ), true ) )
            return new WP_Error( 'mad4b_media_mime_unsupported', 'Unsupported Context MIME type.' );
        if ( strlen( $binary ) > 262144 ) return new WP_Error( 'mad4b_media_text_limit', 'Text exceeds the certified 256 KiB normalization limit.' );
        if ( false !== strpos( $binary, "\x00" ) || ! function_exists( 'wp_check_invalid_utf8' ) )
            return new WP_Error( 'mad4b_media_invalid_text', 'Attachment is not valid UTF-8 text.' );
        $validated = wp_check_invalid_utf8( $binary, true );
        if ( ! is_string( $validated ) || $validated !== $binary || '' === trim( $validated ) )
            return new WP_Error( 'mad4b_media_invalid_text', 'Attachment must contain readable UTF-8 text.' );
        return array( 'normalized_text' => $validated, 'content_complete' => true,
            'content_bytes' => strlen( $binary ), 'normalization_status' => 'ready',
            'normalization_reason' => 'local_text' );
    }
    private static function normalize_attachment_record( array $record ) {
        $id = (int) ( $record['file_id'] ?? 0 );
        $path = get_attached_file( $id );
        if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) )
            return new WP_Error( 'mad4b_media_file_unavailable', 'Attachment cannot be read for normalization.' );
        $bytes = file_get_contents( $path );
        if ( ! is_string( $bytes ) || strlen( $bytes ) > 10485760 )
            return new WP_Error( 'mad4b_media_file_unavailable', 'Attachment exceeds the bounded 10 MiB read.' );
        $current_hash = hash( 'sha256', $bytes );
        if ( ! hash_equals( (string) $record['content_hash'], $current_hash ) )
            return new WP_Error( 'mad4b_media_changed_during_scan', 'Attachment changed while scanning. Retry.' );
        $normalized = self::normalize_bytes( (string) $record['mimeType'], $bytes );
        if ( is_wp_error( $normalized ) ) return $normalized;
        return array_merge( $record, $normalized );
    }
    /** Complete inventory of eligible attachments within an explicit MIME scope. */
    public static function inventory( array $mime_types = array( 'application/pdf', 'text/plain', 'text/markdown', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel.sheet.macroEnabled.12', 'text/csv' ), $source_id = '' ) {
        $allowed = array( 'application/pdf', 'text/plain', 'text/markdown', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel.sheet.macroEnabled.12', 'text/csv' );
        $mime_types = array_values( array_unique( array_map( 'strval', $mime_types ) ) );
        if ( ! $mime_types || array_diff( $mime_types, $allowed ) )
            return new WP_Error( 'mad4b_media_inventory_scope_invalid', 'Select supported MIME types.' );
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $mime_types ), '%s' ) );
        $query = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash' AND post_mime_type IN ($placeholders) ORDER BY ID ASC LIMIT 1001", $mime_types );
        $ids = $wpdb->get_col( $query );
        if ( count( $ids ) > 1000 ) return new WP_Error( 'mad4b_media_inventory_capacity', 'Matching inventory exceeds the 1000-asset registry limit; no complete scan is possible.' );
        $assets = array();
        $known = MAD4B_SCP_Context_Authority::assets();
        foreach ( $ids as $id ) {
            $record = self::attachment_record( $id );
            if ( is_wp_error( $record ) ) return $record;
            $asset_id = hash( 'sha256', (string) $source_id . '|' . (string) $id );
            $prior = $known[ $asset_id ] ?? array();
            if ( $prior && ! empty( $prior['content_complete'] ) && ! empty( $prior['content_hash'] )
                && hash_equals( (string) $prior['content_hash'], (string) $record['content_hash'] )
                && hash_equals( (string) ( $prior['version'] ?? '' ), (string) $record['modifiedTime'] ) ) {
                $record['reuse_existing'] = true;
                $record['content_complete'] = true;
            } else {
                $record = self::normalize_attachment_record( $record );
                if ( is_wp_error( $record ) ) return $record;
            }
            $assets[] = $record;
        }
        return array( 'assets' => $assets, 'complete' => true, 'content_complete' => count( array_filter( $assets, static function ( $asset ) { return ! empty( $asset['content_complete'] ); } ) ) === count( $assets ), 'scoped_inventory' => true,
            'mime_types' => $mime_types, 'started_at' => gmdate( 'c' ), 'completed_at' => gmdate( 'c' ) );
    }
    public static function sync_ids( array $ids ) {
        if ( ! self::allowed() ) return new WP_Error( 'mad4b_media_denied', 'Not authorized.' );
        $ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
        if ( ! $ids || count( $ids ) > 25 || in_array( 0, $ids, true ) ) return new WP_Error( 'mad4b_media_id_limit', 'Select 1–25 valid attachment IDs.' );
        $records = array();
        foreach ( $ids as $id ) {
            $record = self::attachment_record( $id );
            if ( is_wp_error( $record ) ) return $record; // Validate all before mutating the registry.
            $records[] = $record;
        }
        $source = MAD4B_SCP_Context_Authority::upsert_source( array( 'provider'=>'wordpress_media', 'mode'=>'governed',
            'external_root_id'=>self::ROOT, 'label'=>'WordPress Media Library', 'write_policy'=>'read_only', 'recursive'=>false ) );
        if ( is_wp_error( $source ) ) return $source;
        $id = isset( $source['source_id'] ) ? (string) $source['source_id'] : '';
        if ( '' === $id ) return new WP_Error( 'mad4b_media_source_result_invalid', 'Source ID unavailable.' );
        if ( ! class_exists( 'MAD4B_SCP_Context_Source_Pipeline' ) )
            return new WP_Error( 'mad4b_media_pipeline_unavailable', 'Shared source pipeline is unavailable.' );
        $types = array_values( array_unique( array_column( $records, 'mimeType' ) ) );
        $result = MAD4B_SCP_Context_Source_Pipeline::scan_complete( $id, array( 'mime_types' => $types ) );

        if ( is_wp_error( $result ) ) return $result;
        return array( 'source_id'=>$id, 'refreshed'=>(int) $result['asset_count'], 'non_destructive'=>true );
    }
    public static function submit() {
        if ( ! self::allowed() ) wp_die( 'Access denied', '', array( 'response' => 403 ) );
        check_admin_referer( 'mad4b_context_wp_media_sync' );
        $types = isset( $_POST['mime_types'] ) && is_array( $_POST['mime_types'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mime_types'] ) ) : array();
        $allowed = array( 'application/pdf', 'text/plain', 'text/markdown', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel.sheet.macroEnabled.12', 'text/csv' );
        if ( ! isset( $_POST['media_mime_scope'] ) || ! $types || array_diff( $types, $allowed ) )
            wp_die( 'Choose one or more supported MIME types.', '', array( 'response' => 400 ) );
        $source = MAD4B_SCP_Context_Authority::upsert_source( array(
            'provider' => 'wordpress_media', 'mode' => 'governed',
            'external_root_id' => 'site_media_library', 'label' => 'WordPress Media Library',
            'write_policy' => 'read_only', 'recursive' => false,
        ) );
        $result = is_wp_error( $source ) ? $source :
            MAD4B_SCP_Context_Source_Pipeline::scan_complete( (string) $source['source_id'], array( 'mime_types' => $types ) );
        $message = is_wp_error( $result ) ? $result->get_error_code() :
            'scanned_' . (int) ( $result['asset_count'] ?? 0 );
        wp_safe_redirect( add_query_arg( 'mad4b_media_result', sanitize_key( $message ),
            admin_url( 'tools.php?page=mad4b-wp-media-context' ) ) );
        exit;
    }

}
MAD4B_SCP_WordPress_Media_Context::boot();
