<?php
/**
 * Opt-in source consumers. Never replace native WP/JetEngine/SEO values
 * without an explicit company-selected field override or custom field key.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Consumers {
    const CONTRACT='mad4b.tenant-optin-consumers.v1';
    const DISPLAY_FIELDS=['title','excerpt','seo_title','seo_description','seo_focus_keyword','presentation_variant'];
    public static function boot() {
        add_filter('rank_math/frontend/title',[__CLASS__,'rank_title'],20);
        add_filter('rank_math/frontend/description',[__CLASS__,'rank_description'],20);
        add_filter('jet-engine/listings/dynamic-field/custom-value',[__CLASS__,'jet_custom'],20,3);
        add_action('elementor/dynamic_tags/register',[__CLASS__,'register_elementor']);
    }
    public static function field($post_id,$field,$only_overridden=false) {
        if(!in_array($field,self::DISPLAY_FIELDS,true))return null;
        $post=get_post((int)$post_id);
        if(!$post||!is_post_publicly_viewable($post))return null;
        $result=MAD4B_Tenant_Effective_Read::resolve_local_post((int)$post_id);
        if(is_wp_error($result)||($result['status']??'')!=='preview_only')return null;
        if($only_overridden&&!in_array($field,$result['override_fields']??[],true))return null;
        $v=$result['fields'][$field]??null;
        return is_string($v)?$v:null;
    }
    public static function rank_for_post($native,$id,$field) {
        if(!is_string($native)||!in_array($field,['seo_title','seo_description'],true))return $native;
        // Prevent unexpected reindexing: Rank Math is overlaid only for a
        // saved company override on the current publicly viewable post.
        $value=self::field($id,$field,true);
        return $value!==null&&$value!==''?sanitize_text_field($value):$native;
    }
    public static function rank_title($native) {
        if(!is_singular()||is_admin()||!is_string($native))return $native;
        return self::rank_for_post($native,(int)get_queried_object_id(),'seo_title');
    }
    public static function rank_description($native) {
        if(!is_singular()||is_admin()||!is_string($native))return $native;
        return self::rank_for_post($native,(int)get_queried_object_id(),'seo_description');
    }
    public static function jet_custom($native,$settings,$widget){
        if(!is_array($settings))return $native;
        $requested=$settings['dynamic_field_post_meta_custom']??'';
        if(!is_string($requested)||!str_starts_with($requested,'mad4b_effective:'))return $native;
        $field=substr($requested,strlen('mad4b_effective:'));
        if(!in_array($field,self::DISPLAY_FIELDS,true))return $native;
        $id=(int)get_the_ID();
        if(!$id)return $native;
        $v=self::field($id,$field,false);
        return $v===null?$native:sanitize_text_field($v);
    }
    public static function register_elementor($manager){
        if(!class_exists('\Elementor\Core\DynamicTags\Tag')
            ||!is_object($manager)||!method_exists($manager,'register'))return;
        require_once __DIR__.'/class-mad4b-tenant-elementor-tag.php';
        $manager->register(new MAD4B_Tenant_Elementor_Text_Tag());
    }
}
MAD4B_Tenant_Consumers::boot();
