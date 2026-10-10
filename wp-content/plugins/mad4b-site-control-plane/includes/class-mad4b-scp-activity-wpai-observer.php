<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP05 Staging-only, observation-not-execution adapter for documented
 * WP All Import public hooks. Does NOT infer source provenance or write
 * completion from a hook, and never launches a PMXI import.
 */
final class MAD4B_SCP_Activity_WPAI_Observer {
    const CONTRACT = 'mad4b.wpai-run-observation.v1';
    private static function err( $code, $message ) { return new WP_Error( $code, $message ); }
    private static function ready() {
        return class_exists( 'MAD4B_SCP_Site_Profile' ) &&
            MAD4B_SCP_Site_Profile::configured() &&
            MAD4B_SCP_Site_Profile::origin_enrolled() &&
            MAD4B_SCP_Site_Profile::site_urls_match_enrollment() &&
            MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) );
    }
    private static function key( $job ) {
        return 'mad4b_wpai_observation_' .
            hash( 'sha256', MAD4B_SCP_Site_Profile::site_uuid() . '|' . $job );
    }
    private static function hash( $data ) {
        return hash( 'sha256', wp_json_encode( $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }
    public static function arm_plan( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::ready() ||
            ! is_array( $input ) )
            return self::err( 'mad4b_wpai_arm_denied', 'Enrolled Staging administrator required.' );
        $id = isset( $input['import_id'] ) ? $input['import_id'] : 0;
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $snapshot = isset( $input['snapshot_sha256'] ) ?
            (string) $input['snapshot_sha256'] : '';
        if ( ! is_int( $id ) || $id < 1 ||
            ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $snapshot ) )
            return self::err( 'mad4b_wpai_arm_invalid', 'Exact import ID, profile and source hash required.' );
        $receipt = MAD4B_SCP_Activity_Import_Snapshot::approval( $slug, $snapshot );
        if ( is_wp_error( $receipt ) ) return $receipt;
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) ||
            ! in_array( 'wp_all_import_wizard',
                (array) MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile )['enabled_modes'], true ) )
            return self::err( 'mad4b_wpai_mode_denied', 'Site policy must enable WP All Import wizard handoff.' );
        $plan = array( 'contract' => self::CONTRACT,
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_slug' => $slug, 'snapshot_sha256' => $snapshot,
            'approved_plan_sha256' => $receipt['plan_sha256'],
            'profile_authority_sha256' => $profile['authority_sha256'],
            'import_id' => $id, 'configured_import_verified' => false,
            'approved_file_source_config_verified' => false,
            'job_execution_authorized' => false,
            'requires_manual_wizard_source_verification' => true,
            'requires_staging_run_readback' => true,
            'read_only' => true, 'mutation_performed' => false );
        $plan['plan_sha256'] = self::hash( $plan );
        return $plan;
    }
    public static function arm( $input = array() ) {
        if ( ! is_array( $input ) || true !== ( isset( $input['confirmed'] ) ?
            $input['confirmed'] : false ) )
            return self::err( 'mad4b_wpai_arm_confirmation', 'Explicit staging observation confirmation required.' );
        $plan = self::arm_plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        $sha = isset( $input['plan_sha256'] ) ? (string) $input['plan_sha256'] : '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) ||
            ! hash_equals( $plan['plan_sha256'], $sha ) )
            return self::err( 'mad4b_wpai_arm_plan_drift', 'Exact reviewed observation plan required.' );
        $record = array( 'contract' => self::CONTRACT,
            'state' => 'armed_observation_only',
            'import_id' => $plan['import_id'],
            'profile_slug' => $plan['profile_slug'],
            'snapshot_sha256' => $plan['snapshot_sha256'],
            'approved_plan_sha256' => $plan['approved_plan_sha256'],
            'profile_authority_sha256' => $plan['profile_authority_sha256'],
            'arm_plan_sha256' => $sha,
            'armed_by' => (int) get_current_user_id(),
            'armed_at' => gmdate( 'c' ),
            'seen_start_at' => null, 'seen_end_at' => null,
            'post_save_events_observed' => 0, 'event_count_best_effort' => true,
            'source_provenance_verified' => false,
            'provider_write_fence_verified' => false,
            'wpml_links_verified' => false );
        $key = self::key( $plan['import_id'] );
        if ( ! add_option( $key, $record, '', false ) )
            return self::err( 'mad4b_wpai_observation_active', 'Existing observation must be archived before re-arming.' );
        if ( self::hash( get_option( $key, false ) ) !== self::hash( $record ) )
            return self::err( 'mad4b_wpai_observation_persistence_failed', 'Observation readback failed.' );
        return $record;
    }
    private static function update_event( $id, $event ) {
        if ( ! self::ready() || ! is_numeric( $id ) || (int) $id < 1 ) return;
        $key = self::key( (int) $id );
        $record = get_option( $key, false );
        if ( ! is_array( $record ) || ! isset( $record['contract'] ) ||
            self::CONTRACT !== $record['contract'] ) return;
        // These hooks are observational only: multiple workers can race.
        // Never use this counter, hook completion or WordPress option readback
        // as a source/provenance/transaction certificate.
        if ( 'before' === $event ) {
            $record['state'] = 'external_import_running_unverified';
            $record['seen_start_at'] = gmdate( 'c' );
        } elseif ( 'saved' === $event ) {
            $record['post_save_events_observed'] =
                min( 1000000, (int) $record['post_save_events_observed'] + 1 );
        } elseif ( 'after' === $event ) {
            $record['seen_end_at'] = gmdate( 'c' );
            $record['state'] = 'external_import_end_observed_unverified';
        } else return;
        update_option( $key, $record, false );
    }
    public static function on_before( $import_id ) {
        self::update_event( $import_id, 'before' );
    }
    public static function on_saved( $post_id, $xml_node, $is_update ) {
        if ( ! self::ready() || ! function_exists( 'wp_all_import_get_import_id' ) ) return;
        $import_id = wp_all_import_get_import_id();
        self::update_event( $import_id, 'saved' );
    }
    public static function on_after( $import_id, $import = null ) {
        self::update_event( $import_id, 'after' );
    }
    public static function status( $input = array() ) {
        if ( ! self::ready() || ! current_user_can( 'manage_options' ) ||
            ! is_array( $input ) || ! isset( $input['import_id'] ) ||
            ! is_int( $input['import_id'] ) || $input['import_id'] < 1 )
            return self::err( 'mad4b_wpai_status_denied', 'Exact Staging import ID required.' );
        $record = get_option( self::key( $input['import_id'] ), false );
        if ( ! is_array( $record ) || ! isset( $record['contract'] ) ||
            self::CONTRACT !== $record['contract'] )
            return array( 'contract' => self::CONTRACT,
                'state' => 'not_armed', 'post_writes_by_mad4b' => 0,
                'read_only' => true );
        $record['post_writes_by_mad4b'] = 0;
        $record['requires_independent_reconciliation'] = true;
        $record['read_only'] = true;
        return $record;
    }
    public static function register_hooks() {
        add_action( 'pmxi_before_xml_import', array( __CLASS__, 'on_before' ), 10, 1 );
        add_action( 'pmxi_saved_post', array( __CLASS__, 'on_saved' ), 10, 3 );
        add_action( 'pmxi_after_xml_import', array( __CLASS__, 'on_after' ), 10, 2 );
    }
}
if ( function_exists( 'add_action' ) )
    add_action( 'plugins_loaded', array( 'MAD4B_SCP_Activity_WPAI_Observer', 'register_hooks' ), 30 );
