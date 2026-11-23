<?php
/**
 * Plugin Name: MAD4B Scoped Native Listing Layout Epoch Continuity
 * Version: 1.0.0
 * Description: Self-reenroll original browser layout proofs across unrelated CSS epochs only if every tracked style and Elementor dependency remains byte-identical; otherwise fail closed to SSR.
 */
defined('ABSPATH') || exit;

final class MAD4B_Native_Layout_Epoch_Continuity_V1 {
    const BASELINE='mad4b_secondary_grid_lazy_style_identity_v1';
    const RECEIPT='mad4b_secondary_grid_lazy_last_epoch_renewal_v1';
    const PROOF='mad4b_secondary_grid_lazy_layout_proofs_v1';
    const CONTRACT='mad4b.native-listing.scoped-css-identity-epoch-renewal.v1';
    public static function proof_digest(array $registry):string {
        unset($registry['css_epoch']);
        return hash('sha256',serialize($registry));
    }
    public static function matches(array $registry,array $baseline,?int $new_epoch=null):bool {
        if(($baseline['contract']??'')!==self::CONTRACT
            || ($registry['contract']??'')!=='mad4b.native-listing.layout-proof.v1'
            || ($baseline['site_host']??'')!==(string)wp_parse_url(home_url('/'),PHP_URL_HOST)
            || ($registry['site_host']??'')!==$baseline['site_host']
            || !isset($baseline['enrolled_css_epoch'],$baseline['registry_sha256'])
            || (int)$baseline['enrolled_css_epoch']<=0
            || (int)$baseline['enrolled_css_epoch']>(int)($registry['css_epoch']??0)
            || !hash_equals((string)$baseline['registry_sha256'],self::proof_digest($registry))
            || (int)($registry['mobile_breakpoint']??0)!==(int)($baseline['mobile_breakpoint']??-1))return false;
        $current=$new_epoch??(int)get_option('mad4b_css_ai_epoch_v1',0);
        if($current<(int)$baseline['enrolled_css_epoch'] || $current<(int)($registry['css_epoch']??0))return false;
        if((string)get_stylesheet()!==(string)($baseline['theme_stylesheet']??'')
            || (string)get_template()!==(string)($baseline['theme_template']??'')
            || (int)get_option('elementor_active_kit',0)!==(int)($baseline['kit_id']??0))return false;
        $settings=get_post_meta((int)$baseline['kit_id'],'_elementor_page_settings',true);
        if(!hash_equals((string)($baseline['kit_settings_sha256']??''),hash('sha256',maybe_serialize($settings))))return false;
        if(!hash_equals((string)($baseline['wp_custom_css_sha256']??''),hash('sha256',(string)wp_get_custom_css())))return false;
        if(!hash_equals((string)($baseline['theme_mods_sha256']??''),hash('sha256',maybe_serialize(get_theme_mods()))))return false;
        if(!hash_equals((string)($baseline['astra_options_sha256']??''),hash('sha256',maybe_serialize(get_option('astra-settings',[])))))return false;
        $docs=$baseline['elementor_docs']??null;
        if(!is_array($docs) || count($docs)<6 || count($docs)>32)return false;
        foreach($docs as $document=>$expected) {
            $id=absint($document);
            if(!$id || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D',$expected)
                || !hash_equals($expected,hash('sha256',(string)get_post_meta($id,'_elementor_data',true))))return false;
        }
        $files=$baseline['css_dependencies']??null;
        if(!is_array($files) || count($files)<14 || count($files)>55)return false;
        foreach($files as $relative=>$expected){
            if(!is_string($relative) || strlen($relative)>180 || str_contains($relative,'..')
                || !preg_match('~\A(?:litespeed/css/|uploads/elementor/css/|mu-plugins/(?:assets/)?|themes/)[a-zA-Z0-9_./-]+\.(?:css|php)\z~D',$relative)
                || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D',$expected))return false;
            $full=WP_CONTENT_DIR.'/'.$relative;
            if(is_link($full) || !is_file($full) || @filesize($full)<100
                || @filesize($full)>8388608 || !hash_equals($expected,(string)@hash_file('sha256',$full)))return false;
        }
        return true;
    }
    public static function on_front_page():void {
        if(defined('WP_CLI')&&WP_CLI)return;
        if(is_admin() || wp_doing_ajax() || is_user_logged_in() || !is_front_page())return;
        $original=get_option(self::PROOF,[]);
        $manifest=get_option(self::BASELINE,[]);
        if(!is_array($original) || !is_array($manifest) || empty($manifest['contract']))return;
        $active=(int)get_option('mad4b_css_ai_epoch_v1',0);
        if($active<=0 || $active===(int)($original['css_epoch']??0))return;
        if(!self::matches($original,$manifest,$active))return; // Changed styles require real browser QA.
        global $wpdb;
        $lock='mad4b_native_layout_epoch_renewal_v1';
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,1)',$lock))!==1)return;
        try {
            $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",self::PROOF));
            if(!is_string($raw) || maybe_unserialize($raw)!==$original)return;
            $activeNow=(int)get_option('mad4b_css_ai_epoch_v1',0);
            if(!self::matches($original,$manifest,$activeNow))return;
            $next=$original;
            $next['css_epoch']=$activeNow;
            $updated=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=%s",maybe_serialize($next),self::PROOF,$raw));
            if($updated!==1)return;
            wp_cache_delete(self::PROOF,'options');wp_cache_delete('alloptions','options');
            update_option(self::RECEIPT,[
                'contract'=>'mad4b.native-listing.scoped-css-epoch-continuity-receipt.v1',
                'site_host'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
                'old_epoch'=>(int)$original['css_epoch'],'new_epoch'=>$activeNow,
                'registry_semantic_sha256'=>self::proof_digest($original),
                'manifest_sha256'=>hash('sha256',serialize($manifest)),
                'verified_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
                'decision'=>'renewed_identical_css_and_elementor_sources',
            ],false);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function boot():void {
        add_action('wp',[__CLASS__,'on_front_page'],1);
    }
}
MAD4B_Native_Layout_Epoch_Continuity_V1::boot();
