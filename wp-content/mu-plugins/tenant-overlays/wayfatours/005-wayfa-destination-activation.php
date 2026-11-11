<?php
/**
 * Plugin Name: Wayfa Tenant Destination Activation
 * Description: Tenant-local destination visibility using the existing independent taxonomy module.
 * Version: 1.0.0
 */
if (!class_exists('MAD4B_Tenant_Service_Identity_Boundary_V1',false) || !MAD4B_Tenant_Service_Identity_Boundary_V1::eligible()) return;
if (function_exists('destinations_activation_allowed')) return;
$local = WP_PLUGIN_DIR . '/destinations-compatibility/includes/activation.php';
if (is_readable($local)) require_once $local;
