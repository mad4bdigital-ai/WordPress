<?php
/**
 * WPL Client uninstall cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

require_once __DIR__ . '/includes/class-wpl-database-maintenance.php';

WPL_Database_Maintenance::uninstall();
