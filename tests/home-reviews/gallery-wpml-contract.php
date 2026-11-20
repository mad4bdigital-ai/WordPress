<?php
/** Regression: home reviews eligibility and the WPML gallery widget contract. */
defined('ABSPATH') || exit(1);
if (!class_exists('MAD4B_Home_Reviews_Native_Visibility_V1', false))
    throw new RuntimeException('REVIEW_VISIBILITY_ENGINE_NOT_LOADED');
$engine=MAD4B_Home_Reviews_Native_Visibility_V1::class;
$isWayfa=(string)wp_parse_url(home_url('/'),PHP_URL_HOST)==='wayfatours.com';
$gallery=(string)(get_option('look-and-feel',[])['review_gellery_slider']??'');
$reflection=new ReflectionMethod($engine,'has_image_gallery');
$eligible=$engine::has_eligible_content();
if ($eligible!==$isWayfa)throw new RuntimeException('WRONG_ELIGIBILITY_FOR_COMPANY');
if ($reflection->invoke(null,$gallery)!==$isWayfa
  ||$reflection->invoke(null,'')
  ||$reflection->invoke(null,'https://example.com/not-owned.webp')
  ||$reflection->invoke(null,'999999999'))throw new RuntimeException('GALLERY_SOURCE_VALIDATION_REGRESSION');

$langs=array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
$coverage=[];
foreach ($langs as $lang) {
    $id=(int)apply_filters('wpml_object_id',27219,'elementor_library',false,$lang);
    if ($id<1)throw new RuntimeException('MISSING_REVIEW_TRANSLATION_'.$lang);
    $tree=json_decode((string)get_post_meta($id,'_elementor_data',true),true,512,JSON_THROW_ON_ERROR);
    $widgets=0;
    $check=function($nodes)use(&$check,&$widgets):void {
        foreach((array)$nodes as $node){
            if(!is_array($node))continue;
            if(($node['widgetType']??'')==='uael-image-gallery')$widgets++;
            $check($node['elements']??[]);
        }
    };
    $check($tree);
    if ($widgets!==1)throw new RuntimeException('MISSING_OR_DUPLICATE_WPML_GALLERY_'.$lang);
    $coverage[$lang]=['id'=>$id,'galleries'=>$widgets];
}
echo wp_json_encode(['status'=>'PASS','site'=>home_url(),'reviews_eligible'=>$eligible,
    'gallery_url_count'=>strlen($gallery)?count(preg_split('/\s*,\s*/',$gallery)):0,
    'wpml'=>$coverage],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
