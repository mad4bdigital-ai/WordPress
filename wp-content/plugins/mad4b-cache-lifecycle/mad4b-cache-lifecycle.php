<?php
/**
 * Plugin Name: MAD4B Cache Lifecycle
 * Description: Tenant-local targeted invalidation, cache freshness and context isolation, coordinated by the existing Company Manager.
 * Version: 1.0.2
 * Requires PHP: 8.0
 */
defined('ABSPATH')||exit;
require_once __DIR__.'/includes/runtime.php';
require_once __DIR__.'/includes/control.php';
MAD4B_Cache_Lifecycle_Control_V1::boot();
register_activation_hook(__FILE__,[MAD4B_Cache_Lifecycle_Control_V1::class,'activate']);
