<?php
/**
 * Plugin Name: MAD4B Elementor Tour Rate Style Resilience (Staging)
 * Description: Preserve the original Elementor accordion design when generated JetEngine listing CSS is missing; source-derived and event-refreshed.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_Rate_CSS_Resilience_V1 {
    const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    const TARGET=56197;
    const KIT=60050;
    const OPTION='mad4b_rate_css_shadow_v1';
    const HOOK='mad4b_rate_css_shadow_refresh_v1';
    private static function bound(){
        if(wp_get_environment_type()!=='staging'||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return false;
        $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
        return is_array($p)&&($p['site_uuid']??'')===self::UUID;
    }
    public static function valid_css($css){
        return is_string($css)&&strlen($css)>4000&&strlen($css)<65536
            &&str_contains($css,'.elementor-56197')
            &&str_contains($css,'.elementor-element-2d192ba')
            &&str_contains($css,'.jet-toggle__label-text')
            &&!preg_match('~</style~i',$css);
    }
    public static function canonical_css(){
        $meta=get_post_meta(self::TARGET,'_elementor_css',true);
        $css=is_array($meta)?($meta['css']??null):null;
        return self::valid_css($css)?$css:null;
    }
    public static function shadow(){
        $current=self::canonical_css();
        if($current)return ['css'=>$current,'source'=>'elementor_current_meta','sha256'=>hash('sha256',$current)];
        $last=get_option(self::OPTION,[]);
        if(is_array($last)&&self::valid_css($last['css']??null)
            &&hash_equals((string)($last['sha256']??''),hash('sha256',$last['css'])))
            return ['css'=>$last['css'],'source'=>'verified_last_known_good','sha256'=>$last['sha256']];
        return null;
    }
    public static function render(){
        if(!self::bound()||is_admin()||wp_doing_ajax()||is_preview()
            ||!is_singular('tours-and-activities'))return;
        $chosen=self::shadow();
        if(!$chosen){self::schedule();return;}
        if($chosen['source']==='verified_last_known_good')self::schedule();
        // Keep the original template's global token references and entire CSS. It
        // appears before Elementor's regular inline CSS, so fresh Elementor wins.
        echo "\n<style id=\"mad4b-rate-css-shadow\" data-no-optimize=\"1\" data-css-sha256=\"".
            esc_attr($chosen['sha256'])."\" data-source=\"".esc_attr($chosen['source'])."\">\n".
            $chosen['css']."\n</style>\n";
    }
    public static function schedule(){
        if(!self::bound()||wp_next_scheduled(self::HOOK))return;
        wp_schedule_single_event(time()+18,self::HOOK);
    }
    public static function saved($post_id){
        if(!self::bound()||!in_array((int)$post_id,[self::TARGET,self::KIT],true))return;
        self::schedule();
    }
    public static function meta_changed($meta_id,$post_id,$key,$value){
        if($key==='_elementor_data'&&in_array((int)$post_id,[self::TARGET,self::KIT],true))self::saved($post_id);
    }
    public static function refresh(){
        if(!self::bound())return new WP_Error('rate_css_wrong_site');
        if(get_transient('mad4b_rate_css_refresh_lock_v1'))return new WP_Error('rate_css_refresh_locked');
        set_transient('mad4b_rate_css_refresh_lock_v1',1,90);
        try {
            $before=self::shadow();
            $meta_before=get_post_meta(self::TARGET,'_elementor_css',true);
            if(!class_exists('Elementor\\Core\\Files\\CSS\\Post'))return new WP_Error('elementor_css_class_unavailable');
            $css=\Elementor\Core\Files\CSS\Post::create(self::TARGET);
            $css->update();
            wp_cache_delete(self::TARGET,'post_meta');
            $new=self::canonical_css();
            if(!self::valid_css($new)){
                if(is_array($meta_before))update_post_meta(self::TARGET,'_elementor_css',$meta_before);
                return new WP_Error('rate_css_regenerated_invalid');
            }
            $state=['css'=>$new,'sha256'=>hash('sha256',$new),'source_post_id'=>self::TARGET,
                'source_data_sha256'=>hash('sha256',(string)get_post_meta(self::TARGET,'_elementor_data',true)),
                'refreshed_at'=>gmdate('c'),'contract'=>'mad4b.rate-css-resilience.v1'];
            update_option(self::OPTION,$state,false);
            $read=get_option(self::OPTION,[]);
            if(($read['sha256']??null)!==$state['sha256'])return new WP_Error('rate_css_shadow_readback_failed');
            return ['ok'=>true,'source_post_id'=>self::TARGET,'sha256'=>$state['sha256'],
                'source_changed'=>($before['sha256']??null)!==$state['sha256'],
                'css_bytes'=>strlen($new)];
        } finally { delete_transient('mad4b_rate_css_refresh_lock_v1'); }
    }
    public static function cli($args,$assoc){
        $action=$args[0]??'status';
        if($action==='status'){
            $s=self::shadow();$last=get_option(self::OPTION,[]);
            WP_CLI::line(wp_json_encode(['bound'=>self::bound(),'has_healthy_css'=>$s!==null,
                'source'=>$s['source']??null,'sha256'=>$s['sha256']??null,
                'shadow_persisted'=>!empty($last['sha256']),
                'source_post_id'=>self::TARGET,'scheduled'=>wp_next_scheduled(self::HOOK)],JSON_PRETTY_PRINT));return;
        }
        if($action!=='refresh'||!self::bound()||($assoc['confirm']??'')!=='REFRESH VERIFIED RATE CSS')
            WP_CLI::error('Only refresh --confirm="REFRESH VERIFIED RATE CSS" on bound Staging site');
        $r=self::refresh();if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT));
    }
    public static function register(){
        if(!self::bound())return;
        add_action('wp_head',[__CLASS__,'render'],96);
        add_action('elementor/document/after_save',static function($document){
            if(is_object($document)&&method_exists($document,'get_main_id'))self::saved($document->get_main_id());
        },20,1);
        add_action('updated_post_meta',[__CLASS__,'meta_changed'],20,4);
        add_action('added_post_meta',[__CLASS__,'meta_changed'],20,4);
        add_action(self::HOOK,[__CLASS__,'refresh']);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))WP_CLI::add_command('mad4b rate-css',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_Rate_CSS_Resilience_V1','register'],80);
