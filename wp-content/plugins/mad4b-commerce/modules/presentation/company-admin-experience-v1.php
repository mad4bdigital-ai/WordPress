<?php
/**
 * MAD4B Company Admin Experience module.
 * Owns one shared WordPress admin CSS, native notice/card scripts and
 * a user-local light/dark control. Source-owned and tenant-safe.
 * No WordPress auth, password, post, order, payment or roles mutations.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/company-admin-ui-auto-scan-v1.php';
final class MAD4B_Admin_Experience_V1 {
    public const MODULE_CONTRACT='mad4b.admin-experience.v1';
    public const FALLBACK_COLORS=[
        'primary'=>'#102D40','secondary'=>'#204C50','text'=>'#102D40',
        'accent'=>'#B48A46','warm'=>'#F9F7F5','light'=>'#FFFFFF'];
    private static function luminance(string $hex):float {
        if(!preg_match('/^#[a-f0-9]{6}$/iD',$hex))return 0;
        $rgb=[];
        for($i=1;$i<7;$i+=2){
            $c=hexdec(substr($hex,$i,2))/255;
            $rgb[]=$c<=0.04045?($c/12.92):pow(($c+0.055)/1.055,2.4);
        }
        return 0.2126*$rgb[0]+0.7152*$rgb[1]+0.0722*$rgb[2];
    }
    public static function on_color(string $background):string {
        $l=self::luminance($background);
        $light=(1.05)/($l+0.05);
        $dark=($l+0.05)/(.05 + self::luminance('#0D1B26'));
        return $light >= $dark ? '#FFFFFF' : '#0D1B26';
    }
    public static function contrast_css():string {
        $palette=self::site_palette();
        $source=self::palette_source();
        $css='body.wp-admin{';
        foreach(['primary','secondary','accent','warm','light'] as $name){
            $value=self::on_color($palette[$name]);
            $css.='--mad4b-on-'.$name.':'.$value.';';
        }
        return $css.'--mad4b-brand-source:'.($source==='kit'?'kit':($source==='partial'?'partial':'fallback')).';}';
    }
    public static function palette_source():string {
        $id=(int)get_option('elementor_active_kit',0);
        if($id<=0)return 'fallback';
        $settings=get_post_meta($id,'_elementor_page_settings',true);
        if(!is_array($settings))return 'fallback';
        $found=0;
        foreach((array)($settings['system_colors']??[]) as $entry){
            if(!is_array($entry))continue;
            if(isset(self::FALLBACK_COLORS[(string)($entry['_id']??'')]) &&
                preg_match('/^#[a-f0-9]{6}$/iD',(string)($entry['color']??'')))$found++;
        }
        return $found>=4?'kit':($found>0?'partial':'fallback');
    }
    /** Company brand kit, with safe complete fallback when a kit is missing. */
    public static function site_palette():array {
        $fallback=self::FALLBACK_COLORS;
        $kit=(int)get_option('elementor_active_kit',0);
        $meta=$kit?get_post_meta($kit,'_elementor_page_settings',true):[];
        if(!is_array($meta))return $fallback;
        foreach((array)($meta['system_colors']??[]) as $item){
            if(!is_array($item))continue;
            $key=(string)($item['_id']??'');
            $color=(string)($item['color']??'');
            if(isset($fallback[$key]) && preg_match('/^#[a-f0-9]{6}$/iD',$color))$fallback[$key]=strtoupper($color);
        }
        foreach((array)($meta['custom_colors']??[]) as $item){
            if(!is_array($item))continue;
            $label=strtolower(trim((string)($item['title']??'')));
            $key=match($label){'warm bg','warm background'=>'warm','white'=>'light',default=>''};
            $color=(string)($item['color']??'');
            if($key!=='' && preg_match('/^#[a-f0-9]{6}$/iD',$color))$fallback[$key]=strtoupper($color);
        }
        return $fallback;
    }
    public static function enqueue():void {
        $file=MAD4B_Company_System_Appearance_V1::style_file();if(!is_readable($file))return;
        // Preserve WordPress core styles (Quick Edit, Screen Options, accessibility).
        // One central MAD4B stylesheet is printed in HEAD, after core common.
        $deps=array_values(array_filter(['common','forms','list-tables'],static fn($handle)=>wp_style_is($handle,'registered')));
        wp_enqueue_style('mad4b-company-admin-appearance',plugins_url('assets/company-system-appearance-v1.css',__FILE__),$deps,(string)filemtime($file));
        wp_add_inline_style('mad4b-company-admin-appearance',MAD4B_Company_System_Appearance_V1::palette_style().self::contrast_css());
        $js=__DIR__.'/assets/company-admin-notice-accordion-v1.js';
        if(is_readable($js)){
            // Early head boot hides only native notice candidates until the
            // original nodes are grouped. Releases automatically on failure.
            wp_enqueue_script('mad4b-company-admin-notice-accordion',
                plugins_url('assets/company-admin-notice-accordion-v1.js',__FILE__),[],
                (string)filemtime($js),false);
            $noticeBoot='document.documentElement.classList.add("mad4b-admin-notice-boot");'
                .'var mad4bReleaseNotice=function(){setTimeout(function(){'
                .'document.documentElement.classList.remove("mad4b-admin-notice-boot");'
                .'},1800)};'
                .'if(document.readyState==="loading")'
                .'document.addEventListener("DOMContentLoaded",mad4bReleaseNotice,{once:true});'
                .'else mad4bReleaseNotice();';
            wp_add_inline_script('mad4b-company-admin-notice-accordion',$noticeBoot,'before');
            wp_add_inline_script('mad4b-company-admin-notice-accordion',
                'window.MAD4BCompanyAdminNoticeLabels='.wp_json_encode(MAD4B_Company_System_Appearance_V1::admin_labels()['notices'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP).';','before');
        }
        $themeToggle=__DIR__.'/assets/company-admin-theme-toggle-v1.js';
        if(is_readable($themeToggle)){
            // Read the browser's own preference in HEAD, before any admin UI
            // renders, to avoid a flash of the alternate color scheme.
            wp_enqueue_script('mad4b-company-admin-theme-toggle',
                plugins_url('assets/company-admin-theme-toggle-v1.js',__FILE__),[],
                (string)filemtime($themeToggle),false);
            $themeBoot='(function(){try{if(window.localStorage.getItem("mad4b.admin.theme.v1")==="dark")'
                .'document.documentElement.setAttribute("data-mad4b-admin-theme","dark");'
                .'}catch(_){}})();';
            wp_add_inline_script('mad4b-company-admin-theme-toggle',$themeBoot,'before');
            $language=substr(strtolower((string)get_user_locale()),0,2);
            $names=[
                'en'=>['dark'=>'Enable dark mode','light'=>'Enable light mode'],
                'ar'=>['dark'=>'تفعيل الوضع الليلي','light'=>'تفعيل الوضع النهاري'],
                'es'=>['dark'=>'Activar modo oscuro','light'=>'Activar modo claro'],
                'fr'=>['dark'=>'Activer le mode sombre','light'=>'Activer le mode clair'],
                'de'=>['dark'=>'Dunkelmodus aktivieren','light'=>'Hellmodus aktivieren'],
                'it'=>['dark'=>'Attiva modalità scura','light'=>'Attiva modalità chiara']];
            wp_add_inline_script('mad4b-company-admin-theme-toggle',
                'window.MAD4BCompanyAdminThemeLabels='.wp_json_encode(
                    $names[$language]??$names['en'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP).';',
                'before');
        }
        // Contrast corrections for any installed plugin with hardcoded text
        // colors run only in dark mode, and never touch the editor canvas.
        $contrast=__DIR__.'/assets/company-admin-contrast-auditor-v1.js';
        if(is_readable($contrast)){
            wp_enqueue_script('mad4b-company-admin-contrast-auditor',
                plugins_url('assets/company-admin-contrast-auditor-v1.js',__FILE__),
                ['mad4b-company-admin-theme-toggle'],(string)filemtime($contrast),false);
        }
        // Auto-scan only the admin's visited screens. Persist aggregate issue
        // metadata (no DOM text or full URLs) through the original WP AJAX.
        $autoScan=__DIR__.'/assets/company-admin-ui-auto-scan-v1.js';
        if(current_user_can('manage_options') && is_readable($autoScan)){
            wp_enqueue_script('mad4b-company-admin-ui-auto-scan',
                plugins_url('assets/company-admin-ui-auto-scan-v1.js',__FILE__),
                ['mad4b-company-admin-contrast-auditor'],
                (string)filemtime($autoScan),false);
            $config=['enabled'=>true,'ajaxUrl'=>admin_url('admin-ajax.php'),
                'nonce'=>wp_create_nonce('mad4b_admin_ui_auto_scan_v1')];
            wp_add_inline_script('mad4b-company-admin-ui-auto-scan',
                'window.MAD4BAdminUIAutoScanConfig='.wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP).';',
                'before');
        }
        $tables=__DIR__.'/assets/company-admin-tables-v1.js';
        if(is_readable($tables)){
            // Synchronous head loader installs a MutationObserver before the
            // first native WP_List_Table enters the DOM (avoids layout flash).
            wp_enqueue_script('mad4b-company-admin-tables',
                plugins_url('assets/company-admin-tables-v1.js',__FILE__),[],
                (string)filemtime($tables),false);
            // A fail-open presentation guard only: no WordPress core CSS is
            // dequeued, and a timeout always releases content if JS fails.
            $boot='if(window.matchMedia&&matchMedia("(max-width:782px)").matches){'
                .'document.documentElement.classList.add("mad4b-admin-ui-boot");'
                .'var mad4bRelease=function(){setTimeout(function(){'
                .'document.documentElement.classList.remove("mad4b-admin-ui-boot");'
                .'},1600)};'
                .'if(document.readyState==="loading")'
                .'document.addEventListener("DOMContentLoaded",mad4bRelease,{once:true});'
                .'else mad4bRelease();'
                .'}';
            wp_add_inline_script('mad4b-company-admin-tables',$boot,'before');
            wp_add_inline_script('mad4b-company-admin-tables',
                'window.MAD4BCompanyAdminTableLabels='.wp_json_encode(MAD4B_Company_System_Appearance_V1::admin_labels()['table'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP).';','before');
        }
    }
}
