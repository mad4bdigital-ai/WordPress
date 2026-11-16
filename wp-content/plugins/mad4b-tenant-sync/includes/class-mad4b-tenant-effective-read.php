<?php
/**
 * Explicit read-only consumer adapter.
 * No global WP metadata/SEO/WooCommerce filters: templates opt in to an
 * effective field and can fall back to native WordPress unchanged.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Effective_Read {
    const CONTRACT='mad4b.tenant-effective-read.v1';
    public static function boot(){
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_shortcode('mad4b_tenant_field',[__CLASS__,'shortcode']);
        add_shortcode('mad4b_tenant_featured_image',[__CLASS__,'featured_image']);
        add_shortcode('mad4b_tenant_banners',[__CLASS__,'banners']);
    }
    private static function local_map($post_id) {
        $post=get_post((int)$post_id);
        if(!$post || !MAD4B_Tenant_Override_Policy::is_content_type($post->post_type))
            return new WP_Error('mad4b_effective_unsupported_post');
        $s=MAD4B_Tenant_Sync::profile();
        if(empty($s['ready']))return new WP_Error('mad4b_effective_profile_unverified');
        // Use the canonical company post context for both WPML and native WP.
        // Divergent language resolution previously broke shortcodes on
        // single-language customer sites.
        $ctx=MAD4B_Tenant_Override_Policy::context($post_id);
        if(!$ctx || $ctx['site_uuid']!==$s['site_uuid'])
            return new WP_Error('mad4b_effective_language_unverified');
        $lang=$ctx['language'];
        $key='local-'.substr(hash('sha256',$s['site_uuid'].'|'.$post->post_type.'|'.$post->ID),0,32);
        $map=MAD4B_Tenant_Sparse_Store::mapping($key,$lang);
        if(!$map||is_wp_error($map))return new WP_Error('mad4b_effective_mapping_missing');
        if((int)$map['local_post_id']!==(int)$post_id)return new WP_Error('mad4b_effective_wrong_post_binding');
        return $map;
    }
    public static function resolve_local_post($post_id) {
        $map=self::local_map($post_id);
        if(is_wp_error($map))return $map;
        return MAD4B_Tenant_Sparse_Store::effective($map['global_content_id'],$map['language']);
    }
    public static function routes(){
        register_rest_route('mad4b-tenant/v1','/effective',[
            'methods'=>'GET',
            'permission_callback'=>static function($request){
                $id=absint($request->get_param('post_id'));
                return $id && current_user_can('edit_post',$id);
            },
            'callback'=>static function($request){
                $r=self::resolve_local_post(absint($request->get_param('post_id')));
                $response=is_wp_error($r)?$r:rest_ensure_response($r);
                if($response instanceof WP_REST_Response)$response->header('Cache-Control','private, no-store');
                return $response;
            }
        ]);
    }
    private static function public_object($args) {
        $id=absint($args['post_id']??0)?:get_the_ID();
        if(!$id)return null;
        $post=get_post($id);
        if(!$post || !is_post_publicly_viewable($post))return null;
        $r=self::resolve_local_post($id);
        return !is_wp_error($r)&&($r['status']??'')==='preview_only'?$r:null;
    }
    private static function local_asset($asset,$site_uuid,$size='large') {
        if(!is_array($asset)||($asset['scope']??'')!=='tenant'
          ||($asset['site_uuid']??'')!==$site_uuid)return '';
        $id=(int)($asset['attachment_id']??0);
        if(!$id || get_post_type($id)!=='attachment'
          ||!str_starts_with((string)get_post_mime_type($id),'image/'))return '';
        return wp_get_attachment_image($id,$size,false,['loading'=>'lazy']);
    }
    public static function featured_image($atts){
        $args=shortcode_atts(['post_id'=>0,'size'=>'large'],$atts,'mad4b_tenant_featured_image');
        $r=self::public_object($args);
        if(!$r)return '';
        $asset=$r['fields']['featured_media_global_id']??null;
        return self::local_asset($asset,$r['site_uuid'],sanitize_key($args['size']));
    }
    public static function banners($atts){
        $args=shortcode_atts(['post_id'=>0,'size'=>'large'],$atts,'mad4b_tenant_banners');
        $r=self::public_object($args);
        if(!$r)return '';
        $assets=$r['fields']['offer_banners']??[];
        if(!is_array($assets))return '';
        $html='';
        foreach(array_slice($assets,0,20) as $asset)
            $html.=self::local_asset($asset,$r['site_uuid'],sanitize_key($args['size']));
        return $html;
    }
    public static function shortcode($atts){
        $args=shortcode_atts(['post_id'=>0,'field'=>'title'],$atts,'mad4b_tenant_field');
        $id=absint($args['post_id'])?:get_the_ID();
        $field=sanitize_key((string)$args['field']);
        if(!$id||!in_array($field,['title','excerpt','seo_title','seo_description','seo_focus_keyword',
            'presentation_variant'],true))
            return '';
        $post=get_post($id);
        if(!$post || !is_post_publicly_viewable($post))return '';
        $value=self::resolve_local_post($id);
        if(is_wp_error($value)||($value['status']??'')!=='preview_only')return '';
        $text=$value['fields'][$field]??'';
        return is_string($text)?esc_html($text):'';
    }
}
MAD4B_Tenant_Effective_Read::boot();
