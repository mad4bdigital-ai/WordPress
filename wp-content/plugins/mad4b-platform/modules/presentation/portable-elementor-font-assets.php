<?php
/**
 * Plugin Name: Portable Elementor Self-Hosted Font Styles
 * Description: Serves generated Elementor font CSS from the current site's local upload directory.
 */
if(!defined('ABSPATH'))exit;
add_filter('style_loader_src',static function($src,$handle){
 if(!is_string($src) || !str_contains($src,'/elementor/google-fonts/css/'))return $src;
 $path=parse_url($src,PHP_URL_PATH);
 if(!is_string($path) || !preg_match('~/elementor/google-fonts/css/([a-z0-9_-]+\.css)$~i',$path,$match))return $src;
 $upload=wp_get_upload_dir();
 if(!empty($upload['error']) || !is_file(trailingslashit($upload['basedir']).'elementor/google-fonts/css/'.$match[1]))return $src;
 $url=trailingslashit($upload['baseurl']).'elementor/google-fonts/css/'.$match[1];
 // Tie the browser cache to the actual generated CSS revision, rather
 // than a stale source site's Elementor timestamp.
 return add_query_arg('ver',(string)filemtime(trailingslashit($upload['basedir']).'elementor/google-fonts/css/'.$match[1]),$url);
},99,2);
