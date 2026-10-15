<?php
/**
 * Passive, bounded coverage inventory for WordPress admin operations.
 *
 * Collects registration metadata, never executes observed hooks or admin
 * callbacks. An observed route/screen/API endpoint never becomes permission
 * to change settings, publish content or install a plugin.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Admin_Surface_Coverage {
    const CONTRACT = 'mad4b.admin-surface-coverage.v1';
    const INVENTORY = 'mad4b/admin-surface-coverage';
    const BLUEPRINT = 'mad4b/admin-adapter-blueprint';
    const CONTROL_CENSUS = 'mad4b/admin-control-census';
    const CONTROL_KINDS = array( 'abilities', 'pages', 'menus', 'governed_operations',
        'admin_post', 'ajax', 'settings', 'rest_routes', 'content_types',
        'taxonomies', 'blocks', 'cron_hooks' );
    const MAX_PER_KIND = 96;
    const MAX_TOTAL = 512;
    const KINDS = array( 'governed_operations', 'admin_routes', 'admin_menus',
        'registered_settings', 'rest_routes', 'ajax_actions', 'admin_post_actions',
        'content_types', 'taxonomies', 'blocks', 'cron_hooks' );

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 41 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array(
            array( self::INVENTORY, 'Inventory WordPress Admin Operation Coverage', 'inventory',
                array( 'type' => 'object', 'additionalProperties' => false,
                    'properties' => array( 'include_details' => array( 'type' => 'boolean' ),
                        'scan_mode' => array( 'type' => 'string', 'enum' => array( 'fast', 'deep' ) ) ) ) ),
            array( self::CONTROL_CENSUS, 'Page through Registered UI and MCP Controls', 'control_census',
                array( 'type' => 'object', 'additionalProperties' => false,
                    'properties' => array(
                        'kind' => array( 'type' => 'string', 'enum' => self::CONTROL_KINDS ),
                        'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000 ),
                        'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 96 ),
                    ) ) ),
            array( self::BLUEPRINT, 'Propose Reviewed MCP Adapter for Missing Admin Operation', 'blueprint',
                array( 'type' => 'object', 'additionalProperties' => false,
                    'required' => array( 'surface_id', 'snapshot_sha256', 'purpose' ),
                    'properties' => array(
                        'surface_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                        'snapshot_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                        'purpose' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 240 ),
                        'scan_mode' => array( 'type' => 'string', 'enum' => array( 'fast', 'deep' ) ),
                    ) ) ),
        ) as $row ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $row[0] ) ) continue;
            wp_register_ability( $row[0], array(
                'label' => $row[1],
                'description' => 'Read-only capability inventory / source-reviewed typed adapter proposal. No automatic admin callbacks or grants.',
                'category' => 'mad4b-read', 'execute_callback' => array( __CLASS__, $row[2] ),
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => $row[3],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
            ) );
        }
    }

    private static function safe_key( $value ) {
        return is_string( $value ) && '' !== $value && strlen( $value ) <= 240 &&
            ! preg_match( '/[\\x00-\\x1f\\x7f]|[\\\\<>]/', $value );
    }

    private static function mask( $value ) {
        return preg_match( '/password|secret|token|api[_-]?key|credential|private[_-]?key|auth[_-]?code/i', $value )
            ? 'redacted:' . substr( hash( 'sha256', $value ), 0, 16 ) : $value;
    }

    private static function strategy( $kind ) {
        switch ( $kind ) {
            case 'governed_operations':
                return array( 'existing_governed_planner', 'original_ability_plan_apply' );
            case 'registered_settings':
                return array( 'wp_settings_schema_review', 'certified_setting_adapter' );
            case 'rest_routes':
                return array( 'rest_schema_and_permission_review', 'certified_wp_rest_adapter' );
            case 'ajax_actions':
            case 'admin_post_actions':
                return array( 'hook_effect_and_nonce_review', 'source_owned_ability_adapter' );
            case 'content_types':
            case 'taxonomies':
                return array( 'core_entity_schema_review', 'certified_entity_adapter' );
            case 'blocks':
                return array( 'block_attributes_and_side_effect_review', 'certified_block_adapter' );
            case 'cron_hooks':
                return array( 'job_idempotency_schedule_review', 'certified_job_adapter' );
            default:
                return array( 'screen_controls_and_capability_review', 'source_owned_ability_adapter' );
        }
    }

    /** Per-family checklist, not a silent authorization to use WordPress APIs. */
    private static function family_requirements( $kind ) {
        switch ( $kind ) {
            case 'registered_settings':
                return array( 'register_setting_source_ownership', 'rest_schema_and_sanitizer',
                    'nonsecret_field_allowlist', 'option_revision_and_readback', 'rollback_or_irreversible_effect' );
            case 'rest_routes':
                return array( 'rest_permission_callback_review', 'allowed_HTTP_methods_and_args_schema',
                    'authenticated_mcp_ability_wrapper', 'side_effect_and_idempotency_review', 'response_postconditions' );
            case 'ajax_actions':
            case 'admin_post_actions':
                return array( 'hook_callback_source_review', 'csrf_nonce_and_WP_capabilities',
                    'typed_input_and_output_contract', 'no_raw_form_or_AJAX_replay',
                    'governed_ability_wrapper_and_effect_readback' );
            case 'content_types':
            case 'taxonomies':
                return array( 'registered_entity_and_meta_schema', 'per_entity_capabilities',
                    'revision_and_relationship_integrity', 'status_transition_readback',
                    'trash_or_compensation_plan' );
            case 'blocks':
                return array( 'registered_block_attributes_schema', 'editor_serialization',
                    'dynamic_render_side_effect_policy', 'preview_frontend_parity', 'restore_previous_revision' );
            case 'cron_hooks':
                return array( 'named_job_ability_not_direct_cron', 'schedule_lease_and_idempotency',
                    'time_and_resource_budget', 'job_receipt_and_completion_probe', 'cancel_resume_policy' );
            case 'governed_operations':
                return array( 'registered_planner_schema', 'exact_executor_authority',
                    'owner_confirmation_if_mutation', 'fresh_postconditions_and_audit',
                    'rollback_or_compensation_validation' );
            default:
                return array( 'page_source_and_control_review', 'effective_WP_capability',
                    'registered_typed_MCP_adapter', 'exact_owner_authorization',
                    'effects_readback_and_compensation' );
        }
    }

    /** Pure reducer for independently testable discovery and trust boundaries. */
    public static function summarize( $observations, $site_identity = array(), $scan_mode = 'deep' ) {
        if ( ! is_array( $observations ) ) $observations = array();
        $rows = array();
        $counts = array();
        $truncated = array();
        foreach ( self::KINDS as $kind ) {
            $input = isset( $observations[$kind] ) && is_array( $observations[$kind] )
                ? $observations[$kind] : array();
            ksort( $input, SORT_STRING );
            $counts[$kind] = count( $input );
            $truncated[$kind] = count( $input ) > self::MAX_PER_KIND;
            foreach ( array_slice( $input, 0, self::MAX_PER_KIND, true ) as $name => $meta ) {
                if ( ! self::safe_key( $name ) ) continue;
                if ( ! is_array( $meta ) ) $meta = array();
                $id = hash( 'sha256', $kind . ':' . $name );
                $stage = 0;
                $explicit = false;
                if ( 'governed_operations' === $kind &&
                    true === ( $meta['planner_registered'] ?? null ) &&
                    true === ( $meta['executor_registered'] ?? null ) ) {
                    $stage = 2;
                    $explicit = true;
                } elseif ( ( 'registered_settings' === $kind || 'content_types' === $kind ||
                    'taxonomies' === $kind ) && ! empty( $meta['show_in_rest'] ) ) {
                    $stage = 1;
                } elseif ( 'rest_routes' === $kind && ! empty( $meta['has_schema'] ) ) {
                    $stage = 1;
                }
                $steps = self::strategy( $kind );
                $rows[] = array(
                    'surface_id' => $id, 'kind' => $kind, 'name' => self::mask( $name ),
                    'coverage_level' => $stage, 'coverage_state' =>
                        $explicit ? 'registered_governed_planner' : ( $stage > 0 ? 'schema_visible_adapter_required' : 'adapter_required' ),
                    'strategy' => $steps[0], 'next_adapter' => $steps[1],
                    'execution_authorized_by_observation' => false,
                    'operation_write_verified' => false,
                    'approval_required_for_mutation' => true,
                    'risks' => array( 'unknown_effects_until_adapter_review' ),
                );
                if ( count( $rows ) >= self::MAX_TOTAL ) break 2;
            }
        }
        // Bind both source identities AND classification metadata. Merely
        // hashing IDs would miss a settings schema or registration-state drift.
        $top_level = 0;
        foreach ( $rows as $row ) $top_level = max( $top_level, (int) $row['coverage_level'] );
        $identity = array(
            'site_uuid' => (string) ( $site_identity['site_uuid'] ?? '' ),
            'profile_digest' => (string) ( $site_identity['profile_digest'] ?? '' ),
            'origin' => (string) ( $site_identity['canonical_origin'] ?? '' ),
        );
        $fingerprint = hash( 'sha256', (string) wp_json_encode(
            array( 'contract' => self::CONTRACT, 'identity' => $identity,
                'actor_user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
                'scan_mode' => $scan_mode,
                'rows' => $rows, 'counts' => $counts ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) );
        return array(
            'contract' => self::CONTRACT, 'snapshot_sha256' => $fingerprint,
            'counts_by_kind' => $counts, 'truncated_by_kind' => $truncated,
            'scan_mode' => $scan_mode,
            'not_scanned_kinds' => 'fast' === $scan_mode ?
                array( 'rest_routes', 'ajax_actions', 'admin_post_actions', 'blocks', 'cron_hooks' ) : array(),
            'observed_count' => array_sum( $counts ), 'returned_count' => count( $rows ),
            'items' => $rows, 'highest_coverage_level' => $top_level,
            'ui_discovery_proves_execution' => false, 'arbitrary_admin_execution_supported' => false,
            'credentials_included' => false, 'read_only' => true,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }

    /** No wp-admin bootstrap or execution of third-party registration hooks. */
    public static function observations( $scan_mode = 'deep' ) {
        $sources = array_fill_keys( self::KINDS, array() );
        if ( ! current_user_can( 'manage_options' ) ) return $sources;
        if ( class_exists( 'MAD4B_SCP_Operation_Registry' ) ) {
            $reg = MAD4B_SCP_Operation_Registry::status();
            $known = is_array( $reg ) && is_array( $reg['operations'] ?? null ) ? $reg['operations'] : array();
            foreach ( $known as $op )
                if ( is_array( $op ) && self::safe_key( $op['id'] ?? null ) )
                    $sources['governed_operations'][$op['id']] = array(
                        'planner_registered' => $op['planner_registered'] ?? false,
                        'executor_registered' => $op['executor_registered'] ?? false,
                    );
        }
        if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry' ) ) {
            foreach ( MAD4B_SCP_Admin_Route_Registry::routes() as $slug => $route )
                if ( self::safe_key( $slug ) && is_array( $route ) &&
                    current_user_can( (string) ( $route['required_capability'] ?? 'do_not_grant' ) ) )
                    $sources['admin_routes'][$slug] = array();
        }
        global $wp_registered_settings, $menu, $submenu, $wp_filter, $wp_rest_server;
        if ( is_array( $wp_registered_settings ) ) foreach ( $wp_registered_settings as $name => $setting )
            if ( self::safe_key( $name ) )
                $sources['registered_settings'][$name] = array(
                    'show_in_rest' => is_array( $setting ) && ! empty( $setting['show_in_rest'] ) );
        if ( is_array( $menu ) ) {
            $entries = $menu;
            if ( is_array( $submenu ) ) foreach ( $submenu as $items )
                if ( is_array( $items ) ) $entries = array_merge( $entries, $items );
            foreach ( $entries as $entry )
                if ( is_array( $entry ) && self::safe_key( $entry[2] ?? null ) &&
                    current_user_can( (string) ( $entry[1] ?? 'do_not_grant' ) ) )
                    $sources['admin_menus'][$entry[2]] = array();
        }
        if ( function_exists( 'get_post_types' ) ) {
            $types = get_post_types( array( 'show_ui' => true ), 'objects' );
            foreach ( is_array( $types ) ? $types : array() as $name => $type )
                if ( self::safe_key( $name ) )
                    $sources['content_types'][$name] = array(
                        'show_in_rest' => is_object( $type ) && ! empty( $type->show_in_rest ) );
        }
        if ( function_exists( 'get_taxonomies' ) ) {
            $taxes = get_taxonomies( array( 'show_ui' => true ), 'objects' );
            foreach ( is_array( $taxes ) ? $taxes : array() as $name => $tax )
                if ( self::safe_key( $name ) )
                    $sources['taxonomies'][$name] = array(
                        'show_in_rest' => is_object( $tax ) && ! empty( $tax->show_in_rest ) );
        }
        if ( 'deep' === $scan_mode && class_exists( 'WP_Block_Type_Registry' ) ) {
            $blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();
            foreach ( is_array( $blocks ) ? $blocks : array() as $name => $block )
                if ( self::safe_key( $name ) ) $sources['blocks'][$name] = array();
        }
        if ( 'deep' === $scan_mode && is_array( $wp_filter ) ) foreach ( array_keys( $wp_filter ) as $hook ) {
            if ( ! is_string( $hook ) ) continue;
            // Anonymous AJAX endpoints are NOT authenticated admin actions.
            if ( 0 === strpos( $hook, 'wp_ajax_nopriv_' ) ) continue;
            if ( 0 === strpos( $hook, 'wp_ajax_' ) && self::safe_key( substr( $hook, 8 ) ) )
                $sources['ajax_actions'][substr( $hook, 8 )] = array();
            elseif ( 0 === strpos( $hook, 'admin_post_' ) && self::safe_key( substr( $hook, 11 ) ) )
                $sources['admin_post_actions'][substr( $hook, 11 )] = array();
        }
        // Do not instantiate a REST server or run rest_api_init in discovery.
        if ( 'deep' === $scan_mode && is_object( $wp_rest_server ) &&
            method_exists( $wp_rest_server, 'get_routes' ) ) {
            foreach ( $wp_rest_server->get_routes() as $route => $endpoints ) {
                if ( ! self::safe_key( $route ) ) continue;
                $has_schema = false;
                if ( is_array( $endpoints ) ) foreach ( $endpoints as $endpoint )
                    if ( is_array( $endpoint ) && ! empty( $endpoint['args'] ) ) {
                        $has_schema = true; break;
                    }
                $sources['rest_routes'][$route] = array( 'has_schema' => $has_schema );
            }
        }
        // Cron is metadata only. Never run, reschedule, unschedule or reveal args.
        if ( 'deep' === $scan_mode && function_exists( '_get_cron_array' ) ) {
            $events = _get_cron_array();
            if ( is_array( $events ) ) foreach ( array_slice( $events, 0, 100, true ) as $hooks )
                if ( is_array( $hooks ) ) foreach ( $hooks as $name => $events_by_hash )
                    if ( self::safe_key( $name ) ) $sources['cron_hooks'][$name] = array();
        }
        return $sources;
    }

    /**
     * Untruncated, paginated runtime Control register; discovery is NOT UI
     * parity or execution permission. Read only already registered metadata.
     */
    public static function control_census( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) )
            return new WP_Error( 'mad4b_control_census_admin_required', 'Administrator required to inspect registered controls.' );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'kind', 'offset', 'limit' ) ) )
            return new WP_Error( 'mad4b_control_census_input_invalid', 'Only kind, offset and limit are supported.' );
        $kind = isset( $input['kind'] ) ? (string) $input['kind'] : 'abilities';
        if ( ! in_array( $kind, self::CONTROL_KINDS, true ) )
            return new WP_Error( 'mad4b_control_census_kind_invalid', 'Unknown registered Control type.' );
        $offset = isset( $input['offset'] ) ? $input['offset'] : 0;
        $limit = isset( $input['limit'] ) ? $input['limit'] : 50;
        if ( ! is_int( $offset ) || $offset < 0 || $offset > 100000
            || ! is_int( $limit ) || $limit < 1 || $limit > 96 )
            return new WP_Error( 'mad4b_control_census_page_invalid', 'Bounded integer pagination required.' );
        $rows = array();
        $registry_ready = true;
        if ( 'abilities' === $kind ) {
            $registry_ready = function_exists( 'wp_get_abilities' );
            if ( $registry_ready ) foreach ( (array) wp_get_abilities() as $name => $ability ) {
                if ( ! self::safe_key( $name ) ) continue;
                $rows[ $name ] = array(
                    'name' => self::mask( $name ), 'registered' => true,
                    'registered_kind' => 'wordpress_ability',
                    'wp_admin_ui_binding' => 'not_individually_certified',
                    'mcp_transport_verified' => false, 'write_verified' => false );
            }
        } elseif ( 'pages' === $kind ) {
            $registry_ready = class_exists( 'MAD4B_SCP_Admin_Workspace' );
            if ( $registry_ready ) foreach ( MAD4B_SCP_Admin_Workspace::inventory() as $slug => $page ) {
                if ( ! self::safe_key( $slug ) ) continue;
                $rows[ $slug ] = array(
                    'name' => self::mask( $slug ), 'registered' => true,
                    'registered_kind' => 'wp_admin_page',
                    'wp_admin_ui_binding' => 'route_registered_not_browser_certified',
                    'page_title' => (string) ( $page['title'] ?? '' ),
                    'admin_url' => (string) ( $page['url'] ?? '' ),
                    'mcp_transport_verified' => false, 'write_verified' => false );
            }
        } elseif ( 'settings' === $kind ) {
            global $wp_registered_settings;
            $registry_ready = is_array( $wp_registered_settings );
            if ( $registry_ready ) foreach ( $wp_registered_settings as $name => $setting ) {
                if ( ! self::safe_key( $name ) ) continue;
                $rows[ $name ] = array( 'name' => self::mask( $name ), 'registered' => true,
                    'registered_kind' => 'wp_registered_setting',
                    'wp_admin_ui_binding' => 'field_not_individually_certified',
                    'mcp_transport_verified' => false, 'write_verified' => false );
            }
        } elseif ( 'menus' === $kind ) {
            global $menu, $submenu;
            $registry_ready = is_array( $menu );
            if ( $registry_ready ) {
                $menus = $menu;
                if ( is_array( $submenu ) ) foreach ( $submenu as $items )
                    if ( is_array( $items ) ) $menus = array_merge( $menus, $items );
                foreach ( $menus as $entry ) {
                    if ( ! is_array( $entry ) || ! self::safe_key( $entry[2] ?? null )
                        || ! current_user_can( (string) ( $entry[1] ?? 'do_not_grant' ) ) ) continue;
                    $name = (string) $entry[2];
                    $rows[ $name ] = array( 'name' => self::mask( $name ), 'registered' => true,
                        'registered_kind' => 'visible_wp_admin_menu',
                        'wp_admin_ui_binding' => 'menu_only_not_interaction_certified',
                        'mcp_transport_verified' => false, 'write_verified' => false );
                }
            }
        } elseif ( 'governed_operations' === $kind ) {
            $registry_ready = class_exists( 'MAD4B_SCP_Operation_Registry' );
            if ( $registry_ready ) {
                $reg = MAD4B_SCP_Operation_Registry::status();
                $ops = is_array( $reg ) && is_array( $reg['operations'] ?? null ) ? $reg['operations'] : array();
                foreach ( $ops as $op ) {
                    if ( ! is_array( $op ) || ! self::safe_key( $op['id'] ?? null ) ) continue;
                    $name = (string) $op['id'];
                    $rows[ $name ] = array( 'name' => self::mask( $name ), 'registered' => true,
                        'registered_kind' => 'governed_operation',
                        'wp_admin_ui_binding' => 'operation_ui_not_individually_certified',
                        'planner_registered' => true === ( $op['planner_registered'] ?? false ),
                        'executor_registered' => true === ( $op['executor_registered'] ?? false ),
                        'mcp_transport_verified' => false, 'write_verified' => false );
                }
            }
        } elseif ( 'content_types' === $kind || 'taxonomies' === $kind ) {
            $registry_ready = function_exists( 'content_types' === $kind ? 'get_post_types' : 'get_taxonomies' );
            if ( $registry_ready ) {
                $entities = 'content_types' === $kind
                    ? get_post_types( array( 'show_ui' => true ), 'objects' )
                    : get_taxonomies( array( 'show_ui' => true ), 'objects' );
                foreach ( is_array( $entities ) ? $entities : array() as $name => $entity ) {
                    if ( ! self::safe_key( $name ) ) continue;
                    $rows[ $name ] = array( 'name' => self::mask( $name ), 'registered' => true,
                        'registered_kind' => 'content_types' === $kind ? 'post_type' : 'taxonomy',
                        'wp_admin_ui_binding' => 'core_entity_ui_not_browser_certified',
                        'mcp_transport_verified' => false, 'write_verified' => false );
                }
            }
        } elseif ( 'blocks' === $kind ) {
            $registry_ready = class_exists( 'WP_Block_Type_Registry' );
            if ( $registry_ready ) {
                $blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();
                foreach ( is_array( $blocks ) ? $blocks : array() as $name => $block ) {
                    if ( ! self::safe_key( $name ) ) continue;
                    $rows[ $name ] = array( 'name' => self::mask( $name ), 'registered' => true,
                        'registered_kind' => 'block_type',
                        'wp_admin_ui_binding' => 'editor_insertion_not_certified',
                        'mcp_transport_verified' => false, 'write_verified' => false );
                }
            }
        } elseif ( 'rest_routes' === $kind ) {
            global $wp_rest_server;
            // No rest_api_init or callback invocations during a passive admin GET.
            $registry_ready = is_object( $wp_rest_server ) && method_exists( $wp_rest_server, 'get_routes' );
            if ( $registry_ready ) foreach ( $wp_rest_server->get_routes() as $name => $endpoints ) {
                if ( ! self::safe_key( $name ) ) continue;
                $rows[ $name ] = array( 'name' => self::mask( $name ), 'registered' => true,
                    'registered_kind' => 'rest_endpoint',
                    'wp_admin_ui_binding' => 'api_endpoint_not_admin_ui',
                    'mcp_transport_verified' => false, 'write_verified' => false );
            }
        } elseif ( 'cron_hooks' === $kind ) {
            $registry_ready = function_exists( '_get_cron_array' );
            if ( $registry_ready ) {
                $events = _get_cron_array();
                if ( is_array( $events ) ) foreach ( $events as $hooks )
                    if ( is_array( $hooks ) ) foreach ( $hooks as $name => $jobs ) {
                        if ( ! self::safe_key( $name ) ) continue;
                        $rows[ $name ] = array( 'name' => self::mask( $name ), 'registered' => true,
                            'registered_kind' => 'scheduled_cron_hook',
                            'wp_admin_ui_binding' => 'cron_job_not_admin_button',
                            'mcp_transport_verified' => false, 'write_verified' => false );
                    }
            }
        } else {
            global $wp_filter;
            $registry_ready = is_array( $wp_filter );
            $prefix = 'admin_post' === $kind ? 'admin_post_' : 'wp_ajax_';
            if ( $registry_ready ) foreach ( array_keys( $wp_filter ) as $hook ) {
                if ( ! is_string( $hook ) || 0 !== strpos( $hook, $prefix ) ) continue;
                if ( 'ajax' === $kind && 0 === strpos( $hook, 'wp_ajax_nopriv_' ) ) continue;
                $name = substr( $hook, strlen( $prefix ) );
                if ( ! self::safe_key( $name ) ) continue;
                $rows[ $name ] = array( 'name' => self::mask( $name ), 'registered' => true,
                    'registered_kind' => 'admin_post' === $kind ? 'admin_post_hook' : 'authenticated_ajax_hook',
                    'wp_admin_ui_binding' => 'control_event_not_individually_certified',
                    'mcp_transport_verified' => false, 'write_verified' => false );
            }
        }
        ksort( $rows, SORT_STRING );
        $total = count( $rows );
        $items = array_values( array_slice( $rows, $offset, $limit, true ) );
        // The census counts registered metadata. It cannot see every widget,
        // event listener, dynamically injected field, shortcode or third-party
        // UI until the disposable WordPress DOM/browser acceptance runs.
        return array(
            'contract' => 'mad4b.admin-control-census.v1',
            'kind' => $kind, 'registry_ready' => $registry_ready,
            'total_count' => $total, 'offset' => $offset, 'limit' => $limit,
            'returned_count' => count( $items ),
            'next_offset' => ( $offset + count( $items ) < $total ) ? ( $offset + count( $items ) ) : null,
            'items' => $items,
            'coverage_claim' => 'registration_inventory_only',
            'registration_scope' => 'site_registered_controls_including_other_plugins',
            'each_control_ownership_verified' => false,
            'all_registered_controls_are_plugin_owned' => false,
            'unobserved_controls' => array( 'javascript_generated', 'rendered_form_fields',
                'button_event_handlers', 'frontend_editors', 'third_party_screen_controls' ),
            'all_ui_controls_verified' => false, 'each_ability_has_equivalent_wp_admin_ui' => false,
            'all_mcp_operations_tested' => false,
            'read_only' => true, 'authorizing' => false, 'mutation_performed' => false,
        );
    }

    public static function inventory( $input = array() ) {
        if ( ! is_array( $input ) ||
            array_diff( array_keys( $input ), array( 'include_details', 'scan_mode' ) ) )
            return new WP_Error( 'mad4b_surface_input_invalid', 'Bounded inventory inputs only.' );
        $site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
        $mode = (string) ( $input['scan_mode'] ?? 'deep' );
        if ( ! in_array( $mode, array( 'fast', 'deep' ), true ) )
            return new WP_Error( 'mad4b_surface_scan_mode_invalid', 'Supported scan modes: fast or deep.' );
        $summary = self::summarize( self::observations( $mode ),
            is_array( $site ) ? $site : array(), $mode );
        if ( empty( $input['include_details'] ) ) unset( $summary['items'] );
        $summary['visibility'] = 'registered_surfaces_only_not_all_rendered_buttons';
        $summary['operational_next_step'] = 'Use exact snapshot and surface id to request a reviewed adapter blueprint.';
        return $summary;
    }

    public static function blueprint( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'surface_id', 'snapshot_sha256', 'purpose', 'scan_mode' ) ) )
            return new WP_Error( 'mad4b_surface_blueprint_input_invalid', 'Exact surface, snapshot and purpose required.' );
        $id = (string) ( $input['surface_id'] ?? '' );
        $expected = (string) ( $input['snapshot_sha256'] ?? '' );
        $purpose = trim( (string) ( $input['purpose'] ?? '' ) );
        $mode = (string) ( $input['scan_mode'] ?? 'deep' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $id ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ||
            strlen( $purpose ) < 3 || strlen( $purpose ) > 240 ||
            ! in_array( $mode, array( 'fast', 'deep' ), true ) ||
            preg_match( '/[<>\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f]/', $purpose ) )
            return new WP_Error( 'mad4b_surface_blueprint_invalid', 'Invalid blueprint intent or digest.' );
        $site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
        $sum = self::summarize( self::observations( $mode ),
            is_array( $site ) ? $site : array(), $mode );
        if ( ! hash_equals( $sum['snapshot_sha256'], $expected ) )
            return new WP_Error( 'mad4b_surface_snapshot_changed', 'Registered UI signals or site identity changed. Rediscover.' );
        foreach ( $sum['items'] as $row ) {
            if ( ! hash_equals( $id, $row['surface_id'] ) ) continue;
            return array(
                'contract' => self::CONTRACT . '.blueprint.v1',
                'source_surface_id' => $id, 'observed_kind' => $row['kind'],
                'requested_purpose' => $purpose, 'source_inventory_sha256' => $expected,
                'recommended_strategy' => $row['strategy'],
                'adapter_kind' => $row['next_adapter'],
                'family_acceptance_requirements' => self::family_requirements( $row['kind'] ),
                'risk_class' => in_array( $row['kind'], array( 'ajax_actions', 'admin_post_actions',
                    'cron_hooks', 'rest_routes' ), true ) ? 'unknown_external_or_write_effect' : 'source_review_required',
                'required_artifacts' => array(
                    'registered_plugin_or_core_source_owner',
                    'typed_input_and_output_schemas',
                    'exact_permission_and_OAuth_scope',
                    'idempotent_planner_and_exact_plan_sha256',
                    'approved_executor_ability_or_existing_governed_adapter',
                    'readback_and_audit_receipts',
                    'compensation_or_declared_nonreversible_effect',
                    'negative_security_and_staging_runtime_tests',
                ),
                'proposed_coverage_level' => max( 1, (int) $row['coverage_level'] ),
                'next_state' => (int) $row['coverage_level'] >= 2 ?
                    'use_existing_canonical_planner' : 'source_owner_adapter_review',
                'registration_performed' => false, 'execution_performed' => false,
                'credentials_accessed' => false, 'authority_created' => false,
                'production_allowed' => false, 'read_only' => true,
            );
        }
        return new WP_Error( 'mad4b_surface_missing', 'Requested UI surface was not observed in the exact bounded snapshot.' );
    }
}
