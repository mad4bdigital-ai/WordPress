<?php
/**
 * Staging compatibility adapter for the currently deployed SCP runtime.
 * The GitHub-native SCP bootstrap owns the module after certified promotion.
 * No provider credential is embedded, read or persisted by this bridge.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() === 'staging') {
    add_action('plugins_loaded', static function () {
        if (!defined('MAD4B_SCP_DIR')) return;
        $path = MAD4B_SCP_DIR . 'includes/class-mad4b-scp-company-domain-cloudflare.php';
        if (!is_readable($path)) return;
        require_once $path;
        MAD4B_SCP_Company_Domain_Cloudflare::boot();
    }, 16);
}
