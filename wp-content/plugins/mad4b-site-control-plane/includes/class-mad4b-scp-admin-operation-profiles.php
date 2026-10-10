<?php
/**
 * Source-owned WordPress admin workflows with site-specific parameter profiles.
 * Profile metadata is not an executable script, approval, or capability grant.
 * Mutation stays in the original canonical WP Ability, invoked via MCP.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class MAD4B_SCP_Admin_Operation_Profiles {
    const CONTRACT = 'mad4b.admin-operation-profiles.v1';
    const OPTION = 'mad4b_scp_admin_operation_profiles_v1';
    const LOCK = 'mad4b_scp_admin_operation_profiles_lock_v1';
    const INVENTORY = 'mad4b/admin-operation-profiles-discover';
    const PLAN = 'mad4b/admin-operation-profile-plan';
    const APPLY = 'mad4b/admin-operation-profile-apply';
    const RESOLVE = 'mad4b/admin-operation-profile-resolve';
    const CONFIRM = 'SAVE EXACT STAGING ADMIN WORKFLOW PROFILE';
    const MAX_PROFILES = 64;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 40 );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array(
            array( self::INVENTORY, 'Discover Governed Admin Operations', 'discover', true, self::discover_schema() ),
            array( self::PLAN, 'Plan Site-Specific Admin Workflow', 'plan', true, self::configuration_schema() ),
            array( self::APPLY, 'Save Approved Admin Workflow Profile', 'apply', false, self::apply_schema() ),
            array( self::RESOLVE, 'Resolve Admin Form to MCP Handoff', 'resolve', true, self::resolve_schema() ),
        ) as $row ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $row[0] ) ) continue;
            wp_register_ability( $row[0], array(
                'label' => $row[1], 'description' => 'Exact source-owned operation, site profile and typed variables; no generic admin URL execution.',
                'category' => $row[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $row[2] ),
                'permission_callback' => $row[3] ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( __CLASS__, 'can_apply' ),
                'input_schema' => $row[4],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $row[3] ? 'read' : 'admin' ),
                    'annotations' => array( 'readonly' => $row[3], 'destructive' => ! $row[3], 'idempotent' => $row[3] ) ),
            ) );
        }
    }
    private static function discover_schema() {
        return array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array( 'operation_filter' => array( 'type' => 'string', 'maxLength' => 100 ) ) );
    }
    private static function configuration_schema() {
        return array( 'type' => 'object', 'additionalProperties' => false,
            'required' => array( 'operation_id', 'reason', 'profile' ), 'properties' => array(
                'operation_id' => array( 'type' => 'string', 'maxLength' => 120 ),
                'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
                'profile' => array( 'type' => 'object', 'additionalProperties' => false,
                    'required' => array( 'enabled', 'route_slug', 'approval_mode', 'defaults', 'variables' ),
                    'properties' => array(
                        'enabled' => array( 'type' => 'boolean' ),
                        'route_slug' => array( 'type' => 'string', 'maxLength' => 100 ),
                        'approval_mode' => array( 'type' => 'string', 'enum' => array( 'owner_confirm', 'manual_only' ) ),
                        'defaults' => array( 'type' => 'object', 'maxProperties' => 12 ),
                        'variables' => array( 'type' => 'object', 'maxProperties' => 12 ),
                    ) ),
            ) );
    }
    private static function apply_schema() {
        $schema = self::configuration_schema();
        $schema['required'][] = 'expected_plan_sha256';
        $schema['required'][] = 'confirmation';
        $schema['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
        $schema['properties']['confirmation'] = array( 'type' => 'string', 'enum' => array( self::CONFIRM ) );
        return $schema;
    }
    private static function resolve_schema() {
        return array( 'type' => 'object', 'additionalProperties' => false,
            'required' => array( 'operation_id', 'values' ),
            'properties' => array(
                'operation_id' => array( 'type' => 'string', 'maxLength' => 120 ),
                'values' => array( 'type' => 'object', 'maxProperties' => 12 ),
            ) );
    }
    private static function invalid( $code ) {
        return new WP_Error( 'mad4b_admin_profile_' . $code, 'Admin workflow profile denied: ' . $code );
    }
    public static function normalize( $config ) {
        if ( ! is_array( $config ) || array_diff( array_keys( $config ),
            array( 'enabled', 'route_slug', 'approval_mode', 'defaults', 'variables' ) ) ||
            ! is_bool( $config['enabled'] ?? null ) ||
            ! in_array( $config['approval_mode'] ?? null, array( 'owner_confirm', 'manual_only' ), true ) ||
            ! is_string( $config['route_slug'] ?? null ) ||
            ! preg_match( '/^[a-z0-9._-]{1,100}$/D', $config['route_slug'] ) ||
            ! is_array( $config['defaults'] ?? null ) ||
            ! is_array( $config['variables'] ?? null ) ||
            count( $config['variables'] ) > 12 || count( $config['defaults'] ) > 12 )
            return self::invalid( 'configuration_invalid' );
        $rules = array();
        foreach ( $config['variables'] as $key => $rule ) {
            if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $key ) ||
                preg_match( '/password|secret|token|key|credential|auth|cookie|nonce|callback|path|url|sql/i', $key ) ||
                ! is_array( $rule ) || array_diff( array_keys( $rule ), array( 'type', 'required', 'enum' ) ) ||
                ! in_array( $rule['type'] ?? null, array( 'string', 'integer', 'boolean' ), true ) ||
                ! is_bool( $rule['required'] ?? null ) )
                return self::invalid( 'variable_rule_invalid' );
            $en = $rule['enum'] ?? array();
            if ( ! is_array( $en ) || count( $en ) > 20 ) return self::invalid( 'variable_enum_invalid' );
            foreach ( $en as $item )
                if ( ! self::scalar_valid( $item, $rule['type'] ) ) return self::invalid( 'enum_value_invalid' );
            $rules[$key] = array( 'type' => $rule['type'], 'required' => $rule['required'], 'enum' => array_values( $en ) );
        }
        $defaults = array();
        foreach ( $config['defaults'] as $key => $value ) {
            if ( ! isset( $rules[ $key ] ) || ! self::scalar_valid( $value, $rules[ $key ]['type'] ) ||
                ( $rules[ $key ]['enum'] && ! in_array( $value, $rules[ $key ]['enum'], true ) ) )
                return self::invalid( 'default_invalid' );
            $defaults[$key] = $value;
        }
        ksort( $rules, SORT_STRING );
        ksort( $defaults, SORT_STRING );
        return array( 'enabled' => $config['enabled'],
            'route_slug' => $config['route_slug'],
            'approval_mode' => $config['approval_mode'],
            'defaults' => $defaults, 'variables' => $rules );
    }
    private static function scalar_valid( $value, $type ) {
        if ( 'string' === $type )
            return is_string( $value ) && strlen( $value ) <= 256 &&
                ! preg_match( '/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f]/', $value );
        if ( 'integer' === $type ) return is_int( $value ) && $value >= -1000000 && $value <= 1000000;
        return 'boolean' === $type && is_bool( $value );
    }
    private static function authorized_operation( $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9][a-z0-9._-]{1,119}$/D', $id ) ||
            ! class_exists( 'MAD4B_SCP_Operation_Registry' ) ) return self::invalid( 'operation_invalid' );
        $operation = MAD4B_SCP_Operation_Registry::operation( $id );
        if ( is_wp_error( $operation ) ) return $operation;
        $planner = (string) ( $operation['planner'] ?? '' );
        $executor = (string) ( $operation['executor'] ?? '' );
        if ( ! preg_match( '#^[a-z0-9._-]+/[a-z0-9._-]+$#D', $planner ) ||
            ! preg_match( '#^[a-z0-9._-]+/[a-z0-9._-]+$#D', $executor ) ||
            ! function_exists( 'wp_has_ability' ) ||
            ! wp_has_ability( $planner ) || ! wp_has_ability( $executor ) )
            return self::invalid( 'canonical_planner_or_executor_unavailable' );
        return $operation;
    }
    private static function routes() {
        return class_exists( 'MAD4B_SCP_Admin_Route_Registry' ) ?
            MAD4B_SCP_Admin_Route_Registry::routes() : array();
    }
    private static function site() {
        return class_exists( 'MAD4B_SCP_Site_Profile' ) ?
            MAD4B_SCP_Site_Profile::status() : array();
    }
    private static function stored( array $site ) {
        $state = get_option( self::OPTION, array() );
        if ( ! is_array( $state ) ||
            ! hash_equals( (string) ( $site['site_uuid'] ?? '' ), (string) ( $state['site_uuid'] ?? '' ) ) ||
            ! hash_equals( (string) ( $site['profile_digest'] ?? '' ), (string) ( $state['profile_digest'] ?? '' ) ) ||
            ! hash_equals( (string) ( $site['canonical_origin'] ?? '' ), (string) ( $state['origin'] ?? '' ) ) )
            return array();
        return is_array( $state['profiles'] ?? null ) ? $state['profiles'] : array();
    }
    /**
     * Passive runtime WordPress UI signals. Never fires admin_menu or plugin
     * callbacks merely to enumerate them. REST/MCP does not always construct
     * wp-admin menus, so missing menu signals are not an empty UI claim.
     */
    public static function observe_ui() {
        $out = array( 'registered_admin_routes' => array(), 'core_settings' => array(),
            'post_types' => array(), 'menu_routes' => array(),
            'admin_menu_materialized' => false, 'unknown_actions_remotely_executable' => false );
        if ( ! current_user_can( 'manage_options' ) ) return $out;
        foreach ( array_keys( self::routes() ) as $route ) {
            if ( preg_match( '/^[a-z0-9._-]{1,100}$/D', (string) $route ) )
                $out['registered_admin_routes'][] = $route;
            if ( count( $out['registered_admin_routes'] ) >= 96 ) break;
        }
        global $wp_registered_settings, $menu, $submenu;
        if ( is_array( $wp_registered_settings ) ) {
            foreach ( array_keys( $wp_registered_settings ) as $key ) {
                if ( is_string( $key ) && preg_match( '/^[a-zA-Z0-9._-]{1,100}$/D', $key ) )
                    $out['core_settings'][] = $key;
                if ( count( $out['core_settings'] ) >= 128 ) break;
            }
        }
        if ( function_exists( 'get_post_types' ) ) {
            $types = get_post_types( array( 'show_ui' => true ), 'names' );
            if ( is_array( $types ) ) {
                foreach ( array_slice( $types, 0, 96 ) as $type ) {
                    if ( is_string( $type ) && preg_match( '/^[a-z0-9_-]{1,50}$/D', $type ) )
                        $out['post_types'][] = $type;
                }
            }
        }
        $out['admin_menu_materialized'] = is_array( $menu ) && ! empty( $menu );
        if ( $out['admin_menu_materialized'] ) {
            $entries = array();
            foreach ( $menu as $row ) if ( is_array( $row ) ) $entries[] = $row;
            if ( is_array( $submenu ) ) foreach ( $submenu as $children ) {
                if ( is_array( $children ) ) foreach ( $children as $row )
                    if ( is_array( $row ) ) $entries[] = $row;
            }
            foreach ( $entries as $item ) {
                $slug = (string) ( $item[2] ?? '' );
                $cap = (string) ( $item[1] ?? '' );
                if ( ! preg_match( '/^[a-zA-Z0-9._-]{1,100}$/D', $slug ) ||
                    '' === $cap || ! current_user_can( $cap ) ) continue;
                if ( ! in_array( $slug, $out['menu_routes'], true ) ) $out['menu_routes'][] = $slug;
                if ( count( $out['menu_routes'] ) >= 96 ) break;
            }
        }
        return $out;
    }

    public static function discover( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation_filter' ) ) )
            return self::invalid( 'discover_input_invalid' );
        $filter = (string) ( $input['operation_filter'] ?? '' );
        if ( strlen( $filter ) > 100 || ( '' !== $filter && ! preg_match( '/^[a-z0-9._-]{1,100}$/D', $filter ) ) )
            return self::invalid( 'discover_filter_invalid' );
        $site = self::site();
        $catalog = class_exists( 'MAD4B_SCP_Operation_Registry' ) ?
            MAD4B_SCP_Operation_Registry::status() : array();
        $stored = self::stored( is_array( $site ) ? $site : array() );
        $rows = array();
        $registered_routes = self::routes();
        foreach ( array_slice( is_array( $catalog['operations'] ?? null ) ? $catalog['operations'] : array(), 0, 120 ) as $row ) {
            if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) ) continue;
            $id = $row['id'];
            if ( '' !== $filter && false === stripos( $id, $filter ) ) continue;
            $profile = $stored[ $id ] ?? null;
            $route = is_array( $profile ) ? (string) ( $profile['route_slug'] ?? '' ) : '';
            $rows[] = array( 'operation_id' => $id, 'target_kind' => $row['target_kind'] ?? '',
                'planner' => $row['planner'] ?? '', 'executor' => $row['executor'] ?? '',
                'route_slug' => $route,
                'route_registered' => '' !== $route && isset( $registered_routes[ $route ] ),
                'customized' => is_array( $profile ), 'enabled' => ! empty( $profile['enabled'] ),
                'risk' => $row['risk'] ?? 'unknown',
                'profile_resolve_ability' => self::RESOLVE,
                'execution' => 'original_governed_ability_only' );
        }
        return array( 'contract' => self::CONTRACT, 'site_uuid' => $site['site_uuid'] ?? '',
            'operations' => $rows, 'count' => count( $rows ),
            'observed_ui' => self::observe_ui(),
            'unregistered_screen_action_state' => 'adapter_required',
            'separate_plugin_update_recovery' => 'mad4b/plugin-update-recovery-discover',
            'configurable_site_variables' => true, 'unknown_admin_action_executable' => false,
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation_id', 'reason', 'profile' ) ) )
            return self::invalid( 'plan_input_invalid' );
        $id = $input['operation_id'] ?? '';
        $operation = self::authorized_operation( $id );
        if ( is_wp_error( $operation ) ) return $operation;
        $profile = self::normalize( $input['profile'] ?? null );
        if ( is_wp_error( $profile ) ) return $profile;
        $routes = self::routes();
        if ( ! isset( $routes[ $profile['route_slug'] ] ) )
            return self::invalid( 'route_not_registered' );
        $reason = trim( (string) ( $input['reason'] ?? '' ) );
        if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 ) return self::invalid( 'reason_invalid' );
        $site = self::site();
        if ( ! is_array( $site ) || (string) ( $site['configured_environment'] ?? '' ) !== 'staging' ||
            'staging' !== (string) ( $site['environment'] ?? '' ) ||
            empty( $site['authority_ready'] ) || empty( $site['origin_match'] ) ||
            empty( $site['profile_digest'] ) ||
            ( ! empty( $site['wordpress_environment_explicit'] ) &&
              'staging' !== (string) ( $site['wordpress_environment'] ?? '' ) ) )
            return self::invalid( 'staging_site_required' );
        $saved = self::stored( $site );
        $snapshot = hash( 'sha256', wp_json_encode( $saved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $plan = array( 'contract' => self::CONTRACT . '.plan.v1',
            'operation_id' => $id, 'reason' => $reason, 'profile' => $profile,
            'site_uuid' => (string) $site['site_uuid'],
            'site_digest' => (string) $site['profile_digest'],
            'origin' => (string) ( $site['canonical_origin'] ?? '' ),
            'actor' => get_current_user_id(), 'stored_snapshot_sha256' => $snapshot,
            'planner' => $operation['planner'], 'executor' => $operation['executor'],
            'approval_required' => true, 'additional_authority_created' => false,
            'auto_write_allowed' => false, 'production_allowed' => false );
        $plan['plan_sha256'] = hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return $plan;
    }
    public static function can_apply( $input = null ) {
        if ( ! current_user_can( 'manage_options' ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) ||
            ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_mutate() )
            return self::invalid( 'enrolled_admin_and_write_authority_required' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ||
            ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ||
            ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return self::invalid( 'owner_oauth_stepup_required' );
        return MAD4B_SCP_Authorization::authorize_mutation( self::APPLY, 'mad4b-admin', 'core', is_array( $input ) ? $input : array() );
    }
    public static function apply( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'operation_id', 'reason', 'profile', 'expected_plan_sha256', 'confirmation' ) ) ||
            ! is_string( $input['confirmation'] ?? null ) ||
            ! hash_equals( self::CONFIRM, $input['confirmation'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_plan_sha256'] ?? '' ) ) )
            return self::invalid( 'confirmation_required' );
        $allowed = self::can_apply( $input );
        if ( is_wp_error( $allowed ) || true !== $allowed ) return $allowed;
        $plan_input = array( 'operation_id' => $input['operation_id'],
            'reason' => $input['reason'], 'profile' => $input['profile'] );
        $plan = self::plan( $plan_input );
        if ( is_wp_error( $plan ) || ! hash_equals( (string) ( $plan['plan_sha256'] ?? '' ),
            $input['expected_plan_sha256'] ) ) return self::invalid( 'stale_plan' );
        $token = wp_generate_uuid4();
        if ( ! add_option( self::LOCK, $token, '', false ) ) return self::invalid( 'concurrent_change' );
        try {
            $fresh = self::plan( $plan_input );
            if ( is_wp_error( $fresh ) || ! hash_equals( $plan['plan_sha256'], $fresh['plan_sha256'] ) )
                return self::invalid( 'changed_after_lock' );
            $before = get_option( self::OPTION, array() );
            $site = self::site();
            $profiles = self::stored( $site );
            if ( count( $profiles ) >= self::MAX_PROFILES && ! isset( $profiles[ $plan['operation_id'] ] ) )
                return self::invalid( 'capacity_reached' );
            $profiles[ $plan['operation_id'] ] = $plan['profile'];
            ksort( $profiles, SORT_STRING );
            $next = array( 'site_uuid' => $plan['site_uuid'],
                'profile_digest' => $plan['site_digest'],
                'origin' => $plan['origin'], 'profiles' => $profiles );
            update_option( self::OPTION, $next, false );
            if ( get_option( self::OPTION, array() ) !== $next ) {
                update_option( self::OPTION, $before, false );
                return self::invalid( 'readback_failed' );
            }
            $site_after = self::site();
            if ( ! is_array( $site_after ) ||
                ! hash_equals( (string) ( $site_after['site_uuid'] ?? '' ), $plan['site_uuid'] ) ||
                ! hash_equals( (string) ( $site_after['profile_digest'] ?? '' ), $plan['site_digest'] ) ||
                ! hash_equals( (string) ( $site_after['canonical_origin'] ?? '' ), $plan['origin'] ) ) {
                update_option( self::OPTION, $before, false );
                return self::invalid( 'identity_changed_after_write' );
            }
            if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) {
                update_option( self::OPTION, $before, false );
                return self::invalid( 'audit_unavailable' );
            }
            $audit = MAD4B_SCP_Audit::record( 'mad4b/admin-operation-profile-applied',
                array( 'operation_id' => $plan['operation_id'],
                    'plan_sha256' => $plan['plan_sha256'],
                    'site_uuid' => $plan['site_uuid'],
                    'profile_digest' => $plan['site_digest'],
                    'production_authorized' => false ), 'success' );
            if ( is_wp_error( $audit ) ) {
                update_option( self::OPTION, $before, false );
                return self::invalid( 'audit_failed' );
            }
            return array( 'contract' => self::CONTRACT . '.receipt.v1',
                'state' => 'verified', 'operation_id' => $plan['operation_id'],
                'site_uuid' => $plan['site_uuid'], 'plan_sha256' => $plan['plan_sha256'],
                'readback_verified' => true, 'audit_recorded' => true,
                'profile_saved' => true, 'execution_performed' => false, 'production_mutation' => false );
        } finally {
            if ( get_option( self::LOCK, '' ) === $token ) delete_option( self::LOCK );
        }
    }
    public static function resolve( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation_id', 'values' ) ) ||
            ! is_array( $input['values'] ?? null ) || count( $input['values'] ) > 12 )
            return self::invalid( 'resolve_input_invalid' );
        $site = self::site();
        if ( ! is_array( $site ) ) return self::invalid( 'site_unavailable' );
        if ( 'staging' !== (string) ( $site['configured_environment'] ?? '' ) ||
            'staging' !== (string) ( $site['environment'] ?? '' ) ||
            empty( $site['authority_ready'] ) || empty( $site['origin_match'] ) )
            return self::invalid( 'site_no_longer_staging' );
        $profiles = self::stored( $site );
        $id = (string) ( $input['operation_id'] ?? '' );
        if ( ! isset( $profiles[ $id ] ) || empty( $profiles[ $id ]['enabled'] ) )
            return self::invalid( 'profile_not_enabled' );
        $operation = self::authorized_operation( $id );
        if ( is_wp_error( $operation ) ) return $operation;
        $profile = self::normalize( $profiles[ $id ] );
        if ( is_wp_error( $profile ) ) return $profile;
        if ( ! isset( self::routes()[ $profile['route_slug'] ] ) ) return self::invalid( 'route_disappeared' );
        $values = $profile['defaults'];
        foreach ( $input['values'] as $key => $value ) {
            if ( ! isset( $profile['variables'][ $key ] ) ) return self::invalid( 'unknown_field' );
            $values[$key] = $value;
        }
        foreach ( $profile['variables'] as $key => $rule ) {
            if ( ! array_key_exists( $key, $values ) ) {
                if ( $rule['required'] ) return self::invalid( 'required_field_missing' );
                continue;
            }
            if ( ! self::scalar_valid( $values[$key], $rule['type'] ) ||
                ( $rule['enum'] && ! in_array( $values[$key], $rule['enum'], true ) ) )
                return self::invalid( 'field_out_of_contract' );
        }
        if ( ! function_exists( 'wp_get_ability' ) ) return self::invalid( 'wordpress_ability_registry_unavailable' );
        $planner_ability = wp_get_ability( (string) $operation['planner'] );
        if ( ! $planner_ability || ! method_exists( $planner_ability, 'validate_input' ) )
            return self::invalid( 'planner_ability_unavailable' );
        // The actual registered WordPress Ability schema is authoritative;
        // configuration cannot silently inject unchecked values into it.
        $checked = $planner_ability->validate_input( $values );
        if ( is_wp_error( $checked ) || true !== $checked )
            return self::invalid( 'original_planner_schema_rejected_values' );
        $context = array( 'site_uuid' => $site['site_uuid'] ?? '',
            'site_digest' => $site['profile_digest'] ?? '',
            'operation_id' => $id,
            'planner' => $operation['planner'], 'executor' => $operation['executor'],
            'variables' => $values, 'approval_mode' => $profile['approval_mode'] );
        return array( 'contract' => self::CONTRACT . '.handoff.v1',
            'operation' => $context, 'handoff_sha256' => hash( 'sha256', wp_json_encode( $context ) ),
            'planner_input' => $values, 'planner_input_schema_verified' => true,
            'next_ability' => $operation['planner'],
            'execute_only_via_original_governed_mcp_ability' => true,
            'original_executor' => $operation['executor'],
            'auto_approve_write' => false, 'arbitrary_admin_url_execution' => false,
            'read_only' => true, 'mutation_performed' => false );
    }
}
