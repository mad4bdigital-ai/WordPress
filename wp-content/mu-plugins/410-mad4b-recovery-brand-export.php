<?php
/**
 * Plugin Name: MAD4B — Public Brand Recovery Snapshot Exporter
 * Description: Read-only public brand metadata for Coolify's offline error pages.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Public_Recovery_Brand_Snapshot',false)) {
final class MAD4B_Public_Recovery_Brand_Snapshot {
    private static function color($raw, $fallback):string {
        $raw=is_string($raw)?trim($raw):'';
        return preg_match('/^#[a-f0-9]{6}$/i',$raw)?strtoupper($raw):$fallback;
    }
    public static function snapshot():array {
        $name=wp_strip_all_tags((string)get_option('blogname',''));
        if(!$name)$name='Travel';
        $tagline=wp_strip_all_tags((string)get_option('blogdescription',''));
        $site=esc_url_raw(home_url('/'));
        $logo=(int)get_theme_mod('custom_logo',0);
        $icon=(int)get_option('site_icon',0);
        $preferred=$logo?:$icon;
        $logo_url=$preferred?wp_get_attachment_image_url($preferred,'medium'):false;
        $logo_path=$preferred?get_attached_file($preferred):'';
        $logo_medium_path='';
        if($logo_url) {
            $up=wp_upload_dir();
            $baseurl=rtrim((string)($up['baseurl']??''),'/').'/';
            if(str_starts_with((string)$logo_url,$baseurl)) {
                $rel=substr((string)$logo_url,strlen($baseurl));
                if(!str_contains($rel,'..')&&!str_contains($rel,'\\')) {
                    $candidate=trailingslashit((string)($up['basedir']??'')).$rel;
                    if(is_file($candidate))$logo_medium_path=$candidate;
                }
            }
        }
        $logo_path=$logo_medium_path?:($logo_path&&is_file($logo_path)?$logo_path:'');
        $kit=(int)get_option('elementor_active_kit',0);
        $kit_data=$kit?get_post_meta($kit,'_elementor_page_settings',true):[];
        $colors=[];
        foreach((array)($kit_data['system_colors']??[]) as $row) {
            if(!is_array($row))continue;
            $k=strtolower((string)($row['_id']??$row['title']??$row['id']??''));
            if($k)$colors[$k]=self::color($row['color']??'','#102D40');
        }
        $colors=[
            'primary'=>$colors['primary']??'#102D40',
            'secondary'=>$colors['secondary']??'#204C50',
            'accent'=>$colors['accent']??'#B48A46',
            'text'=>$colors['text']??'#102D40'
        ];
        $langs=['en'];
        if (has_filter('wpml_active_languages')) {
            $active=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
            if(is_array($active) && $active) {
                $langs=array_values(array_filter(array_keys($active),static fn($x)=>is_string($x)&&preg_match('/^[a-z]{2}$/',$x)));
            }
        }
        if(!$langs)$langs=['en'];
        $content=[];
        $custom=get_option('mad4b_recovery_copy_v1',[]);
        foreach($langs as $lang) {
            $slug=$lang==='es'?'servicio-temporalmente-no-disponible':'site-temporarily-unavailable';
            if($lang!=='en' && $lang!=='es')$slug='site-temporarily-unavailable-'.$lang;
            $post=get_page_by_path($slug,OBJECT,'page');
            if($post && get_post_status($post)==='publish' && get_post_meta($post->ID,'mad4b_recovery_notice_owner',true)==='coolify-public-recovery-v1') {
                $body=(string)$post->post_content;
                if(preg_match('~<h1\b[^>]*>(.*?)</h1>~is',$body,$m)) {
                    $content[$lang]['heading']=trim(wp_strip_all_tags(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8')));
                }
                if(preg_match('~<p\b[^>]*style=["\'][^"\']*font-size:\s*18px[^"\']*["\'][^>]*>(.*?)</p>~is',$body,$m)) {
                    $content[$lang]['description']=trim(wp_strip_all_tags(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8')));
                }
            }
            foreach(['heading','description','eyebrow','button'] as $key) {
                if(isset($custom[$lang][$key]) && is_string($custom[$lang][$key])) {
                    $content[$lang][$key]=mb_substr(wp_strip_all_tags($custom[$lang][$key]),0,300);
                }
            }
        }
        return [
            'name'=>mb_substr($name,0,100),
            'description'=>mb_substr($tagline,0,180),
            'home'=>$site,
            'colors'=>$colors,
            'languages'=>array_values(array_unique($langs)),
            'logo_path'=>$logo_path,
            'content'=>$content,
            'site_updated'=>gmdate('c')
        ];
    }
}
}
