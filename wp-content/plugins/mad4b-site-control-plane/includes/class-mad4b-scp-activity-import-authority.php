<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP03 governance: the SOURCE NEVER supplies its own validation authority.
 * Existing sites without an approved validation policy remain preview-blocked
 * rather than silently retaining caller-controlled commercial safeguards.
 */
final class MAD4B_SCP_Activity_Import_Authority {
    const CONTRACT = 'mad4b.activity-import-authority.v1';
    private static function err( $code, $message ) { return new WP_Error( $code, $message ); }
    public static function digest( $value ) {
        $json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    public static function normalize( $raw, $meta_keys ) {
        if ( ! is_array( $raw ) || array_diff( array_keys( $raw ), array(
            'identity_field', 'required_columns', 'allowed_currencies',
            'field_mapping', 'price_tier_policy', 'review_past_intervals',
            'wpml_languages', 'require_complete_wpml_groups',
            'required_relationships', 'max_rows', 'currency_field',
            'price_fields', 'decimal_scale', 'destination_identity_meta_key',
            'period_start_field', 'period_end_field', 'period_format'
        ) ) ) return self::err( 'mad4b_import_validation_unknown_keys', 'Validation policy contains unsupported fields.' );
        $identity = isset( $raw['identity_field'] ) ? (string) $raw['identity_field'] : '';
        if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $identity ) )
            return self::err( 'mad4b_import_policy_identity_invalid', 'Approved stable identity source column required.' );
        $columns = isset( $raw['required_columns'] ) ? $raw['required_columns'] : array();
        $currencies = isset( $raw['allowed_currencies'] ) ? $raw['allowed_currencies'] : array();
        $mapping = isset( $raw['field_mapping'] ) ? $raw['field_mapping'] : array();
        $languages = isset( $raw['wpml_languages'] ) ? $raw['wpml_languages'] : array();
        $relationships = isset( $raw['required_relationships'] ) ? $raw['required_relationships'] : array();
        $currency_field = isset( $raw['currency_field'] ) ? (string) $raw['currency_field'] : '';
        $price_fields = isset( $raw['price_fields'] ) ? $raw['price_fields'] : array();
        $decimal_scale = isset( $raw['decimal_scale'] ) ? $raw['decimal_scale'] : 4;
        $period_start = isset( $raw['period_start_field'] ) ?
            (string) $raw['period_start_field'] : '';
        $period_end = isset( $raw['period_end_field'] ) ?
            (string) $raw['period_end_field'] : '';
        $period_format = isset( $raw['period_format'] ) ?
            (string) $raw['period_format'] : 'iso_date';
        $destination_identity_meta_key = isset( $raw['destination_identity_meta_key'] )
            ? (string) $raw['destination_identity_meta_key'] : '';
        $safe_cols = static function( $x, $maximum ) {
            if ( ! is_array( $x ) || count( $x ) > $maximum ) return false;
            foreach ( $x as $val ) if ( ! is_string( $val ) ||
                ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $val ) ) return false;
            return count( $x ) === count( array_unique( $x ) );
        };
        if ( ! $safe_cols( $columns, 80 ) || ! $safe_cols( $relationships, 20 ) ||
            ! $safe_cols( $price_fields, 20 ) || ! is_int( $decimal_scale ) ||
            $decimal_scale < 0 || $decimal_scale > 6 ||
            ! in_array( $period_format, array( 'iso_date', 'unix_seconds' ), true ) ||
            ( ( '' === $period_start ) !== ( '' === $period_end ) ) ||
            ( '' !== $period_start && (
                ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $period_start ) ||
                ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $period_end ) ) ) ||
            ( '' !== $currency_field && ! preg_match(
                '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $currency_field ) ) ||
            ! is_array( $currencies ) || count( $currencies ) > 20 ||
            ! is_array( $mapping ) || count( $mapping ) < 1 || count( $mapping ) > 80 ||
            ! is_array( $languages ) || count( $languages ) > 20 )
            return self::err( 'mad4b_import_policy_bounds', 'Identity, mapping, currency and required-column policy must be bounded.' );
        if ( '' !== $currency_field && !$currencies )
            return self::err( 'mad4b_import_policy_currency_required',
                'Commercial currency fields require an administrator-owned currency allowlist.' );
        $currency_set = array();
        foreach ( $currencies as $currency ) {
            if ( ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) ||
                isset( $currency_set[ $currency ] ) )
                return self::err( 'mad4b_import_policy_currency_invalid', 'Approved three-letter currency allowlist invalid.' );
            $currency_set[ $currency ] = true;
        }
        $approved_meta = array_fill_keys( (array) $meta_keys, true );
        if ( '' !== $destination_identity_meta_key &&
            ! isset( $approved_meta[ $destination_identity_meta_key ] ) )
            return self::err( 'mad4b_import_identity_destination_not_allowed',
                'WordPress identity metadata must be explicitly allowlisted by the Profile.' );
        $approved_wpml = array_fill_keys( array(
            '_wpml_import_language_code', '_wpml_import_source_language_code',
            '_wpml_import_translation_group', '_wpml_import_after_process_post_status'
        ), true );
        if ( count( array_unique( array_values( $mapping ) ) ) !== count( $mapping ) )
            return self::err( 'mad4b_import_duplicate_destination_mapping',
                'Import source columns must not compete for the same destination field.' );
        foreach ( $mapping as $from => $to ) {
            if ( ! is_string( $from ) ||
                ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $from ) ||
                ! is_string( $to ) ||
                ( ! isset( $approved_meta[ $to ] ) && ! isset( $approved_wpml[ $to ] ) ) )
                return self::err( 'mad4b_import_policy_mapping_not_allowed', 'Every destination must be approved by the Content Experience Profile.' );
        }
        foreach ( $languages as $language )
            if ( ! is_string( $language ) ||
                ! preg_match( '/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/D', $language ) )
                return self::err( 'mad4b_import_policy_wpml_language_invalid', 'Invalid governed language code.' );
        if ( count( $languages ) !== count( array_unique( $languages ) ) ||
            ( ! empty( $raw['require_complete_wpml_groups'] ) && !$languages ) )
            return self::err( 'mad4b_import_policy_wpml_incomplete', 'Complete WPML groups require explicitly configured languages.' );
        $price_policy = isset( $raw['price_tier_policy'] ) ? (string) $raw['price_tier_policy'] : 'none';
        if ( ! in_array( $price_policy, array( 'none', 'review_monotonic' ), true ) ||
            ( 'review_monotonic' === $price_policy && count( $price_fields ) < 2 ) )
            return self::err( 'mad4b_import_policy_price_invalid',
                'An ordered pair of explicitly configured price fields is required for tier comparison.' );
        $max_rows = isset( $raw['max_rows'] ) ? (int) $raw['max_rows'] : 500;
        if ( $max_rows < 1 || $max_rows > 500 )
            return self::err( 'mad4b_import_policy_max_rows_invalid', 'Allowed preview row limit is 1..500.' );
        return array(
            'identity_field' => $identity, 'required_columns' => array_values( $columns ),
            'allowed_currencies' => array_keys( $currency_set ),
            'field_mapping' => $mapping,
            'price_tier_policy' => $price_policy,
            'review_past_intervals' => ! empty( $raw['review_past_intervals'] ),
            'wpml_languages' => array_values( $languages ),
            'require_complete_wpml_groups' => ! empty( $raw['require_complete_wpml_groups'] ),
            'required_relationships' => array_values( $relationships ),
            'currency_field' => $currency_field,
            'price_fields' => array_values( $price_fields ),
            'decimal_scale' => $decimal_scale,
            'destination_identity_meta_key' => $destination_identity_meta_key,
            'period_start_field' => $period_start,
            'period_end_field' => $period_end,
            'period_format' => $period_format,
            'max_rows' => $max_rows
        );
    }
    /** Independent, optional profile-level import contract; no user role/link required. */
    public static function normalize_contract( $raw, $meta_keys ) {
        if ( null === $raw || array() === $raw || false === $raw ) return array( 'enabled' => false );
        if ( ! is_array( $raw ) || array_diff( array_keys( $raw ),
            array( 'enabled', 'validation', 'enabled_modes', 'preferred_mode',
                'fallback_modes', 'manual_review_required', 'configured_explicitly',
                'auto_execute' ) ) )
            return self::err( 'mad4b_import_contract_unknown_fields', 'Unsupported import contract options.' );
        if ( array_key_exists( 'auto_execute', $raw ) &&
            false !== $raw['auto_execute'] )
            return self::err( 'mad4b_import_implicit_execution_denied',
                'Automatic import execution cannot be enabled by a profile.' );
        if ( empty( $raw['enabled'] ) ) return array( 'enabled' => false );
        $enabled = isset( $raw['enabled_modes'] ) ? $raw['enabled_modes'] : array();
        $fallback = isset( $raw['fallback_modes'] ) ? $raw['fallback_modes'] : array();
        $preferred = isset( $raw['preferred_mode'] ) ? (string) $raw['preferred_mode'] : '';
        if ( ! is_array( $enabled ) || count( $enabled ) > 40 ||
            ! is_array( $fallback ) || count( $fallback ) > 40 )
            return self::err( 'mad4b_import_contract_mode_bounds', 'Import alternatives exceed policy limits.' );
        foreach ( array_merge( $enabled, $fallback ) as $id )
            if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_]{2,64}$/D', $id ) )
                return self::err( 'mad4b_import_contract_mode_invalid', 'Only registered safe Mode IDs are accepted.' );
        if ( count( $enabled ) !== count( array_unique( $enabled ) ) ||
            count( $fallback ) !== count( array_unique( $fallback ) ) ||
            array_diff( $fallback, $enabled ) ||
            ( $preferred && ! in_array( $preferred, $enabled, true ) ) ||
            ( isset( $raw['manual_review_required'] ) && true !== $raw['manual_review_required'] ) )
            return self::err( 'mad4b_import_contract_mode_policy_invalid', 'Approved Mode allowlist and human review are mandatory.' );
        $validation = self::normalize( isset( $raw['validation'] ) ? $raw['validation'] : null, $meta_keys );
        if ( is_wp_error( $validation ) ) return $validation;
        return array( 'enabled' => true, 'validation' => $validation,
            'enabled_modes' => array_values( $enabled ),
            'preferred_mode' => $preferred,
            'fallback_modes' => array_values( $fallback ),
            'manual_review_required' => true,
            'auto_execute' => false
        );
    }
    public static function profile_contract( $profile ) {
        if ( ! empty( $profile['import_contract']['enabled'] ) )
            return $profile['import_contract'];
        if ( ! empty( $profile['import_contract']['configured_explicitly'] ) )
            return array(); // Explicitly disabled: never resurrect legacy authority.
        if ( ! empty( $profile['activity_contract']['enabled'] ) &&
            ! empty( $profile['activity_contract']['import_modes']['validation'] ) )
            return $profile['activity_contract']['import_modes'];
        return array();
    }
    public static function resolve( $profile, $input ) {
        $contract = self::profile_contract( $profile );
        $policy = isset( $contract['validation'] ) ? $contract['validation'] : null;
        if ( ! is_array( $policy ) || !$policy )
            return self::err( 'mad4b_import_site_validation_not_configured',
                'Commercial import requires an approved site-owned validation policy.' );
        if ( ! is_array( $input ) ) return self::err( 'mad4b_import_source_invalid', 'Invalid input.');
        if ( array_diff( array_keys( $input ), array(
            'profile_slug', 'rows', 'headers', 'identity_field', 'field_mapping',
            'allowed_currencies', 'price_tier_policy', 'review_past_intervals'
        ) ) ) return self::err( 'mad4b_import_source_unknown_fields',
            'Source may submit rows and legacy-compatible requests only, never new validation authority.' );
        foreach ( array( 'identity_field', 'field_mapping', 'allowed_currencies',
            'price_tier_policy', 'review_past_intervals' ) as $name ) {
            if ( array_key_exists( $name, $input ) &&
                ( ! in_array( $name, array( 'field_mapping' ), true ) || ! empty( $input[ $name ] ) ) &&
                self::digest( $input[ $name ] ) !== self::digest( $policy[ $name ] ) )
                return self::err( 'mad4b_import_source_cannot_override_policy',
                    'Source-supplied commercial validation differs from approved site policy.' );
        }
        $headers = isset( $input['headers'] ) ? $input['headers'] : array();
        if ( ! is_array( $headers ) || array_diff( $policy['required_columns'], $headers ) ||
            ! in_array( $policy['identity_field'], $headers, true ) ||
            array_diff( array_keys( $policy['field_mapping'] ), $headers ) ||
            array_diff( $policy['price_fields'], $headers ) ||
            ( ! empty( $policy['period_start_field'] ) &&
                ( ! in_array( $policy['period_start_field'], $headers, true ) ||
                  ! in_array( $policy['period_end_field'], $headers, true ) ) ) ||
            ( '' !== $policy['currency_field'] &&
              ! in_array( $policy['currency_field'], $headers, true ) ) ||
            ( ! empty( $policy['require_complete_wpml_groups'] ) &&
              ( ! in_array( '_wpml_import_translation_group', $headers, true ) ||
                ! in_array( '_wpml_import_language_code', $headers, true ) ) ) )
            return self::err( 'mad4b_import_required_columns_missing',
                'Required site-owned fields or approved mappings are missing from source.' );
        return array( 'policy' => $policy, 'policy_sha256' => self::digest( $policy ) );
    }
}
