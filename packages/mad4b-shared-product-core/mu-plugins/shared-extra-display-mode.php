<?php
/** Generic selection of extra-options presentation; safety-scoped to isolated Lab. */
if (!defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_filter('body_class',static function($classes){
 $tour_post=function_exists('shared_presentation_primary_tour_post_v1') ? shared_presentation_primary_tour_post_v1() : null;
 if(!($tour_post instanceof WP_Post))return $classes;
 $tour=(int)$tour_post->ID;$mode=get_post_meta($tour,'extra_options_display_mode',true);
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
 $id=(int)$tour_post->ID;$value=get_post_meta($id,$map[$a['field']],true);
 if(!is_string($value)||trim($value)==='')$value=['title'=>'Explore more experiences','description'=>'Discover optional activities and services available for this tour.','link_label'=>'Explore extras'][$a['field']];
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
 $id=(int)$tour_post->ID;$mode=get_post_meta($id,'extra_options_display_mode',true);
 $selected=$mode==='carousel'?'carousel':'listing';
 if(($carousel&&$selected!=='carousel')||($listing&&$selected!=='listing'))return false;
 // Same availability principle as Tour Rates: the section is disabled without eligible extras.
 $enabled=get_post_meta($id,'tour_extras_status',true);
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
