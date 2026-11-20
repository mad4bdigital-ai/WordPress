<?php
/**
 * Plugin Name: MAD4B Tour Media Host Bridge
 * Description: Optional tourism-only signed-Host media workflows; never grants Host authority.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires Plugins: mad4b-site-control-plane
 */
if (!defined('ABSPATH')) exit;
if (version_compare(PHP_VERSION, '8.1', '<')) return;

/** Domain-specific media workflows are never global Control Plane defaults. */
add_action('init', static function () {
    // The core control plane and tourism CPT must both already be installed.
    // No new CPTs, secrets, grants, or authentication are registered here.
    if (!class_exists('MAD4B_SCP_Host_Agent_Bridge', false)
        || !function_exists('post_type_exists')
        || !post_type_exists('tours-and-activities')) return;
    foreach (array(
        'class-mad4b-scp-host-media-intake.php',
        'class-mad4b-scp-host-media-closure.php',
        'class-mad4b-scp-host-media-reconcile-proposal.php',
        'class-mad4b-scp-host-visual-strategy-proposal.php',
    ) as $file) {
        require_once __DIR__ . '/includes/' . $file;
    }
}, 99);
