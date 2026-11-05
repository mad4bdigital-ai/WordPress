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
        $session_id = isset($_GET['scan_session']) ? sanitize_text_field(wp_unslash($_GET['scan_session'])) : '';
        $session = self::scan_session($session_id);
        if ( is_array($session) ) {
            $processed = (int)($session['next']??0);
            $count = count($session['mime_types']);
            echo '<div class="notice notice-info"><p>'.esc_html(sprintf('Scoped Media scan progress: %d/%d MIME types. Successful scopes and errors are saved independently.', $processed,$count)).'</p>';
            foreach ( $session['results'] as $r ) {
                $status = !empty($r['ok']) ? 'PASS' : 'ERROR';
                echo '<p>'.esc_html($status.' | '.($r['mime']??'').' | '.($r['code']??'').' | '.(string)($r['count']??0));
                if(!empty($r['attachment_id'])) echo ' — '.esc_html('Attachment #'.$r['attachment_id']);
                if(!empty($r['errors'])&&is_array($r['errors'])){
                    foreach(array_slice($r['errors'],0,25) as $problem)
                        echo '<span class="description">'.esc_html('Attachment #'.(int)($problem['attachment_id']??0).': '.sanitize_key((string)($problem['code']??'unknown'))).'</span> ';
                }
                echo '</p>';
            }
            echo '</div>';
            $failed=array_values(array_filter($session['results'],static fn($r)=>empty($r['ok'])));
            if($failed){
                echo '<p>'.esc_html__('File issues do not discard successful MIME scopes. Repair only the affected attachment, then retry its MIME scope.','mad4b-site-control-plane').'</p>';
                foreach($failed as $problem){
                    $mime=(string)($problem['mime']??'');
                    if(''===$mime)continue;
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                    wp_nonce_field('mad4b_context_wp_media_sync');
                    echo '<input type="hidden" name="action" value="mad4b_context_wp_media_sync">';
                    echo '<input type="hidden" name="mime_types[]" value="'.esc_attr($mime).'">';
                    submit_button(sprintf('Retry %s scope',$mime),'secondary small','submit',false);
                    echo '</form>';
                }
            }
            if ($processed < $count) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mad4b_context_wp_media_sync');
                echo '<input type="hidden" name="action" value="mad4b_context_wp_media_sync">';
                echo '<input type="hidden" name="scan_session" value="'.esc_attr($session_id).'">';
                echo '<input type="hidden" name="expected_scan_step" value="'.esc_attr((string)$processed).'">';
                submit_button('Continue next bounded scan step','secondary');
                echo '</form>';
            } else echo '<p>'.esc_html(($failed?'Scan finished with errors; ':'Scan complete; ').count(array_filter($session['results'],static fn($r)=>!empty($r['ok']))).' successful MIME scope(s). Approval remains separate.').'</p>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="mad4b_context_wp_media_sync">';
        echo '<input type="hidden" name="media_mime_scope" value="1">';
        wp_nonce_field( 'mad4b_context_wp_media_sync' );
        foreach ( array( 'application/pdf' => 'PDF', 'text/plain' => 'TXT', 'text/markdown' => 'Markdown', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel XLSX', 'application/vnd.ms-excel.sheet.macroEnabled.12' => 'Excel XLSM', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX', 'text/csv' => 'Excel CSV' ) as $mime => $name )
            echo '<p><label><input type="checkbox" name="mime_types[]" value="' . esc_attr( $mime ) . '" checked> ' . esc_html( $name ) . '</label></p>';
        submit_button( 'Start bounded Media scan' );
        echo '</form></div>';
    }
    public static function attachment_record( $id ) {
        $post = get_post( (int) $id );
        if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status ) return new WP_Error( 'mad4b_media_missing', 'Attachment unavailable.' );
        $path = get_attached_file( (int) $id );
        $mime = (string) get_post_mime_type( (int) $id );
        if ( ! in_array( $mime, array( 'application/pdf', 'text/plain', 'text/markdown', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel.sheet.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/csv' ), true ) ) return new WP_Error( 'mad4b_media_mime_unsupported', 'Unsupported Context MIME type.' );
        if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > 10485760 )
            return new WP_Error( 'mad4b_media_file_unavailable', 'Attachment must be readable and at most 10 MiB.',
                array( 'attachment_id'=>(int)$id,'mime_type'=>$mime,'retry'=>'repair_attachment_acl_or_file_then_rescan_scope' ) );
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
            if ( ! class_exists( 'MAD4B_SCP_Context_Local_Document_Normalizer' ) || ! method_exists( 'MAD4B_SCP_Context_Local_Document_Normalizer', 'normalize_local_spreadsheet_bytes' ) )
                return new WP_Error( 'mad4b_media_excel_parser_unavailable', 'Shared local workbook normalizer unavailable.' );
            $parsed = MAD4B_SCP_Context_Local_Document_Normalizer::normalize_local_spreadsheet_bytes( $mime, $binary );
            if ( is_wp_error( $parsed ) ) return $parsed;
            if ( ! is_array( $parsed ) || true !== ( $parsed['complete'] ?? null ) || '' === trim( (string) ( $parsed['content'] ?? '' ) ) )
                return new WP_Error( 'mad4b_media_excel_incomplete', 'Excel workbook normalization is incomplete.' );
            return array( 'normalized_text' => (string) $parsed['content'], 'content_complete' => true,
                'content_bytes' => strlen( $binary ), 'normalization_status' => 'ready',
                'normalization_reason' => 'local_excel_structured' );
        }
        if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime ) {
            if(!class_exists('MAD4B_SCP_Context_Local_Document_Normalizer'))
                return new WP_Error('mad4b_media_docx_parser_unavailable','Local DOCX normalizer unavailable.');
            $parsed=MAD4B_SCP_Context_Local_Document_Normalizer::normalize_local_docx_bytes($mime,$binary);
            if(is_wp_error($parsed))return $parsed;
            if(!is_array($parsed)||empty($parsed['complete'])||''===trim((string)($parsed['content']??'')))
                return new WP_Error('mad4b_media_docx_incomplete','DOCX has no complete text content.');
            return array('normalized_text'=>(string)$parsed['content'],'content_complete'=>true,
                'content_bytes'=>strlen($binary),'normalization_status'=>'ready',
                'normalization_reason'=>'local_docx_text');
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
            if ( ! class_exists( 'MAD4B_SCP_Context_Local_Document_Normalizer' ) || ! method_exists( 'MAD4B_SCP_Context_Local_Document_Normalizer', 'normalize_local_pdf_bytes' ) )
                return new WP_Error( 'mad4b_media_pdf_parser_unavailable', 'Shared local PDF text normalizer unavailable.' );
            $parsed = MAD4B_SCP_Context_Local_Document_Normalizer::normalize_local_pdf_bytes( $binary );
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
    /**
     * Certified, read-only Context readback. Registry stores the SHA256 of the
     * raw attachment, not the extracted-text hash used by Google Drive.
     * This is intentionally unable to modify a file or approve an asset.
     */
    public static function read_context_asset( $asset_id ) {
        if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) )
            return new WP_Error( 'mad4b_media_context_authority_missing', 'Context registry unavailable.' );
        $asset = MAD4B_SCP_Context_Authority::asset( (string) $asset_id );
        if ( ! $asset ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found.' );
        if ( ( $asset['status'] ?? '' ) !== 'ready' || empty( $asset['content_complete'] ) )
            return new WP_Error( 'mad4b_context_asset_not_ready', 'Context asset is not ready or its normalized content is incomplete.' );
        $source = MAD4B_SCP_Context_Authority::source( (string) ( $asset['source_id'] ?? '' ) );
        if ( ! is_array( $source ) || ( $source['provider'] ?? '' ) !== 'wordpress_media' ||
             ( $asset['provider'] ?? '' ) !== 'wordpress_media' ||
             (string)( $source['source_id'] ?? '' ) !== (string)( $asset['source_id'] ?? '' ) )
            return new WP_Error( 'mad4b_media_context_source_invalid', 'Media asset is not bound to a trusted source.' );
        // Context Authority already restricts the returned source/asset to the
        // active Site/Brand. Retain an exact second identity check.
        foreach ( array( 'site_uuid', 'brand_id' ) as $key ) {
            if ( empty( $source[$key] ) || ! hash_equals( (string)$source[$key], (string)( $asset[$key] ?? '' ) ) )
                return new WP_Error( 'mad4b_media_context_scope_mismatch', 'Media asset belongs to another Site/Brand.' );
        }
        $id = (string) ( $asset['file_id'] ?? '' );
        if ( ! ctype_digit( $id ) || (int)$id < 1 )
            return new WP_Error( 'mad4b_media_context_file_id_invalid', 'Attachment identity is invalid.' );
        if ( ! hash_equals( hash( 'sha256', (string)$source['source_id'] . '|' . $id ), (string)( $asset['asset_id'] ?? '' ) ) )
            return new WP_Error( 'mad4b_media_context_asset_id_invalid', 'Attachment source identity is inconsistent.' );
        $record = self::attachment_record( (int)$id );
        if ( is_wp_error( $record ) ) return $record;
        if ( ! hash_equals( (string)( $asset['mime_type'] ?? '' ), (string)$record['mimeType'] ) )
            return new WP_Error( 'mad4b_media_context_mime_drift', 'Attachment MIME changed since the approved scan.' );
        if ( ! hash_equals( (string)( $asset['version'] ?? '' ), (string)$record['modifiedTime'] ) )
            return new WP_Error( 'mad4b_media_context_version_drift', 'Attachment version changed; refresh and review it.' );
        $registered = strtolower( (string)( $asset['content_hash'] ?? '' ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $registered ) || ! hash_equals( $registered, $record['content_hash'] ) )
            return new WP_Error( 'mad4b_media_context_hash_drift', 'Attachment bytes changed; rescan and reapprove the asset.' );
        $path = get_attached_file( (int)$id );
        $uploads = wp_get_upload_dir();
        $uploads_root = realpath( (string)( $uploads['basedir'] ?? '' ) );
        $actual_path = is_string( $path ) ? realpath( $path ) : false;
        if ( ! is_string($uploads_root) || ! is_string($actual_path)
             || 0 !== strpos( $actual_path, rtrim($uploads_root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR ) )
            return new WP_Error( 'mad4b_media_context_path_outside_uploads', 'Attachment path is outside the authorized Uploads directory.' );
        // Read the bounded exact bytes again to catch races between metadata and file load.
        $binary = file_get_contents( $actual_path, false, null, 0, 10485761 );
        if ( ! is_string($binary) || strlen($binary)>10485760 || ! hash_equals($registered, hash('sha256',$binary)) )
            return new WP_Error( 'mad4b_media_context_changed_during_read', 'Attachment changed during the authorized read.' );
        $normalized = self::normalize_bytes( $record['mimeType'], $binary );
        if ( is_wp_error($normalized) ) return $normalized;
        if ( empty($normalized['content_complete']) || '' === trim((string)($normalized['normalized_text']??'')) )
            return new WP_Error('mad4b_media_context_normalization_incomplete','Attachment text is not complete.');
        $text = (string)$normalized['normalized_text'];
        return array(
            'contract'=>'mad4b.context-asset-read.v2',
            'asset_id'=>(string)$asset['asset_id'],
            'source_id'=>(string)$source['source_id'],
            'file_id'=>$id,
            'content'=>$text,
            'bytes'=>strlen($text),
            'content_complete'=>true,
            'content_sha256'=>hash('sha256',$text),
            'source_raw_sha256'=>$registered,
            'mime_type'=>$record['mimeType'],
            'observed_at'=>gmdate('c'),
        );
    }
    /** Complete inventory of eligible attachments within an explicit MIME scope. */
    public static function inventory( array $mime_types = array( 'application/pdf', 'text/plain', 'text/markdown', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel.sheet.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/csv' ), $source_id = '' ) {
        $allowed = array( 'application/pdf', 'text/plain', 'text/markdown', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel.sheet.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/csv' );
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
            if ( is_wp_error( $record ) )
                return new WP_Error( $record->get_error_code(), $record->get_error_message(),
                    array_merge( (array)$record->get_error_data(),
                        array('attachment_id'=>(int)$id,'successful_count'=>count($assets),'mime_scope'=>$mime_types,'scan_complete'=>false) ) );
            $asset_id = hash( 'sha256', (string) $source_id . '|' . (string) $id );
            $prior = $known[ $asset_id ] ?? array();
            if ( $prior && ! empty( $prior['content_complete'] ) && ! empty( $prior['content_hash'] )
                && hash_equals( (string) $prior['content_hash'], (string) $record['content_hash'] )
                && hash_equals( (string) ( $prior['version'] ?? '' ), (string) $record['modifiedTime'] ) ) {
                $record['reuse_existing'] = true;
                $record['content_complete'] = true;
            } else {
                $record = self::normalize_attachment_record( $record );
                if ( is_wp_error( $record ) )
                    return new WP_Error( $record->get_error_code(), $record->get_error_message(),
                        array_merge( (array)$record->get_error_data(),
                            array('attachment_id'=>(int)$id,'successful_count'=>count($assets),'mime_scope'=>$mime_types,'scan_complete'=>false) ) );
            }
            $assets[] = $record;
        }
        return array( 'assets' => $assets, 'complete' => true, 'content_complete' => count( array_filter( $assets, static function ( $asset ) { return ! empty( $asset['content_complete'] ); } ) ) === count( $assets ), 'scoped_inventory' => true,
            'mime_types' => $mime_types, 'started_at' => gmdate( 'c' ), 'completed_at' => gmdate( 'c' ) );
    }
    /**
     * Exact 1–25 attachment merge; does NOT scan every file with the same MIME.
     * All candidates must normalize before the first registry mutation.
     * Idempotent retries update only these exact file identities.
     */
    public static function sync_ids( array $ids ) {
        if ( ! self::allowed() ) return new WP_Error('mad4b_media_denied','Not authorized.');
        $ids = array_values(array_unique(array_map('absint',$ids)));
        if (!$ids || count($ids)>25 || in_array(0,$ids,true))
            return new WP_Error('mad4b_media_id_limit','Select 1–25 valid attachment IDs.');
        $records=array();
        foreach($ids as $id){
            $record=self::attachment_record($id);
            if(is_wp_error($record))return $record;
            $normalized=self::normalize_attachment_record($record);
            if(is_wp_error($normalized))return $normalized;
            $records[]=$normalized;
        }
        $source=MAD4B_SCP_Context_Authority::upsert_source(array(
            'provider'=>'wordpress_media','mode'=>'governed',
            'external_root_id'=>self::ROOT,'label'=>'WordPress Media Library',
            'write_policy'=>'read_only','recursive'=>false));
        if(is_wp_error($source))return $source;
        $source_id=(string)($source['source_id']??'');
        if(''===$source_id)return new WP_Error('mad4b_media_source_result_invalid','Source ID unavailable.');
        $updated=array();
        foreach($records as $record){
            // Fail closed on file drift after normalization, before upsert.
            $fresh=self::attachment_record((int)$record['file_id']);
            if(is_wp_error($fresh)||!hash_equals((string)$record['content_hash'],(string)($fresh['content_hash']??'')))
                return new WP_Error('mad4b_media_changed_during_sync','Attachment changed during the selected-ID merge.',
                    array('completed'=>$updated,'retry'=>'retry_exact_ids_after_revalidation'));
            $previous=MAD4B_SCP_Context_Authority::asset(hash('sha256',$source_id.'|'.$record['file_id']));
            $added=MAD4B_SCP_Context_Authority::upsert_asset_from_provider($source_id,$record,is_array($previous)?$previous:array());
            if(is_wp_error($added))
                return new WP_Error($added->get_error_code(),$added->get_error_message(),
                    array('completed'=>$updated,'retry'=>'retry_exact_ids_after_revalidation'));
            $updated[]=(int)$record['file_id'];
        }
        return array('source_id'=>$source_id,'updated_attachment_ids'=>$updated,
            'refreshed'=>count($updated),'non_destructive'=>true,'scope'=>'exact_attachment_ids',
            'absence_mutation_allowed'=>false,'automatic_approval'=>false);
    }
    /** User+site bound short-lived scan session; no filenames or bytes in URL. */
    public static function scan_session( $session_id ) {
        if ( ! self::allowed() || ! preg_match('/^[a-f0-9]{32}$/D',(string)$session_id) ) return null;
        $session = get_transient('mad4b_wp_media_scan_'.(string)$session_id);
        if ( ! is_array($session) || (int)($session['user_id']??0)!==get_current_user_id()
             || ! hash_equals((string)($session['site_url']??''),home_url()) )
            return null;
        return $session;
    }
    public static function begin_scan( array $types ) {
        if ( ! self::allowed() ) return new WP_Error('mad4b_media_scan_denied','An authorized Media administrator is required.');
        $allowed = array('application/pdf','text/plain','text/markdown',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/csv');
        $types = array_values(array_unique(array_map('strval',$types)));
        if (!$types || array_diff($types,$allowed))return new WP_Error('mad4b_media_inventory_scope_invalid','Choose one or more supported MIME types.');
        $source = MAD4B_SCP_Context_Authority::upsert_source(array(
            'provider'=>'wordpress_media','mode'=>'governed',
            'external_root_id'=>self::ROOT,'label'=>'WordPress Media Library',
            'write_policy'=>'read_only','recursive'=>false,
        ));
        if(is_wp_error($source))return $source;
        $session_id = bin2hex(random_bytes(16));
        $session = array(
            'user_id'=>get_current_user_id(), 'site_url'=>home_url(),
            'source_id'=>(string)$source['source_id'],'mime_types'=>$types,
            'next'=>0,'results'=>array(),'created_at'=>gmdate('c'),'state'=>'SCANNING',
        );
        if(!set_transient('mad4b_wp_media_scan_'.$session_id,$session,30*MINUTE_IN_SECONDS))
            return new WP_Error('mad4b_media_scan_checkpoint_failed','Could not create a resumable scan checkpoint.');
        return array('scan_session'=>$session_id,'next'=>0,'total'=>count($types));
    }
    /** Non-destructive, bounded recovery when one attachment breaks a MIME scan.
     * At most 25 exact IDs are examined per request; invalid items are logged
     * individually, and only validated IDs are merged. No absence/deletion
     * evidence or auto-approval may be generated from this partial recovery.
     */
    private static function recover_mime_page( $mime, $after_id = 0 ) {
        global $wpdb;
        if ( ! method_exists($wpdb,'get_col') )
            return new WP_Error('mad4b_media_recovery_database_unavailable','Media recovery database reader unavailable.');
        $query=$wpdb->prepare("SELECT ID FROM {$wpdb->posts}
          WHERE post_type = 'attachment' AND post_status <> 'trash'
          AND post_mime_type = %s AND ID > %d ORDER BY ID ASC LIMIT 26",
          (string)$mime,max(0,(int)$after_id));
        $rows=$wpdb->get_col($query);
        if(!is_array($rows))
            return new WP_Error('mad4b_media_recovery_list_failed','Unable to read bounded attachment IDs.');
        $has_more=count($rows)>25;
        if($has_more)array_pop($rows);
        $valid=array();$errors=array();
        foreach($rows as $raw_id){
            $id=(int)$raw_id;
            if($id<1)continue;
            $record=self::attachment_record($id);
            if(!is_wp_error($record))$record=self::normalize_attachment_record($record);
            if(is_wp_error($record)){
                $errors[]=array('attachment_id'=>$id,'code'=>$record->get_error_code());
            }else $valid[]=$id;
        }
        if($valid){
            $merged=self::sync_ids($valid);
            if(is_wp_error($merged))return $merged;
        }
        return array('checked'=>count($rows),'recovered'=>count($valid),
            'errors'=>$errors,'after_id'=>$rows?(int)end($rows):max(0,(int)$after_id),
            'has_more'=>$has_more,'absence_proof'=>false,'automatic_approval'=>false);
    }
    /** Scan one complete MIME scope per request, never treating partial scopes as global deletion proof. */
    public static function scan_step( $session_id, $expected_step ) {
        $s=self::scan_session($session_id);
        if(!is_array($s))return new WP_Error('mad4b_media_scan_session_invalid','Scan expired or belongs to another operator.');
        $step=(int)$s['next'];
        if($step!==(int)$expected_step)return new WP_Error('mad4b_media_scan_step_stale','Scan progress changed; reload the current checkpoint.');
        if($step>=count($s['mime_types']))
            return array('scan_session'=>$session_id,'complete'=>($s['state']??'')==='COMPLETE',
                'finished'=>true,'has_errors'=>($s['state']??'')==='COMPLETE_WITH_ERRORS',
                'results'=>$s['results']);
        global $wpdb;
        $mutex='mad4b_wp_media_scan_'.substr(hash('sha256',$session_id),0,32);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$mutex))!==1)
            return new WP_Error('mad4b_media_scan_concurrent','A step is already running for this session.');
        try{
            $s=self::scan_session($session_id);
            if(!is_array($s)||(int)$s['next']!==$step) return new WP_Error('mad4b_media_scan_step_stale','Step changed while acquiring the lock.');
            $mime=(string)$s['mime_types'][$step];
            $recovering=isset($s['recovery'])&&is_array($s['recovery'])
                && (int)($s['recovery']['step']??-1)===$step;
            $result=$recovering
                ? new WP_Error('mad4b_media_partial_recovery_active','Continue bounded per-attachment recovery.')
                : MAD4B_SCP_Context_Source_Pipeline::scan_complete((string)$s['source_id'],array('mime_types'=>array($mime)));
            if(is_wp_error($result)){
                $cursor=$recovering?(int)($s['recovery']['after_id']??0):0;
                $before=$recovering?(int)($s['recovery']['checked']??0):0;
                $page=self::recover_mime_page($mime,$cursor);
                if(is_wp_error($page)||$before+($page['checked']??0)>1000){
                    $code=is_wp_error($page)?$page->get_error_code():'mad4b_media_recovery_capacity';
                    $e=is_wp_error($page)?(array)$page->get_error_data():array();
                    // Preserve the original exact attachment diagnostic when
                    // the bounded per-file recovery reader is unavailable.
                    if(!$recovering && empty($e['attachment_id']))
                        $e=array_merge($e,(array)$result->get_error_data());
                    $s['results'][]=array('mime'=>$mime,'ok'=>false,'code'=>$code,
                        'attachment_id'=>(int)($e['attachment_id']??0),
                        'errors'=>array(),'count'=>0,'retry'=>'repair_then_rescan_scope');
                    unset($s['recovery']);$s['next']=$step+1;
                }else{
                    $prior=$recovering?$s['recovery']:array('checked'=>0,'recovered'=>0,'errors'=>array(),'step'=>$step);
                    $sum=array(
                        'step'=>$step,'after_id'=>(int)$page['after_id'],
                        'checked'=>(int)$prior['checked']+(int)$page['checked'],
                        'recovered'=>(int)$prior['recovered']+(int)$page['recovered'],
                        'errors'=>array_merge($prior['errors'],$page['errors'])
                    );
                    if(!empty($page['has_more'])){
                        $s['recovery']=$sum;
                        $s['results'][]=array('mime'=>$mime,'ok'=>false,'code'=>'bounded_partial_recovery',
                            'count'=>$sum['recovered'],'errors'=>$page['errors'],
                            'retry'=>'continue_same_mime_step','absence_proof'=>false);
                    }else{
                        unset($s['recovery']);
                        $s['results'][]=array('mime'=>$mime,'ok'=>false,'code'=>'partial_recovery_needs_rescan',
                            'count'=>$sum['recovered'],'errors'=>$sum['errors'],
                            'retry'=>'repair_failed_attachments_and_rescan_scope','absence_proof'=>false);
                        $s['next']=$step+1;
                    }
                }
            }else{
                $s['results'][]=array('mime'=>$mime,'ok'=>true,'code'=>'complete',
                    'count'=>(int)($result['observed_asset_count']??0),
                    'source_asset_count'=>(int)($result['asset_count']??0));
                $s['next']=$step+1;
            }
            $s['state']=$s['next']<count($s['mime_types'])?'PARTIAL':
                (count(array_filter($s['results'],static fn($r)=>empty($r['ok'])))?'COMPLETE_WITH_ERRORS':'COMPLETE');
            $s['updated_at']=gmdate('c');
            if(!set_transient('mad4b_wp_media_scan_'.$session_id,$s,30*MINUTE_IN_SECONDS))
                return new WP_Error('mad4b_media_scan_checkpoint_failed','Could not persist the step result.');
            return array('scan_session'=>$session_id,'complete'=>$s['state']==='COMPLETE','finished'=>$s['next']>=count($s['mime_types']),
                'next'=>$s['next'],'total'=>count($s['mime_types']),'last'=>end($s['results']));
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$mutex));}
    }
    public static function submit() {
        if ( ! self::allowed() ) wp_die('Access denied','',array('response'=>403));
        check_admin_referer('mad4b_context_wp_media_sync');
        $session_id=isset($_POST['scan_session'])?sanitize_text_field(wp_unslash($_POST['scan_session'])):'';
        if(''!==$session_id){
            $expected=isset($_POST['expected_scan_step'])?absint($_POST['expected_scan_step']):-1;
            $result=self::scan_step($session_id,$expected);
        }else{
            $types=isset($_POST['mime_types'])&&is_array($_POST['mime_types'])
                ?array_map('sanitize_text_field',wp_unslash($_POST['mime_types'])):array();
            $result=self::begin_scan($types);
            if(!is_wp_error($result))$session_id=(string)$result['scan_session'];
        }
        $code=is_wp_error($result)?$result->get_error_code():'scan_checkpoint_saved';
        $query=array('page'=>'mad4b-wp-media-context','mad4b_media_result'=>sanitize_key($code));
        if(!is_wp_error($result))$query['scan_session']=$session_id;
        wp_safe_redirect(add_query_arg($query,admin_url('tools.php')));
        exit;
    }

}
MAD4B_SCP_WordPress_Media_Context::boot();
