<?php
/** Generic read-only media-visibility compatibility for JetEngine galleries. */
defined('ABSPATH')||exit;
add_action('wp_enqueue_scripts',static function():void {
 if(is_admin()||is_feed())return;
 $path=__DIR__.'/shared-product-core/assets/presentation-media-visibility-v1.js';
 if(!is_readable($path))return;
 wp_enqueue_script('shared-presentation-media-visibility-v1',
  content_url('mu-plugins/shared-product-core/assets/presentation-media-visibility-v1.js'),
  [],(string)filemtime($path),true);
},85);
