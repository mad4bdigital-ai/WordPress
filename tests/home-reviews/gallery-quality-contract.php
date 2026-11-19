<?php
/**
 * Contract regression for the eight localized MAD4B review galleries.
 * Usage: wp eval-file tests/home-reviews/gallery-quality-contract.php
 */
defined('ABSPATH') || exit(1);
global $wpdb;
$languages = array_keys((array) apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
if (count($languages)<2 || count($languages)>10)throw new RuntimeException('LANGUAGE_SET_INVALID');
$templates=[];
foreach ($languages as $language) {
    $id=(int)apply_filters('wpml_object_id',27219,'elementor_library',false,$language);
    if ($id<1 || get_post_status($id)!=='publish')throw new RuntimeException('NO_TEMPLATE:'.$language);
    $raw=(string)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",
        $id,'_elementor_data'
    ));
    $nodes=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $widget=[];$parent=[];
    $scan=function ($items)use(&$scan,&$widget,&$parent):void {
        foreach ((array)$items as $item) {
            if (!is_array($item))continue;
            if (($item['id']??'')==='d3b8065')$widget[]=$item;
            if (($item['id']??'')==='b9c2361')$parent[]=$item;
            $scan($item['elements']??[]);
        }
    };
    $scan($nodes);
    if (count($widget)!==1 || count($parent)!==1 ||
        ($widget[0]['widgetType']??'')!=='uael-image-gallery') {
        throw new RuntimeException('DUPLICATE_OR_MISSING_COMPONENT:'.$language);
    }
    $s=$widget[0]['settings']??[];
    $p=$parent[0]['settings']??[];
    foreach (['image_size'=>'large','slides_to_show'=>3,
        'slides_to_show_tablet'=>2,'slides_to_show_mobile'=>1,
        'autoplay'=>'','gallery_rand'=>''] as $key=>$expected) {
        if (($s[$key]??null)!==$expected)throw new RuntimeException(
            'GALLERY_REGRESSION:'.$language.':'.$key
        );
    }
    if (($p['width']['size']??null)!==100
        || ($p['min_height']['size']??null)!==0) {
        throw new RuntimeException('PARENT_WIDTH_REGRESSION:'.$language);
    }
    if (!str_contains((string)($s['__dynamic__']['wp_gallery']??''),'review_gellery_slider')){
        throw new RuntimeException('JETENGINE_SOURCE_LOST:'.$language);
    }
    $templates[$language]=$id;
}
// Real original media quality cannot be invented by a thumbnail-size setting.
// Prevent new gallery uploads with insufficient dimensions from silently passing.
$look=get_option('look-and-feel',[]);
$links=(string)(is_array($look)?($look['review_gellery_slider']??''):'');
$ids=[];
if($links!=='') {
    foreach(array_filter(array_map('trim',explode(',',$links))) as $url) {
        $id=(int)attachment_url_to_postid($url);
        if($id<1 || !wp_attachment_is_image($id))throw new RuntimeException('UNRESOLVED_GALLERY_IMAGE');
        $m=wp_get_attachment_metadata($id);
        if (!is_array($m) || (int)($m['width']??0)<700
            || (int)($m['height']??0)<500
            || !is_file((string)get_attached_file($id))) {
            throw new RuntimeException('LOW_RES_OR_MISSING_MEDIA:'.$id);
        }
        $ids[]=$id;
    }
}
echo wp_json_encode([
    'status'=>'PASS','site'=>home_url(),
    'template_count'=>count($templates),'wpml_templates'=>$templates,
    'validated_gallery_images'=>count($ids),'min_width'=>700,'min_height'=>500,
    'responsive'=>[3,2,1],'media_size'=>'large',
    'autoplay_off'=>true,'all_media_resolved'=>true
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
