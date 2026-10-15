<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only cross-lane Staging recovery decision model.
 *
 * Reuses existing immutable Deployment Binding and Write executors; this
 * class never creates a ticket, signs an approval or performs a mutation.
 */
final class MAD4B_SCP_Staging_Recovery_Center {
    const CONTRACT = 'mad4b.staging-recovery-center.v1';

    public static function decide( array $site, array $binding, array $readback, array $write, array $context = array() ) {
        $environment = (string) ( $site['configured_environment'] ?? '' );
        $origin_ok = ! empty( $site['origin_match'] ) && ! empty( $site['environment_match'] );
        $safe = 'staging' === $environment
            && 'staging' === (string) ( $site['wordpress_environment'] ?? '' )
            && $origin_ok && ! empty( $site['authority_ready'] )
            && empty( $site['mutation_pending_audit'] );
        $base = array(
            'contract' => self::CONTRACT,
            'read_only' => true, 'authorizing' => false, 'mutation_performed' => false,
            'environment' => $environment, 'production_allowed' => false,
            'developer_allowed' => false, 'breakglass_allowed' => false,
            'site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
            'profile_revision' => (int) ( $site['revision'] ?? 0 ),
            'binding_state' => (string) ( $binding['state'] ?? 'UNAVAILABLE' ),
            'binding_recreated' => false, 'binding_approval_generated' => false,
            'write_state' => 'not_checked', 'next_action' => '',
            'next_url' => '', 'next_ability' => '', 'reason' => '',
        );
        if ( ! $safe ) return array_merge( $base, array(
            'state' => 'SITE_NOT_EXACT_STAGING', 'reason' => 'Verify the exact enrolled Staging Site Profile. No recovery mutation is permitted.',
            'next_action' => 'Verify Site Profile',
            'next_url' => 'admin.php?page=mad4b-control-plane-site-profile',
        ) );
        if ( 'ALREADY_HAS_PROFILE_BINDING' === $base['binding_state'] ) {
            $verified = ! empty( $readback['functional_scope_ready_for_census'] )
                && ! empty( $readback['site_identity_preserved'] )
                && hash_equals( $base['site_uuid'], (string) ( $readback['site_uuid'] ?? '' ) )
                && (int) ( $readback['profile_revision'] ?? 0 ) >= $base['profile_revision'];
            if ( ! $verified ) return array_merge( $base, array(
                'state' => 'BINDING_READBACK_REQUIRED',
                'reason' => 'Existing binding must be read back independently; do not rotate or recreate it.',
                'next_action' => 'Verify existing binding',
                'next_ability' => 'mad4b/deployment-binding-native-readback',
            ) );
            $base['binding_reused'] = true;
            $base['binding_scope_verified'] = true;
            $base['readback_state'] = (string) ( $readback['state'] ?? '' );
        } elseif ( 'READY_FOR_OWNER_APPROVAL' === $base['binding_state'] && ! empty( $binding['eligible'] ) ) {
            return array_merge( $base, array(
                'state' => 'BINDING_OWNER_APPROVAL_REQUIRED',
                'reason' => 'First-time host-local identity requires its separate exact governed approval ticket; this is not an update-time key rotation.',
                'next_action' => 'Review binding approval',
                'next_url' => 'admin.php?page=mad4b-approval-decisions',
                'next_ability' => 'mad4b/deployment-binding-native-plan',
            ) );
        } else {
            return array_merge( $base, array(
                'state' => 'BINDING_RECOVERY_BLOCKED',
                'reason' => 'Host binding is inconsistent or its safe private directory is unavailable. No automatic overwrite or silent reset.',
                'next_action' => 'Inspect host binding',
                'next_ability' => 'mad4b/deployment-binding-native-status',
            ) );
        }
        $candidate = is_array( $write['candidate_binding'] ?? null ) ? $write['candidate_binding'] : array();
        $write_ready = ! empty( $write['write_ready'] ) && ! empty( $candidate['match'] );
        $base['write_state'] = $write_ready ? 'verified' : 'requires_exact_plan';
        if ( ! $write_ready ) return array_merge( $base, array(
            'state' => 'WRITE_REVIEW_REQUIRED',
            'reason' => 'Existing host identity stays unchanged. Review the fresh exact Write plan after host binding verification.',
            'next_action' => 'Review governed Write',
            'next_ability' => 'mad4b/staging-write-authority-convergence-handshake',
        ) );
        if ( ! empty( $context['revocation_pending'] ) ) return array_merge( $base, array(
            'state' => 'GOOGLE_REVOCATION_PENDING',
            'reason' => 'Google provider revocation remains unverified. HTTP 400 is not evidence of revocation; keep Drive reads and writes blocked.',
            'next_action' => 'Review Google revocation',
            'next_url' => 'admin.php?page=mad4b-control-plane-context&tab=google-drive',
        ) );
        if ( empty( $context['connected'] ) || empty( $context['read_available'] ) ) return array_merge( $base, array(
            'state' => 'GOOGLE_READ_ACCESS_REQUIRED',
            'reason' => 'Google access is not verified for reading. Recheck the connected provider without silently upgrading scope.',
            'next_action' => 'Review Google connection',
            'next_url' => 'admin.php?page=mad4b-control-plane-context&tab=google-drive',
        ) );
        if ( ! empty( $context['quarantined_source_record_count'] ) || ! empty( $context['quarantined_asset_record_count'] ) ) return array_merge( $base, array(
            'state' => 'LEGACY_CONTEXT_OWNERSHIP_REVIEW',
            'reason' => 'Historical Context sources or files remain quarantined. Review lineage without automatic ownership transfer.',
            'next_action' => 'Review Source Folders',
            'next_url' => 'admin.php?page=mad4b-control-plane-context&tab=sources',
        ) );
        return array_merge( $base, array(
            'state' => 'IDENTITY_AND_WRITE_CURRENT',
            'reason' => 'Host identity and Write are current. Reuse the binding and proceed to Context/Google checks without further binding approval.',
            'next_action' => 'Continue Source Folders',
            'next_url' => 'admin.php?page=mad4b-control-plane-context&tab=sources',
            'next_ability' => 'mad4b/context-source-onboarding-preflight',
        ) );
    }

    public static function observe() {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error(
            'mad4b_staging_recovery_admin_required', 'Administrator permission required.'
        );
        $site = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        // Never ask the native binding reader to provision or rotate.
        $binding = class_exists( 'MAD4B_SCP_Deployment_Binding_Native' )
            ? MAD4B_SCP_Deployment_Binding_Native::status() : array();
        $site = is_array( $site ) ? $site : array();
        $binding = is_array( $binding ) ? $binding : array();
        $readback = array();
        if ( 'staging' === (string) ( $site['configured_environment'] ?? '' )
            && 'ALREADY_HAS_PROFILE_BINDING' === (string) ( $binding['state'] ?? '' )
            && class_exists( 'MAD4B_SCP_Deployment_Binding_Native' ) ) {
            $result = MAD4B_SCP_Deployment_Binding_Native::readback( array(
                'expected_site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
                'minimum_profile_revision' => (int) ( $site['revision'] ?? 0 ),
            ) );
            if ( is_array( $result ) ) $readback = $result;
        }
        $write = array();
        if ( 'staging' === (string) ( $site['configured_environment'] ?? '' )
            && class_exists( 'MAD4B_SCP_Staging_Write_Authority_Convergence' )
            && 'ALREADY_HAS_PROFILE_BINDING' === (string) ( $binding['state'] ?? '' )
            && ! empty( $readback['functional_scope_ready_for_census'] ) ) {
            $result = MAD4B_SCP_Staging_Write_Authority_Convergence::status();
            if ( is_array( $result ) ) $write = $result;
        }
        $context = array();
        if ( ! empty( $write['write_ready'] ) && class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ) {
            $google = MAD4B_SCP_Google_Drive_Context::connection_status();
            if ( is_array( $google ) ) $context = $google;
            if ( class_exists( 'MAD4B_SCP_Context_Authority' ) ) {
                $ownership = MAD4B_SCP_Context_Authority::status();
                if ( is_array( $ownership ) ) {
                    $context['quarantined_source_record_count'] = (int) ( $ownership['quarantined_source_record_count'] ?? 0 );
                    $context['quarantined_asset_record_count'] = (int) ( $ownership['quarantined_asset_record_count'] ?? 0 );
                }
            }
        }
        return self::decide( $site, $binding, $readback, $write, $context );
    }

    public static function render() {
        $state = self::observe();
        echo '<section id="mad4b-staging-recovery" class="card" style="max-width:900px;margin:16px 0;padding:12px 20px">';
        echo '<h2>Staging Recovery — one guided journey</h2>';
        if ( is_wp_error( $state ) ) {
            echo '<p role="alert">' . esc_html( $state->get_error_code() ) . '</p></section>';
            return;
        }
        echo '<p><strong>Current state:</strong> <code>' . esc_html( $state['state'] ) . '</code></p>';
        echo '<p>' . esc_html( $state['reason'] ) . '</p>';
        echo '<p><strong>Host binding:</strong> <code>' . esc_html( $state['binding_state'] )
            . '</code> <strong>Write:</strong> <code>' . esc_html( $state['write_state'] ) . '</code></p>';
        echo '<p class="description">Identity and permissions are separate checks, not repeated approvals. No key rotation, grants or provider changes are made by opening this page.</p>';
        if ( ! empty( $state['next_url'] ) ) {
            echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( $state['next_url'] ) ) . '">'
                . esc_html( $state['next_action'] ) . '</a></p>';
        } elseif ( ! empty( $state['next_ability'] ) ) {
            echo '<p><strong>Next governed operation:</strong> <code>' . esc_html( $state['next_ability'] )
                . '</code></p>';
        }
        if ( 'BINDING_OWNER_APPROVAL_REQUIRED' === $state['state'] ) {
            echo '<p class="description">Approval Decisions shows existing tickets; creating and executing a new binding ticket still requires the authenticated governed request. This link does not create or authorize one.</p>';
        }
        echo '</section>';
    }
}
