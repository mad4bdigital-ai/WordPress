<?php
/**
 * Staging-only: Action Scheduler is pumped by the sole MAD4B unified timer.
 * Prevent a second, overlapping dispatch transport through admin-ajax.php.
 */
defined('ABSPATH') || exit;

// Give interactive FPM requests priority over concurrently arriving WP-CLI jobs.
if (defined('WP_CLI') && WP_CLI && wp_get_environment_type() === 'staging'
    && function_exists('proc_nice')) {
    @proc_nice(6);
}
add_filter('action_scheduler_allow_async_request_runner',static function($allowed) {
    if (function_exists('wp_get_environment_type')
        && wp_get_environment_type() === 'staging'
        && function_exists('home_url')
        && strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)) === 'tours.dev.mad4b.com') {
        return false;
    }
    return $allowed;
}, 9999);
