<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-resilience-context.php';

/**
 * Pinned code-owned local evidence reader. It never invents provider
 * certification, host isolation, external-effect completeness or pilot metrics.
 * A later certified host observer must supply all four gates before release.
 */
final class MAD4B_SCP_G9_Local_Reader implements MAD4B_SCP_Resilience_Reader {
    public function read_local( array $binding ) {
        $key = MAD4B_SCP_Resilience_Context::site_key( $binding );
        // Capture bounded local diagnostics without treating installed plugins
        // or healthy enrollment as a claim of verified release isolation.
        $runtime = array();
        if ( class_exists( 'MAD4B_SCP_Runtime_Release_Set' )
            && method_exists( 'MAD4B_SCP_Runtime_Release_Set', 'status' ) ) {
            $status = MAD4B_SCP_Runtime_Release_Set::status();
            if ( is_array( $status ) && ! is_wp_error( $status ) ) {
                $runtime = array(
                    'contract' => isset( $status['contract'] ) ? (string) $status['contract'] : '',
                    'runtime_release_status_known' => true,
                );
            }
        }
        return array(
            'binding_sha256' => MAD4B_SCP_Resilience_Context::digest( $binding ),
            'providers' => array(),
            'host' => array(
                'site_key' => $key,
                'isolation_verified' => false, 'local_readback_verified' => false,
                'runtime' => $runtime,
            ),
            'health' => array( 'sample_count'=>0, 'error_rate_bps'=>0,
                'p95_ms'=>0, 'observed_at'=>MAD4B_SCP_Resilience_Context::now() ),
            'external_effects' => array(),
            'gates' => array(
                'provider_inventory_complete' => false,
                'host_inventory_complete' => false,
                'external_effect_inventory_complete' => false,
                'health_sample_window_complete' => false,
                'prior_ring_health_accepted' => false,
            ),
        );
    }
}
