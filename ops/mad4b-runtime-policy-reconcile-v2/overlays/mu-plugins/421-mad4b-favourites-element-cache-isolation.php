<?php
/**
 * Plugin Name: MAD4B Favourites Elementor Element Cache Isolation
 * Description: Isolate favourite pages from Elementor shared output caching. All other pages keep default caching.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Favourites_Element_Cache_Isolation_V11 {
    public static function is_favourites_page():bool {
        if (is_admin() || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || !is_page())return false;
        $current_id=(int)get_queried_object_id();
        if(!$current_id)return false;
        $base=get_page_by_path('favourites');
        if(!$base || $base->post_type!=='page'
            || $base->post_status!=='publish')return false;
        if ($current_id===(int)$base->ID)return true;
        $language=(string)apply_filters('wpml_current_language','');
        if ($language==='' || !preg_match('/\A[a-z]{2}(?:-[a-z]{2})?\z/D',$language))return false;
        $translated=(int)apply_filters('wpml_object_id',(int)$base->ID,'page',false,$language);
        return $translated>0 && $translated===$current_id;
    }
    public static function cache_policy($previous) {
        return self::is_favourites_page() ? 'disable' : $previous;
    }
}
add_filter('pre_option_elementor_element_cache_ttl',
    ['MAD4B_Favourites_Element_Cache_Isolation_V11','cache_policy'],88,1);
