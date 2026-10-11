<?php
/**
 * Dynamically enrolled first-party WordPress-native build adapter.
 *
 * This class contributes its declared ability through the existing governed
 * adapter registry, not through a fixed entry in MAD4B_SCP_Servers. Each
 * future first-party operation can use the same registry contract.
 *
 * Structural mount eligibility is NOT an execution approval. The original
 * Ability callback still verifies Staging identity, owner OAuth Step-Up,
 * global policy, exact grants, one-time approval, and package boundaries.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_WP_Native_Build_Adapter extends MAD4B_SCP_Adapter_Base {
    public function id() { return 'wp-native-build'; }
    public function label() { return 'WordPress-native source packaging'; }
    public function is_available() {
        return class_exists( 'MAD4B_SCP_WP_Native_Build', false );
    }
    protected function certified_provider_key() { return 'core'; }
    // First-party core ability; no third-party provider contract to certify.
    // The original mutation callback and site checks remain mandatory.
    protected function mutation_requires_certification() { return false; }
    protected function detect_plugin_version() {
        return defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '';
    }
    public function ability_names() {
        $name = MAD4B_SCP_WP_Native_Build::PREFIX . 'start';
        return array(
            'read' => array(),
            'content' => array(),
            'admin' => array( $name, MAD4B_SCP_WP_Native_Build::PREFIX . 'self-approve-install' ),
            'write' => array(),
        );
    }
    public function register_abilities() {
        // The original Ability is registered by the source worker on
        // wp_abilities_api_init; never replace its callback or permission
        // gate with adapter wrappers.
    }
    public function mutation_ability_runtime_eligibility( $ability_name ) {
        if ( ! $this->is_available() ||
            ! in_array( (string) $ability_name, array(
                MAD4B_SCP_WP_Native_Build::PREFIX . 'start',
                MAD4B_SCP_WP_Native_Build::PREFIX . 'self-approve-install',
            ), true ) ) {
            return new WP_Error( 'mad4b_native_adapter_ability_not_supported',
                'Unrecognized dynamic native-build mutation.' );
        }
        $cap = MAD4B_SCP_WP_Native_Build::capabilities();
        if ( ! is_array( $cap ) || empty( $cap['eligible'] ) ) {
            return new WP_Error( 'mad4b_native_adapter_staging_not_eligible',
                'Staging native-build prerequisites are not effective.' );
        }
        return true;
    }
}
