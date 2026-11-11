<?php
/**
 * Plugin Name: Company Presentation Text — Elementor Dynamic Tags
 * Description: Brand-neutral JetEngine Options Page text, WPML aware.
 */
if(!defined('ABSPATH'))exit;

function company_presentation_text_fields(){
 return [
  'destination_next_trip_heading'=>'Next Trip — Section Heading',
  'destination_next_trip_subheading'=>'Next Trip — Introduction',
  'destination_stays_heading'=>'Destination — Accommodation Heading',
  'destination_transport_heading'=>'Destination — Transport Heading',
  'destination_road_heading'=>'Destination — Road Transfers Heading',
  'destination_rail_heading'=>'Destination — Rail Transfers Heading',
  'destination_air_heading'=>'Destination — Air Transfers Heading',
  'destination_tours_heading'=>'Destination — Tours Heading',
  'destination_quote_cta_label'=>'Destination — Quote CTA',
  'destination_services_note'=>'Destination — Section Notice',
  'destination_no_results_label'=>'Destination — Empty Results Label'
 ];
}
function company_presentation_text($field,$fallback='',$language=''){
 if(!is_string($field)||!array_key_exists($field,company_presentation_text_fields()))
  return is_string($fallback)?$fallback:'';
 $options=get_option('look-and-feel',[]);
 $source=is_array($options)?($options[$field]??''):'';
 if(!is_string($source)||trim($source)==='')return (string)$fallback;
 $lang=$language!==''?$language:(string)apply_filters('wpml_current_language','en');
 $translated=apply_filters('wpml_translate_single_string',$source,'company-presentation',$field,$lang);
 $translated=is_string($translated)&&trim($translated)!==''?$translated:$source;
 // Default (untranslated) English seed never overrides an existing
 // translated Elementor template until company-specific copy is approved.
 $defaults=[
  'destination_next_trip_heading'=>'Get your self ready for the Next Trip:',
  'destination_next_trip_subheading'=>'Discover experiences selected for your destination.'
 ];
 if($lang!=='en'&&$translated===$source&&isset($defaults[$field])&&
    $source===$defaults[$field]&&$fallback!=='')return (string)$fallback;
 return $translated;
}
add_action('init',static function(){
 if(!has_action('wpml_register_single_string') && !defined('ICL_SITEPRESS_VERSION'))return;
 $options=get_option('look-and-feel',[]);
 foreach(company_presentation_text_fields() as $field=>$label){
  $value=is_array($options)?($options[$field]??null):null;
  if(is_string($value)&&trim($value)!=='')
   do_action('wpml_register_single_string','company-presentation',$field,$value);
 }
},40);
add_action('elementor/dynamic_tags/register',static function($registry){
 if(!class_exists('\Elementor\Core\DynamicTags\Tag'))return;
 if(!class_exists('Company_Presentation_Text_Tag',false)){
  class Company_Presentation_Text_Tag extends \Elementor\Core\DynamicTags\Tag {
   public function get_name(){return 'company-presentation-option';}
   public function get_title(){return 'Company Option Text';}
   public function get_group(){
    return class_exists('Jet_Engine_Dynamic_Tags_Module')
      ?\Jet_Engine_Dynamic_Tags_Module::JET_GROUP:'site';
   }
   public function get_categories(){return ['text'];}
   public function is_settings_required(){return true;}
   protected function register_controls(){
    $this->add_control('field',[
      'label'=>'JetEngine Look & Feel field',
      'type'=>\Elementor\Controls_Manager::SELECT,
      'options'=>company_presentation_text_fields()
    ]);
    // Elementor's base Tag owns the fallback control.
    // Preserve saved 'fallback' values without redeclaring the control.
   }
   public function render(){
    echo esc_html(company_presentation_text(
      (string)$this->get_settings('field'),
      (string)$this->get_settings('fallback')
    ));
   }
  }
 }
 $registry->register(new Company_Presentation_Text_Tag());
},50);

// Keep historical discovery data/API independent from the public template.
// The legacy inline shortcode only renders when deliberately enabled.
add_filter('pre_do_shortcode_tag',static function($pre,$tag){
 if($tag!=='booking_destination_services'||$pre!==false)return $pre;
 $settings=get_option('service-activation',[]);
 $mode=is_array($settings)?($settings['discovery_presentation_mode']??'native_listing'):'native_listing';
 return $mode==='legacy_shortcode'?false:'';
},20,2);

add_filter('elementor/frontend/builder_content_data',static function($nodes,$document_id){
 if(!is_array($nodes)||is_admin()||!is_tax('destinations'))return $nodes;
 $settings=get_option('service-activation',[]);
 if(!is_array($settings))$settings=[];
 $enabled=($settings['discovery_enabled']??'enabled')==='enabled' &&
   in_array('destination',(array)($settings['discovery_surfaces']??['destination']),true);
 $mode=$settings['discovery_presentation_mode']??'native_listing';
 if($enabled&&$mode==='native_listing')return $nodes;
 $walk=function($items)use(&$walk){
  $out=[];
  foreach($items as $node){
   // Only opt-in native discovery template elements are suppressed;
   // parent archive, general page content and non-discovery widgets survive.
   $service=(string)($node['settings']['service_visibility_key']??'');
   if(($node['widgetType']??'')==='template'&&
     in_array($service,['tours','properties','transportations'],true))continue;
   if(!empty($node['elements']))$node['elements']=$walk($node['elements']);
   $out[]=$node;
  }
  return $out;
 };
 return $walk($nodes);
},120,2);
