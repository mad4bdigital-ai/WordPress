<?php
/** Source-only original Core Web Vitals admin presentation extraction. */
defined('ABSPATH') || exit;

trait MAD4B_SCP_Core_Web_Vitals_Admin_Trait {
    public static function render_admin_section() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $status = self::status();
        echo '<h2>Core Web Vitals Assessment (Google real-user data)</h2>';
        echo '<p>Rolling 28-day Chrome UX Report (75th percentile). A Lighthouse score is diagnostic only and never determines Passed/Failed.</p>';
        self::render_settings_form();
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Device</th><th>Origin assessment</th><th>LCP (ms)</th><th>INP (ms)</th><th>CLS</th><th>Updated UTC</th><th>Lighthouse</th></tr></thead><tbody>';
        foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
            $snapshot = $status['devices'][ $strategy ] ?? array();
            $origin = $snapshot['origin'] ?? array();
            $metrics = $origin['metrics'] ?? array();
            $verdict = $origin['verdict'] ?? 'not_measured';
            if ( ! empty( $snapshot['stale'] ) ) $verdict .= ' (stale)';
            $values = array(
                ucfirst( $strategy ), $verdict,
                $metrics['lcp']['p75'] ?? null, $metrics['inp']['p75'] ?? null,
                $metrics['cls']['p75'] ?? null, $snapshot['collected_at'] ?? '—',
                isset( $snapshot['lighthouse_performance_score'] ) ? (string) $snapshot['lighthouse_performance_score'] . '/100' : '—',
            );
            echo '<tr>';
            foreach ( $values as $value ) echo '<td>' . esc_html( null === $value ? '—' : (string) $value ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Data source:</strong> Google CrUX origin field data. LCP ≤ 2500ms; INP ≤ 200ms; CLS ≤ 0.10. All three must be good for Passed.</p>';
        if ( ! empty( $status['attempts'] ) ) {
            echo '<p><strong>Last refresh attempts:</strong> ';
            foreach ( $status['attempts'] as $strategy => $attempt ) {
                echo esc_html( $strategy . ': ' . ( $attempt['state'] ?? 'unknown' ) . ( isset( $attempt['http_status'] ) ? ' (HTTP ' . $attempt['http_status'] . ')' : '' ) ) . ' ';
            }
            echo '</p>';
        }
        if ( ! $status['api_key_configured'] ) echo '<p>A Google API key is not configured. Add one above for authenticated API quota. HTTP 429 is a rate-limit response, not a Failed assessment.</p>';
        $life = $status['lifecycle'] ?? self::lifecycle_status();
        echo '<p><strong>Worker lifecycle:</strong> ' . esc_html( $life['state'] ) .
            ' · <strong>Last job:</strong> ' . esc_html( $life['job_id'] ?? 'none' ) . '</p>';
        if ( $life['cooldown_until_epoch'] > time() ) echo '<p><strong>Google API retry after (UTC):</strong> ' .
            esc_html( gmdate( 'Y-m-d H:i:s', $life['cooldown_until_epoch'] ) ) . '</p>';
        $refresh_notice = $_GET['mad4b_cwv_refresh_state'] ?? '';
        if ( is_string( $refresh_notice ) && '' !== $refresh_notice ) echo '<div class="notice notice-info inline"><p>Refresh request: ' .
            esc_html( sanitize_key( wp_unslash( $refresh_notice ) ) ) . '</p></div>';
        echo '<p>Next scheduled run (UTC): ' . esc_html( $status['next_run_epoch'] ? gmdate( 'Y-m-d H:i:s', $status['next_run_epoch'] ) : 'not scheduled' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="mad4b_cwv_refresh">';
        wp_nonce_field( 'mad4b_cwv_refresh', 'mad4b_cwv_nonce' );
        submit_button( 'Queue Core Web Vitals refresh', 'secondary', 'submit', false );
        echo '</form><hr>';
    }
}
