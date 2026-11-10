<?php
/**
 * Plugin Name: MAD4B Navigation Cache Consistency
 * Description: Selectively invalidates Elementor native-menu header HTML when its WPML menu changes.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Navigation_Cache_Consistency_V1',false)) {
final class MAD4B_Navigation_Cache_Consistency_V1 {
    private static bool $invalidated=false;
    private static function is_native_navigation_menu(int $menu_id):bool {
        if ($menu_id<=0)return false;
        $term=get_term($menu_id,'nav_menu');
        if(!$term instanceof WP_Term || is_wp_error($term))return false;
        $id=(int)apply_filters('wpml_object_id',$menu_id,'nav_menu',true,apply_filters('wpml_default_language','en'));
        if(!$id)$id=$menu_id;
        $base=get_term_by('slug',apply_filters('product_navigation_menu_slug','main-menu-en'),'nav_menu');
        return $base instanceof WP_Term && (int)$base->term_id===$id;
    }
    public static function menu_updated($menu_id=0,...$args):void {
        if(!self::is_native_navigation_menu((int)$menu_id))return;
        self::invalidate_headers();
    }
    public static function nav_item_post_updated(int $post_id, WP_Post $post_after, WP_Post $post_before):void {
        if($post_after->post_type!=='nav_menu_item')return;
        if((int)$post_after->menu_order===(int)$post_before->menu_order &&
           $post_after->post_status===$post_before->post_status &&
           $post_after->post_title===$post_before->post_title)return;
        $menus=wp_get_object_terms($post_id,'nav_menu',['fields'=>'ids']);
        if(is_wp_error($menus))return;
        foreach($menus as $id)if(self::is_native_navigation_menu((int)$id)){self::invalidate_headers();break;}
    }
    public static function invalidate_headers():array {
        if(self::$invalidated)return ['invalidated'=>0,'already_done'=>true];
        self::$invalidated=true;
        global $wpdb;
        // Only published Elementor templates containing the native MAD4B
        // mega-menu projection. No LiteSpeed Purge All and no CSS regeneration.
        $like='%'.$wpdb->esc_like('product-native-navigation').'%';
        $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type='elementor_library' AND p.post_status='publish' AND m.meta_key='_elementor_data' AND m.meta_value LIKE %s",$like));
        $removed=0;
        foreach((array)$ids as $id){
            $id=(int)$id;
            if(metadata_exists('post',$id,'_elementor_element_cache')){
                if(delete_post_meta($id,'_elementor_element_cache'))$removed++;
                clean_post_cache($id);
            }
        }
        do_action('mad4b/navigation/cache_invalidation',[ 'template_ids'=>array_map('intval',(array)$ids),'removed'=>$removed,'reason'=>'nav_menu_changed' ]);
        return ['invalidated'=>$removed,'templates'=>array_map('intval',(array)$ids)];
    }
}
add_action('wp_update_nav_menu_item',[MAD4B_Navigation_Cache_Consistency_V1::class,'menu_updated'],50,2);
add_action('wp_update_nav_menu',[MAD4B_Navigation_Cache_Consistency_V1::class,'menu_updated'],50,1);
add_action('post_updated',[MAD4B_Navigation_Cache_Consistency_V1::class,'nav_item_post_updated'],50,3);
}
