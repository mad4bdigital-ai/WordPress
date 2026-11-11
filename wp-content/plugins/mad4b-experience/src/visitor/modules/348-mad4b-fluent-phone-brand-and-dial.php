<?php
/**
 * Plugin Name: MAD4B Fluent Forms Phone Brand and Dial
 * Description: Shared Elementor Kit styling for intl-tel-input and guarded international-prefix detection across Fluent Forms.
 * Source module: live WordPress implementation, generalized by site profile.
 */
defined('ABSPATH') || exit;
final class MAD4B_Fluent_Phone_Brand_And_Dial {
    public static function scope():bool { return MAD4B_Visitor_Experience::enabled('fluent-phone-brand-and-dial'); }
    public static function assets():void {
        if (!self::scope()||is_admin()||is_preview())return;
        // No Fluent form ID and no plugin source edits: selectors activate
        // only where the existing telephone library/form widgets are present.
        $base=MAD4B_Visitor_Experience::asset_path('');
        foreach (['css','js'] as $ext) {
            $name='mad4b-fluent-phone-brand-and-dial.'.$ext;
            $file=$base.$name;
            if (!is_readable($file))continue;
            $url=MAD4B_Visitor_Experience::asset_url($name);
            if ($ext==='css') {
                wp_enqueue_style('mad4b-fluent-phone-brand-and-dial',$url,[],(string)filemtime($file));
            } else {
                wp_enqueue_script('mad4b-fluent-phone-brand-and-dial',$url,[],(string)filemtime($file),['in_footer'=>true]);
            }
        }
    }
    public static function independent_script(string $tag,string $handle,string $src):string {
        if ($handle!=='mad4b-fluent-phone-brand-and-dial'||!self::scope())return $tag;
        return str_replace('id="mad4b-fluent-phone-brand-and-dial-js"',
            'data-no-optimize="1" data-no-defer="1" data-cfasync="false" id="mad4b-fluent-phone-brand-and-dial-js"',$tag);
    }
    public static function excluded($list) {
        if (!self::scope()||!is_array($list))return $list;
        $list[]='mad4b-fluent-phone-brand-and-dial.js';
        return array_values(array_unique($list));
    }
    public static function boot():void {
        if (!self::scope())return;
        add_action('wp_enqueue_scripts',[__CLASS__,'assets'],125);
        add_filter('script_loader_tag',[__CLASS__,'independent_script'],565,3);
        add_filter('litespeed_optimize_js_excludes',[__CLASS__,'excluded'],120);
    }
}
add_action('init',['MAD4B_Fluent_Phone_Brand_And_Dial','boot'],25);
