<?php
/**
 * MAD4B Unified Operational Integrity — versioned, non-authorizing scope fence.
 *
 * Keep this inside Site Control Plane, not a separate plugin. Each operation
 * captures a trusted, revision-bound scope, then verifies the exact fence again
 * before irreversible / externally visible effects or an SQL COMMIT.
 *
 * This component NEVER issues a grant, reads untrusted identity assertions as
 * authority, certifies a provider, or declares MCP/Host acceptance.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Operational_Integrity {
    const CONTRACT = 'mad4b.unified-operational-integrity.v1';
    const REQUIRED_SCOPE = array( 'tenant_ref', 'brand_ref', 'site_uuid', 'blog_id', 'network_id', 'environment', 'deployment_mode' );

    public static function capture() {
        if ( ! class_exists( 'MAD4B_SCP_Deployment_Mode_Resolver' ) ) {
            return new WP_Error( 'mad4b_integrity_resolver_missing', 'Trusted deployment resolver is not installed.' );
        }
        $resolved = MAD4B_SCP_Deployment_Mode_Resolver::resolve();
        if ( ! is_array( $resolved ) || 'RESOLVED_FOR_REVIEW_ONLY' !== ( $resolved['status'] ?? '' ) ||
            ! isset( $resolved['scope'] ) || ! is_array( $resolved['scope'] ) ) {
            return new WP_Error( 'mad4b_integrity_scope_blocked', 'Trusted deployment identity is unavailable.' );
        }
        $scope = $resolved['scope'];
        foreach ( self::REQUIRED_SCOPE as $name ) {
            if ( ! isset( $scope[ $name ] ) || (string) $scope[ $name ] === '' ) {
                return new WP_Error( 'mad4b_integrity_scope_incomplete', 'Trusted scope lacks mandatory identity fields.' );
            }
        }
        if ( 'wordpress_dedicated' !== (string) $scope['deployment_mode'] ||
            ! preg_match( '/^[a-f0-9-]{36}$/D', (string) $scope['site_uuid'] ) ||
            ! preg_match( '/^[a-f0-9]{32}$/D', (string) $scope['brand_ref'] ) ||
            ! hash_equals( 'wp-site:' . $scope['site_uuid'], (string) $scope['tenant_ref'] ) ||
            (int) $scope['blog_id'] < 1 || (int) $scope['network_id'] < 1 ||
            ! in_array( (string) $scope['environment'], array( 'local', 'development', 'staging', 'production' ), true ) ) {
            return new WP_Error( 'mad4b_integrity_scope_invalid', 'Trusted scope is not a valid WordPress Dedicated binding.' );
        }
        $revisions = $resolved['dependency_revision'] ?? null;
        if ( ! is_array( $revisions ) || (int) ( $revisions['site_profile'] ?? 0 ) < 1 ||
            (int) ( $revisions['brand_profile'] ?? 0 ) < 1 ) {
            return new WP_Error( 'mad4b_integrity_revision_missing', 'Exact Site and Brand profile revisions are required.' );
        }
        if ( ! function_exists( 'get_current_blog_id' ) || ! function_exists( 'get_current_network_id' ) ||
            (int) $scope['blog_id'] !== (int) get_current_blog_id() ||
            (int) $scope['network_id'] !== (int) get_current_network_id() ) {
            return new WP_Error( 'mad4b_integrity_blog_context_drift', 'WordPress blog/network context changed.' );
        }
        // A WordPress interactive actor and an unprivileged cron actor are never
        // interchangeable across a long-running operation.
        if ( ! function_exists( 'get_current_user_id' ) ) {
            return new WP_Error( 'mad4b_integrity_actor_unavailable', 'Operation actor is unknown.' );
        }
        $actor_id = (int) get_current_user_id();
        $identity = array(
            'scope' => array(
                'tenant_ref' => (string) $scope['tenant_ref'],
                'brand_ref' => (string) $scope['brand_ref'],
                'site_uuid' => (string) $scope['site_uuid'],
                'blog_id' => (int) $scope['blog_id'],
                'network_id' => (int) $scope['network_id'],
                'environment' => (string) $scope['environment'],
                'deployment_mode' => 'wordpress_dedicated',
            ),
            'dependency_revision' => array(
                'site_profile' => (int) $revisions['site_profile'],
                'brand_profile' => (int) $revisions['brand_profile'],
            ),
            'actor_user_id' => $actor_id,
        );
        $json = wp_json_encode( $identity );
        if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_integrity_scope_digest_failed', 'Unable to encode trusted scope.' );
        return array(
            'contract' => self::CONTRACT,
            'fingerprint' => hash( 'sha256', $json ),
            'scope' => $identity['scope'],
            'dependency_revision' => $identity['dependency_revision'],
            'actor_user_id' => $actor_id,
            'execution_authorized' => false,
            'publication_authorized' => false,
            'operational_acceptance' => false,
        );
    }

    public static function assert_unchanged( $expected, $mutation = false ) {
        if ( ! is_array( $expected ) || self::CONTRACT !== ( $expected['contract'] ?? '' ) ||
            ! isset( $expected['fingerprint'] ) || ! is_string( $expected['fingerprint'] ) ||
            1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected['fingerprint'] ) ) {
            return new WP_Error( 'mad4b_integrity_checkpoint_invalid', 'Missing exact trusted checkpoint.' );
        }
        $current = self::capture();
        if ( is_wp_error( $current ) ) return $current;
        if ( ! hash_equals( $expected['fingerprint'], $current['fingerprint'] ) ) {
            return new WP_Error( 'mad4b_integrity_checkpoint_stale', 'Site, brand, actor or revision changed during the operation.' );
        }
        if ( $mutation ) {
            if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! method_exists( 'MAD4B_SCP_Policy', 'can_mutate' ) ||
                true !== MAD4B_SCP_Policy::can_mutate() ) {
                return new WP_Error( 'mad4b_integrity_authorization_revoked', 'Mutation authority is no longer effective.' );
            }
        }
        return true;
    }
}
