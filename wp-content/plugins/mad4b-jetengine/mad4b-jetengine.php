<?php
/**
 * Plugin Name: MAD4B JetEngine
 * Description: Extensible, modular JetEngine integration, with independent Hub and Functional Modes modules.
 * Version: 1.0.0
 * Requires PHP: 8.0
 * Requires Plugins: jet-engine
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_JetEngine_Core_V1', false)) {
    require_once __DIR__ . '/core/class-mad4b-jetengine-core.php';
    MAD4B_JetEngine_Core_V1::boot(__DIR__);
}
