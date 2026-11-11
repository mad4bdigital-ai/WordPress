<?php
/**
 * Plugin Name: MAD4B — Native JetEngine Taxonomy FAQ Runtime
 * Description: Loads one editable Elementor section and JetEngine listing only when approved taxonomy FAQ rows exist.
 */
defined('ABSPATH') || exit;
final class MAD4B_Taxonomy_FAQ_Runtime {
    const TEMPLATE_OPTION='mad4b_taxonomy_faq_template_id';
    const CACHE_GROUP='mad4b_taxonomy_faq_fragments';
    public static function register():void {
        add_shortcode('mad4b_taxonomy_faq_archive',[self::class,'shortcode']);
        add_action('rest_api_init',[self::class,'rest']);
        add_action('wp_enqueue_scripts',[self::class,'assets'],98);
    }
    private static function allowed_archive():bool {
        if (is_post_type_archive('tours-and-activities')) return true;
        if (is_singular('tours-and-activities')) return true;
        return is_tax(MAD4B_Taxonomy_FAQ_Source::enabled_taxonomies());
    }
    public static function assets():void {
        if(!self::allowed_archive() || is_singular('tours-and-activities'))return;
        // Tour single pages are server-rendered and have no archive filter URL.
        $path=__DIR__.'/assets/mad4b-taxonomy-faq-runtime.js';
        if(!is_file($path))return;
        wp_enqueue_script('mad4b-taxonomy-faq-runtime',content_url('mu-plugins/assets/mad4b-taxonomy-faq-runtime.js'),array(),(string)filemtime($path),true);
        wp_add_inline_script('mad4b-taxonomy-faq-runtime','window.MAD4B_TAXONOMY_FAQ=' . wp_json_encode(array(
            'endpoint'=>esc_url_raw(rest_url('mad4b/v1/taxonomy-faq')),
            'archivePath'=>'/tours-and-activities/',
            'version'=>'1',
        ),JSON_UNESCAPED_SLASHES).';','before');
    }
    public static function valid_path(string $path):bool {
        if(strlen($path)>600 || preg_match('/[\\x00-\\x1f\\x7f\\\\]/',$path))return false;
        if(str_contains($path,'?')||str_contains($path,'#'))return false;
        $p=(string)wp_parse_url($path,PHP_URL_PATH);
        if($p!==$path || str_contains($path,'..'))return false;
        if(preg_match('~^/(?:en/|fr/|de/|es/|it/|ar/)?tours-and-activities/$~D',$path))return true;
        return (bool)preg_match('~^/(?:en/|fr/|de/|es/|it/|ar/)?tours-and-activities/jsf/jet-engine:tours_query_archive/tax/[a-z0-9_-]+:[a-z0-9_-]+(?:;[a-z0-9_-]+:[a-z0-9_-]+){0,3}/$~D',$path);
    }
    public static function fragment(?string $route=null):array {
        if(!class_exists('MAD4B_Taxonomy_FAQ_Source'))return array('html'=>'','count'=>0);
        $loader=static function():array {
            $rows=MAD4B_Taxonomy_FAQ_Source::items();
            $count=count($rows);
            if(!$count)return array('html'=>'','count'=>0);
            $id=(int)get_option(self::TEMPLATE_OPTION,0);
            if(!$id || get_post_status($id)!=='publish')return array('html'=>'','count'=>0);
            // Hash the approved rows (and locale), so a term edit changes only
            // the relevant fragment's dependency key, not every archive page.
            $listingId=(int)get_option('mad4b_taxonomy_faq_listing_id',0);
            $templateRevision=hash('sha256',(string)get_post_meta($id,'_elementor_data',true)
                .'|'.(string)get_post_meta($listingId,'_elementor_data',true));
            $contextId=is_singular('tours-and-activities') ? (int)get_queried_object_id() : 0;
            $hash=hash('sha256',wp_json_encode(array('grid-v2',MAD4B_Taxonomy_FAQ_Source::language(),$id,$listingId,
                $templateRevision,$contextId,$rows),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $key='termfaq_v2_'.substr($hash,0,38);
            $cached=wp_cache_get($key,self::CACHE_GROUP);
            if(is_array($cached)&&isset($cached['html'],$cached['count']))return $cached;
            if(!class_exists('Elementor\\Plugin'))return array('html'=>'','count'=>0);
            $html=(string)\Elementor\Plugin::$instance->frontend->get_builder_content_for_display($id,true);
            // The previous substring check also matched CSS __items and JetEngine's
            // 'No data was found' wrapper, caching a blank result as a valid FAQ.
            if(!preg_match('/class="[^"]*\bjet-listing-grid__item\b[^"]*"/',$html))
                return array('html'=>'','count'=>0);
            $result=array('html'=>$html,'count'=>$count);
            wp_cache_set($key,$result,self::CACHE_GROUP,300);
            return $result;
        };
        if($route!==null)return MAD4B_Taxonomy_FAQ_Source::with_route($route,$loader);
        // Native Elementor and JetEngine replace the current object while
        // rendering nested templates. Pin the actual tour and locale.
        if(is_singular('tours-and-activities')){
            $postId=(int)get_queried_object_id();
            if($postId>0)return MAD4B_Taxonomy_FAQ_Source::with_tour($postId,$loader);
        }
        return $loader();
    }
    public static function shortcode($attrs=array()):string {
        if(!self::allowed_archive())return '';
        $result=self::fragment();
        // Avoid empty Elementor accordion wrappers on an individual tour.
        if(is_singular('tours-and-activities') && empty($result['count']))return '';
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        return '<div class="mad4b-taxonomy-faq-mount" data-mad4b-faq-mount="1" data-mad4b-faq-path="'.esc_attr($path).'"'.
          (empty($result['count'])?' hidden':'').'>' . $result['html'].'</div>';
    }
    public static function rest():void {
        register_rest_route('mad4b/v1','/taxonomy-faq',array(
            'methods'=>'GET','permission_callback'=>'__return_true',
            'args'=>array('path'=>array('required'=>true,'type'=>'string')),
            'callback'=>static function(\WP_REST_Request $request){
                $path=(string)$request->get_param('path');
                if(!self::valid_path($path))return new \WP_Error('mad4b_invalid_faq_route','Unsupported FAQ route',array('status'=>400));
                $result=self::fragment($path);
                $response=rest_ensure_response(array('status'=>'ready','count'=>$result['count'],'html'=>$result['html'],'path'=>$path));
                $response->header('Cache-Control','public, max-age=60');
                return $response;
            }
        ));
    }
}
add_action('init',array('MAD4B_Taxonomy_FAQ_Runtime','register'),20);

/**
 * Elementor Element Cache: only FAQ hosts must render per current term/post.
 * This is the native dynamic-element opt out, not a site-wide cache bypass.
 */
add_filter('elementor/element/is_dynamic_content',static function($dynamic,$data,$element){
    if($dynamic || !is_array($data))return $dynamic;
    $css=(string)($data['settings']['css_classes']??'');
    $classes=' '.trim($css).' ';
    return str_contains($classes,' mad4b-term-faq-mount-container ') ||
           str_contains($classes,' mad4b-term-faq-section ');
},20,3);
