<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

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
        if (defined('ETG_DFSB_FORCE_FULL_BOOT') && ETG_DFSB_FORCE_FULL_BOOT) { return false; }
        $state = self::state();
        if (self::$build === '' || (string)($state['build'] ?? '') !== self::$build) { return true; }
        return !empty($state['hold']) || !empty($state['faulted']);
    }

    public static function run(callable $boot): bool {
        self::$monitoring = true;
        try {
            $boot();
            self::writeState(array(
                'build' => self::$build,
                'hold' => false,
                'faulted' => false,
                'reason' => 'boot_ok',
                'updated_at' => time(),
            ));
            return true;
        } catch (\Throwable $e) {
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
        if (function_exists('error_log')) { error_log('[ETG DFSB safe boot] ' . wp_json_encode($row)); }
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
        if (function_exists('error_log')) { error_log('[ETG DFSB fatal guard] ' . (function_exists('wp_json_encode') ? wp_json_encode($row) : json_encode($row))); }
    }

    public static function retryFullBoot(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 403); }
        check_admin_referer(self::RETRY_ACTION);
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
        echo '<div class="notice notice-warning"><p><strong>ETG Dynamic Filter SEO Bridge is in Safe Boot mode.</strong> Full Elementor/JetEngine integration is not loaded, so this request cannot white-screen wp-admin.</p>';
        if (!empty($state['faulted'])) {
            echo '<p>Last guarded failure: <code>' . esc_html((string)($state['surface'] ?? 'boot')) . '</code> — ' . esc_html((string)($state['message'] ?? 'unknown error'));
            if (!empty($state['file'])) { echo ' <code>' . esc_html((string)$state['file']) . ':' . esc_html((string)($state['line'] ?? 0)) . '</code>'; }
            echo '</p>';
        }
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Run guarded full boot</a> <span class="description">If a fatal occurs, the next request returns to Safe Boot automatically.</span></p></div>';
    }

    public static function state(): array {
        $state = function_exists('get_option') ? get_option(self::OPTION_NAME, array()) : array();
        return is_array($state) ? $state : array();
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
