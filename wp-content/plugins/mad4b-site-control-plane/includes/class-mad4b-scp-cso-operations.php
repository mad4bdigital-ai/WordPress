<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded operator diagnostics; never schedules monitoring or self-remediation. */
final class MAD4B_SCP_CSO_Operations {
    const CONTRACT = 'mad4b.cso.doctor-plan.v1';
    public static function doctor_plan( $input ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'operations' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() ||
            ! is_array( $input ) ||
            array_diff( array_keys( $input ),
                array( 'scope','domain','diagnostic_input','preparation' ) ) ||
            ! is_array( $input['scope'] ?? null ) ||
            ! MAD4B_SCP_CSO_Scope::bounded( $input ) ||
            ! MAD4B_SCP_CSO_Scope::safe_data( $input ) )
            return MAD4B_SCP_CSO_Scope::error( 'DOCTOR_INPUT_INVALID' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        if ( ! isset( $input['scope']['site_uuid'] ) ||
            count( $input['scope'] ) !== 1 ||
            ! hash_equals( (string) $scope['site_uuid'],
                (string) $input['scope']['site_uuid'] ) )
            return MAD4B_SCP_CSO_Scope::error( 'DOCTOR_FOREIGN_SITE' );
        $domain = $input['domain'] ?? 'readiness';
        if ( ! in_array( $domain,
            array( 'readiness', 'forms', 'adapters', 'privacy', 'recovery' ), true ) )
            return MAD4B_SCP_CSO_Scope::error( 'DOCTOR_DOMAIN_UNKNOWN' );
        $blockers = array(
            'native_write_executor_not_certified',
            'staging_mysql_races_not_verified',
            'exact_installed_release_not_attested',
            'no_independent_browser_or_mcp_receipt',
            'no_production_grant' );
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return MAD4B_SCP_CSO_Scope::error( 'DOCTOR_SCOPE_CHANGED' );
        return array( 'contract' => self::CONTRACT,
            'domain' => $domain, 'site_uuid' => $scope['site_uuid'],
            'context_hash' => $scope['binding_sha256'],
            'blockers' => $blockers, 'status' => 'IMPLEMENTATION_NOT_LIVE_CERTIFIED',
            'mutation_performed' => false, 'monitor_created' => false,
            'recovery_executed' => false, 'production_approved' => false );
    }

    public static function monitor_plan( $input ) {
        return MAD4B_SCP_CSO_Scope::error( 'MONITOR_RUNTIME_NOT_CERTIFIED' );
    }
    public static function drift_plan( $input ) {
        return MAD4B_SCP_CSO_Scope::error( 'DRIFT_RUNTIME_NOT_CERTIFIED' );
    }
    public static function metrics_plan( $input ) {
        return MAD4B_SCP_CSO_Scope::error( 'METRICS_RUNTIME_NOT_CERTIFIED' );
    }
    public static function accessibility_report( $input ) {
        return MAD4B_SCP_CSO_Scope::error( 'LIVE_ACCESSIBILITY_NOT_CERTIFIED' );
    }
}
