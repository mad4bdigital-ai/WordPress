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
    // A prior false is a denial, while a prior true is an unverified success
    // short-circuit. Neither can authorize a Staging email delivery.
    if (false === $pre) return false;
    if (!class_exists('MAD4B_SCP_Company_Communication', false)
        || !method_exists('MAD4B_SCP_Company_Communication', 'canSend')
        || !MAD4B_SCP_Company_Communication::canSend()) return false;
    // Transport certification is external and independent of stored credentials.
    if (!method_exists('MAD4B_SCP_Company_Communication', 'smtpConfigured')
        || !MAD4B_SCP_Company_Communication::smtpConfigured()) return false;
    // Preserve a prior error/interception only if its failure is explicit;
    // do not falsely report successful delivery without native wp_mail.
    if ($pre !== null) return false;
    return null; // Native WordPress/Fluent SMTP may run after all approvals.
}, PHP_INT_MAX, 2);
