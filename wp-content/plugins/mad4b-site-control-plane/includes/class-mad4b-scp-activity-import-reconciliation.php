<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP04 read-only, source-hash-bound native WP post/meta reconciliation.
 * Never resolve a source ID to a WordPress post ID by guess or spreadsheet row.
 * JetEngine CCT/non-post storage requires a separate certified provider driver.
 */
final class MAD4B_SCP_Activity_Import_Reconciliation {
    const CONTRACT = 'mad4b.import-reconciliation-plan.v1';
    private static function err( $code, $message ) { return new WP_Error( $code, $message ); }
    private static function digest( $value ) {
        $json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) || ! current_user_can( 'manage_options' ) ||
            ! class_exists( 'MAD4B_SCP_Activity_Import_Snapshot' ) )
            return self::err( 'mad4b_import_reconciliation_denied', 'Enrolled admin and exact source required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $sha = isset( $input['snapshot_sha256'] ) ? (string) $input['snapshot_sha256'] : '';
        $start = isset( $input['start_index'] ) ? $input['start_index'] : 0;
        $page_size = isset( $input['page_size'] ) ? $input['page_size'] : 25;
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) ||
            ! is_int( $start ) || $start < 0 || $start > 500 ||
            ! is_int( $page_size ) || $page_size < 1 || $page_size > 25 )
            return self::err( 'mad4b_import_reconciliation_range', 'Exact snapshot and bounded integer page required.' );
        $loaded = MAD4B_SCP_Activity_Import_Snapshot::raw_snapshot( $slug, $sha );
        if ( is_wp_error( $loaded ) ) return $loaded;
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) ||
            empty( $profile['post_type'] ) || ! function_exists( 'get_post_type_object' ) ||
            ! get_post_type_object( $profile['post_type'] ) )
            return self::err( 'mad4b_import_reconcile_post_type_missing', 'Registered CPT destination required.' );
        $contract = MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile );
        $policy = isset( $contract['validation'] ) ? $contract['validation'] : array();
        $meta_key = isset( $policy['destination_identity_meta_key'] ) ?
            (string) $policy['destination_identity_meta_key'] : '';
        if ( ! $meta_key || ! in_array( $meta_key, (array) $profile['meta_keys'], true ) )
            return self::err( 'mad4b_import_identity_registry_not_configured',
                'Explicit WordPress destination Meta key required for stable external identity.' );
        $fresh = MAD4B_SCP_Activity_Import_Review::plan( $loaded['input'] );
        if ( is_wp_error( $fresh ) || ! isset( $loaded['receipt']['plan']['plan_sha256'] ) ||
            ! isset( $fresh['plan_sha256'] ) ||
            ! hash_equals( $loaded['receipt']['plan']['plan_sha256'],
                $fresh['plan_sha256'] ) )
            return self::err( 'mad4b_import_reconciliation_stale',
                'Source, policy or Profile revision changed since encrypted intake.' );
        if ( (string) $loaded['receipt']['plan']['profile_revision'] !==
             (string) $profile['revision'] ||
            (string) $loaded['receipt']['plan']['authority_sha256'] !==
             (string) $profile['authority_sha256'] )
            return self::err( 'mad4b_import_reconciliation_profile_drift',
                'Profile identity changed since snapshot.' );
        $rows = $loaded['input']['rows'];
        $count = count( $rows );
        if ( $start >= $count )
            return self::err( 'mad4b_import_reconciliation_empty_page', 'Page start exceeds source row count.' );
        $end = min( $count, $start + $page_size );
        $map = $policy['field_mapping'];
        $results = array();
        $counts = array( 'matched' => 0, 'different' => 0,
            'missing' => 0, 'ambiguous' => 0, 'unverified' => 0 );
        for ( $i = $start; $i < $end; $i++ ) {
            $row = $rows[ $i ];
            $source_id = (string) $row[ $policy['identity_field'] ];
            // Query only exact, scoped post type and a site-approved external
            // identity Meta key. A title, row offset or source "ID" is never
            // silently assumed to equal WordPress post_id.
            $posts = get_posts( array( 'post_type' => $profile['post_type'],
                'post_status' => array( 'publish', 'draft', 'pending', 'private',
                    'future', 'trash', 'inherit' ),
                'meta_key' => $meta_key,
                'meta_value' => $source_id, 'meta_compare' => '=',
                'numberposts' => 2, 'fields' => 'ids',
                'suppress_filters' => true ) );
            if ( ! is_array( $posts ) ) {
                $status = 'unverified'; $details = array();
            } elseif ( count( $posts ) > 1 ) {
                $status = 'ambiguous'; $details = array();
            } elseif ( ! $posts ) {
                $status = 'missing'; $details = array();
            } else {
                $post_id = (int) $posts[0];
                $details = array();
                // Database collations can be case-insensitive: re-read the
                // canonical destination ID and refuse a case-changed match.
                $observed_identity = get_post_meta( $post_id, $meta_key, true );
                if ( ! is_scalar( $observed_identity ) ||
                    (string) $observed_identity !== $source_id ) {
                    $status = 'unverified';
                    $details[] = array( 'field' => $meta_key,
                        'reason' => 'destination_identity_not_exact' );
                } else {
                foreach ( $map as $source_col => $dest_meta ) {
                    // WPML import control fields must be verified by a WPML
                    // adapter, not guessed from arbitrary WordPress meta.
                    if ( 0 === strpos( $dest_meta, '_wpml_' ) ) continue;
                    $expected = isset( $row[ $source_col ] ) ?
                        (string) $row[ $source_col ] : '';
                    $actual_raw = get_post_meta( $post_id, $dest_meta, true );
                    if ( ! is_scalar( $actual_raw ) && null !== $actual_raw ) {
                        $details[] = array( 'field' => $dest_meta,
                            'reason' => 'non_scalar_destination_requires_adapter' );
                        continue;
                    }
                    $actual = (string) $actual_raw;
                    if ( $expected !== $actual )
                        $details[] = array( 'field' => $dest_meta,
                            'reason' => 'field_value_differs',
                            'expected_sha256' => hash( 'sha256', $expected ),
                            'actual_sha256' => hash( 'sha256', $actual ) );
                }
                $status = $details ? 'different' : 'matched';
                }
            }
            $counts[ $status ]++;
            $results[] = array( 'row_index' => $i,
                'source_identity_sha256' => hash( 'sha256', $source_id ),
                'status' => $status, 'field_issues' => $details );
        }
        $result = array( 'contract' => self::CONTRACT,
            'profile_slug' => $slug, 'snapshot_sha256' => $sha,
            'policy_sha256' => $fresh['policy_sha256'],
            'profile_revision' => $profile['revision'],
            'destination_post_type' => $profile['post_type'],
            'destination_identity_meta_key' => $meta_key,
            'start_index' => $start, 'next_index' => $end < $count ? $end : null,
            'page_rows' => $end - $start, 'total_rows' => $count,
            'counts' => $counts, 'items' => $results,
            'independent_post_meta_comparison' => true,
            'provider_execution_provenance_verified' => false,
            'wpml_group_links_verified' => false,
            'multi_provider_write_fence_verified' => false,
            'ready_for_automatic_import' => false,
            'read_only' => true, 'mutation_performed' => false );
        $result['receipt_sha256'] = self::digest( $result );
        return $result;
    }
}
