<?php
/**
 * Plugin Name: MAD4B JetEngine Native Options Dynamic Tags
 * Description: Elementor Dynamic Tags backed by registered JetEngine Options Pages and WPML Brand Copy records.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_JetEngine_Brand_Dynamic_Tag_Bridge {
    public static function ready(): bool {
        return function_exists('jet_engine') && isset(jet_engine()->options_pages)
            && isset(jet_engine()->listings->data);
    }
    public static function choices(string $context='plain'): array {
        if(!self::ready())return [];
        $groups=jet_engine()->options_pages->get_options_for_select($context);
        return is_array($groups)?$groups:[];
    }
    public static function resolve(string $ref,string $context='plain') {
        if(!self::ready()||!preg_match('/^([a-z0-9_-]{2,80})::([a-z0-9_-]{2,100})$/D',$ref,$m))
            return $context==='media'?[]:'';
        $slug=$m[1];$field=$m[2];
        $page=jet_engine()->options_pages->registered_pages[$slug]??null;
        if(!$page)return $context==='media'?[]:'';
        // Require an actually registered field, not an invented raw option key.
        $meta_box=(array)($page->meta_box??[]);
        $known=false;
        foreach($meta_box as $item) {
            if(is_array($item)&&($item['name']??'')===$field&&
                ($item['object_type']??'field')==='field'){$known=true;break;}
        }
        if(!$known)return $context==='media'?[]:'';
        $raw=$page->get($field,'');
        if($slug==='look-and-feel' && str_starts_with($field,'mad4b_') &&
            class_exists('MAD4B_Brand_Experience_Core')) {
            $brand_field=substr($field,6);
            if(isset(MAD4B_Brand_Experience_Core::strings()[$brand_field])) {
                $language=(string)apply_filters('wpml_current_language','en');
                if(!preg_match('/^[a-z]{2}$/D',$language))$language='en';
                $translated=MAD4B_Brand_Experience_Core::translate($brand_field,$language);
                if($language!=='en')return $translated;
                // Before the first JetEngine save, the EN field may exist without
                // its post-backed source. Never fake a translated non-English string.
                return $translated!==''?$translated:(is_scalar($raw)?(string)$raw:'');
            }
        }
        if($context==='media') {
            $id=is_numeric($raw)?(int)$raw:0;
            if($id<1||get_post_type($id)!=='attachment')return [];
            $url=wp_get_attachment_image_url($id,'full');
            return $url?['id'=>$id,'url'=>$url]:[];
        }
        if(!is_scalar($raw))return '';
        return (string)$raw;
    }
    public static function register($manager): void {
        if(!self::ready()||
            !class_exists('Elementor\Core\DynamicTags\Tag')||
            !class_exists('Elementor\Modules\DynamicTags\Module'))return;
        require_once WPMU_PLUGIN_DIR . '/brand-options-tags/elementor-tags.php';
        if(!class_exists('MAD4B_JetEngine_Option_Text_Tag'))return;
        $manager->register_group('mad4b-jetengine-options',[
            'title'=>'MAD4B · JetEngine Options Pages'
        ]);
        $manager->register(new MAD4B_JetEngine_Option_Text_Tag());
        $manager->register(new MAD4B_JetEngine_Option_Image_Tag());
    }
}
add_action('elementor/dynamic_tags/register',
    ['MAD4B_JetEngine_Brand_Dynamic_Tag_Bridge','register'],30);
