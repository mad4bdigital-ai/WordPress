<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded CSO form projection over CSO01's actual read-only validator.
 * An untrusted client form is never treated as an authority or a stored schema.
 */
final class MAD4B_SCP_CSO_Forms {
    private static function owner() {
        return class_exists( 'MAD4B_SCP_CSO01_Read_Foundation', false ) &&
            MAD4B_SCP_CSO01_Read_Foundation::can_read();
    }
    public static function schema( $ability, $target = array() ) {
        if ( ! self::owner() || ! is_string( $ability ) ||
            ! preg_match( '~^[a-z0-9][a-z0-9._-]{0,95}/[a-z0-9][a-z0-9._-]{0,95}$~D', $ability ) ||
            ! is_array( $target ) || $target ||
            ! MAD4B_SCP_CSO_Scope::enabled( 'forms' ) )
            return MAD4B_SCP_CSO_Scope::error( 'FORM_NOT_CERTIFIED_OR_TARGET_UNSUPPORTED' );
        $form = MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => $ability ) );
        if ( is_wp_error( $form ) || ! is_array( $form ) ||
            ! isset( $form['descriptor_sha256'], $form['fields'] ) ||
            ! is_array( $form['fields'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) $form['descriptor_sha256'] ) )
            return MAD4B_SCP_CSO_Scope::error( 'FORM_DESCRIPTOR_UNAVAILABLE' );
        $form['sealed_form'] = array( 'ability_name' => $ability,
            'expected_descriptor_sha256' => $form['descriptor_sha256'] );
        $form['write_supported'] = false;
        $form['authorizing'] = false;
        return $form;
    }
    public static function validate( $form, $values ) {
        if ( ! self::owner() || ! is_array( $form ) ||
            array_diff( array_keys( $form ), array( 'ability_name','expected_descriptor_sha256' ) ) ||
            ! isset( $form['ability_name'], $form['expected_descriptor_sha256'] ) ||
            ! is_array( $values ) || true !== MAD4B_SCP_CSO_Scope::safe_data( $values ) )
            return MAD4B_SCP_CSO_Scope::error( 'UNTRUSTED_FORM_OR_SECRET_VALUES' );
        $out = MAD4B_SCP_CSO01_Read_Foundation::form_validate( array(
            'ability_name' => $form['ability_name'],
            'expected_descriptor_sha256' => $form['expected_descriptor_sha256'],
            'values' => $values ) );
        if ( is_wp_error( $out ) ) return $out;
        $out['status'] = ! empty( $out['valid'] ) ? 'VALIDATED' : 'INVALID';
        $out['saved'] = false; $out['authorizing'] = false;
        return $out;
    }
    public static function explain( $form, $field ) {
        if ( ! self::owner() || ! is_array( $form ) ||
            ! isset( $form['ability_name'], $form['expected_descriptor_sha256'] ) ||
            ! is_string( $field ) || strlen( $field ) > 80 )
            return MAD4B_SCP_CSO_Scope::error( 'FIELD_HELP_UNAVAILABLE' );
        $fresh = self::schema( $form['ability_name'] );
        if ( is_wp_error( $fresh ) || ! hash_equals(
            (string) $fresh['descriptor_sha256'], (string) $form['expected_descriptor_sha256'] ) )
            return MAD4B_SCP_CSO_Scope::error( 'FORM_DESCRIPTOR_STALE' );
        return MAD4B_SCP_CSO01_Read_Foundation::form_explain(
            array( 'ability_name' => $form['ability_name'], 'field' => $field ) );
    }
}
