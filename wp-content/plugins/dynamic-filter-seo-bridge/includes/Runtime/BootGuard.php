<?php
namespace DynamicFilterSEOBridge\Runtime;

final class BootGuard {
    const OPTION_NAME = 'etg_dfsb_boot_guard_v1';
    const RETRY_ACTION = 'etg_dfsb_retry_full_boot';

    private static $build = '';
    private static $monitoring = false;

    public static function register(string $build): void {
        self::$build = trim($build);
        register_shutdown_function(array(__CLASS__, 'shutdown'));
        add_action('admin_notices', array(__CLASS__, 'adminNotice'));
        add_action('admin_post_' . self::RETRY_ACTION, array(__CLASS__, 'retryFullBoot'));
    }

    public static function holdOnFirstLoad(string $reason = 'package_change'): void {
        self::writeState(array(
            'build' => self::$build,
            'hold' => true,
            'faulted' => false,
            'reason' => sanitize_key($reason),
            'updated_at' => time(),
        ));
    }

    public static function shouldHold(): bool {
        // Persistent hold=false is NOT release authority. Recheck the source,
        // detached receipt and independent provider certificate every request,
        // including when a previously trusted certificate has been revoked.
        if ( empty( self::releaseReadiness()['ready'] ) ) { return true; }
        // Development overrides can only bypass the manual hold after an
        // independently certified exact package has passed the authority gate.
        if (defined('DFSB_FORCE_FULL_BOOT') && DFSB_FORCE_FULL_BOOT) { return false; }
        $state = self::state();
        if (self::$build === '' || (string)($state['build'] ?? '') !== self::$build) { return true; }
        return !empty($state['hold']) || !empty($state['faulted']);
    }

    public static function run(callable $boot): bool {
        if ( empty( self::releaseReadiness()['ready'] ) ) { return false; }
        self::$monitoring = true;
        try {
            $boot();
            self::$monitoring = false;
            self::writeState(array(
                'build' => self::$build,
                'hold' => false,
                'faulted' => false,
                'reason' => 'boot_ok',
                'updated_at' => time(),
            ));
            return true;
        } catch (\Throwable $e) {
            self::$monitoring = false;
            self::recordThrowable('bootstrap', $e);
            return false;
        }
    }

    public static function recordThrowable(string $surface, \Throwable $e): void {
        $row = array(
            'build' => self::$build,
            'hold' => true,
            'faulted' => true,
            'reason' => 'throwable',
            'surface' => sanitize_key($surface),
            'error_type' => get_class($e),
            'message' => self::safeMessage($e->getMessage()),
            'file' => self::relativeFile($e->getFile()),
            'line' => (int)$e->getLine(),
            'updated_at' => time(),
        );
        self::writeState($row);
        if (function_exists('error_log')) { error_log('[Dynamic Filter SEO Bridge safe boot] ' . wp_json_encode($row)); }
    }

    public static function shutdown(): void {
        if (!self::$monitoring) { return; }
        $error = error_get_last();
        if (!is_array($error) || !in_array((int)($error['type'] ?? 0), array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) { return; }
        $row = array(
            'build' => self::$build,
            'hold' => true,
            'faulted' => true,
            'reason' => 'fatal_shutdown',
            'surface' => 'request',
            'error_type' => (int)($error['type'] ?? 0),
            'message' => self::safeMessage((string)($error['message'] ?? 'fatal error')),
            'file' => self::relativeFile((string)($error['file'] ?? '')),
            'line' => (int)($error['line'] ?? 0),
            'updated_at' => time(),
        );
        self::writeState($row);
        if (function_exists('error_log')) { error_log('[Dynamic Filter SEO Bridge fatal guard] ' . (function_exists('wp_json_encode') ? wp_json_encode($row) : json_encode($row))); }
    }

    public static function retryFullBoot(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 'Forbidden', array('response' => 403)); }
        check_admin_referer(self::RETRY_ACTION);
        // A successful nonce is not proof that installed release bytes match
        // their source manifest. Never clear Safe Boot for an uncertified tree.
        $release = self::releaseReadiness();
        if (empty($release['ready'])) {
            wp_die('Package integrity has not been certified. Rebuild and verify the release before retrying.',
                'Release certification required', array('response' => 409));
        }
        self::writeState(array(
            'build' => self::$build,
            'hold' => false,
            'faulted' => false,
            'reason' => 'manual_retry',
            'updated_at' => time(),
        ));
        wp_safe_redirect(admin_url('plugins.php?etg_dfsb_boot_retry=1'));
        exit;
    }

    public static function adminNotice(): void {
        if (!current_user_can('manage_options') || !self::shouldHold()) { return; }
        $state = self::state();
        $url = wp_nonce_url(admin_url('admin-post.php?action=' . self::RETRY_ACTION), self::RETRY_ACTION);
        echo '<div class="notice notice-warning"><p><strong>Dynamic Filter SEO Bridge is in Safe Boot mode.</strong> Full Elementor/JetEngine integration is not loaded, so this request cannot white-screen wp-admin.</p>';
        if (!empty($state['faulted'])) {
            echo '<p>Last guarded failure: <code>' . esc_html((string)($state['surface'] ?? 'boot')) . '</code> — ' . esc_html((string)($state['message'] ?? 'unknown error'));
            if (!empty($state['file'])) { echo ' <code>' . esc_html((string)$state['file']) . ':' . esc_html((string)($state['line'] ?? 0)) . '</code>'; }
            echo '</p>';
        }
        $release = self::releaseReadiness();
        if (empty($release['ready'])) {
            echo '<p><strong>Full boot blocked:</strong> installed package integrity is not certified. <code>'
                . esc_html((string) $release['reason']) . '</code></p></div>';
            return;
        }
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Run guarded full boot</a> <span class="description">If a fatal occurs, the next request returns to Safe Boot automatically.</span></p></div>';
    }

    public static function state(): array {
        $state = function_exists('get_option') ? get_option(self::OPTION_NAME, array()) : array();
        return is_array($state) ? $state : array();
    }

    /**
     * Read-only release authority. Neither an embedded identity, a locally
     * written receipt nor a signed source build independently authorizes boot.
     * All three must be bound to the exact approved provider/package row.
     */
    public static function releaseReadiness(): array {
        $identity = \DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect();
        $version = (string) ( $identity['plugin_version'] ?? '' );
        $sourceSha = strtolower( (string) ( $identity['git_sha'] ?? '' ) );
        $packageSha = strtolower( (string) ( $identity['package_sha256'] ?? '' ) );

        $integrity = ! empty( $identity['valid'] )
            && ! empty( $identity['installed_content_valid'] )
            && ! empty( $identity['provenance_complete'] )
            && ! empty( $identity['package_provenance_valid'] )
            && 'ok' === (string) ( $identity['exact_build_reason'] ?? '' )
            && 1 === preg_match( '/^[a-f0-9]{64}$/D', $packageSha );

        $provider = array();
        $source = array();
        $authorityAvailable = class_exists( 'MAD4B_SCP_Provider_Contracts' )
            && method_exists( 'MAD4B_SCP_Provider_Contracts', 'get_for_version' )
            && class_exists( 'MAD4B_SCP_Cross_Plugin_Deployment' )
            && method_exists( 'MAD4B_SCP_Cross_Plugin_Deployment', 'sources' );
        if ( $authorityAvailable && $integrity ) {
            $provider = \MAD4B_SCP_Provider_Contracts::get_for_version( 'dynamic-filter-seo-bridge', $version );
            $sources = \MAD4B_SCP_Cross_Plugin_Deployment::sources();
            $source = isset( $sources['dynamic-filter-seo-bridge'] )
                && is_array( $sources['dynamic-filter-seo-bridge'] )
                ? $sources['dynamic-filter-seo-bridge'] : array();
        }

        $plugin = 'dynamic-filter-seo-bridge/dynamic-filter-seo-bridge.php';
        $certified = $integrity
            && $authorityAvailable
            && is_array( $provider ) && ! empty( $provider )
            && is_array( $source ) && ! empty( $source )
            && (string) ( $provider['plugin_file'] ?? '' ) === $plugin
            && (string) ( $source['plugin_file'] ?? '' ) === $plugin
            && (string) ( $source['provider_id'] ?? '' ) === 'dynamic-filter-seo-bridge'
            && (string) ( $provider['version'] ?? '' ) === $version
            && (string) ( $source['artifact_version'] ?? '' ) === $version
            && (string) ( $source['candidate_artifact_version'] ?? '' ) === $version
            && (string) ( $source['package_certification'] ?? '' ) === 'certified'
            && (string) ( $source['review_state'] ?? '' ) === 'source_reviewed_artifact_certified'
            && (string) ( $source['source_commit_sha'] ?? '' ) === $sourceSha
            && (string) ( $source['candidate_source_commit_sha'] ?? '' ) === $sourceSha
            && hash_equals( $packageSha, strtolower( (string) ( $provider['archive_sha256'] ?? '' ) ) )
            && hash_equals( $packageSha, strtolower( (string) ( $source['artifact_sha256'] ?? '' ) ) )
            && ! empty( $provider['require_runtime_integrity'] );

        $reason = (string) ( $identity['exact_build_reason'] ?? 'unverified' );
        if ( 'ok' === $reason ) {
            if ( ! $integrity ) {
                $reason = 'certified_release_receipt_required';
            } elseif ( ! $certified ) {
                $reason = 'independent_provider_certificate_required';
            }
        }
        return array(
            'ready' => (bool) $certified,
            'reason' => $certified ? 'ok' : $reason,
            'installed_content_valid' => ! empty( $identity['installed_content_valid'] ),
            'provenance_complete' => ! empty( $identity['provenance_complete'] ),
            'package_provenance_valid' => ! empty( $identity['package_provenance_valid'] ),
            'provider_certificate_valid' => (bool) $certified,
            'authorizing' => false,
        );
    }

    public static function status(): array {
        $state = self::state();
        $registeredBuild = trim( self::$build );
        $stateBuild = trim( (string) ( $state['build'] ?? '' ) );
        $buildMatches = '' !== $registeredBuild && '' !== $stateBuild && $registeredBuild === $stateBuild;
        $stateHold = ! empty( $state['hold'] );
        $faulted = ! empty( $state['faulted'] );
        $forced = defined( 'DFSB_FORCE_FULL_BOOT' ) && DFSB_FORCE_FULL_BOOT;
        $effectiveHold = $forced ? false : ( ! $buildMatches || $stateHold || $faulted );
        $reason = sanitize_key( (string) ( $state['reason'] ?? '' ) );
        return array(
            'contract' => 'etg.dfsb.safe-boot-status.v1',
            'canonical_contract' => 'dfsb.safe-boot-status.v1',
            'authorizing' => false,
            'read_only' => true,
            'mutation_exposed' => false,
            'registered_build' => $registeredBuild,
            'state_build' => $stateBuild,
            'build_matches' => $buildMatches,
            'state_hold' => $stateHold,
            'effective_hold' => $effectiveHold,
            'faulted' => $faulted,
            'force_full_boot' => $forced,
            'monitoring' => self::$monitoring,
            'reason' => $reason,
            'boot_ok' => $buildMatches && ! $effectiveHold && ! $faulted && 'boot_ok' === $reason,
            'updated_at' => (int) ( $state['updated_at'] ?? 0 ),
        );
    }

    private static function writeState(array $state): void {
        if (function_exists('update_option')) { update_option(self::OPTION_NAME, $state, false); }
    }

    private static function safeMessage(string $message): string {
        $message = trim(strip_tags($message));
        if (strlen($message) > 500) { $message = substr($message, 0, 497) . '...'; }
        return $message;
    }

    private static function relativeFile(string $file): string {
        if (defined('ABSPATH') && 0 === strpos($file, ABSPATH)) { return ltrim(substr($file, strlen(ABSPATH)), '/\\'); }
        return basename($file);
    }
}
