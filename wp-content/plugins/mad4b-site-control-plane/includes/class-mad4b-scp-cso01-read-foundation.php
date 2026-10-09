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
    private static $booted = false;
    private static $registration_attempted = false;
    private static $registered = false;

    /** Only expose the endpoint set when THIS module registered every callback. */
    public static function read_ability_names() {
        return self::$registered
            ? array( 'cso/discover', 'cso/form-schema', 'cso/form-validate', 'cso/form-explain' )
            : array();
    }

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 44 );
    }

    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
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
            'cso/form-schema' => array( 'CSO01 Read-Only Form Schema', 'form_schema',
                array( 'type' => 'object', 'properties' => array(
                    'ability_name' => array( 'type' => 'string', 'maxLength' => 120 ),
                ), 'required' => array( 'ability_name' ), 'additionalProperties' => false ) ),
            'cso/form-validate' => array( 'CSO01 Form Input Validation (No Save)', 'form_validate',
                array( 'type' => 'object', 'properties' => array(
                    'ability_name' => array( 'type' => 'string', 'maxLength' => 120 ),
                    'expected_descriptor_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'values' => array( 'type' => 'object', 'additionalProperties' => true ),
                ), 'required' => array( 'ability_name', 'expected_descriptor_sha256', 'values' ),
                    'additionalProperties' => false ) ),
            'cso/form-explain' => array( 'CSO01 Field Help (Non-authorizing)', 'form_explain',
                array( 'type' => 'object', 'properties' => array(
                    'ability_name' => array( 'type' => 'string', 'maxLength' => 120 ),
                    'field' => array( 'type' => 'string', 'maxLength' => 80 ),
                ), 'required' => array( 'ability_name', 'field' ), 'additionalProperties' => false ) ),
        );
        foreach ( $names as $name => $row ) {
            // A plugin-claimed cso/* name is never silently trusted as ours.
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
            if ( is_wp_error( $outcome ) || ! wp_has_ability( $name ) ) $registered_ok = false;
        }
        self::$registered = $registered_ok;
    }

    private static function error( $code ) {
        return new WP_Error( 'mad4b_cso01_' . $code, 'CSO01 request denied; use a fresh authorized, site-bound read.' );
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
        );
        if ( ! preg_match( '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $site['site_uuid'] )
            || ! preg_match( '~^https://[a-zA-Z0-9.-]+(?::[0-9]{2,5})?(?:/[a-zA-Z0-9._/-]*)?$~D', $site['origin'] )
            || ! in_array( $site['environment'], array( 'local', 'development', 'staging', 'production' ), true )
            || $site['blog_id'] < 1 ) return self::error( 'site_scope_invalid' );
        return $site;
    }

    private static function same_scope( $before ) {
        $after = self::site_scope();
        return ! is_wp_error( $after ) && $after === $before;
    }

    public static function discover( $input = array() ) {
        if ( ! is_array( $input ) || $input ) return self::error( 'unexpected_input' );
        $site = self::site_scope();
        if ( is_wp_error( $site ) ) return $site;
        if ( ! class_exists( 'MAD4B_SCP_Site_Capability_Discovery', false ) )
            return self::error( 'discovery_unavailable' );
        $inventory = MAD4B_SCP_Site_Capability_Discovery::observe( $site['origin'] );
        if ( ! self::same_scope( $site ) ) return self::error( 'scope_changed' );
        if ( ! is_array( $inventory ) ) return self::error( 'discovery_invalid' );
        // An incomplete inventory is a diagnostic, NOT a passed certification.
        // Preserve its bounded blockers and prevent any inferred write rights.
        $complete = true === ( $inventory['discovery_complete'] ?? false );
        return array(
            'contract' => self::CONTRACT,
            'site' => $site,
            'inventory' => $inventory,
            'discovery_complete' => $complete,
            'read_only' => true, 'authorizing' => false, 'writes_enabled' => false,
            'provider_certification_issued' => false,
            'next_safe_action' => $complete
                ? 'request_explicit_canonical_read_ability_schema'
                : 'reconcile_inventory_evidence_before_any_certification',
        );
    }

    private static function descriptor( $input ) {
        if ( ! is_array( $input ) || ! isset( $input['ability_name'] )
            || ! is_string( $input['ability_name'] )
            || ! preg_match( '~^[a-z][a-z0-9-]{0,63}/[a-z][a-z0-9-]{0,63}$~D', $input['ability_name'] ) )
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
        $digest = hash( 'sha256', wp_json_encode( array( self::CONTRACT, $binding, $fields ) ) );
        return array(
            'contract' => self::CONTRACT . '.form.v1',
            'ability_name' => $name,
            'descriptor_sha256' => $digest,
            'ability_descriptor_sha256' => (string) $binding['descriptor_sha256'],
            'fields' => $fields,
            'read_only' => true, 'authorizing' => false, 'editable' => false,
            'write_ability' => null, 'rollback_available' => false,
            'credential_ingress' => 'separate_first_party_handoff_not_implemented',
        );
    }

    public static function form_schema( $input = array() ) {
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'ability_name' ) )
            return self::error( 'unexpected_input' );
        $site = self::site_scope();
        if ( is_wp_error( $site ) ) return $site;
        $form = self::descriptor( $input );
        if ( ! self::same_scope( $site ) ) return self::error( 'scope_changed' );
        if ( is_wp_error( $form ) ) return $form;
        $form['site'] = $site;
        return $form;
    }

    /** Pure, deliberately restricted schema compiler. Unknown constructs deny. */
    public static function compile_schema( $schema ) {
        if ( ! is_array( $schema ) || ( $schema['type'] ?? '' ) !== 'object'
            || ( $schema['additionalProperties'] ?? null ) !== false
            || ! is_array( $schema['properties'] ?? null )
            || count( $schema['properties'] ) > self::MAX_FIELDS ) return self::error( 'schema_unsupported' );
        foreach ( array_keys( $schema ) as $key ) {
            if ( ! in_array( $key, array( 'type', 'additionalProperties', 'properties', 'required', 'title', 'description' ), true ) )
                return self::error( 'root_schema_unsupported' );
        }
        $required = isset( $schema['required'] ) ? $schema['required'] : array();
        if ( ! is_array( $required ) || count( $required ) > self::MAX_FIELDS ) return self::error( 'required_invalid' );
        foreach ( $required as $key ) if ( ! is_string( $key ) || ! array_key_exists( $key, $schema['properties'] ) )
            return self::error( 'required_unknown' );
        $fields = array();
        foreach ( $schema['properties'] as $key => $spec ) {
            if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/D', $key )
                || preg_match( '/secret|token|password|credential|api.?key|auth|private|bearer|nonce/i', $key )
                || ! is_array( $spec ) ) return self::error( 'field_sensitive_or_invalid' );
            // Never silently omit a constraint such as pattern, minimum,
            // format or a vendor extension and then claim validation passed.
            foreach ( array_keys( $spec ) as $constraint ) {
                if ( ! in_array( $constraint, array( 'type', 'enum', 'maxLength', 'title', 'description' ), true ) )
                    return self::error( 'field_schema_unsupported' );
            }
            $type = isset( $spec['type'] ) ? $spec['type'] : '';
            if ( ! in_array( $type, array( 'string', 'integer', 'number', 'boolean' ), true ) )
                return self::error( 'field_type_unsupported' );
            $enum = isset( $spec['enum'] ) ? $spec['enum'] : null;
            if ( null !== $enum && ( ! is_array( $enum ) || count( $enum ) > self::MAX_OPTIONS ) )
                return self::error( 'field_enum_invalid' );
            if ( null !== $enum ) foreach ( $enum as $value ) {
                if ( ( 'string' === $type && ( ! is_string( $value ) || strlen( $value ) > 128 ) )
                    || ( 'integer' === $type && ! is_int( $value ) )
                    || ( 'number' === $type && ! is_int( $value ) && ! is_float( $value ) )
                    || ( 'boolean' === $type && ! is_bool( $value ) ) )
                    return self::error( 'field_enum_type_invalid' );
            }
            $max_length = 256;
            if ( 'string' === $type ) {
                $max_length = isset( $spec['maxLength'] ) ? $spec['maxLength'] : 256;
                if ( ! is_int( $max_length ) || $max_length < 1 || $max_length > 2048 ) return self::error( 'field_length_invalid' );
            } elseif ( array_key_exists( 'maxLength', $spec ) ) {
                return self::error( 'nonstring_length_invalid' );
            }
            $fields[] = array(
                'key' => $key, 'type' => $type,
                'required' => in_array( $key, $required, true ),
                'max_length' => 'string' === $type ? $max_length : null,
                'enum' => $enum,
                'editable' => false, 'sensitive' => false,
                'storage_target' => 'not_disclosed_or_certified',
                'validation_only' => true,
            );
        }
        return $fields;
    }

    /** JSON Schema string limits count Unicode code points, not UTF-8 bytes. */
    private static function valid_text( $value, $max_length ) {
        if ( ! is_string( $value ) || strlen( $value ) > 8192 || 1 !== preg_match( '//u', $value ) ) return false;
        $characters = function_exists( 'mb_strlen' )
            ? mb_strlen( $value, 'UTF-8' )
            : preg_match_all( '/./us', $value );
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
        foreach ( $values as $key => $value ) {
            if ( ! is_string( $key ) || ! isset( $map[ $key ] ) ) {
                $issues[] = 'unknown_or_hidden_field';
                continue;
            }
            $field = $map[ $key ];
            $type = $field['type'];
            $valid = ( 'string' === $type && self::valid_text( $value, $field['max_length'] ) )
                || ( 'boolean' === $type && is_bool( $value ) )
                || ( 'integer' === $type && is_int( $value ) )
                || ( 'number' === $type && ( is_int( $value ) || is_float( $value ) )
                    && is_finite( (float) $value ) );
            if ( $valid && is_array( $field['enum'] ) ) $valid = in_array( $value, $field['enum'], true );
            if ( ! $valid ) $issues[] = 'field_type_or_constraint_invalid';
        }
        foreach ( $map as $key => $field ) {
            if ( ! empty( $field['required'] ) && ! array_key_exists( $key, $values ) )
                $issues[] = 'required_field_absent';
        }
        return array(
            'contract' => self::CONTRACT . '.validation.v1',
            'valid' => empty( $issues ),
            'issues' => array_values( array_unique( $issues ) ),
            'values_echoed' => false, 'saved' => false, 'authorizing' => false,
        );
    }

    public static function form_validate( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) !== 3
            || ! isset( $input['ability_name'], $input['expected_descriptor_sha256'], $input['values'] )
            || ! is_array( $input['values'] )
            || ! is_string( $input['expected_descriptor_sha256'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $input['expected_descriptor_sha256'] ) )
            return self::error( 'validation_input_invalid' );
        $form = self::form_schema( array( 'ability_name' => $input['ability_name'] ) );
        if ( is_wp_error( $form ) ) return $form;
        if ( ! hash_equals( $form['descriptor_sha256'], $input['expected_descriptor_sha256'] ) )
            return self::error( 'stale_descriptor' );
        $result = self::validate_values( $form['fields'], $input['values'] );
        if ( is_wp_error( $result ) ) return $result;
        $result['site'] = $form['site'];
        $result['descriptor_sha256'] = $form['descriptor_sha256'];
        return $result;
    }

    public static function form_explain( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) !== 2
            || ! isset( $input['ability_name'], $input['field'] )
            || ! is_string( $input['field'] ) ) return self::error( 'explain_input_invalid' );
        $form = self::form_schema( array( 'ability_name' => $input['ability_name'] ) );
        if ( is_wp_error( $form ) ) return $form;
        foreach ( $form['fields'] as $field ) if ( $field['key'] === $input['field'] ) {
            return array( 'contract' => self::CONTRACT . '.explain.v1',
                'field' => $field, 'site' => $form['site'],
                'explanation' => 'Schema field from an explicitly enumerated read-only WordPress Ability. Storage and side effects are not certified.',
                'write_supported' => false, 'secret_supported' => false,
                'authorizing' => false );
        }
        return self::error( 'field_unknown' );
    }
}
