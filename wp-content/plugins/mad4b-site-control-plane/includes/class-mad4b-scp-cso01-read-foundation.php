<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * CSO01 Foundation: read-only site explorer and bounded, non-authorizing form
 * descriptors. This is not a generic plugin-options editor or write bridge.
 * Uses existing Site Profile, policy and canonical Capability Descriptors.
 */
final class MAD4B_SCP_CSO01_Read_Foundation {
    const CONTRACT = 'mad4b.cso01.read-foundation.v1';
    const MAX_FIELDS = 24;
    const MAX_OPTIONS = 40;
    const MAX_VALUES = 24;
    const MAX_CATALOG = 256;
    const MAX_ABILITY_NAME_BYTES = 193; // Match MAD4B Canonicalization::ability_name() exactly.
    const PAGE_SIZE = 12;
    private static $booted = false;
    private static $registration_attempted = false;
    private static $registered = false;

    /** Only expose the endpoint set when THIS module registered every callback. */
    public static function read_ability_names() {
        return self::$registered
            ? array( 'cso/discover', 'cso/form-catalog', 'cso/integration-search', 'cso/integration-inspect', 'cso/form-schema', 'cso/form-validate', 'cso/form-explain' )
            : array();
    }

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 44 );
    }

    public static function can_read() {
        // Any partial WordPress registration must stay non-executable, even
        // if another MCP catalog discovers the surviving Ability directly.
        return self::$registered && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && function_exists( 'get_current_user_id' )
            && class_exists( 'MAD4B_SCP_Policy', false )
            && MAD4B_SCP_Policy::can_read()
            && MAD4B_SCP_Policy::can_connect_user( get_current_user_id() );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' )
            || self::$registration_attempted ) return;
        self::$registration_attempted = true;
        $registered_ok = true;
        $names = array(
            'cso/discover' => array( 'CSO01 Site Explorer', 'discover',
                array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ) ),
            'cso/form-catalog' => array( 'CSO01 Search Available Read Forms', 'form_catalog',
                array( 'type' => 'object', 'properties' => array(
                    'query' => array( 'type' => 'string', 'maxLength' => 60 ),
                    'page' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 15 ),
                    'expected_catalog_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                ), 'additionalProperties' => false ) ),
            'cso/integration-search' => array( 'CSO01 Governed Site Integration Search', 'integration_search',
                array( 'type' => 'object', 'properties' => array(
                    'task' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 160 ),
                    'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 8 ),
                ), 'required' => array( 'task' ), 'additionalProperties' => false ) ),
            'cso/integration-inspect' => array( 'CSO01 Read Integration Schema Compatibility', 'integration_inspect',
                array( 'type' => 'object', 'properties' => array(
                    'ability_name' => array( 'type' => 'string', 'maxLength' => self::MAX_ABILITY_NAME_BYTES ),
                ), 'required' => array( 'ability_name' ), 'additionalProperties' => false ) ),
            'cso/form-schema' => array( 'CSO01 Read-Only Form Schema', 'form_schema',
                array( 'type' => 'object', 'properties' => array(
                    'ability_name' => array( 'type' => 'string', 'maxLength' => self::MAX_ABILITY_NAME_BYTES ),
                ), 'required' => array( 'ability_name' ), 'additionalProperties' => false ) ),
            'cso/form-validate' => array( 'CSO01 Form Input Validation (No Save)', 'form_validate',
                array( 'type' => 'object', 'properties' => array(
                    'ability_name' => array( 'type' => 'string', 'maxLength' => self::MAX_ABILITY_NAME_BYTES ),
                    'expected_descriptor_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'values' => array( 'type' => 'object', 'maxProperties' => self::MAX_VALUES, 'additionalProperties' => true ),
                ), 'required' => array( 'ability_name', 'expected_descriptor_sha256', 'values' ),
                    'additionalProperties' => false ) ),
            'cso/form-explain' => array( 'CSO01 Field Help (Non-authorizing)', 'form_explain',
                array( 'type' => 'object', 'properties' => array(
                    'ability_name' => array( 'type' => 'string', 'maxLength' => self::MAX_ABILITY_NAME_BYTES ),
                    'field' => array( 'type' => 'string', 'maxLength' => 80 ),
                ), 'required' => array( 'ability_name', 'field' ), 'additionalProperties' => false ) ),
        );
        // Avoid creating a partially owned namespace if any previous plugin
        // registered a conflicting name. A mid-loop failure is still possible;
        // can_read() denies *all* partial callbacks until the set is complete.
        foreach ( array_keys( $names ) as $reserved_name )
            if ( wp_has_ability( $reserved_name ) ) return;
        foreach ( $names as $name => $row ) {
            if ( wp_has_ability( $name ) ) { $registered_ok = false; continue; }
            $outcome = wp_register_ability( $name, array(
                'label' => $row[0],
                'description' => 'Read-only CSO01 contract; discovery, validation and form display never authorize, save or publish.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $row[1] ),
                'permission_callback' => array( __CLASS__, 'can_read' ),
                'input_schema' => $row[2],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read',
                        'non_authorizing' => true ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false,
                        'idempotent' => true ),
                ),
            ) );
            if ( ! is_object( $outcome ) || is_wp_error( $outcome ) || ! wp_has_ability( $name ) ) $registered_ok = false;
        }
        self::$registered = $registered_ok;
    }

    private static function error( $code ) {
        // Stable, value-free recovery codes: UI clients can translate these
        // without exposing submitted values, plugin internals or credentials.
        $actions = array(
            'read_permission_denied' => 'request_site_read_access',
            'site_unenrolled' => 'enroll_exact_site',
            'site_scope_invalid' => 'verify_site_identity',
            'scope_changed' => 'restart_on_current_site',
            'blog_switch_denied' => 'use_fresh_target_site_connection',
            'stale_descriptor' => 'refresh_form_schema',
            'uncertified_read_ability' => 'select_from_form_catalog',
            'schema_unsupported' => 'request_certified_schema_adapter',
            'field_schema_unsupported' => 'request_certified_schema_adapter',
            'field_sensitive_or_invalid' => 'use_separate_secret_handoff',
            'integration_query_secret_denied' => 'use_separate_secret_handoff',
            'read_ability_not_verified' => 'request_governed_read_certification',
            'catalog_input_invalid' => 'refine_search_or_page',
            'catalog_overflow' => 'limit_search_scope',
            'catalog_changed' => 'restart_catalog_search',
        );
        $action = isset( $actions[ $code ] ) ? $actions[ $code ] : 'inspect_connection_and_schema';
        return new WP_Error( 'mad4b_cso01_' . $code,
            'This operation is not available for this authorized site and form.',
            array( 'reason' => $code, 'next_safe_action' => $action,
                'safe_to_retry_after_refresh' => in_array( $code,
                    array( 'stale_descriptor', 'scope_changed', 'catalog_input_invalid' ), true ) ) );
    }

    private static function site_scope() {
        if ( ! self::can_read() ) return self::error( 'read_permission_denied' );
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile', false )
            || ! MAD4B_SCP_Site_Profile::configured()
            || ! MAD4B_SCP_Site_Profile::origin_enrolled() ) return self::error( 'site_unenrolled' );
        if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway', false )
            && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() )
            return self::error( 'blog_switch_denied' );
        $site = array(
            'site_uuid' => (string) MAD4B_SCP_Site_Profile::site_uuid(),
            'origin' => (string) MAD4B_SCP_Site_Profile::current_origin(),
            'environment' => (string) MAD4B_SCP_Site_Profile::current_environment(),
            'profile_revision' => (string) MAD4B_SCP_Site_Profile::revision(),
            'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
            'observed_wp_environment' => function_exists( 'wp_get_environment_type' )
                ? (string) wp_get_environment_type() : 'unknown',
        );
        $secure_origin = (bool) preg_match(
            '~^https://[a-zA-Z0-9.-]+(?::[0-9]{2,5})?(?:/[a-zA-Z0-9._/-]*)?$~D',
            $site['origin'] );
        // Developer loopback is supported only in an enrolled local/dev
        // profile. Never downgrade remote Staging/Production origin checks.
        $local_loopback = in_array( $site['environment'], array( 'local', 'development' ), true )
            && (bool) preg_match(
                '~^http://(?:localhost|127\\.0\\.0\\.1|\\[::1\\])(?::[0-9]{1,5})?(?:/[a-zA-Z0-9._/-]*)?$~D',
                $site['origin'] );
        if ( ! preg_match( '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $site['site_uuid'] )
            || ( ! $secure_origin && ! $local_loopback )
            || ! in_array( $site['environment'], array( 'local', 'development', 'staging', 'production' ), true )
            || $site['blog_id'] < 1 ) return self::error( 'site_scope_invalid' );
        $site['environment_identity_review_required'] =
            'unknown' !== $site['observed_wp_environment']
            && $site['observed_wp_environment'] !== $site['environment'];
        // Identity divergence is visible as diagnostic, never an elevated grant.
        // Bind the descriptor to the enrolled actor without disclosing the
        // WordPress user ID or any authentication secret in form output.
        if ( ! function_exists( 'wp_salt' ) || ! function_exists( 'get_current_user_id' ) )
            return self::error( 'subject_binding_unavailable' );
        $salt = wp_salt( 'auth' );
        $actor = (int) get_current_user_id();
        if ( ! is_string( $salt ) || strlen( $salt ) < 16 || $actor < 1 )
            return self::error( 'subject_binding_invalid' );
        $site['subject_binding_sha256'] = hash_hmac( 'sha256',
            $site['site_uuid'] . ':' . $site['profile_revision'] . ':' . $actor, $salt );
        return $site;
    }

    private static function public_site( array $site ) {
        // The actor HMAC is for internal exact binding only; never return an
        // otherwise stable user-linked pseudonym to the conversational client.
        unset( $site['subject_binding_sha256'] );
        return $site;
    }

    private static function same_scope( $before ) {
        $after = self::site_scope();
        if ( is_wp_error( $after ) ) return false;
        return isset( $before['subject_binding_sha256'] )
            ? $after === $before
            : self::public_site( $after ) === $before;
    }

    public static function discover( $input = array() ) {
        if ( ! is_array( $input ) || $input ) return self::error( 'unexpected_input' );
        $site = self::site_scope();
        if ( is_wp_error( $site ) ) return $site;
        if ( ! class_exists( 'MAD4B_SCP_Site_Capability_Discovery', false ) )
            return self::error( 'discovery_unavailable' );
        try {
            $inventory = MAD4B_SCP_Site_Capability_Discovery::observe( $site['origin'] );
        } catch ( \Throwable $error ) {
            return self::error( 'discovery_provider_error' );
        }
        if ( ! self::same_scope( $site ) ) return self::error( 'scope_changed' );
        if ( ! is_array( $inventory ) ) return self::error( 'discovery_invalid' );
        // An incomplete inventory is a diagnostic, NOT a passed certification.
        // Preserve its bounded blockers and prevent any inferred write rights.
        $complete = true === ( $inventory['discovery_complete'] ?? false );
        return array(
            'contract' => self::CONTRACT,
            'site' => self::public_site( $site ),
            'inventory' => $inventory,
            'discovery_complete' => $complete,
            'read_only' => true, 'authorizing' => false, 'writes_enabled' => false,
            'provider_certification_issued' => false,
            'next_safe_action' => $complete
                ? 'request_explicit_canonical_read_ability_schema'
                : 'reconcile_inventory_evidence_before_any_certification',
        );
    }

    /**
     * Human-facing entry point: search bounded, already-listed read tools.
     * No plugin option values, WordPress posts, secrets or execution callbacks
     * are invoked. Unsupported schemas remain visible as non-editable rows.
     */
    public static function form_catalog( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) > 3 )
            return self::error( 'catalog_input_invalid' );
        foreach ( array_keys( $input ) as $key ) {
            if ( ! in_array( $key, array( 'query', 'page', 'expected_catalog_sha256' ), true ) )
                return self::error( 'catalog_input_invalid' );
        }
        $query = isset( $input['query'] ) ? $input['query'] : '';
        $page = isset( $input['page'] ) ? $input['page'] : 0;
        if ( ! is_string( $query ) || strlen( $query ) > 180
            || 1 !== preg_match( '//u', $query ) || ! is_int( $page ) || $page < 0 || $page > 15 )
            return self::error( 'catalog_input_invalid' );
        $expected = isset( $input['expected_catalog_sha256'] ) ? $input['expected_catalog_sha256'] : '';
        if ( ! is_string( $expected ) || ( '' !== $expected
            && ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) )
            || ( $page > 0 && '' === $expected ) )
            return self::error( 'catalog_input_invalid' );
        $query = trim( $query );
        if ( '' !== $query && ! self::valid_text( $query, 60 ) )
            return self::error( 'catalog_input_invalid' );
        $site = self::site_scope();
        if ( is_wp_error( $site ) ) return $site;
        if ( ! class_exists( 'MAD4B_SCP_Servers', false ) || ! function_exists( 'wp_get_ability' )
            || ! function_exists( 'wp_has_ability' ) )
            return self::error( 'catalog_provider_missing' );
        $tools = MAD4B_SCP_Servers::core_tools( 'mad4b-read' );
        if ( ! is_array( $tools ) ) return self::error( 'catalog_invalid' );
        $name_only_fallback = false;
        if ( count( $tools ) > self::MAX_CATALOG ) {
            // Never scan an unbounded plugin ecosystem for arbitrary labels.
            // The operator can still narrow by exact Ability-name tokens.
            if ( '' === $query ) return self::error( 'catalog_overflow' );
            $name_only_fallback = true;
            $tools = array_values( array_filter( $tools, static function ( $name ) use ( $query ) {
                return is_string( $name ) && false !== stripos( $name, $query );
            } ) );
            if ( count( $tools ) > self::MAX_CATALOG )
                return self::error( 'catalog_overflow' );
        }
        foreach ( $tools as $name ) {
            if ( ! is_string( $name ) || strlen( $name ) > self::MAX_ABILITY_NAME_BYTES )
                return self::error( 'catalog_invalid_tool' );
        }
        $tools = array_values( array_unique( $tools ) );
        sort( $tools, SORT_STRING );
        $matched = array();
        foreach ( $tools as $name ) {
            if ( ! is_string( $name ) || ! preg_match( '~^[a-z0-9][a-z0-9._-]{0,95}/[a-z0-9][a-z0-9._-]{0,95}$~D', $name )
                || 0 === strpos( $name, 'cso/' ) || ! wp_has_ability( $name ) ) continue;
            try {
                $ability = wp_get_ability( $name );
                if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_label' ) ) continue;
                $label = self::display_label( $ability->get_label(), $name );
            } catch ( \Throwable $error ) {
                // Faulty optional plugin metadata cannot crash all form discovery.
                continue;
            }
            $needle = self::normalize_search( $query );
            if ( '' !== $needle && false === strpos( self::normalize_search( $name ), $needle )
                && false === strpos( self::normalize_search( $label ), $needle ) ) continue;
            $matched[] = array( 'ability_name' => $name, 'label' => $label );
        }
        $total = count( $matched );
        // Pagination fences *observed* catalog items and labels, not only
        // theoretical core-tool names. Provider disappearance changes the hash.
        $serialized_catalog = wp_json_encode( array( self::CONTRACT,
            $site, $matched, $query, self::ui_context()['locale'] ) );
        if ( ! is_string( $serialized_catalog ) ) return self::error( 'catalog_invalid' );
        $fingerprint = hash( 'sha256', $serialized_catalog );
        if ( '' !== $expected && ! hash_equals( $fingerprint, $expected ) )
            return self::error( 'catalog_changed' );
        $slice = array_slice( $matched, $page * self::PAGE_SIZE, self::PAGE_SIZE );
        $items = array();
        foreach ( $slice as $row ) {
            try {
                $form = self::descriptor( array( 'ability_name' => $row['ability_name'] ), $site );
            } catch ( \Throwable $error ) {
                $form = self::error( 'provider_contract_unavailable' );
            }
            $ready = ! is_wp_error( $form );
            $items[] = array(
                'ability_name' => $row['ability_name'],
                'label' => $row['label'],
                'form_ready' => $ready,
                'control_count' => $ready ? count( $form['fields'] ) : 0,
                'not_ready_reason' => $ready ? '' : $form->get_error_code(),
                'next_safe_action' => $ready ? 'open_readonly_form_schema' : 'request_certified_schema_adapter',
                'editable' => false, 'authorizing' => false,
            );
        }
        if ( ! self::same_scope( $site ) ) return self::error( 'scope_changed' );
        return array(
            'contract' => self::CONTRACT . '.catalog.v1',
            'site' => self::public_site( $site ),
            'query' => $query,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'total_matches' => $total,
            'catalog_sha256' => $fingerprint,
            'has_more' => ( $page + 1 ) * self::PAGE_SIZE < $total,
            'name_only_filter_due_to_large_catalog' => $name_only_fallback,
            'items' => $items,
            'read_only' => true, 'authorizing' => false,
            'workflow_hint' => 'choose_form_then_validate_without_saving',
            'ui' => self::ui_context(),
        );
    }

    private static function ui_context() {
        $locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : 'en_US';
        if ( ! preg_match( '/^[a-z]{2,3}(?:[_-][A-Za-z]{2,8})?$/D', $locale ) )
            $locale = 'en_US';
        $rtl_locale = 1 === preg_match( '/^(ar|fa|he|ur|ps|dv)(?:$|[_-])/i', $locale );
        return array( 'locale' => $locale,
            'direction' => $rtl_locale || ( function_exists( 'is_rtl' ) && is_rtl() ) ? 'rtl' : 'ltr',
            'identifier_direction' => 'ltr',
            'steps' => array( 'select_read_capability', 'inspect_field_schema',
                'validate_proposed_values', 'stop_without_saving' ),
            'can_save' => false, 'can_preview_live' => false );
    }

    /** Search-only Arabic folding; never modifies schema, values or identity. */
    private static function normalize_search( $value ) {
        if ( ! is_string( $value ) || '' === $value ) return '';
        $value = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value );
        if ( ! is_string( $value ) ) return '';
        $value = strtr( $value, array(
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و',
        ) );
        return function_exists( 'mb_strtolower' )
            ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }

    /** Labels are hints only, never schema, permission or HTML authority. */
    private static function display_label( $value, $fallback ) {
        $label = is_string( $value ) && strlen( $value ) <= 1024
            && 1 === preg_match( '//u', $value ) ? strip_tags( $value ) : '';
        $label = preg_replace( '/[\x00-\x1f\x7f]/', ' ', $label );
        $label = is_string( $label ) ? trim( $label ) : '';
        if ( '' === $label || strlen( $label ) > 160
            || preg_match( '/secret|password|credential|token|api.?key/i', $label ) )
            $label = ucwords( str_replace( array( '-', '_' ), ' ', $fallback ) );
        return $label;
    }

    /**
     * Adaptive search over the existing gateway, not a new plugin crawler.
     * Search metadata is provisional; each result still requires exact
     * schema preparation and the governed executor's live authorization.
     */
    public static function integration_search( $input = array() ) {
        if ( ! is_array( $input ) || ! isset( $input['task'] )
            || ! is_string( $input['task'] ) || strlen( $input['task'] ) > 512
            || ! self::valid_text( $input['task'], 160 )
            || self::text_length( trim( $input['task'] ) ) < 2 )
            return self::error( 'integration_query_invalid' );
        // Natural-language discovery must not become credential ingress.
        // This is a conservative screen, not a substitute for a vault.
        if ( preg_match( '/\bBearer\s+[A-Za-z0-9._~-]{16,}\b/i', $input['task'] )
            || preg_match( '/\b(?:api[_-]?key|password|client_secret|access_token)\s*[=:]\s*\S{8,}/i', $input['task'] )
            || preg_match( '/\b(?:sk-|ghp_|github_pat_)[A-Za-z0-9_-]{16,}\b/i', $input['task'] ) )
            return self::error( 'integration_query_secret_denied' );
        foreach ( array_keys( $input ) as $key )
            if ( ! in_array( $key, array( 'task', 'limit' ), true ) )
                return self::error( 'integration_query_invalid' );
        $limit = isset( $input['limit'] ) ? $input['limit'] : 8;
        if ( ! is_int( $limit ) || $limit < 1 || $limit > 8 )
            return self::error( 'integration_query_invalid' );
        $site = self::site_scope();
        if ( is_wp_error( $site ) ) return $site;
        if ( ! class_exists( 'MAD4B_SCP_Unified_Capability_Gateway', false ) )
            return self::error( 'unified_gateway_unavailable' );
        try {
            $found = MAD4B_SCP_Unified_Capability_Gateway::dispatch(
                array( 'action' => 'search',
                    'task' => trim( $input['task'] ), 'limit' => 25,
                    'declared_readonly_only' => true ), 'internal' );
        } catch ( \Throwable $error ) {
            return self::error( 'integration_search_unavailable' );
        }
        if ( ! self::same_scope( $site ) ) return self::error( 'scope_changed' );
        if ( is_wp_error( $found ) || ! is_array( $found )
            || ! is_array( $found['items'] ?? null ) )
            return self::error( 'integration_search_unavailable' );
        $items = array();
        $omitted = 0;
        foreach ( $found['items'] as $row ) {
            if ( ! is_array( $row ) || true !== ( $row['declared_readonly'] ?? null ) ) {
                $omitted++;
                continue;
            }
            $name = $row['ability_name'] ?? '';
            if ( ! is_string( $name )
                || ! preg_match( '~^[a-z0-9][a-z0-9._-]{0,95}/[a-z0-9][a-z0-9._-]{0,95}$~D', $name )
                || 0 === strpos( $name, 'cso/' ) ) continue;
            $items[] = array(
                'ability_name' => $name,
                'label' => self::display_label( $row['label'] ?? '', $name ),
                'provider_family' => substr( $name, 0, strpos( $name, '/' ) ),
                'discovery_state' => 'provisional_unprepared',
                'schema_ready' => false,
                'write_supported' => false,
                'next_safe_action' => 'prepare_exact_read_schema',
            );
            if ( count( $items ) >= $limit ) break;
        }
        return array( 'contract' => self::CONTRACT . '.integration-search.v1',
            'site' => self::public_site( $site ), 'items' => $items,
            'visible_count' => count( $items ),
            'universe_count' => (int) ( $found['universe_count'] ?? 0 ),
            'top_search_window' => count( $found['items'] ),
            'nonread_rows_omitted' => $omitted,
            'results_exhaustive' => false,
            'read_only' => true, 'authorizing' => false,
            'next_safe_action' => 'inspect_selected_read_ability' );
    }

    /**
     * Existing governed capability certification is the authority for
     * vendor readiness, never the existence of a registered Ability name.
     * A family without a certification provider may expose a read schema
     * preview but never claim a ready provider execution.
     */
    private static function provider_readiness( $ability_name ) {
        $family = substr( $ability_name, 0, strpos( $ability_name, '/' ) );
        $result = array(
            'provider_family' => $family, 'provider_runtime_verified' => false,
            'read_execution_certified' => false, 'preview_allowed' => true,
            'state' => 'family_certification_unavailable',
            'next_safe_action' => 'inspect_signed_provider_capability_evidence',
        );
        if ( ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification', false )
            || ! MAD4B_SCP_Provider_Compatibility_Certification::supports_provider( $family ) )
            return $result;
        try {
            // Public selector resolves the catalog-specific adapter_id.
            // Calling assess_provider($family) with no Adapter object would
            // incorrectly set available=false for even installed plugins.
            $certificate = MAD4B_SCP_Provider_Compatibility_Certification::capability_certification(
                array( 'provider_id' => $family, 'ability' => $ability_name ) );
        } catch ( \Throwable $error ) {
            $result['state'] = 'provider_runtime_evidence_unavailable';
            $result['preview_allowed'] = false;
            return $result;
        }
        if ( ! is_array( $certificate )
            || ( $certificate['provider_id'] ?? '' ) !== $family
            || ! is_array( $certificate['capabilities'] ?? null ) ) {
            $result['state'] = 'provider_runtime_evidence_unavailable';
            $result['preview_allowed'] = false;
            return $result;
        }
        $compatibility = isset( $certificate['compatibility_state'] )
            && is_string( $certificate['compatibility_state'] )
            ? sanitize_key( $certificate['compatibility_state'] ) : 'unknown';
        if ( in_array( $compatibility, array( 'unavailable', 'provider_unavailable', 'adapter_unavailable' ), true ) ) {
            $result['state'] = 'provider_unavailable';
            $result['preview_allowed'] = false;
            $result['next_safe_action'] = 'install_or_activate_exact_provider_then_recheck';
            return $result;
        }
        if ( 'version_drift' === $compatibility ) {
            $result['state'] = 'provider_version_drift';
            $result['preview_allowed'] = false;
            $result['next_safe_action'] = 'recertify_exact_provider_runtime';
            return $result;
        }
        $capabilities = array_values( $certificate['capabilities'] );
        if ( 1 !== count( $capabilities ) || ! is_array( $capabilities[0] ) ) {
            $result['state'] = 'read_capability_not_certified';
            $result['preview_allowed'] = false;
            $result['next_safe_action'] = 'review_provider_capability_probes';
            return $result;
        }
        $capability = $capabilities[0];
        if ( 'read' !== ( $capability['risk'] ?? '' )
            || empty( $capability['read_eligible'] )
            || empty( $capability['structural_compatible'] ) ) {
            $result['state'] = 'read_capability_not_certified';
            $result['preview_allowed'] = false;
            $result['next_safe_action'] = 'review_provider_capability_probes';
            return $result;
        }
        // The canonical provider selector may report READ_COMPATIBLE while
        // runtime plugin versions remain unattested. This is sufficient only
        // to PREVIEW a bounded read input schema, never to claim execution.
        if ( 'compatible_unattested' === $compatibility
            && 'READ_COMPATIBLE' === ( $capability['certification_level'] ?? '' )
            && 'structural_compatibility' === ( $capability['certification_source'] ?? '' ) ) {
            $result['state'] = 'structural_read_preview_unattested';
            $result['preview_allowed'] = true;
            $result['next_safe_action'] = 'recertify_exact_provider_runtime';
            return $result;
        }
        if ( 'FULLY_CERTIFIED' !== ( $capability['certification_level'] ?? '' )
            || 'repository_exact_baseline' !== ( $capability['certification_source'] ?? '' ) ) {
            $result['state'] = 'provider_uncertified';
            $result['preview_allowed'] = false;
            $result['next_safe_action'] = 'recertify_exact_provider_runtime';
            return $result;
        }
        $generation = method_exists( 'MAD4B_SCP_Provider_Compatibility_Certification',
            'certification_generation_sha256' )
            ? MAD4B_SCP_Provider_Compatibility_Certification::certification_generation_sha256( $family ) : '';
        if ( ! is_string( $generation ) || ! preg_match( '/^[a-f0-9]{64}$/D', $generation ) ) {
            $result['state'] = 'provider_generation_unavailable';
            $result['preview_allowed'] = false;
            $result['next_safe_action'] = 'recertify_exact_provider_runtime';
            return $result;
        }
        $result['certification_generation_sha256'] = $generation;
        $result['state'] = 'exact_provider_read_capability_verified';
        $result['preview_allowed'] = true;
        $result['provider_runtime_verified'] = true;
        $result['read_execution_certified'] = true;
        $result['next_safe_action'] = 'inspect_schema_with_readonly_fences';
        return $result;
    }

    /**
     * Prepare one exact provider Ability through the canonical gateway.
     * Never expose raw defaults, secrets, execution receipts or output Schema.
     * Does not execute the target Ability, not even a declared read.
     */
    public static function integration_inspect( $input = array() ) {
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'ability_name' )
            || ! is_string( $input['ability_name'] )
            || ! preg_match( '~^[a-z0-9][a-z0-9._-]{0,95}/[a-z0-9][a-z0-9._-]{0,95}$~D',
                $input['ability_name'] ) || 0 === strpos( $input['ability_name'], 'cso/' ) )
            return self::error( 'integration_name_invalid' );
        $site = self::site_scope();
        if ( is_wp_error( $site ) ) return $site;
        if ( ! class_exists( 'MAD4B_SCP_Unified_Capability_Gateway', false )
            || ! class_exists( 'MAD4B_SCP_Servers', false )
            || ! MAD4B_SCP_Servers::is_chatgpt_full_catalog_candidate( $input['ability_name'] ) )
            return self::error( 'integration_not_catalogued' );
        $provider = self::provider_readiness( $input['ability_name'] );
        if ( empty( $provider['preview_allowed'] ) )
            return array( 'contract' => self::CONTRACT . '.integration-inspect.v1',
                'ability_name' => $input['ability_name'], 'site' => self::public_site( $site ),
                'state' => $provider['state'], 'provider_readiness' => $provider,
                'form_ready' => false, 'read_only' => true, 'authorizing' => false,
                'next_safe_action' => $provider['next_safe_action'] );
        try {
            $prepared = MAD4B_SCP_Unified_Capability_Gateway::dispatch(
                array( 'action' => 'prepare',
                    'ability_names' => array( $input['ability_name'] ) ), 'internal' );
        } catch ( \Throwable $error ) {
            return self::error( 'integration_prepare_unavailable' );
        }
        if ( ! self::same_scope( $site ) ) return self::error( 'scope_changed' );
        if ( is_wp_error( $prepared ) || ! is_array( $prepared )
            || count( $prepared['abilities'] ?? array() ) !== 1 )
            return self::error( 'integration_prepare_unavailable' );
        $entry = $prepared['abilities'][0];
        if ( ! is_array( $entry ) || $input['ability_name'] !== ( $entry['ability_name'] ?? '' ) )
            return self::error( 'integration_prepare_identity_mismatch' );
        $execution = $entry['execution'] ?? array();
        if ( ! is_array( $execution )
            || 'read' !== ( $execution['expected_execution_lane'] ?? '' )
            || empty( $entry['execution_eligible'] ) )
            return self::error( 'integration_not_verified_read' );
        $input_schema = $entry['schema']['inputSchema'] ?? null;
        // Gateways can choose cached/chunked Schema transfer. Do not claim a
        // usable form until an exact verified inline representation arrives.
        if ( ! is_array( $input_schema ) )
            return array( 'contract' => self::CONTRACT . '.integration-inspect.v1',
                'ability_name' => $input['ability_name'],
                'site' => self::public_site( $site ),
                'state' => 'schema_transport_required',
                'schema_transfer_mode' => (string) ( $entry['schema_transfer_mode'] ?? 'unknown' ),
                'form_ready' => false, 'read_only' => true, 'authorizing' => false,
                'next_safe_action' => 'fetch_exact_pinned_schema' );
        $fields = self::compile_schema( $input_schema );
        if ( is_wp_error( $fields ) )
            return array( 'contract' => self::CONTRACT . '.integration-inspect.v1',
                'ability_name' => $input['ability_name'],
                'site' => self::public_site( $site ), 'state' => 'unsupported_schema',
                'reason' => $fields->get_error_code(),
                'form_ready' => false, 'read_only' => true, 'authorizing' => false,
                'next_safe_action' => 'request_certified_provider_field_adapter' );
        $source_hash = $entry['input_schema_sha256'] ?? '';
        $descriptor_hash = $entry['descriptor_sha256'] ?? '';
        $classification_hash = $entry['classification_sha256'] ?? '';
        $scope_hash = $entry['authority_scope_sha256'] ?? '';
        foreach ( array( $source_hash, $descriptor_hash, $classification_hash, $scope_hash ) as $identity ) {
            if ( ! is_string( $identity ) || ! preg_match( '/^[a-f0-9]{64}$/D', $identity ) )
                return self::error( 'integration_schema_identity_missing' );
        }
        $provider_after = self::provider_readiness( $input['ability_name'] );
        if ( $provider_after !== $provider )
            return self::error( 'provider_changed_during_preparation' );
        $serialized_descriptor = wp_json_encode( array( self::CONTRACT, $input['ability_name'],
            $source_hash, $site, $provider, $entry['descriptor_sha256'],
            $entry['classification_sha256'], $entry['authority_scope_sha256'], $fields ) );
        if ( ! is_string( $serialized_descriptor ) || '' === $serialized_descriptor )
            return self::error( 'integration_serialization_failed' );
        $digest = hash( 'sha256', $serialized_descriptor );
        if ( ! self::same_scope( $site ) ) return self::error( 'scope_changed' );
        return array( 'contract' => self::CONTRACT . '.integration-inspect.v1',
            'ability_name' => $input['ability_name'],
            'site' => self::public_site( $site ),
            'state' => $provider['provider_runtime_verified']
                ? 'read_form_prepared' : 'read_schema_preview_uncertified',
            'descriptor_sha256' => $digest,
            'input_schema_sha256' => $source_hash,
            'fields' => $fields, 'field_count' => count( $fields ),
            'ui' => self::ui_context(),
            'form_ready' => true, 'read_only' => true,
            'provider_readiness' => $provider,
            'read_execution_certified' => $provider['read_execution_certified'],
            'authorizing' => false, 'execution_performed' => false,
            'provider_write_certified' => false, 'approval_granted' => false,
            'next_safe_action' => $provider['provider_runtime_verified']
                ? 'review_read_parameters_no_execution'
                : 'inspect_signed_provider_capability_evidence' );
    }

    private static function descriptor( $input, $site ) {
        if ( ! is_array( $input ) || ! isset( $input['ability_name'] )
            || ! is_string( $input['ability_name'] )
            || ! preg_match( '~^[a-z0-9][a-z0-9._-]{0,95}/[a-z0-9][a-z0-9._-]{0,95}$~D', $input['ability_name'] ) )
            return self::error( 'ability_name_invalid' );
        $name = $input['ability_name'];
        // Only the existing trusted read-server catalog is eligible. Mere
        // WordPress registration (including a hostile plugin) is insufficient.
        if ( ! class_exists( 'MAD4B_SCP_Servers', false )
            || ! in_array( $name, MAD4B_SCP_Servers::core_tools( 'mad4b-read' ), true )
            || 0 === strpos( $name, 'cso/' ) ) return self::error( 'uncertified_read_ability' );
        if ( ! class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry', false )
            || ! function_exists( 'wp_get_ability' ) ) return self::error( 'descriptor_unavailable' );
        $binding = MAD4B_SCP_Capability_Descriptor_Registry::binding( $name, 'cso01_form' );
        if ( is_wp_error( $binding ) || ! is_array( $binding )
            || 'read' !== ( $binding['execution_lane'] ?? '' ) )
            return self::error( 'read_ability_not_verified' );
        $ability = wp_get_ability( $name );
        if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' )
            || ! method_exists( $ability, 'get_input_schema' ) )
            return self::error( 'ability_metadata_invalid' );
        $meta = $ability->get_meta();
        if ( ! is_array( $meta ) || true !== ( $meta['annotations']['readonly'] ?? null )
            || 'read' !== ( $meta['mcp']['surface'] ?? null ) )
            return self::error( 'not_declared_readonly' );
        $fields = self::compile_schema( $ability->get_input_schema() );
        if ( is_wp_error( $fields ) ) return $fields;
        $serialized = wp_json_encode( array( self::CONTRACT, $binding, $fields, $site ) );
        if ( ! is_string( $serialized ) || '' === $serialized ) return self::error( 'descriptor_serialization_failed' );
        $digest = hash( 'sha256', $serialized );
        return array(
            'contract' => self::CONTRACT . '.form.v1',
            'ability_name' => $name,
            'descriptor_sha256' => $digest,
            'ability_descriptor_sha256' => (string) $binding['descriptor_sha256'],
            'fields' => $fields,
            'read_only' => true, 'authorizing' => false, 'editable' => false,
            'write_ability' => null, 'rollback_available' => false,
            'ui' => self::ui_context(),
            'next_safe_action' => 'validate_draft_without_saving',
            'credential_ingress' => 'separate_first_party_handoff_not_implemented',
        );
    }

    public static function form_schema( $input = array() ) {
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'ability_name' ) )
            return self::error( 'unexpected_input' );
        $site = self::site_scope();
        if ( is_wp_error( $site ) ) return $site;
        try {
            $form = self::descriptor( $input, $site );
        } catch ( \Throwable $error ) {
            $form = self::error( 'provider_contract_unavailable' );
        }
        if ( ! self::same_scope( $site ) ) return self::error( 'scope_changed' );
        if ( is_wp_error( $form ) ) return $form;
        $form['site'] = self::public_site( $site );
        return $form;
    }

    /** Pure, deliberately restricted schema compiler. Unknown constructs deny. */
    public static function compile_schema( $schema ) {
        // Several valid native WordPress read Abilities declare no inputs as
        // an empty array. Normalize only that exact no-field sentinel.
        if ( is_array( $schema ) && array() === $schema )
            $schema = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array() );
        if ( ! is_array( $schema ) || ( $schema['type'] ?? '' ) !== 'object'
            || ( $schema['additionalProperties'] ?? null ) !== false
            || ! is_array( $schema['properties'] ?? null )
            || count( $schema['properties'] ) > self::MAX_FIELDS ) return self::error( 'schema_unsupported' );
        foreach ( array_keys( $schema ) as $key ) {
            if ( ! in_array( $key, array( 'type', 'additionalProperties', 'properties', 'required', 'title', 'description', 'default' ), true ) )
                return self::error( 'root_schema_unsupported' );
        }
        // Only the empty root default commonly emitted by WordPress is safe
        // to interpret; per-field default values are never sent to the client.
        if ( array_key_exists( 'default', $schema ) && array() !== $schema['default'] )
            return self::error( 'root_default_unsupported' );
        $required = isset( $schema['required'] ) ? $schema['required'] : array();
        if ( ! is_array( $required ) || count( $required ) > self::MAX_FIELDS )
            return self::error( 'required_invalid' );
        foreach ( $required as $key ) if ( ! is_string( $key )
            || ! array_key_exists( $key, $schema['properties'] ) )
            return self::error( 'required_unknown' );
        if ( count( $required ) !== count( array_unique( $required ) ) )
            return self::error( 'required_invalid' );
        $fields = array();
        foreach ( $schema['properties'] as $key => $spec ) {
            if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/D', $key )
                || preg_match( '/secret|token|password|credential|api.?key|auth|private|bearer|nonce/i', $key )
                || ! is_array( $spec ) ) return self::error( 'field_sensitive_or_invalid' );
            // A generic key can still carry a credential in its title or
            // description; do not render the field and merely hide the label.
            foreach ( array( 'title', 'description' ) as $presentation_field ) {
                if ( ! array_key_exists( $presentation_field, $spec ) ) continue;
                $hint = $spec[ $presentation_field ];
                if ( ! is_string( $hint ) || strlen( $hint ) > 1024
                    || 1 !== preg_match( '//u', $hint )
                    || preg_match( '/secret|api.?key|access.?token|bearer|password|credential|private.?key|nonce/i', $hint ) )
                    return self::error( 'field_sensitive_or_invalid' );
            }
            // Never silently omit a constraint such as pattern, minimum,
            // format or a vendor extension and then claim validation passed.
            foreach ( array_keys( $spec ) as $constraint ) {
                if ( ! in_array( $constraint, array( 'type', 'enum', 'minLength', 'maxLength', 'minimum', 'maximum', 'default', 'title', 'description' ), true ) )
                    return self::error( 'field_schema_unsupported' );
            }
            $type = isset( $spec['type'] ) ? $spec['type'] : '';
            if ( ! in_array( $type, array( 'string', 'integer', 'number', 'boolean' ), true ) )
                return self::error( 'field_type_unsupported' );
            $enum = isset( $spec['enum'] ) ? $spec['enum'] : null;
            if ( null !== $enum && ( ! is_array( $enum ) || ! count( $enum )
                || count( $enum ) > self::MAX_OPTIONS ) )
                return self::error( 'field_enum_invalid' );
            if ( null !== $enum ) foreach ( $enum as $value ) {
                // Vendor-supplied enums are values, not trusted labels. A
                // generic read form must not turn stored PII, credentials,
                // URLs with tokens or HTML into ChatGPT option metadata.
                if ( 'string' === $type &&
                    ( strlen( $value ) > 160
                        || preg_match( '/[\x00-\x1f\x7f<>&@]/', $value )
                        || preg_match( '/(?:secret|password|bearer|api[_-]?key|access[_-]?token|private[_-]?key)/i', $value )
                        || preg_match( '/^(?:sk-|ghp_|github_pat_)[A-Za-z0-9_-]{12,}/i', $value ) ) )
                    return self::error( 'enum_metadata_sensitive' );
                if ( ( 'string' === $type && ( ! is_string( $value ) || strlen( $value ) > 512
                        || 1 !== preg_match( '//u', $value ) ) )
                    || ( 'integer' === $type && ! is_int( $value ) )
                    || ( 'number' === $type && ( ( ! is_int( $value ) && ! is_float( $value ) )
                        || ! is_finite( (float) $value ) ) )
                    || ( 'boolean' === $type && ! is_bool( $value ) ) )
                    return self::error( 'field_enum_type_invalid' );
            }
            $min_length = 0;
            $max_length = 256;
            $minimum = null;
            $maximum = null;
            if ( 'string' === $type ) {
                $min_length = isset( $spec['minLength'] ) ? $spec['minLength'] : 0;
                $max_length = isset( $spec['maxLength'] ) ? $spec['maxLength'] : 256;
                if ( ! is_int( $min_length ) || ! is_int( $max_length )
                    || $min_length < 0 || $max_length < 1 || $max_length > 2048
                    || $min_length > $max_length ) return self::error( 'field_length_invalid' );
            } elseif ( array_key_exists( 'minLength', $spec ) || array_key_exists( 'maxLength', $spec ) ) {
                return self::error( 'nonstring_length_invalid' );
            }
            if ( in_array( $type, array( 'integer', 'number' ), true ) ) {
                foreach ( array( 'minimum', 'maximum' ) as $bound ) {
                    if ( ! array_key_exists( $bound, $spec ) ) continue;
                    $number = $spec[ $bound ];
                    if ( ( ! is_int( $number ) && ! is_float( $number ) )
                        || ! is_finite( (float) $number ) || abs( (float) $number ) > 1.0e12
                        || ( 'integer' === $type && ! is_int( $number ) ) )
                        return self::error( 'numeric_bound_invalid' );
                }
                $minimum = isset( $spec['minimum'] ) ? $spec['minimum'] : null;
                $maximum = isset( $spec['maximum'] ) ? $spec['maximum'] : null;
                if ( null !== $minimum && null !== $maximum && $minimum > $maximum )
                    return self::error( 'numeric_range_invalid' );
            } elseif ( array_key_exists( 'minimum', $spec ) || array_key_exists( 'maximum', $spec ) ) {
                return self::error( 'nonnumeric_bound_invalid' );
            }
            if ( array_key_exists( 'default', $spec ) ) {
                $default_value = $spec['default'];
                $valid_default = ( 'string' === $type && self::valid_text( $default_value, $max_length )
                        && self::text_length( $default_value ) >= $min_length )
                    || ( 'integer' === $type && is_int( $default_value ) )
                    || ( 'number' === $type && ( is_int( $default_value ) || is_float( $default_value ) )
                        && is_finite( (float) $default_value ) )
                    || ( 'boolean' === $type && is_bool( $default_value ) );
                if ( ! $valid_default || ( null !== $minimum && $default_value < $minimum )
                    || ( null !== $maximum && $default_value > $maximum )
                    || ( null !== $enum && ! in_array( $default_value, $enum, true ) ) )
                    return self::error( 'field_default_invalid' );
            }
            if ( null !== $enum ) {
                // A dropdown must not advertise a value impossible to submit.
                // Preserve exact PHP types (true != 1) when rejecting duplicates.
                $seen = array();
                foreach ( $enum as $choice ) {
                    if ( 'string' === $type && ( ! self::valid_text( $choice, $max_length )
                        || self::text_length( $choice ) < $min_length ) )
                        return self::error( 'enum_exceeds_field_length' );
                    if ( in_array( $type, array( 'integer', 'number' ), true )
                        && ( ( null !== $minimum && $choice < $minimum )
                            || ( null !== $maximum && $choice > $maximum ) ) )
                        return self::error( 'enum_outside_numeric_range' );
                    $fingerprint = gettype( $choice ) . ':' . wp_json_encode( $choice );
                    if ( isset( $seen[ $fingerprint ] ) )
                        return self::error( 'enum_duplicate' );
                    $seen[ $fingerprint ] = true;
                }
            }
            $fields[] = array(
                'key' => $key, 'type' => $type,
                'label' => self::display_label( isset( $spec['title'] ) ? $spec['title'] : '',
                    ucwords( str_replace( array( '-', '_' ), ' ', $key ) ) ),
                'control' => null !== $enum ? 'select'
                    : ( 'boolean' === $type ? 'checkbox'
                        : ( in_array( $type, array( 'integer', 'number' ), true ) ? 'number' : 'text' ) ),
                'direction' => 'auto',
                'required' => in_array( $key, $required, true ),
                'min_length' => 'string' === $type ? $min_length : null,
                'max_length' => 'string' === $type ? $max_length : null,
                'minimum' => $minimum,
                'maximum' => $maximum,
                'has_default' => array_key_exists( 'default', $spec ),
                'default_value_exposed' => false,
                'enum' => $enum,
                'editable' => false, 'sensitive' => false,
                'storage_target' => 'not_disclosed_or_certified',
                'validation_only' => true,
            );
        }
        return $fields;
    }

    /** A bounded JSON Schema character count, including Arabic grapheme text. */
    private static function text_length( $value ) {
        return function_exists( 'mb_strlen' )
            ? mb_strlen( $value, 'UTF-8' )
            : preg_match_all( '/./us', $value );
    }

    /** JSON Schema string limits count Unicode code points, not UTF-8 bytes. */
    private static function valid_text( $value, $max_length ) {
        if ( ! is_string( $value ) || strlen( $value ) > 8192 || 1 !== preg_match( '//u', $value ) ) return false;
        $characters = self::text_length( $value );
        return false !== $characters && $characters <= $max_length;
    }

    /** No submitted values appear in diagnostics, logs or results. */
    public static function validate_values( $fields, $values ) {
        if ( ! is_array( $fields ) || ! is_array( $values ) || count( $values ) > self::MAX_VALUES )
            return self::error( 'values_invalid' );
        $map = array();
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) || ! isset( $field['key'] ) ) return self::error( 'descriptor_invalid' );
            $map[ $field['key'] ] = $field;
        }
        $issues = array();
        $field_errors = array();
        foreach ( $values as $key => $value ) {
            if ( ! is_string( $key ) || ! isset( $map[ $key ] ) ) {
                $issues[] = 'unknown_or_hidden_field';
                $field_errors['__form'] = 'remove_unknown_field';
                continue;
            }
            $field = $map[ $key ];
            $type = $field['type'];
            $valid = ( 'string' === $type && self::valid_text( $value, $field['max_length'] ) )
                || ( 'boolean' === $type && is_bool( $value ) )
                || ( 'integer' === $type && is_int( $value ) )
                || ( 'number' === $type && ( is_int( $value ) || is_float( $value ) )
                    && is_finite( (float) $value ) );
            if ( $valid && 'string' === $type ) {
                $valid = self::text_length( $value ) >= (int) ( $field['min_length'] ?? 0 );
            }
            if ( $valid && in_array( $type, array( 'integer', 'number' ), true ) ) {
                $valid = ( null === $field['minimum'] || $value >= $field['minimum'] )
                    && ( null === $field['maximum'] || $value <= $field['maximum'] );
            }
            if ( $valid && is_array( $field['enum'] ) ) $valid = in_array( $value, $field['enum'], true );
            if ( ! $valid ) {
                $issues[] = 'field_type_or_constraint_invalid';
                $field_errors[ $key ] = 'check_type_length_or_allowed_choice';
            }
        }
        foreach ( $map as $key => $field ) {
            if ( ! empty( $field['required'] ) && ! array_key_exists( $key, $values ) ) {
                $issues[] = 'required_field_absent';
                $field_errors[ $key ] = 'fill_required_field';
            }
        }
        return array(
            'contract' => self::CONTRACT . '.validation.v1',
            'valid' => empty( $issues ),
            'issues' => array_values( array_unique( $issues ) ),
            'field_errors' => $field_errors,
            'next_safe_action' => empty( $issues ) ? 'review_only_no_commit' : 'correct_highlighted_fields',
            'values_echoed' => false, 'saved' => false, 'authorizing' => false,
        );
    }

    /** Resolve one read-only form without treating plugin registration as trust. */
    private static function selected_form( $name ) {
        if ( class_exists( 'MAD4B_SCP_Servers', false )
            && in_array( $name, MAD4B_SCP_Servers::core_tools( 'mad4b-read' ), true ) )
            return self::form_schema( array( 'ability_name' => $name ) );
        $inspection = self::integration_inspect( array( 'ability_name' => $name ) );
        if ( is_wp_error( $inspection ) ) return $inspection;
        if ( ! is_array( $inspection ) || empty( $inspection['form_ready'] )
            || ! isset( $inspection['fields'], $inspection['descriptor_sha256'] ) )
            return self::error( 'integration_form_not_ready' );
        return $inspection;
    }

    public static function form_validate( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) !== 3
            || ! isset( $input['ability_name'], $input['expected_descriptor_sha256'], $input['values'] )
            || ! is_array( $input['values'] )
            || ! is_string( $input['expected_descriptor_sha256'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $input['expected_descriptor_sha256'] ) )
            return self::error( 'validation_input_invalid' );
        $actor_scope = self::site_scope();
        if ( is_wp_error( $actor_scope ) ) return $actor_scope;
        $form = self::selected_form( $input['ability_name'] );
        if ( is_wp_error( $form ) ) return $form;
        if ( ! hash_equals( $form['descriptor_sha256'], $input['expected_descriptor_sha256'] ) )
            return self::error( 'stale_descriptor' );
        $result = self::validate_values( $form['fields'], $input['values'] );
        if ( is_wp_error( $result ) ) return $result;
        if ( ! self::same_scope( $actor_scope ) ) return self::error( 'scope_changed' );
        $result['site'] = $form['site'];
        $result['descriptor_sha256'] = $form['descriptor_sha256'];
        $result['provider_readiness'] = isset( $form['provider_readiness'] )
            ? $form['provider_readiness'] : array( 'state' => 'canonical_core_read_form' );
        $result['execution_approved'] = false;
        return $result;
    }

    public static function form_explain( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) !== 2
            || ! isset( $input['ability_name'], $input['field'] )
            || ! is_string( $input['field'] ) ) return self::error( 'explain_input_invalid' );
        $actor_scope = self::site_scope();
        if ( is_wp_error( $actor_scope ) ) return $actor_scope;
        $form = self::selected_form( $input['ability_name'] );
        if ( is_wp_error( $form ) ) return $form;
        foreach ( $form['fields'] as $field ) if ( $field['key'] === $input['field'] ) {
            if ( ! self::same_scope( $actor_scope ) ) return self::error( 'scope_changed' );
            return array( 'contract' => self::CONTRACT . '.explain.v1',
                'field' => $field, 'site' => $form['site'],
                'explanation' => 'Schema field from an explicitly enumerated read-only WordPress Ability. Storage and side effects are not certified.',
                'write_supported' => false, 'secret_supported' => false,
                'provider_readiness' => isset( $form['provider_readiness'] )
                    ? $form['provider_readiness'] : array( 'state' => 'canonical_core_read_form' ),
                'authorizing' => false );
        }
        return self::error( 'field_unknown' );
    }
}
