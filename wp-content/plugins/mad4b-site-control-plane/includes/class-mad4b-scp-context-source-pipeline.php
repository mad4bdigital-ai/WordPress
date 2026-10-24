<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Provider-neutral context pipeline. Discovery is read-only; approval is NEVER implicit. */
final class MAD4B_SCP_Context_Source_Pipeline {
    const CONTRACT = 'mad4b.context-source-pipeline.v1';
    public static function plan( $source_id, array $input = array() ) {
        $source = MAD4B_SCP_Context_Authority::source( $source_id );
        if ( ! is_array( $source ) || empty( $source ) ) return new WP_Error( 'mad4b_pipeline_source_missing', 'Registered source is required.' );
        $provider = (string) ( $source['provider'] ?? '' );
        if ( ! in_array( $provider, array( 'wordpress_media', 'google_drive' ), true ) ) return new WP_Error( 'mad4b_pipeline_provider_unknown', 'Source provider unsupported.' );
        $records = array();
        if ( 'wordpress_media' === $provider ) {
            if ( ! class_exists( 'MAD4B_SCP_WordPress_Media_Context' ) ) return new WP_Error( 'mad4b_pipeline_provider_unavailable', 'WordPress Media provider unavailable.' );
            $ids = isset( $input['attachment_ids'] ) && is_array( $input['attachment_ids'] ) ? $input['attachment_ids'] : array();
            $ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
            if ( ! $ids || count( $ids ) > 25 || in_array( 0, $ids, true ) ) return new WP_Error( 'mad4b_pipeline_media_scope_invalid', 'Select 1–25 explicit attachment IDs.' );
            foreach ( $ids as $id ) {
                $record = MAD4B_SCP_WordPress_Media_Context::attachment_record( $id );
                if ( is_wp_error( $record ) ) return $record;
                $records[] = $record;
            }
        } else {
            // Existing Drive scanner retains its own OAuth, budget, continuation and integrity gates.
            if ( ! class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ) return new WP_Error( 'mad4b_pipeline_drive_unavailable', 'Drive provider unavailable.' );
            if ( empty( $input['allow_live_scan'] ) ) return new WP_Error( 'mad4b_pipeline_drive_scan_explicit', 'Explicit Drive scan intent is required.' );
            $scan = MAD4B_SCP_Google_Drive_Context::scan_folder( (string) $source['external_root_id'], ! empty( $source['recursive'] ) );
            if ( is_wp_error( $scan ) ) return $scan;
            if ( ! is_array( $scan ) || empty( $scan['complete'] ) || ! isset( $scan['assets'] ) || ! is_array( $scan['assets'] ) )
                return new WP_Error( 'mad4b_pipeline_drive_scan_incomplete', 'Incomplete Drive scan must not be published as complete inventory.' );
            $records = $scan['assets'];
        }
        $known = MAD4B_SCP_Context_Authority::assets();
        $changes = array();
        foreach ( $records as $record ) {
            $file_id = (string) ( $record['file_id'] ?? '' );
            $asset_id = hash( 'sha256', (string) $source_id . '|' . $file_id );
            $previous = $known[$asset_id] ?? array();
            $old_hash = (string) ( $previous['content_hash'] ?? '' );
            $new_hash = (string) ( $record['content_hash'] ?? '' );
            $changes[] = array( 'file_id'=>$file_id, 'asset_id'=>$asset_id,
                'action'=>empty( $previous ) ? 'new' : ( '' !== $new_hash && hash_equals( $old_hash, $new_hash ) ? 'unchanged' : 'changed' ) );
        }
        return array( 'contract'=>self::CONTRACT, 'source_id'=>$source_id, 'provider'=>$provider,
            'stages'=>array( 'discover'=>'complete', 'validate'=>'complete', 'sync'=>'pending', 'diff'=>'complete', 'review'=>'pending', 'approve'=>'manual_only' ),
            'changes'=>$changes, 'records'=>$records, 'destructive_changes'=>false,
            'source_fingerprint'=>hash( 'sha256', wp_json_encode( $source ) ) );
    }
    public static function sync( array $plan ) {
        if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_connect_user( get_current_user_id() ) )
            return new WP_Error( 'mad4b_pipeline_write_denied', 'Source sync requires an enrolled administrator.' );
        if ( ( $plan['contract'] ?? '' ) !== self::CONTRACT || ! is_array( $plan['records'] ?? null ) || count( $plan['records'] ) > 25 )
            return new WP_Error( 'mad4b_pipeline_plan_invalid', 'Invalid bounded source plan.' );
        $source_id = (string) ( $plan['source_id'] ?? '' );
        $source = MAD4B_SCP_Context_Authority::source( $source_id );
        if ( ! is_array( $source ) || empty( $source ) || ! hash_equals( (string) ( $plan['source_fingerprint'] ?? '' ), hash( 'sha256', wp_json_encode( $source ) ) ) )
            return new WP_Error( 'mad4b_pipeline_source_changed', 'Source changed after discovery. Replan required.' );
        if ( ( $plan['provider'] ?? '' ) !== ( $source['provider'] ?? '' ) ) return new WP_Error( 'mad4b_pipeline_provider_changed', 'Provider changed.' );
        $count = 0;
        foreach ( $plan['records'] as $record ) {
            if ( ! is_array( $record ) ) return new WP_Error( 'mad4b_pipeline_record_invalid', 'Invalid record.' );
            if ( 'wordpress_media' === $plan['provider'] ) {
                $fresh = MAD4B_SCP_WordPress_Media_Context::attachment_record( (int) ( $record['file_id'] ?? 0 ) );
                if ( is_wp_error( $fresh ) || ! hash_equals( (string)( $record['content_hash'] ?? '' ), (string)( is_array( $fresh ) ? ( $fresh['content_hash'] ?? '' ) : '' ) ) )
                    return new WP_Error( 'mad4b_pipeline_asset_changed', 'Attachment changed; replan required.' );
            }
            $result = MAD4B_SCP_Context_Authority::upsert_asset_from_provider( $source_id, $record );
            if ( is_wp_error( $result ) ) return $result;
            ++$count;
        }
        return array( 'synced'=>$count, 'review'=>'pending', 'approval'=>'not_performed', 'destructive_changes'=>false );
    }
}
