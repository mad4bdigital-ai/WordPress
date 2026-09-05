<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Diagnostics\LiveRuntimeProbe;

final class RuntimeValidationPage {
    private $config;
    private $profiles;
    private $probe;

    public function __construct( Configuration $config, ProfileRegistry $profiles, LiveRuntimeProbe $probe ) {
        $this->config = $config;
        $this->profiles = $profiles;
        $this->probe = $probe;
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_post_etg_dfsb_bind_runtime_evidence', array( $this, 'bindEvidence' ) );
    }

    public function menu(): void {
        add_options_page( 'ETG SEO Runtime Validation', 'ETG SEO Runtime Validation', 'manage_options', 'etg-filter-seo-runtime', array( $this, 'render' ) );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $status = $this->probe->status();
        $notice = isset( $_GET['etg_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['etg_notice'] ) ) : '';
        echo '<div class="wrap"><h1>ETG SEO Runtime Validation</h1>';
        if ( $notice ) {
            $ok = in_array( $notice, array( 'evidence_bound', 'probe_armed', 'probe_cleared' ), true );
            printf( '<div class="notice %s inline"><p>%s</p></div>', $ok ? 'notice-success' : 'notice-error', esc_html( $this->noticeText( $notice ) ) );
        }
        echo '<div class="notice notice-info inline"><p><strong>Non-authorizing surface.</strong> Global Bridge remains the master gate. Evidence here never authorizes Production activation.</p></div>';
        printf( '<p><strong>Global Bridge:</strong> %s &nbsp; <strong>Live probe:</strong> %s &nbsp; <strong>Samples:</strong> %d</p>', $this->config->enabled() ? '<span style="color:#b32d2e">ON</span>' : '<span style="color:#008a20">OFF</span>', ! empty( $status['armed'] ) ? 'ARMED' : 'DISARMED', (int) ( $status['sample_count'] ?? 0 ) );
        $this->renderProbeActions();
        $this->renderEvidenceBinding();
        echo '</div>';
    }

    private function renderProbeActions(): void {
        echo '<hr/><h2>Live Runtime Probe</h2><p>Arm for a short window, then open representative filtered frontend URLs. The probe captures timing/provider/count/policy/Unicode signals and hashes server-rendered HTML without storing raw HTML, IP, cookies or request bodies.</p><div style="display:flex;gap:10px;flex-wrap:wrap">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="etg_dfsb_arm_live_probe"/>'; wp_nonce_field( 'etg_dfsb_arm_live_probe' ); echo '<label>Minutes <input type="number" name="minutes" value="10" min="1" max="15"/></label> <button class="button button-primary">Arm Probe</button></form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="etg_dfsb_export_live_probe"/>'; wp_nonce_field( 'etg_dfsb_export_live_probe' ); echo '<button class="button">Download Probe JSON</button></form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="etg_dfsb_clear_live_probe"/>'; wp_nonce_field( 'etg_dfsb_clear_live_probe' ); echo '<button class="button">Clear / Disarm</button></form></div>';
    }

    private function renderEvidenceBinding(): void {
        echo '<hr/><h2>Immutable Evidence Binding</h2><p>Verified evidence must be an immutable <code>sha256:&lt;64hex&gt;</code> reference and is bound to the exact current Profile Authority Fingerprint. Any authority or plugin-version change makes previous evidence stale automatically.</p>';
        foreach ( $this->profiles->all() as $id => $profile ) {
            $pub = (array) ( $profile['publication'] ?? array() );
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px;margin:12px 0;max-width:1100px">';
            printf( '<h3>%s</h3><p><strong>Authority fingerprint:</strong> <code>%s</code></p>', esc_html( (string) $id ), esc_html( (string) ( $profile['authority_fingerprint'] ?? '' ) ) );
            printf( '<p>Provider: <strong>%s</strong> &nbsp; Elementor: <strong>%s</strong> &nbsp; Count parity: <strong>%s</strong></p>', ! empty( $pub['provider_observation_evidence_current'] ) ? 'CURRENT' : 'MISSING/STALE', ! empty( $pub['elementor_evidence_current'] ) ? 'CURRENT' : 'MISSING/STALE', ! empty( $pub['result_count_parity_evidence_current'] ) ? 'CURRENT' : 'MISSING/STALE' );
            if ( $this->config->enabled() ) {
                echo '<p><em>WRITE LOCKED while Global Bridge is ON.</em></p></div>';
                continue;
            }
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="etg_dfsb_bind_runtime_evidence"/><input type="hidden" name="profile_id" value="' . esc_attr( (string) $id ) . '"/>'; wp_nonce_field( 'etg_dfsb_bind_runtime_evidence:' . (string) $id );
            $this->evidenceRow( 'Provider/query observation', 'provider', ! empty( $pub['provider_observation_verified'] ), (string) ( $pub['provider_observation_evidence_id'] ?? '' ) );
            $this->evidenceRow( 'Elementor server HTML', 'elementor', ! empty( $pub['elementor_content_verified'] ), (string) ( $pub['elementor_verification_evidence_id'] ?? '' ) );
            $this->evidenceRow( 'Frontend/request/background count parity', 'count', ! empty( $pub['result_count_parity_verified'] ), (string) ( $pub['result_count_parity_evidence_id'] ?? '' ) );
            echo '<p><button class="button button-primary">Bind Evidence to Current Authority</button></p></form></div>';
        }
    }

    private function evidenceRow( string $label, string $prefix, bool $verified, string $id ): void {
        printf( '<p><label><input type="checkbox" name="%1$s_verified" value="1" %2$s/> %3$s verified</label><br/><input class="large-text code" style="max-width:780px" name="%1$s_id" value="%4$s" placeholder="sha256:&lt;64hex&gt;"/></p>', esc_attr( $prefix ), checked( $verified, true, false ), esc_html( $label ), esc_attr( $id ) );
    }

    public function bindEvidence(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 'Forbidden', array( 'response' => 403 ) ); }
        if ( $this->config->enabled() ) { $this->redirect( 'write_blocked_global_on' ); }
        $profileId = isset( $_POST['profile_id'] ) ? sanitize_key( (string) wp_unslash( $_POST['profile_id'] ) ) : '';
        check_admin_referer( 'etg_dfsb_bind_runtime_evidence:' . $profileId );
        $normalized = $this->profiles->get( $profileId );
        if ( ! $normalized ) { $this->redirect( 'profile_not_found' ); }
        $settings = $this->config->all();
        $raw = json_decode( (string) ( $settings['profiles_json'] ?? '' ), true );
        if ( ! is_array( $raw ) ) { $this->redirect( 'profile_registry_invalid' ); }
        $index = null;
        foreach ( $raw as $i => $candidate ) { if ( is_array( $candidate ) && sanitize_key( (string) ( $candidate['id'] ?? '' ) ) === $profileId ) { $index = $i; break; } }
        if ( null === $index ) { $this->redirect( 'profile_not_found' ); }
        $profile = (array) $raw[ $index ];
        $pub = is_array( $profile['publication'] ?? null ) ? $profile['publication'] : array();
        $fingerprint = (string) ( $normalized['authority_fingerprint'] ?? '' );
        foreach ( array(
            'provider' => array( 'provider_observation_verified', 'provider_observation_evidence_id', 'provider_observation_authority_fingerprint' ),
            'elementor' => array( 'elementor_content_verified', 'elementor_verification_evidence_id', 'elementor_verification_authority_fingerprint' ),
            'count' => array( 'result_count_parity_verified', 'result_count_parity_evidence_id', 'result_count_parity_authority_fingerprint' ),
        ) as $prefix => $keys ) {
            list( $flagKey, $idKey, $fingerprintKey ) = $keys;
            $verified = isset( $_POST[ $prefix . '_verified' ] );
            $id = isset( $_POST[ $prefix . '_id' ] ) ? trim( (string) wp_unslash( $_POST[ $prefix . '_id' ] ) ) : '';
            if ( $verified && ! preg_match( '/^sha256:[a-fA-F0-9]{64}$/', $id ) ) { $this->redirect( 'invalid_evidence_sha' ); }
            $pub[ $flagKey ] = $verified;
            $pub[ $idKey ] = $verified ? strtolower( $id ) : '';
            $pub[ $fingerprintKey ] = $verified ? $fingerprint : '';
        }
        $profile['publication'] = $pub;
        $raw[ $index ] = $profile;
        $settings['profiles_json'] = wp_json_encode( $raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        update_option( Configuration::OPTION_NAME, $this->config->sanitize( $settings ), false );
        $this->redirect( 'evidence_bound' );
    }

    private function redirect( string $notice ): void {
        $url = add_query_arg( array( 'page' => 'etg-filter-seo-runtime', 'etg_notice' => $notice ), admin_url( 'options-general.php' ) );
        wp_safe_redirect( $url ); exit;
    }

    private function noticeText( string $notice ): string {
        $map = array(
            'evidence_bound' => 'Evidence references were bound to the exact current profile authority fingerprint.',
            'write_blocked_global_on' => 'Write blocked: turn Global Bridge OFF before changing runtime evidence bindings.',
            'profile_not_found' => 'Profile not found.',
            'profile_registry_invalid' => 'Profile registry is invalid; no write was performed.',
            'invalid_evidence_sha' => 'Verified evidence must use sha256:<64 hexadecimal characters>.',
            'probe_armed' => 'Live Runtime Probe armed.',
            'probe_cleared' => 'Live Runtime Probe cleared and disarmed.',
        );
        return (string) ( $map[ $notice ] ?? $notice );
    }
}
