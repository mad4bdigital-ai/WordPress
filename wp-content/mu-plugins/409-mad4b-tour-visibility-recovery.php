<?php
/**
 * Plugin Name: MAD4B Tour Visibility Recovery — Central Source
 * Description: Read-only fallback for missed Elementor entrance callbacks on published tour sections.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
add_action('wp_head', static function (): void {
    if (is_admin() || is_preview() || !is_singular('tours-and-activities') ||
        wp_doing_ajax() || is_user_logged_in()) return;
    // Portable source template: the policy binds to the resolved WordPress host.
    $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    if ($host === '' || strlen($host) > 253 || !preg_match('/^[a-z0-9.-]+$/D', $host)) return;
    $id = get_queried_object_id();
    if (!$id || get_post_status($id) !== 'publish' || post_password_required($id)) return;
    $policy = WPMU_PLUGIN_DIR . '/mad4b-tour-visibility-recovery.policy.json';
    $cfg = is_file($policy) && !is_link($policy) && filesize($policy) < 2048
        ? json_decode((string) file_get_contents($policy), true) : null;
    if (!is_array($cfg) || !in_array(($cfg['site'] ?? null), [$host, '@current'], true)) return;
    $mode = $cfg['mode'] ?? 'off';
    if ($mode !== 'active' && !($mode === 'canary' &&
        ($_GET['mad4b_tour_visibility_canary'] ?? null) === '1')) return;
    $asset = WPMU_PLUGIN_DIR . '/assets/mad4b-tour-visibility-recovery.js';
    if (!is_file($asset) || !is_readable($asset)) return;
    $src = content_url('mu-plugins/assets/mad4b-tour-visibility-recovery.js');
    $src = add_query_arg('ver', (string) filemtime($asset), $src);
    echo '<script id="mad4b-tour-visibility-recovery-js" data-no-optimize="1" data-no-defer="1" data-cfasync="false" defer src="'
       . esc_url($src) . '"></script>' . "\n";
}, 8);
