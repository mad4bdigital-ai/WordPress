<?php
/**
 * Plugin Name: MAD4B Wayfatours Provider-Verified Notice Revalidation
 * Description: Keeps historical dashboard observations separate from live plugin state; only corrects a notice with independent provider evidence.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Wayfa_Configuration_Notice_Revalidation_V1', false)) {
final class MAD4B_Wayfa_Configuration_Notice_Revalidation_V1 {
    public const CONTRACT = 'mad4b.wayfa.provider-verified-admin-notice.v1';
    public const STORE = 'mad4b_configuration_alerts_v1';
    private const STRIPE_NOTICE_PREFIX = 'Test mode active: All transactions are simulated.';

    public static function stripe_evidence($evidence, array $row) {
        if (($row['source'] ?? '') !== 'admin_notice'
            || !preg_match('/^admin_notice_[a-f0-9]{16}$/D', (string)($row['code'] ?? ''))
            || !str_starts_with((string)($row['label'] ?? ''), self::STRIPE_NOTICE_PREFIX)
            || !str_contains((string)($row['label'] ?? ''), 'through Stripe.')) return $evidence;
        // Observe actual installed gateway and native settings, never notice age,
        // DOM scraping, or secret values. Unknown provider state never closes.
        if (!in_array('woocommerce-gateway-stripe/woocommerce-gateway-stripe.php',
              (array)get_option('active_plugins', []), true)) return $evidence;
        $settings = get_option('woocommerce_stripe_settings', []);
        if (!is_array($settings) || ($settings['enabled'] ?? '') !== 'yes'
            || !in_array(($settings['testmode'] ?? null), ['yes', 'no'], true)) return $evidence;
        return ['contract' => self::CONTRACT, 'provider' => 'woocommerce-gateway-stripe',
            'provider_evidence' => 'woocommerce_stripe_settings.testmode',
            'test_mode' => $settings['testmode'] === 'yes',
            'verification' => 'independent_live_option_readback'];
    }

    public static function recheck(): array {
        if (strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST)) !== 'wayfatours.com')
            return ['contract' => self::CONTRACT, 'status' => 'site_scope_mismatch', 'changed' => 0];
        if (!empty($GLOBALS['mad4b_configuration_discovery_incomplete']))
            return ['contract' => self::CONTRACT, 'status' => 'scan_incomplete', 'changed' => 0];
        global $wpdb;
        $lock = 'mad4b_config_alerts_' . get_current_blog_id();
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,1)', $lock)) !== 1)
            return ['contract' => self::CONTRACT, 'status' => 'ledger_busy', 'changed' => 0];
        try {
            $rows = get_option(self::STORE, []);
            if (!is_array($rows)) return ['contract' => self::CONTRACT, 'status' => 'ledger_invalid', 'changed' => 0];
            $resolved = 0; $reopened = 0; $checked = 0;
            foreach ($rows as &$row) {
                if (!is_array($row) || ($row['source'] ?? '') !== 'admin_notice') continue;
                $e = apply_filters('mad4b/configuration_jobs/admin_notice_revalidation_evidence', null, $row);
                if (!is_array($e) || ($e['contract'] ?? '') !== self::CONTRACT
                    || ($e['provider'] ?? '') !== 'woocommerce-gateway-stripe'
                    || ($e['provider_evidence'] ?? '') !== 'woocommerce_stripe_settings.testmode'
                    || !is_bool($e['test_mode'] ?? null)) continue;
                $checked++;
                if ($e['test_mode'] === false && ($row['state'] ?? '') === 'open') {
                    $row['state'] = 'resolved';
                    $row['resolved_at'] = gmdate('c');
                    $row['resolution_contract'] = self::CONTRACT;
                    $row['resolution_evidence'] = $e['provider_evidence'] . '=no';
                    $row['provider_verified_at'] = gmdate('c');
                    $resolved++;
                } elseif ($e['test_mode'] === true && ($row['state'] ?? '') === 'resolved'
                          && ($row['resolution_contract'] ?? '') === self::CONTRACT) {
                    $row['state'] = 'open';
                    unset($row['resolved_at'], $row['resolution_evidence']);
                    $row['provider_verified_at'] = gmdate('c');
                    $row['reopens'] = min(100000, 1 + (int)($row['reopens'] ?? 0));
                    $reopened++;
                }
            }
            unset($row);
            if (($resolved + $reopened) > 0 && !update_option(self::STORE, $rows, false))
                return ['contract' => self::CONTRACT, 'status' => 'ledger_update_failed', 'changed' => 0];
            return ['contract' => self::CONTRACT, 'status' => 'checked',
                'checked' => $checked, 'resolved' => $resolved,
                'reopened' => $reopened, 'changed' => $resolved + $reopened];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
    public static function boot(): void {
        add_filter('mad4b/configuration_jobs/admin_notice_revalidation_evidence',
            [self::class, 'stripe_evidence'], 10, 2);
        // Run after MAD4B_Configuration_Jobs::scan() (priority 10). No extra
        // schedule or remote credentials; the existing 30-minute scan owns cadence.
        add_action('mad4b_configuration_jobs_scan', [self::class, 'recheck'], 90);
    }
}
MAD4B_Wayfa_Configuration_Notice_Revalidation_V1::boot();
}
