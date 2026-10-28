<?php
/**
 * Plugin Name: MAD4B Staging Mail Admission
 * Description: Site-specific Staging mail gate; no Sandbox HTTP or Cron filtering.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) exit;
if (!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'staging') return;

// The control-plane and transport must BOTH be approved before permitting wp_mail.
// Do not short-circuit success; false explicitly reports that mail was blocked.
add_filter('pre_wp_mail', static function ($pre, $atts) {
    if ($pre !== null) return $pre; // Respect stricter earlier-deny policies.
    if (!class_exists('MAD4B_SCP_Company_Communication', false)
        || !method_exists('MAD4B_SCP_Company_Communication', 'canSend')
        || !MAD4B_SCP_Company_Communication::canSend()) return false;
    // An installed SMTP plugin is not proof that transport is configured.
    if (!in_array('fluent-smtp/fluent-smtp.php', (array)get_option('active_plugins', array()), true))
        return false;
    $connections=get_option('fluentmail-settings', null);
    if (!is_array($connections) || !$connections) return false;
    return $pre; // Null hands off to native WordPress / Fluent SMTP execution.
}, PHP_INT_MAX, 2);
