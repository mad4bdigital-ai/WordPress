<?php
/**
 * Plugin Name: MAD4B Pricing FX Refresh & Health
 * Description: Reliable, validated rate refresh for the shared USD pricing adapter.
 */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) {
    return;
}

function mad4b_fx_snapshot_file() {
    return __DIR__ . '/currency-data/public-rates.json';
}

function mad4b_fx_health() {
    $file = mad4b_fx_snapshot_file();
    $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    $age = is_array($data) ? time() - (int) strtotime((string) ($data['fetched_at'] ?? '')) : null;
    $valid = is_array($data) && ($data['base'] ?? '') === 'USD'
        && isset($data['rates']) && is_array($data['rates'])
        && $age !== null && $age >= -300 && $age <= 172800;
    foreach (['EUR', 'EGP', 'GBP'] as $quote) {
        if (!isset($data['rates'][$quote]) || !is_numeric($data['rates'][$quote])
            || !is_finite((float) $data['rates'][$quote]) || (float) $data['rates'][$quote] <= 0) {
            $valid = false;
        }
    }
    $state = get_option('mad4b_fx_refresh_state', []);
    return [
        'status' => $valid ? 'ready' : 'degraded_usd_only',
        'age_seconds' => $age,
        'source' => is_array($data) ? (string) ($data['source'] ?? '') : '',
        'last_success' => $state['last_success'] ?? null,
        'last_attempt' => $state['last_attempt'] ?? null,
        'last_error' => $state['last_error'] ?? null,
    ];
}

function mad4b_fx_refresh() {
    $dir = dirname(mad4b_fx_snapshot_file());
    if (!is_dir($dir) || !is_writable($dir)) {
        return new WP_Error('fx_storage_not_writable', 'Currency data directory is not writable');
    }
    $lock = @fopen($dir . '/.refresh.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        return new WP_Error('fx_refresh_busy', 'Another rate refresh is already running');
    }
    try {
        $state = get_option('mad4b_fx_refresh_state', []);
        if (!is_array($state)) {
            $state = [];
        }
        $state['last_attempt'] = gmdate('c');
        $state['last_error'] = null;
        $response = wp_safe_remote_get('https://api.frankfurter.dev/v2/rates?base=USD', [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException('fx_provider_unavailable');
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            throw new RuntimeException('fx_provider_http_' . (int) wp_remote_retrieve_response_code($response));
        }
        $raw = wp_remote_retrieve_body($response);
        if (strlen($raw) > 200000) {
            throw new RuntimeException('fx_response_oversize');
        }
        $entries = json_decode($raw, true);
        if (!is_array($entries) || count($entries) < 20) {
            throw new RuntimeException('fx_response_invalid');
        }
        $rates = ['USD' => 1.0];
        $dates = ['USD' => gmdate('Y-m-d')];
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['base'] ?? '') !== 'USD') {
                throw new RuntimeException('fx_base_mismatch');
            }
            $quote = (string) ($entry['quote'] ?? '');
            $value = $entry['rate'] ?? null;
            $date = (string) ($entry['date'] ?? '');
            if (!preg_match('/^[A-Z]{3}$/D', $quote)
                || !is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0
                || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $date)
                || strtotime($date . ' 00:00:00 UTC') === false
                || strtotime($date . ' 00:00:00 UTC') > time() + 86400) {
                throw new RuntimeException('fx_quote_invalid');
            }
            if ($quote === 'USD') {
                if (abs((float) $value - 1.0) > 0.000001) {
                    throw new RuntimeException('fx_base_quote_invalid');
                }
                continue;
            }
            if (isset($rates[$quote])) {
                throw new RuntimeException('fx_duplicate_quote');
            }
            $rates[$quote] = (float) $value;
            $dates[$quote] = $date;
        }
        foreach (['EUR', 'EGP', 'GBP'] as $required) {
            if (!isset($rates[$required]) || strtotime($dates[$required] . ' 00:00:00 UTC') < time() - 8 * DAY_IN_SECONDS) {
                throw new RuntimeException('fx_required_quote_stale_or_missing_' . $required);
            }
        }
        $old = is_readable(mad4b_fx_snapshot_file()) ? json_decode((string) file_get_contents(mad4b_fx_snapshot_file()), true) : null;
        if (is_array($old) && is_array($old['rates'] ?? null)) {
            foreach (['EUR', 'EGP', 'GBP'] as $code) {
                $previous = (float) ($old['rates'][$code] ?? 0);
                if ($previous > 0 && abs($rates[$code] / $previous - 1) > 0.30) {
                    throw new RuntimeException('fx_rate_change_requires_review_' . $code);
                }
            }
        }
        $payload = [
            'source' => 'Frankfurter v2',
            'base' => 'USD',
            'fetched_at' => gmdate('c'),
            'rates' => $rates,
            'dates' => $dates,
        ];
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!$json) {
            throw new RuntimeException('fx_json_encoding_failed');
        }
        $tmp = tempnam($dir, '.fx-');
        if (!$tmp) {
            throw new RuntimeException('fx_tempfile_failed');
        }
        try {
            if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('fx_write_failed');
            }
            chmod($tmp, 0640);
            if (!rename($tmp, mad4b_fx_snapshot_file())) {
                throw new RuntimeException('fx_atomic_replace_failed');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
        $state['last_success'] = gmdate('c');
        $state['last_error'] = null;
        update_option('mad4b_fx_refresh_state', $state, false);
        return ['status' => 'updated', 'quotes' => count($rates), 'fetched_at' => $payload['fetched_at']];
    } catch (Throwable $e) {
        $state['last_error'] = $e->getMessage();
        update_option('mad4b_fx_refresh_state', $state, false);
        return new WP_Error('fx_refresh_failed', $e->getMessage());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
add_action('mad4b_fx_rates_refresh', function () {
    $result = mad4b_fx_refresh();
    if (is_wp_error($result)) {
        error_log('[MAD4B FX] Refresh failed: ' . $result->get_error_message());
    }
});
add_action('init', function () {
    if (!wp_next_scheduled('mad4b_fx_rates_refresh')) {
        wp_schedule_event(time() + 60, 'hourly', 'mad4b_fx_rates_refresh');
    }
}, 20);
add_action('admin_notices', function () {
    if (!current_user_can('manage_woocommerce') || mad4b_fx_health()['status'] === 'ready') {
        return;
    }
    echo '<div class="notice notice-warning"><p>'
        . esc_html__('Currency feed is temporarily unavailable. Foreign-currency checkout remains blocked; USD stays available. The rate refresh retries automatically.', 'woocommerce')
        . '</p></div>';
});
