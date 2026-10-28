<?php
/**
 * MAD4B Native General Settings Contract.
 *
 * No duplicated URL/email values. A native options.php update can never
 * write immutable canonical URLs, nor bypass WP's admin email confirmation.
 * Runs at the final allowed_options stage even if an unrelated component
 * initializes WordPress REST and re-registers initial settings early.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Native_General_Settings_Contract {
    const CONTRACT = 'mad4b.native-general-settings-contract.v1';

    public static function allowed_options($allowed) {
        if (!is_array($allowed)
            || !function_exists('is_admin') || !is_admin()
            || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
            || basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'options.php'
            || (string)($_POST['option_page'] ?? '') !== 'general'
            || !isset($allowed['general']) || !is_array($allowed['general'])) {
            return $allowed;
        }

        $excluded = ['admin_email'];
        if (defined('WP_SITEURL')) $excluded[] = 'siteurl';
        if (defined('WP_HOME')) $excluded[] = 'home';

        $allowed['general'] = array_values(array_diff($allowed['general'], $excluded));
        return $allowed;
    }

    public static function boot() {
        add_filter('allowed_options', [__CLASS__, 'allowed_options'], PHP_INT_MAX, 1);
    }
}
MAD4B_SCP_Native_General_Settings_Contract::boot();
