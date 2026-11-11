<?php
/**
 * Plugin Name: MAD4B Platform
 * Description: Central-only control plane, site services, diagnostics and integrations modules.
 * Version: 0.2.2
 * Requires PHP: 8.0
 */
defined('ABSPATH')||exit;
// Runtime modules are loaded early from the one MU bootstrap, in original
// order, to preserve authority and inter-module dependencies. This plugin
// supplies the inventory/Settings surface, not a second module-loader.
final class MAD4B_Platform_Modules_V1 {
 public const VERSION='0.2.2';
 public static function installed():bool {return true;}
}

// Read-only operational inventory, no write or module-disable endpoints.
require_once __DIR__ . '/includes/class-mad4b-module-registry-admin.php';
