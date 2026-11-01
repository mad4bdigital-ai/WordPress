<?php
/**
 * Explicit WP-CLI operator recovery of build-bound Staging Skill certification.
 *
 * Usage: export MAD4B_STAGING_MCP_RECERTIFY='CERTIFY CURRENT STAGING SKILLS'
 * and MAD4B_STAGING_EXPECTED_SHA, MAD4B_STAGING_EXPECTED_ORIGIN,
 * MAD4B_STAGING_CERT_BACKUP_PATH; run via wp eval-file on the exact Staging site.
 * Never executed from web, hooks, or a passive MCP request.
 */
if ( ! class_exists( 'MAD4B_Staging_MCP_Skills_Recertify', false ) ) {
final class MAD4B_Staging_MCP_Skills_Recertify {
    const CONFIRMATION = 'CERTIFY CURRENT STAGING SKILLS';
    public static function preflight( array $e ) {
        if ( 'staging' !== ( $e['environment'] ?? '' ) ) return 'non_staging_denied';
        $sha = $e['current_sha'] ?? '';
        $expected = $e['expected_sha'] ?? '';
        if ( ! is_string( $sha ) || ! is_string( $expected )
            || ! preg_match( '/^[a-f0-9]{40}$/D', $sha )
            || ! hash_equals( $sha, $expected ) ) return 'exact_build_mismatch';
        $origin = $e['origin'] ?? '';
        $expected_origin = $e['expected_origin'] ?? '';
        if ( ! is_string( $origin ) || ! is_string( $expected_origin )
            || '' === $expected_origin || ! hash_equals( $origin, $expected_origin ) ) return 'origin_mismatch';
        if ( empty( $e['skills_live_ready'] ) || empty( $e['skills_build_provenance_ready'] ) ) return 'live_skills_not_ready';
        if ( empty( $e['audit_ready'] ) ) return 'audit_storage_not_ready';
        return 'eligible';
    }

    public static function execute() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) throw new RuntimeException( 'WP_CLI_only' );
        if ( self::CONFIRMATION !== getenv( 'MAD4B_STAGING_MCP_RECERTIFY' ) ) WP_CLI::error( 'explicit_operator_confirmation_required' );
        $backup = getenv( 'MAD4B_STAGING_CERT_BACKUP_PATH' );
        $real = is_string( $backup ) ? realpath( $backup ) : false;
        $site = realpath( ABSPATH );
        if ( ! $real || ! $site || strpos( $real, $site . DIRECTORY_SEPARATOR ) === 0
            || ! is_file( $real ) || ! is_readable( $real ) || filesize( $real ) < 50 ) {
            WP_CLI::error( 'protected_certification_backup_required_outside_webroot' );
        }
        foreach ( array( 'MAD4B_SCP_Site_Profile', 'MAD4B_SCP_Runtime_Convergence',
            'MAD4B_SCP_Skill_Runtime_Certification', 'MAD4B_SCP_Audit' ) as $class ) {
            if ( ! class_exists( $class ) ) WP_CLI::error( 'runtime_class_unavailable' );
        }
        $runtime = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
        $live = MAD4B_SCP_Skill_Runtime_Certification::current_status();
        $old = MAD4B_SCP_Skill_Runtime_Certification::persisted_status();
        $audit = MAD4B_SCP_Audit::storage_status();
        $state = self::preflight( array(
            'environment' => MAD4B_SCP_Site_Profile::current_environment(),
            'current_sha' => $runtime['current_identity']['source_commit_sha'] ?? '',
            'expected_sha' => getenv( 'MAD4B_STAGING_EXPECTED_SHA' ),
            'origin' => rtrim( home_url( '/' ), '/' ),
            'expected_origin' => rtrim( (string) getenv( 'MAD4B_STAGING_EXPECTED_ORIGIN' ), '/' ),
            'skills_live_ready' => ! empty( $live['ready'] ),
            'skills_build_provenance_ready' => ! empty( $live['checks']['build_provenance_identity_ready'] ),
            'audit_ready' => ! empty( $audit['ready'] ),
        ) );
        if ( 'eligible' !== $state ) WP_CLI::error( $state );
        if ( ! empty( $old['ready'] ) && ! empty( $old['build_identity_current'] ) ) {
            WP_CLI::line( 'ALREADY_CURRENT: no certification mutation' );
            return;
        }
        $checkpoint_before = get_option( MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION, array() );
        $written = MAD4B_SCP_Skill_Runtime_Certification::observe( true );
        $new = MAD4B_SCP_Skill_Runtime_Certification::persisted_status();
        if ( empty( $written['ready'] ) || empty( $new['ready'] ) || empty( $new['build_identity_current'] ) )
            WP_CLI::error( 'certification_readback_failed' );
        $checkpoint_after = get_option( MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION, array() );
        if ( serialize( $checkpoint_before ) !== serialize( $checkpoint_after ) )
            WP_CLI::error( 'checkpoint_changed_unexpectedly' );
        WP_CLI::line( 'CERTIFIED_CURRENT_BUILD_SKILLS_OK' );
        WP_CLI::line( 'Owner/write authority remains separately gated; authenticated MCP acceptance required.' );
    }
}
}
if ( defined( 'WP_CLI' ) && WP_CLI ) MAD4B_Staging_MCP_Skills_Recertify::execute();
