<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Non-authorizing site UI context and bounded provider-readiness projections. */
trait MAD4B_SCP_CSO01_Read_Context_Provider {
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
}
