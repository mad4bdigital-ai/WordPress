<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Scope-bound reusable, nonsecret form recipe; not a write grant. */
final class MAD4B_SCP_CSO_Templates {
    const CONTRACT = 'mad4b.cso.template-plan.v1';

    public static function plan( $form, $recipe ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'forms' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() ||
            ! is_array( $form ) || ! is_array( $recipe ) ||
            array_diff( array_keys( $recipe ), array( 'label', 'locale', 'values' ) ) ||
            ! is_array( $recipe['values'] ?? null ) ||
            count( $recipe['values'] ) > 24 ||
            ! MAD4B_SCP_CSO_Scope::safe_data( $recipe ) ||
            ! MAD4B_SCP_CSO_Scope::bounded( $recipe ) )
            return MAD4B_SCP_CSO_Scope::error( 'TEMPLATE_INPUT_UNSAFE' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        $result = MAD4B_SCP_CSO_Forms::validate( $form, $recipe['values'] );
        if ( is_wp_error( $result ) || empty( $result['valid'] ) )
            return MAD4B_SCP_CSO_Scope::error( 'TEMPLATE_DESCRIPTOR_STALE_OR_INVALID' );
        $label = (string) ( $recipe['label'] ?? '' );
        if ( '' === trim( $label ) || strlen( $label ) > 120 )
            return MAD4B_SCP_CSO_Scope::error( 'TEMPLATE_LABEL_INVALID' );
        $locale = $recipe['locale'] ?? ( $scope['locale'] ?? 'en_US' );
        if ( ! is_string( $locale ) ||
            ! preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/D', $locale ) )
            return MAD4B_SCP_CSO_Scope::error( 'TEMPLATE_LOCALE_INVALID' );
        $material = array(
            'contract' => self::CONTRACT,
            'scope_sha256' => $scope['binding_sha256'],
            'actor_sha256' => $scope['actor_sha256'],
            'descriptor' => $form,
            'label' => $label,
            'locale' => $locale,
            'values' => $recipe['values'],
            'expires_at' => time() + 600,
            'authorization' => 'none' );
        $seal = MAD4B_SCP_CSO_Scope::seal( $material, self::CONTRACT );
        if ( is_wp_error( $seal ) ) return $seal;
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return MAD4B_SCP_CSO_Scope::error( 'TEMPLATE_SCOPE_CHANGED' );
        return array( 'contract' => self::CONTRACT,
            'template_plan' => $seal,
            'digest' => MAD4B_SCP_CSO_Scope::digest( $material ),
            'site_binding_required' => true, 'approval_issued' => false,
            'saved' => false, 'mutation_performed' => false );
    }
}
