<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Three independent, source-neutral Cross-Plugin lifecycle PRE-FLIGHTS.
 * These are deliberately READ-ONLY. Certification is a separate repository
 * review; Staging mutation belongs to the existing central-ticket
 * mad4b/plugin-package-apply ability. Production deployment remains
 * separately certified and is never inherited from Staging.
 */
final class MAD4B_SCP_Cross_Plugin_Lifecycle {
    const CONTRACT = 'mad4b.cross-plugin-lifecycle.v1';

    private static function row( $input ) {
        if ( ! is_array( $input ) ) return new WP_Error( 'cross_plugin_input_invalid', 'Expected an object.' );
        $id = isset( $input['source_id'] ) ? (string) $input['source_id'] : '';
        if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id )
            || ! class_exists( 'MAD4B_SCP_Cross_Plugin_Deployment' ) )
            return new WP_Error( 'cross_plugin_source_invalid', 'Provide one reviewed source ID.' );
        $sources = MAD4B_SCP_Cross_Plugin_Deployment::sources();
        if ( ! isset( $sources[ $id ] ) ) return new WP_Error( 'cross_plugin_source_unreviewed', 'Unknown source.' );
        return $sources[ $id ];
    }

    private static function certificate( array $row ) {
        $blockers = array();
        $expected_sha = (string) ( $row['candidate_source_commit_sha'] ?? '' );
        $expected_version = (string) ( $row['candidate_artifact_version'] ?? '' );
        $sha = (string) ( $row['source_commit_sha'] ?? '' );
        $archive_sha = (string) ( $row['artifact_sha256'] ?? '' );
        $version = (string) ( $row['artifact_version'] ?? '' );
        if ( 'source_reviewed_artifact_certified' !== (string) ( $row['review_state'] ?? '' ) )
            $blockers[] = 'source_review_not_certified';
        if ( 'certified' !== (string) ( $row['package_certification'] ?? '' ) )
            $blockers[] = 'package_certification_pending';
        if ( ! preg_match( '/^[a-f0-9]{40}$/D', $sha ) || $sha !== $expected_sha )
            $blockers[] = 'source_commit_not_exact_reviewed_candidate';
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $archive_sha ) )
            $blockers[] = 'archive_sha256_not_certified';
        if ( '' === $version || $version !== $expected_version )
            $blockers[] = 'version_not_exact_reviewed_candidate';
        if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) {
            $blockers[] = 'independent_provider_certificate_unavailable';
        } else {
            $provider = MAD4B_SCP_Provider_Contracts::get( (string) ( $row['provider_id'] ?? '' ) );
            if ( ! is_array( $provider ) || empty( $provider )
                || (string) ( $provider['plugin_file'] ?? '' ) !== (string) $row['plugin_file']
                || (string) ( $provider['archive_sha256'] ?? '' ) !== $archive_sha
                || (string) ( $provider['version'] ?? '' ) !== $version )
                $blockers[] = 'independent_provider_certificate_mismatch';
        }
        sort( $blockers, SORT_STRING );
        return $blockers;
    }

    public static function certification_plan( $input ) {
        $row = self::row( $input );
        if ( is_wp_error( $row ) ) return $row;
        $blockers = self::certificate( $row );
        return array(
            'contract' => self::CONTRACT, 'phase' => 'certification',
            'source_id' => $row['id'],
            'repository' => $row['repository'],
            'candidate_source_commit_sha' => (string) ( $row['candidate_source_commit_sha'] ?? '' ),
            'candidate_artifact_version' => (string) ( $row['candidate_artifact_version'] ?? '' ),
            'candidate_is_certified' => empty( $blockers ),
            'certified_archive_sha256' => (string) ( $row['artifact_sha256'] ?? '' ),
            'blockers' => $blockers,
            'required_evidence' => array(
                'deterministic_zip_sha256_and_file_manifest', 'independent_source_review',
                'immutable_artifact_publication', 'exact_provider_certificate',
                'source_registry_certification_review',
            ),
            'next_phase' => empty( $blockers ) ? 'staging_apply_review' : 'independent_certification_review',
            'authorizing' => false, 'mutation_performed' => false,
        );
    }

    private static function environment() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! function_exists( 'wp_get_environment_type' )
            || ! MAD4B_SCP_Site_Profile::origin_enrolled() ) return 'not_verified';
        $enrolled = (string) MAD4B_SCP_Site_Profile::current_environment();
        $reported = (string) wp_get_environment_type();
        return $enrolled === $reported && in_array( $reported, array( 'staging', 'production' ), true )
            ? $reported : 'not_verified';
    }

    public static function staging_apply_plan( $input ) {
        $row = self::row( $input );
        if ( is_wp_error( $row ) ) return $row;
        $blockers = self::certificate( $row );
        if ( 'staging' !== self::environment() ) $blockers[] = 'exact_enrolled_staging_environment_required';
        $native = array();
        if ( ! $blockers ) {
            $native = MAD4B_SCP_Cross_Plugin_Deployment::plan( array(
                'source_id' => $row['id'], 'ref_type' => 'commit',
                'reference' => (string) $row['source_commit_sha'],
                'reason' => (string) ( $input['reason'] ?? 'Exact certified cross-plugin staging review' ),
            ) );
            if ( is_wp_error( $native ) ) {
                $blockers[] = $native->get_error_code();
                $native = array();
            } elseif ( empty( $native['eligible'] ) ) {
                $blockers = array_merge( $blockers, (array) ( $native['blockers'] ?? array( 'native_plan_ineligible' ) ) );
            }
        }
        $blockers = array_values( array_unique( $blockers ) );
        sort( $blockers, SORT_STRING );
        return array(
            'contract' => self::CONTRACT, 'phase' => 'staging_apply',
            'source_id' => $row['id'], 'environment' => self::environment(),
            'eligible_for_separate_owner_authorization' => ! $blockers,
            'blockers' => $blockers,
            'native_executor' => ! $blockers ? 'mad4b/plugin-package-apply' : '',
            'native_apply_input' => ! $blockers ? (array) ( $native['native_apply_input'] ?? array() ) : array(),
            'native_plan_sha256' => ! $blockers ? (string) ( $native['native_plan_sha256'] ?? '' ) : '',
            'owner_review_and_oauth_step_up_required' => true,
            'disk_backup_activation_readback_rollback_required' => true,
            'production_allowed' => false, 'authorizing' => false, 'mutation_performed' => false,
        );
    }

    public static function production_promotion_plan( $input ) {
        $row = self::row( $input );
        if ( is_wp_error( $row ) ) return $row;
        $blockers = self::certificate( $row );
        if ( 'production' !== self::environment() ) $blockers[] = 'exact_enrolled_production_environment_required';
        if ( empty( $row['production_promotion_attestation'] ) )
            $blockers[] = 'independent_production_promotion_attestation_required';
        // An arbitrary manifest string is not proof of an enrolled-owner,
        // digest-bound, one-use production approval.
        $blockers[] = 'independent_production_executor_not_certified';
        $blockers = array_values( array_unique( $blockers ) );
        sort( $blockers, SORT_STRING );
        return array(
            'contract' => self::CONTRACT, 'phase' => 'production_promotion',
            'source_id' => $row['id'], 'environment' => self::environment(),
            'eligible' => false, 'blockers' => $blockers,
            'required_evidence' => array(
                'same_exact_immutable_package_as_staging',
                'independent_staging_success_and_rollback_readback',
                'fresh_digest_bound_production_owner_review_and_oauth_step_up',
                'separately_certified_production_executor_and_postcondition',
            ),
            'inherited_staging_authority' => false, 'production_executor' => '',
            'authorizing' => false, 'mutation_performed' => false,
        );
    }
}
