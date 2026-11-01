<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
require_once __DIR__ . '/includes/Lifecycle/UninstallPolicy.php';
DynamicFilterSEOBridge\Lifecycle\UninstallPolicy::cleanup();
