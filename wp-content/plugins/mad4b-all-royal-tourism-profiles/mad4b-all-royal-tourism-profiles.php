<?php
/**
 * Plugin Name: MAD4B — All Royal Tourism Profiles
 * Description: Site-specific WordPress user/profile relations for All Royal Egypt. Not a generic MAD4B Control Plane feature.
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class MAD4B_All_Royal_Tourism_Profiles {
    const CONTRACT = 'allroyal.tourism-profiles.v1';
    const CONFIG = 'allroyal_tourism_profile_mapping_v1';
    const KEY = '_allroyal_profile_user_id';
    const ATTRIBUTES = '_allroyal_profile_attributes';
    const EXTERNAL_KEY = '_allroyal_profile_external_key';
    const USER_KEY_PREFIX = '_allroyal_profile_';
    const DOMAINS = array( 'staging.allroyalegypt.com', 'allroyalegypt.com', 'www.allroyalegypt.com' );

    public static function init() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 30 );
    }
    private static function fail( $code, $msg ) { return new WP_Error( $code, $msg ); }
    private static function site_matches() {
        $host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        return is_string( $host ) && in_array( strtolower( $host ), self::DOMAINS, true );
    }
    public static function can_write() {
        return self::site_matches() && current_user_can( 'create_users' ) && current_user_can( 'edit_users' )
            && current_user_can( 'edit_posts' )
            && function_exists( 'wp_get_environment_type' )
            && 'staging' === wp_get_environment_type();
    }
    private static function config() {
        $defaults = array(
            'revision' => 0,
            'profile_types' => array(
                'dmcs' => array( 'label' => 'DMCs', 'allowed_fields' => array() ),
                'drivers' => array( 'label' => 'Drivers', 'allowed_fields' => array() ),
                'guides' => array( 'label' => 'Tour Guides', 'allowed_fields' => array() ),
            ),
            'default_new_user_role' => 'subscriber',
        );
        $saved = get_option( self::CONFIG, array() );
        if ( ! is_array( $saved ) ) return self::fail( 'allroyal_profile_config_corrupt', 'Profile mapping configuration is invalid.' );
        $result = array_merge( $defaults, $saved );
        if ( ! is_array( $result['profile_types'] ) || count( $result['profile_types'] ) > 20 ) return self::fail( 'allroyal_profile_config_invalid', 'Profile types must be bounded.' );
        foreach ( $result['profile_types'] as $type => $descriptor ) {
            if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/', (string) $type ) || ! is_array( $descriptor ) ||
                ! isset( $descriptor['allowed_fields'] ) || ! is_array( $descriptor['allowed_fields'] ) ||
                count( $descriptor['allowed_fields'] ) > 40 ) return self::fail( 'allroyal_profile_mapping_invalid', 'Profile field mapping is invalid.' );
        }
        return $result;
    }
    private static function digest( $value ) {
        $json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    public static function model( $input = array() ) {
        if ( ! self::site_matches() ) return self::fail( 'allroyal_site_mismatch', 'Tourism profile addon is not enrolled for this website.' );
        if ( ! current_user_can( 'list_users' ) ) return self::fail( 'allroyal_users_read_denied', 'Profile-user registry inspection requires user-list access.' );
        $cfg = self::config();
        if ( is_wp_error( $cfg ) ) return $cfg;
        $types = array();
        foreach ( $cfg['profile_types'] as $type => $policy ) {
            $obj = get_post_type_object( $type );
            $types[ $type ] = array(
                'label' => isset( $policy['label'] ) ? $policy['label'] : $type,
                'registered' => (bool) $obj,
                'taxonomies' => $obj ? array_values( (array) get_object_taxonomies( $type ) ) : array(),
                'registered_meta_keys' => $obj && function_exists( 'get_registered_meta_keys' )
                    ? array_keys( (array) get_registered_meta_keys( 'post', $type ) ) : array(),
                'allowed_mapped_fields' => $policy['allowed_fields'],
                'connection_post_meta' => self::KEY,
                'connection_user_meta' => self::USER_KEY_PREFIX . $type . '_post_id',
            );
        }
        return array( 'contract' => self::CONTRACT, 'site_specific' => true,
            'config_revision' => (int) $cfg['revision'], 'config_sha256' => self::digest( $cfg ),
            'new_users_receive_nonprivileged_role' => true,
            'types' => $types, 'write_ready' => self::can_write(),
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $model = self::model();
        if ( is_wp_error( $model ) ) return $model;
        $type = isset( $input['profile_type'] ) ? sanitize_key( (string) $input['profile_type'] ) : '';
        if ( ! isset( $model['types'][ $type ] ) || ! $model['types'][ $type ]['registered'] )
            return self::fail( 'allroyal_profile_type_unknown', 'Requested tourism profile type is not registered and enabled on this site.' );
        $post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
        $user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
        if ( $post_id && ( get_post_type( $post_id ) !== $type || ! current_user_can( 'edit_post', $post_id ) ) )
            return self::fail( 'allroyal_profile_post_mismatch', 'Profile post does not belong to the requested type or actor.' );
        if ( $user_id && ! get_userdata( $user_id ) ) return self::fail( 'allroyal_profile_user_missing', 'Target WordPress user does not exist.' );
        $current_link = $post_id ? absint( get_post_meta( $post_id, self::KEY, true ) ) : 0;
        if ( $current_link && $user_id && $current_link !== $user_id )
            return self::fail( 'allroyal_profile_link_conflict', 'Profile is linked to another user; explicit unlink/reconcile is required.' );
        return array( 'contract' => self::CONTRACT, 'profile_type' => $type,
            'post_id' => $post_id, 'user_id' => $user_id, 'current_linked_user_id' => $current_link,
            'registered_taxonomies' => $model['types'][ $type ]['taxonomies'],
            'allowed_mapped_fields' => $model['types'][ $type ]['allowed_mapped_fields'],
            'requires_new_user' => !$user_id,
            'requires_new_profile_post' => !$post_id,
            'new_user_role' => 'subscriber',
            'expected_config_sha256' => $model['config_sha256'],
            'requires_exact_admin_write_authority' => true,
            'staging_write_ready' => $model['write_ready'],
            'next_action' => 'allroyal/profile-apply',
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function apply( $input = array() ) {
        if ( ! self::can_write() ) return self::fail( 'allroyal_profile_staging_admin_required', 'Exact All Royal Staging administrator grant required.' );
        $input = is_array( $input ) ? $input : array();
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        $expected = isset( $input['expected_config_sha256'] ) ? (string) $input['expected_config_sha256'] : '';
        if ( empty( $input['confirmed'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ||
            ! hash_equals( $plan['expected_config_sha256'], $expected ) )
            return self::fail( 'allroyal_profile_confirmation_stale', 'Confirm the exact site profile mapping before mutation.' );
        $type = $plan['profile_type'];
        $post_id = $plan['post_id'];
        $user_id = $plan['user_id'];
        $attributes = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array();
        $cfg = self::config();
        if ( is_wp_error( $cfg ) ) return $cfg;
        $allowed = $cfg['profile_types'][ $type ]['allowed_fields'];
        if ( count( $attributes ) > 40 || array_diff( array_keys( $attributes ), $allowed ) )
            return self::fail( 'allroyal_profile_attribute_unmapped', 'Only site-configured Meta Fields may be written.' );
        foreach ( $attributes as $k => $v ) if ( ! is_scalar( $v ) || strlen( (string) $v ) > 4000 )
            return self::fail( 'allroyal_profile_attribute_invalid', 'Profile classification field must be a bounded scalar.' );
        $email = isset( $input['email'] ) ? trim( (string) $input['email'] ) : '';
        $login = isset( $input['login'] ) ? sanitize_user( (string) $input['login'], true ) : '';
        if ( !$user_id && ( ! is_email( $email ) || '' === $login || strlen( $login ) > 60 || username_exists( $login ) || email_exists( $email ) ) )
            return self::fail( 'allroyal_profile_new_user_invalid', 'New WordPress account must have a unique login and email.' );
        if ( !$post_id && ( empty( $input['post_title'] ) || ! is_string( $input['post_title'] ) || strlen( $input['post_title'] ) > 200 ) )
            return self::fail( 'allroyal_profile_post_title_required', 'A bounded title is required to create a profile.' );
        // Site-only idempotent reservation, protecting the linked pair from
        // concurrent requests with the same external operation identity.
        $key = isset( $input['operation_key'] ) ? (string) $input['operation_key'] : '';
        if ( ! preg_match( '/^[A-Za-z0-9._:-]{12,128}$/', $key ) )
            return self::fail( 'allroyal_profile_operation_key_required', 'Provide a stable exact operation key.' );
        $lock = 'allroyal_prof_lock_' . hash( 'sha256', $type . '|' . $key );
        if ( ! add_option( $lock, array( 'at' => time(), 'post_id' => $post_id, 'user_id' => $user_id ), '', false ) )
            return self::fail( 'allroyal_profile_operation_inflight', 'The same profile operation is already running or needs reconciliation.' );
        $created_user = false;
        if ( !$user_id ) {
            $new = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email,
                'user_pass' => wp_generate_password( 32, true, true ), 'role' => 'subscriber' ) );
            if ( is_wp_error( $new ) ) return $new; // Keep reservation: do not replay a partial creation.
            $user_id = (int) $new; $created_user = true;
        }
        if ( !$post_id ) {
            $new = wp_insert_post( array( 'post_type' => $type, 'post_title' => sanitize_text_field( $input['post_title'] ),
                'post_status' => 'draft', 'post_author' => get_current_user_id() ), true );
            if ( is_wp_error( $new ) ) return $new;
            $post_id = (int) $new;
        }
        $prior = absint( get_post_meta( $post_id, self::KEY, true ) );
        $reverse_key = self::USER_KEY_PREFIX . $type . '_post_id';
        $reverse = absint( get_user_meta( $user_id, $reverse_key, true ) );
        if ( ( $prior && $prior !== $user_id ) || ( $reverse && $reverse !== $post_id ) )
            return self::fail( 'allroyal_profile_relation_collision', 'Another WordPress relation already exists; recovery requires explicit reconciliation.' );
        update_post_meta( $post_id, self::KEY, $user_id );
        update_user_meta( $user_id, $reverse_key, $post_id );
        update_post_meta( $post_id, self::EXTERNAL_KEY, hash( 'sha256', $type . '|' . $key ) );
        foreach ( $attributes as $field => $value ) update_post_meta( $post_id, $field, $value );
        if ( absint( get_post_meta( $post_id, self::KEY, true ) ) !== $user_id ||
            absint( get_user_meta( $user_id, $reverse_key, true ) ) !== $post_id )
            return self::fail( 'allroyal_profile_relation_readback_failed', 'Exact bi-directional WordPress relation was not confirmed.' );
        if ( $created_user && function_exists( 'wp_send_new_user_notifications' ) ) wp_send_new_user_notifications( $user_id, 'user' );
        update_option( $lock, array( 'completed' => true, 'user_id' => $user_id, 'post_id' => $post_id ), false );
        return array( 'contract' => self::CONTRACT, 'profile_type' => $type,
            'user_id' => $user_id, 'post_id' => $post_id, 'user_created' => $created_user,
            'relation_confirmed' => true, 'profile_status' => 'draft',
            'mutation_performed' => true, 'production_mutation' => false );
    }
    public static function register() {
        if ( ! self::site_matches() || ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array(
            'allroyal/profile-model' => array( 'model', 'read' ),
            'allroyal/profile-plan' => array( 'plan', 'read' ),
            'allroyal/profile-apply' => array( 'apply', 'write' ),
        ) as $name => $def ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, array(
                'label' => 'All Royal Tourism Profile ' . $def[0],
                'description' => 'All Royal site-specific WordPress users and tourism CPT relations.',
                'category' => 'mad4b-' . $def[1],
                'execute_callback' => array( __CLASS__, $def[0] ),
                'permission_callback' => 'apply' === $def[0]
                    ? array( __CLASS__, 'can_write' ) : function () { return current_user_can( 'list_users' ); },
                'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            ) );
        }
    }
}
MAD4B_All_Royal_Tourism_Profiles::init();
