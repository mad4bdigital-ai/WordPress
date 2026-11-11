<?php
/** Plugin Name: Header Control Appearance
 * Presentation only: native Elementor owns button/tab styling; these rules
 * normalize WPML, currency and market controls inside Theme Builder headers.
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts',static function(){
 $file=WPMU_PLUGIN_DIR.'/header-control-appearance.css';
 wp_enqueue_style('header-control-appearance',plugins_url('header-control-appearance.css',(WPMU_PLUGIN_DIR.'/header-control-appearance.php')),[],(string)filemtime($file));
},99);
