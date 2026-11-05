<?php
if(!defined('ABSPATH'))exit;

/** Explicit locale overrides win; only absent meta inherits canonical source. */
if (!function_exists('mad4b_shared_extra_locale_value_v1')) {
 function mad4b_shared_extra_locale_value_v1(int $id,string $key):string {
  if($id<1 || get_post_type($id)!=='tours-and-activities')return '';
  if(metadata_exists('post',$id,$key))return (string)get_post_meta($id,$key,true);
  $default=(string)apply_filters('wpml_default_language','');
  $source=$default!==''?(int)apply_filters('wpml_object_id',$id,'tours-and-activities',false,$default):0;
  if($source>0 && $source!==$id && get_post_type($source)==='tours-and-activities' && metadata_exists('post',$source,$key))return (string)get_post_meta($source,$key,true);
  return '';
 }
}
/** Generic selection of extra-options presentation; safety-scoped to isolated Lab. */
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || MAD4B_COMMERCE_RUNTIME_ENABLED!==true)) return;
require_once __DIR__.'/tour-content-layout-control.php';
require_once __DIR__.'/tour-rate-language-policy.php';
add_action('init',static function(){
 foreach([
  'Explore more experiences',
  'Discover optional activities and services available for this tour.',
  'Explore extras',
 ] as $text){
  do_action('wpml_register_single_string','MAD4B Extra Options',$text,$text);
 }
},65);
add_filter('body_class',static function($classes){
 $tour_post=function_exists('shared_presentation_primary_tour_post_v1') ? shared_presentation_primary_tour_post_v1() : null;
 if(!($tour_post instanceof WP_Post))return $classes;
 $tour=(int)$tour_post->ID;$mode=mad4b_shared_extra_locale_value_v1($tour,'extra_options_display_mode');
 $classes[]=$mode==='carousel'?'shared-extras-mode-carousel':'shared-extras-mode-listing';
 return $classes;
});
add_action('wp_head',static function(){
 echo '<style id="shared-extras-visibility">.shared-extra-feature-carousel{display:none!important}body.shared-extras-mode-carousel .shared-extra-feature-carousel{display:flex!important}body.shared-extras-mode-carousel .shared-extra-standard-listing{display:none!important}</style>';
},50);
add_shortcode('extra_options_field',static function($atts){
 $a=shortcode_atts(['field'=>'title'],$atts);
 $map=['title'=>'extra_options_feature_title','description'=>'extra_options_feature_description','link_label'=>'extra_options_feature_link_label'];
 if(!isset($map[$a['field']]))return '';
 $tour_post=function_exists('shared_presentation_primary_tour_post_v1') ? shared_presentation_primary_tour_post_v1() : null;
 if(!($tour_post instanceof WP_Post))return '';
 $id=(int)$tour_post->ID;
 $key=$map[$a['field']];
 $value=mad4b_shared_extra_locale_value_v1($id,$key);
 if(trim($value)===''){
  $defaults=[
   'en'=>['title'=>'Explore more experiences','description'=>'Discover optional activities and services available for this tour.','link_label'=>'Explore extras'],
   'fr'=>['title'=>'Découvrez d’autres expériences','description'=>'Découvrez les activités et services en option pour ce circuit.','link_label'=>'Découvrir les options'],
   'de'=>['title'=>'Weitere Erlebnisse entdecken','description'=>'Entdecken Sie optionale Aktivitäten und Services für diese Reise.','link_label'=>'Extras entdecken'],
   'es'=>['title'=>'Descubre más experiencias','description'=>'Descubre actividades y servicios opcionales disponibles para este tour.','link_label'=>'Explorar extras'],
   'it'=>['title'=>'Scopri altre esperienze','description'=>'Scopri attività e servizi opzionali disponibili per questo tour.','link_label'=>'Esplora gli extra'],
   'ar'=>['title'=>'اكتشف المزيد من التجارب','description'=>'اكتشف الأنشطة والخدمات الاختيارية المتاحة لهذه الرحلة.','link_label'=>'استكشف الإضافات'],
  ];
  $language=(string)apply_filters('wpml_current_language',null);
  $english=$defaults['en'][$a['field']];
  $translated=apply_filters('wpml_translate_single_string',$english,'MAD4B Extra Options',$english);
  $value=is_string($translated)&&trim($translated)!==''&&$translated!==$english
    ?$translated:($defaults[$language][$a['field']]??$english);
 }
 return $a['field']==='description'?wpautop(esc_html($value)):esc_html($value);
});
/** Elementor-native server-side rendering guard; also works without CSS/JavaScript. */
add_filter('elementor/frontend/container/should_render',static function($render,$element){
 if(!$render)return $render;
 $classes=preg_split('/\s+/',(string)$element->get_settings('css_classes'));
 $listing=in_array('shared-extra-standard-listing',$classes,true);
 $carousel=in_array('shared-extra-feature-carousel',$classes,true);
 if(!$listing&&!$carousel)return $render;
 $tour_post=function_exists('shared_presentation_primary_tour_post_v1') ? shared_presentation_primary_tour_post_v1() : null;
 if(!($tour_post instanceof WP_Post))return $render;
 $id=(int)$tour_post->ID;$mode=mad4b_shared_extra_locale_value_v1($id,'extra_options_display_mode');
 $selected=$mode==='carousel'?'carousel':'listing';
 if(($carousel&&$selected!=='carousel')||($listing&&$selected!=='listing'))return false;
 // Same availability principle as Tour Rates: the section is disabled without eligible extras.
 $enabled=mad4b_shared_extra_locale_value_v1($id,'tour_extras_status');
 if(!in_array((string)$enabled,['1','yes','true','on'],true))return false;
 return $render;
},40,2);
/** Scoped presentation for the cloned feature carousel only; original listing stays unchanged. */
add_action('wp_head',static function(){ ?>
<style id="shared-extra-feature-layout">
.shared-extra-feature-carousel {align-items:stretch;min-width:0}
.shared-extra-feature-carousel .jet-listing-grid,.shared-extra-feature-carousel .jet-listing-grid__items,.shared-extra-feature-carousel .jet-listing-grid__item{min-width:0}
.shared-extra-feature-carousel .jet-listing-grid__item > .elementor{width:100%}
.shared-extra-feature-carousel .jet-listing-grid__item .elementor-widget-wrap{min-width:0}
.shared-extra-feature-carousel .jet-listing-grid__item a,.shared-extra-feature-carousel .jet-listing-grid__item button{min-height:2.75rem}
.shared-extra-feature-carousel .jet-listing-grid__item img{max-width:100%;object-fit:cover}
.shared-extra-feature-carousel .jet-swiper-button-prev,.shared-extra-feature-carousel .jet-swiper-button-next{touch-action:manipulation}
@media(max-width:767px){.shared-extra-feature-carousel{flex-direction:column!important;width:100%;gap:1.25rem}.shared-extra-feature-carousel .jet-listing-grid{width:100%}.shared-extra-feature-carousel .jet-listing-grid__item{max-width:100%}}
</style>
<?php },55);
