<?php
/** Local Lab frontend tunnel AJAX only, not a global rewrite of WP admin. */
if(!defined('ABSPATH'))return;
add_filter('admin_url',static function($url,$path='', $blog_id=null){
 if(wp_get_environment_type()!=='local'||wp_doing_ajax()||is_admin())return $url;
 if(!is_string($path)||!preg_match('~^/?admin-ajax\.php(?:\?.*)?$~',$path))return $url;
 return '/wp-admin/'.ltrim($path,'/');
},80,3);
