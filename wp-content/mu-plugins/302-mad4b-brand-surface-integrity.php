<?php
/**
 * Plugin Name: MAD4B Brand Surface Integrity (Staging)
 * Description: Tenant-bound foreign-brand checks for public Elementor templates; rejects known foreign contact widgets at render time.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Brand_Surface_Integrity_V1 {
    const POLICY='mad4b_brand_surface_policy_v1';
    const CONTRACT='mad4b.brand-surface-integrity.v1';
    private static function site() {
        if(wp_get_environment_type()!=='staging' || wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')
            return false;
        $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
        $policy=get_option(self::POLICY,[]);
        return is_array($profile)&&is_array($policy)
            && ($policy['site_uuid']??'')===($profile['site_uuid']??null)
            && ($profile['brand_name']??'')==='Wo Vacation'
            && ($policy['brand_name']??'')===$profile['brand_name']
            && ($policy['origin']??'')===rtrim(home_url(),'/');
    }
    private static function patterns() {
        $policy=get_option(self::POLICY,[]);
        $allowed=is_array($policy)?(array)($policy['blocked_source_tokens']??[]):[];
        return array_values(array_filter(array_map('strval',array_slice($allowed,0,30)),
            static fn($v)=>strlen($v)>5 && strlen($v)<130));
    }
    public static function matches($v) {
        if(!self::site())return [];
        $content=is_scalar($v)?(string)$v:wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($content)||$content==='')return [];
        $content=html_entity_decode($content,ENT_QUOTES,'UTF-8');
        $hits=[];
        foreach(self::patterns() as $pattern)if(stripos($content,$pattern)!==false)$hits[]=$pattern;
        return $hits;
    }
    public static function render_guard($should,$widget) {
        if(!$should||!self::site()||!is_object($widget)||!method_exists($widget,'get_settings_for_display'))
            return $should;
        // Only remove the contaminated widget; never purge a whole header or the page.
        $settings=$widget->get_settings_for_display();
        if(!is_array($settings))return $should;
        $watched=['icon_list','social_icon_list','text','editor','title','html','description','link'];
        foreach($watched as $key)if(isset($settings[$key])&&self::matches($settings[$key]))return false;
        return $should;
    }
    private static function header_ids() {
        return get_posts(['post_type'=>'elementor_library','post_status'=>'publish','numberposts'=>-1,
            'fields'=>'ids','suppress_filters'=>true,'meta_key'=>'_elementor_template_type','meta_value'=>'header']);
    }
    public static function scan() {
        if(!self::site())return ['contract'=>self::CONTRACT,'complete'=>false,'reason'=>'site_profile_policy_not_bound','issues'=>[]];
        $issues=[];$checked=0;$default=(string)apply_filters('wpml_default_language',null);
        foreach(self::header_ids() as $id){
            $checked++;
            $raw=get_post_meta($id,'_elementor_data',true);
            $hits=self::matches($raw);
            if(!$hits)continue;
            $info=apply_filters('wpml_post_language_details',null,$id);
            $lang=(string)(is_array($info)?($info['language_code']??''):'');
            if(!in_array($lang,['en','ar','fr','de','es','it'],true))continue;
            $source=apply_filters('wpml_object_id',$id,'elementor_library',false,$default);
            $source=(int)($source?:$id);
            if(!get_post($source)||get_post_status($source)!=='publish')continue;
            $code='loc_brand_'.$lang;
            $issues[]=['id'=>hash('sha256',get_current_blog_id().'|elementor_library|'.$source.'|'.$code),
                'post_type'=>'elementor_library','object_id'=>$source,'code'=>$code,
                'severity'=>'critical','source'=>'mad4b-elementor-localization',
                'label'=>'['.$lang.'] '.mb_substr(get_the_title($source),0,100).
                    ': rendered/eligible header contains known foreign-company contact or social identity'];
        }
        return ['contract'=>self::CONTRACT,'complete'=>true,'scanned_headers'=>$checked,
            'detected'=>count($issues),'issues'=>$issues,
            'brand_name'=>'Wo Vacation','source_tokens_redacted'=>true];
    }
    public static function external($rows,$cfg) {
        if(!is_array($rows))return $rows;
        $s=self::scan();
        if(empty($s['complete']))return $rows;
        $seen=array_fill_keys(array_column($rows,'id'),true);
        foreach($s['issues'] as $issue)if(!isset($seen[$issue['id']]))$rows[]=$issue;
        return $rows;
    }
    public static function schedule($mid,$id,$key,$value){
        if($key!=='_elementor_data'||!self::site())return;
        if(!in_array((int)$id,self::header_ids(),true))return;
        if(!wp_next_scheduled('mad4b_localization_scan_v1'))
            wp_schedule_single_event(time()+45,'mad4b_localization_scan_v1');
        // Scanner picks up content changes on the next bound host readiness cycle.
    }
    public static function cli($args,$assoc) {
        if($args||$assoc)WP_CLI::error('No arguments allowed');
        $r=self::scan();
        WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        if(!$r['complete'])WP_CLI::error('Site policy not bound to active company');
    }
    public static function ability() {
        if(!function_exists('wp_register_ability')||!self::site())return;
        if(function_exists('wp_has_ability')&&wp_has_ability('mad4b/brand-surface-check'))return;
        wp_register_ability('mad4b/brand-surface-check',[
            'label'=>'Brand Surface Check',
            'description'=>'Read-only report of confirmed tenant-bound foreign-brand Elementor headers.',
            'category'=>'mad4b-read','execute_callback'=>static fn($in)=>self::scan(),
            'permission_callback'=>static fn($in)=>current_user_can('edit_others_posts'),
            'input_schema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['mcp'=>['public'=>false,'type'=>'tool','surface'=>'read']]
        ]);
    }
    public static function boot(){
        if(!self::site())return;
        add_filter('elementor/frontend/widget/should_render',[__CLASS__,'render_guard'],10,2);
        add_filter('mad4b/content_readiness/external_issues',[__CLASS__,'external'],45,2);
        add_action('updated_post_meta',[__CLASS__,'schedule'],30,4);
        add_action('wp_abilities_api_init',[__CLASS__,'ability'],40);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))WP_CLI::add_command('mad4b brand scan',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_Brand_Surface_Integrity_V1','boot'],40);
