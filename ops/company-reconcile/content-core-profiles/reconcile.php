<?php
/**
 * Site-neutral Content Experience profile reconciler, invoked with WP-CLI eval-file.
 * Default: read-only plan. Apply mode is an exact host-maintenance operation
 * for new profiles only. Never alters existing profiles, content, or grants.
 *
 * Input: blueprint.json next to this file.
 * Apply: MAD4B_CONTENT_CORE_PARITY_CONFIRM=APPLY EXACT CONTENT CORE PROFILES,
 *        MAD4B_CONTENT_CORE_PARITY_SITE_UUID=<exact enrolled UUID>,
 *        MAD4B_CONTENT_CORE_PARITY_ORIGIN=<exact canonical origin>
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "content_core_parity_requires_wp_cli\n" );
    exit( 1 );
}
$report = static function ( $row ) {
    echo wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
};
$fail = static function ( $code ) use ( $report ) {
    $report( array( 'state' => 'blocked', 'code' => $code ) );
    exit( 1 );
};
if ( get_current_user_id() !== 0 ) $fail( 'no_user_impersonation_required' );
if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
    || ! MAD4B_SCP_Site_Profile::configured()
    || ! MAD4B_SCP_Site_Profile::origin_enrolled()
    || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
    || ! MAD4B_SCP_Site_Profile::write_enabled()
    || ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) {
    $fail( 'exact_enrolled_profile_required' );
}
$environment = MAD4B_SCP_Site_Profile::current_environment();
if ( ! in_array( $environment, array( 'staging', 'production' ), true ) ) $fail( 'environment_not_supported' );
$origin = untrailingslashit( home_url() );
$uuid = MAD4B_SCP_Site_Profile::site_uuid();
$blueprint_path = __DIR__ . '/blueprint.json';
$raw = is_readable( $blueprint_path ) ? file_get_contents( $blueprint_path ) : false;
$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
if ( ! is_array( $data ) || ( $data['contract'] ?? '' ) !== 'mad4b.content-core-blueprint.v1'
    || ! is_array( $data['profiles'] ?? null ) || count( $data['profiles'] ) !== 5
    || ! empty( $data['policy']['write_grants_issued'] )
    || ! empty( $data['policy']['content_migration_allowed'] ) ) {
    $fail( 'blueprint_contract_invalid' );
}
$apply = getenv( 'MAD4B_CONTENT_CORE_PARITY_CONFIRM' ) === 'APPLY EXACT CONTENT CORE PROFILES';
if ( $apply ) {
    if ( $uuid === '' || ! hash_equals( (string) $uuid, (string) getenv( 'MAD4B_CONTENT_CORE_PARITY_SITE_UUID' ) )
        || $origin === '' || ! hash_equals( $origin, (string) getenv( 'MAD4B_CONTENT_CORE_PARITY_ORIGIN' ) ) ) {
        $fail( 'exact_host_target_assertion_failed' );
    }
    if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) ) {
        $fail( 'audit_storage_not_ready' );
    }
}
$allowed = array( 'page', 'post', 'properties', 'tours-and-activities', 'transportations' );
$option = MAD4B_SCP_Content_Experience_Profiles::OPTION;
$missing_marker = '__mad4b_content_core_profiles_absent__';
$before = get_option( $option, $missing_marker );
if ( $before !== $missing_marker && ! is_array( $before ) ) $fail( 'existing_profile_store_invalid' );
$existing = is_array( $before ) ? $before : array();
$planned = array();
$seen = array();
foreach ( $data['profiles'] as $profile ) {
    $slug = is_array( $profile ) ? (string) ( $profile['slug'] ?? '' ) : '';
    if ( ! in_array( $slug, $allowed, true ) || isset( $seen[ $slug ] )
        || ( $profile['post_type'] ?? '' ) !== $slug
        || empty( $profile['enabled'] ) || ( $profile['creation_status'] ?? '' ) !== 'draft'
        || ( $profile['live_update_mode'] ?? '' ) !== 'draft_first'
        || ( $profile['meta_mode'] ?? '' ) !== 'allowlist'
        || ( $profile['taxonomy_mode'] ?? '' ) !== 'allowlist'
        || ! post_type_exists( $slug ) ) {
        $fail( 'profile_blueprint_out_of_scope' );
    }
    $seen[ $slug ] = true;
    $attached = get_object_taxonomies( $slug );
    $configured = array_values( array_filter( (array) ( $profile['taxonomies'] ?? array() ),
        static function ( $taxonomy ) use ( $attached ) { return in_array( $taxonomy, $attached, true ); } ) );
    $excluded = array_values( array_diff( (array) ( $profile['taxonomies'] ?? array() ), $configured ) );
    if ( empty( $configured ) ) $fail( 'taxonomy_scope_empty_' . $slug );
    $site_profile = $profile;
    $site_profile['taxonomies'] = $configured;
    if ( isset( $existing[ $slug ] ) ) {
        if ( ! is_array( $existing[ $slug ] ) ) $fail( 'existing_profile_invalid_' . $slug );
        foreach ( $site_profile as $field => $value ) {
            $current_value = $existing[ $slug ][ $field ] ?? null;
            if ( in_array( $field, array( 'taxonomies', 'meta_keys', 'protected_meta_keys' ), true ) ) {
                $current_value = array_values( (array) $current_value );
                $value = array_values( (array) $value );
                sort( $current_value, SORT_STRING ); sort( $value, SORT_STRING );
            }
            if ( $current_value !== $value ) $fail( 'existing_profile_drift_' . $slug . '_' . $field );
        }
        $planned[ $slug ] = array( 'action' => 'preserve_existing', 'taxonomies_excluded' => $excluded );
        continue;
    }
    $input = array( 'profile' => $site_profile, 'expected_revision' => 0 );
    $plan = MAD4B_SCP_Content_Experience_Profiles::profile_plan( $input );
    if ( is_wp_error( $plan ) ) $fail( 'plan_' . $slug . '_' . $plan->get_error_code() );
    if ( empty( $plan['profile']['enabled'] ) || (int) $plan['profile']['revision'] !== 1 ) $fail( 'unexpected_revision_' . $slug );
    $planned[ $slug ] = array( 'action' => 'create_profile', 'input' => $input,
        'plan_sha256' => $plan['plan_sha256'], 'taxonomies_excluded' => $excluded );
}
if ( count( $seen ) !== count( $allowed ) || count( $planned ) !== 5 ) $fail( 'profile_set_not_exact' );
$summary = array( 'contract' => 'mad4b.content-core-parity-readback.v1',
    'environment' => $environment, 'target_profile_count' => 5, 'enabled_content_write_abilities' => 15,
    'create_count' => count( array_filter( $planned, static function ( $v ) { return $v['action'] === 'create_profile'; } ) ),
    'preserved_count' => count( array_filter( $planned, static function ( $v ) { return $v['action'] === 'preserve_existing'; } ) ),
    'blueprint_sha256' => hash( 'sha256', (string) $raw ),
    'mode' => $apply ? 'apply' : 'plan', 'grant_mutation_performed' => false,
    'content_mutation_performed' => false );
if ( ! $apply ) { $report( $summary ); exit( 0 ); }
foreach ( $planned as $slug => $info ) {
    if ( $info['action'] !== 'create_profile' ) continue;
    $input = $info['input'];
    $input['plan_sha256'] = $info['plan_sha256'];
    $result = MAD4B_SCP_Content_Experience_Profiles::profile_apply( $input );
    if ( is_wp_error( $result ) || empty( $result['applied'] ) ) {
        if ( $before === $missing_marker ) delete_option( $option );
        else update_option( $option, $before, false );
        MAD4B_SCP_Content_Experience_Profiles::reset_request_cache();
        $fail( 'apply_failed_rolled_back_' . $slug );
    }
}
$after = get_option( $option, array() );
if ( ! is_array( $after ) || count( $after ) < 5 ) {
    if ( $before === $missing_marker ) delete_option( $option );
    else update_option( $option, $before, false );
    MAD4B_SCP_Content_Experience_Profiles::reset_request_cache();
    $fail( 'readback_failed_rolled_back' );
}
$summary['state'] = 'profiles_applied';
$summary['postapply_readback_ready'] = true;
$report( $summary );
