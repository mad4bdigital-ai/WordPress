<?php
/** Reversible Staging-only suppression of developer Query Monitor overhead. */
defined('ABSPATH') || exit;
add_filter('option_active_plugins', static function($active) {
    if (wp_get_environment_type() !== 'staging' || !is_array($active)) {
        return $active;
    }
    return array_values(array_filter($active,static function($plugin) {
        return !str_starts_with((string)$plugin, 'query-monitor/');
    }));
}, 1);
