<?php
/**
 * First-party, dynamic governed Admin mount for Staging-only public-key trust.
 *
 * Registration exposes provider identity; it grants no authority. The original
 * Ability owns enrolled owner / OAuth Step-Up / exact grant & ticket / plan
 * and rollback checks. No signing private key is accepted or stored here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_CI_Outage_Attestor_Adapter extends MAD4B_SCP_Adapter_Base {
    public function id() { return 'ci-outage-attestor'; }
    public function label() { return 'Independent Staging native-test trust enrollment'; }
    protected function certified_provider_key() { return 'core'; }
    protected function mutation_requires_certification() { return false; }
    public function is_available() {
        return class_exists( 'MAD4B_SCP_CI_Outage_Attestation', false )
            && function_exists( 'sodium_crypto_sign_verify_detached' );
    }
    public function ability_names() {
        return array(
            'read' => array(),
            'content' => array(),
            'admin' => array( MAD4B_SCP_CI_Outage_Attestation::ENROLL_APPLY ),
            'write' => array(),
        );
    }
    public function register_abilities() {
        // The original attestor registers its plan and enrolled-owner
        // mutation; never shadow or bypass the permission callback.
    }
    public function mutation_ability_runtime_eligibility( $ability_name ) {
        if ( ! $this->is_available() ||
            MAD4B_SCP_CI_Outage_Attestation::ENROLL_APPLY !== (string) $ability_name ) {
            return new WP_Error( 'mad4b_offline_attestor_mount_unavailable',
                'Only the enrolled attestor public-key registration is eligible.' );
        }
        $site = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        if ( ! is_array( $site ) ||
            'staging' !== (string) ( $site['configured_environment'] ?? '' ) ||
            'staging' !== (string) ( $site['wordpress_environment'] ?? '' ) ||
            empty( $site['authority_ready'] ) ||
            empty( $site['origin_match'] ) ||
            empty( $site['site_uuid'] ) ||
            empty( $site['profile_digest'] ) ) {
            return new WP_Error( 'mad4b_offline_attestor_staging_required',
                'Staging profile and exact site binding must be valid.' );
        }
        return true;
    }
}
