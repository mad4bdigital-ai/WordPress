<?php
/**
 * Plugin Name: Company Surface Patterns for Elementor
 * Description: Brand-neutral opt-in decorative backgrounds governed by JetEngine Look & Feel.
 */
if (!defined('ABSPATH')) exit;
// The feature is intentionally limited to the development Lab until signed-off.
if (function_exists('wp_get_environment_type') && (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)) return;

function company_pattern_choices(){
 return [
  'off'=>'None — leave container unchanged',
  'company'=>'Inherit Look & Feel preset',
  'dots'=>'Subtle dots',
  'grid'=>'Fine grid',
  'arches'=>'Soft arches',
  'diagonal'=>'Diagonal hatching',
  'orbit'=>'Organic shapes',
  'custom'=>'Custom company artwork'
 ];
}
function company_pattern_preset($requested,$options){
 $valid=['dots','grid','arches','diagonal','orbit','custom'];
 $preset=$requested==='company'?($options['company_pattern_preset']??'none'):$requested;
 return in_array($preset,$valid,true)?$preset:'none';
}
function company_pattern_accent($options){
 $explicit=is_array($options)?($options['company_pattern_accent']??''):'';
 if(is_string($explicit)&&preg_match('/^#[0-9a-f]{6}$/i',$explicit))return $explicit;
 $kit=absint(get_option('elementor_active_kit',0));
 if($kit){
  $settings=get_post_meta($kit,'_elementor_page_settings',true);
  foreach((array)($settings['system_colors']??[]) as $row)
   if(($row['_id']??'')==='accent' && preg_match('/^#[0-9a-f]{6}$/i',(string)($row['color']??'')))
    return $row['color'];
 }
 return '#7A8794'; // Neutral safety fallback; never force a brand color.
}
function company_pattern_rgba($hex,$alpha){
 if(!is_string($hex)||!preg_match('/^#([0-9a-f]{6})$/i',$hex,$m))$hex='#7A8794';
 $hex=substr($hex,1);
 $rgb=[hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
 return 'rgba('.implode(',',$rgb).','.number_format($alpha,3,'.','').')';
}
function company_pattern_artwork($value){
 $id=0;
 if(is_numeric($value))$id=absint($value);
 elseif(is_array($value))$id=absint($value['id']??$value['attachment_id']??0);
 if(!$id||get_post_type($id)!=='attachment')return '';
 $mime=get_post_mime_type($id);
 if(!in_array($mime,['image/png','image/jpeg','image/webp'],true))return '';
 $file=get_attached_file($id);
 if(!$file||!is_file($file))return '';
 $url=wp_get_attachment_url($id);
 return is_string($url)?esc_url_raw($url):'';
}
function company_pattern_eligible_container($settings){
 if(!is_array($settings))return false;
 if(($settings['background_background']??'')==='gradient')return false;
 foreach(['background_image','background_overlay_image','background_video_fallback',
  'background_overlay_video_fallback'] as $name){
  $value=$settings[$name]??null;
  if(is_array($value)&&(!empty($value['url'])||!empty($value['id'])))return false;
  if(is_string($value)&&trim($value)!=='')return false;
  if(!empty($settings['__dynamic__'][$name]))return false;
 }
 if(!empty($settings['background_video_link'])||!empty($settings['background_video_link_external']))return false;
 if(is_string($settings['custom_css']??null)&&
  preg_match('/background(-image)?\s*:/i',$settings['custom_css']))return false;
 return true;
}
/**
 * Prevent a decorative Container from accidentally becoming a background
 * wrapper around booking forms, filters, products or pricing controls.
 * This checks native element children; cross-template composition requires
 * separate acceptance when an author enables the pattern.
 */
function company_pattern_has_protected_children($element,$depth=0){
 if($depth>=14||!is_object($element)||!method_exists($element,'get_children'))return false;
 $protected=['form','shortcode','jet-listing-grid','jet-ajax-search',
  'jet-smart-filters-search','jet-smart-filters-select',
  'jet-smart-filters-sorting','jet-smart-filters-checkboxes',
  'jet-smart-filters-radio','jet-smart-filters-range','jet-smart-filters-apply-button',
  'woocommerce-cart','woocommerce-checkout-page','woocommerce-products',
  'wc-add-to-cart','wc-add-to-cart-button'];
 foreach((array)$element->get_children() as $child){
  if(!is_object($child))continue;
  $name=method_exists($child,'get_name')?(string)$child->get_name():'';
  if(in_array($name,$protected,true))return true;
  if(company_pattern_has_protected_children($child,$depth+1))return true;
 }
 return false;
}

add_action('elementor/element/container/section_background/after_section_end',static function($element){
 if(!method_exists($element,'start_controls_section')||!method_exists($element,'add_control'))return;
 $element->start_controls_section('company_pattern_background_section',[
  'label'=>'Company Shapes & Patterns','tab'=>\Elementor\Controls_Manager::TAB_STYLE
 ]);
 $element->add_control('company_pattern_zone',[
  'label'=>'Decorative zone',
  'type'=>\Elementor\Controls_Manager::SELECT,'default'=>'off',
  'options'=>[
   'off'=>'Disabled — default',
   'intro'=>'Section introduction',
   'transition'=>'Content transition',
   'cta'=>'Closing CTA band'
  ],
  'description'=>'Only opt in on spacious outer containers. Never use inside Hero, galleries, cards, booking forms or filter inputs.'
 ]);
 $element->add_control('company_pattern_style',[
  'label'=>'Pattern from brand library',
  'type'=>\Elementor\Controls_Manager::SELECT,
  'options'=>company_pattern_choices(),'default'=>'company',
  'condition'=>['company_pattern_zone!'=>'off']
 ]);
 $element->add_control('company_pattern_strength_override',[
  'label'=>'Contrast override',
  'type'=>\Elementor\Controls_Manager::SELECT,
  'options'=>['company'=>'Inherit company','light'=>'Very light',
   'subtle'=>'Subtle','balanced'=>'Balanced'],
  'default'=>'company','condition'=>['company_pattern_zone!'=>'off']
 ]);
 $element->add_control('company_pattern_notice',[
  'type'=>\Elementor\Controls_Manager::RAW_HTML,
  'raw'=>'<p>Uses JetEngine → Look & Feel → Brand Patterns & Shapes. Original Elementor colors, spacing and layout are retained. Background images and overlays are protected.</p>',
  'content_classes'=>'elementor-panel-alert elementor-panel-alert-info'
 ]);
 $element->end_controls_section();
},45);
add_action('elementor/frontend/container/before_render',static function($element){
 if(!method_exists($element,'get_settings_for_display')||!method_exists($element,'add_render_attribute'))return;
 $zone=(string)$element->get_settings_for_display('company_pattern_zone');
 if(!in_array($zone,['intro','transition','cta'],true))return;
 $settings=$element->get_settings_for_display();
 if(!company_pattern_eligible_container($settings) ||
    company_pattern_has_protected_children($element))return;
 $options=get_option('look-and-feel',[]);
 if(!is_array($options))$options=[];
 $options=apply_filters('company_pattern_options_for_context',$options,$element);
 if(!is_array($options))return;
 $preset=company_pattern_preset((string)$element->get_settings_for_display('company_pattern_style'),$options);
 if($preset==='none')return;
 $strength=(string)$element->get_settings_for_display('company_pattern_strength_override');
 if($strength===''||$strength==='company')$strength=$options['company_pattern_strength']??'subtle';
 if(!in_array($strength,['light','subtle','balanced'],true))$strength='subtle';
 $opacity=['light'=>0.045,'subtle'=>0.075,'balanced'=>0.12][$strength];
 $accent=company_pattern_accent($options);
 $media='';
 if($preset==='custom'){
  $media=company_pattern_artwork($options['company_pattern_media']??null);
  if(!$media)return;
 }
 $mobile=(string)($options['company_pattern_mobile']??'reduce');
 if(!in_array($mobile,['reduce','hide','same'],true))$mobile='reduce';
 $element->add_render_attribute('_wrapper','class','company-pattern-surface');
 $element->add_render_attribute('_wrapper','data-company-pattern',$preset);
 $element->add_render_attribute('_wrapper','data-company-pattern-zone',$zone);
 $element->add_render_attribute('_wrapper','data-company-pattern-mobile',$mobile);
 $element->add_render_attribute('_wrapper','style',
  '--company-pattern-ink:'.company_pattern_rgba($accent,$opacity).';'.
  '--company-pattern-mobile-ink:'.company_pattern_rgba($accent,min(0.05,$opacity/2)).';'
 );
 if($media){
  $element->add_render_attribute('_wrapper','style','--company-pattern-artwork:url("'.esc_url($media).'");');
 }
},80);
function company_pattern_enqueue_style(){
 $path=WP_CONTENT_DIR.'/mu-plugins/company-patterns/patterns.css';
 if(is_file($path))wp_enqueue_style('company-container-patterns',
  content_url('mu-plugins/company-patterns/patterns.css'),[],(string)filemtime($path));
}
add_action('wp_enqueue_scripts','company_pattern_enqueue_style',45);
add_action('elementor/preview/enqueue_styles','company_pattern_enqueue_style');
add_action('elementor/editor/after_enqueue_styles','company_pattern_enqueue_style');
