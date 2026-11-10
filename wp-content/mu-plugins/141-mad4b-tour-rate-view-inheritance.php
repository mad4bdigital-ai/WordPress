<?php
/**
 * Plugin Name: MAD4B Tour Rate View Inheritance
 * Description: JetEngine Look & Feel -> tour_mode preferences -> Tour override -> one native Elementor rate view.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) return;
if (!class_exists('MAD4B_Tour_Rate_View_Inheritance_V1')) {
final class MAD4B_Tour_Rate_View_Inheritance_V1 {
 const OPTION='look-and-feel';
 const OVERRIDE='mad4b_rate_view_override_v1';
 const MODE_PREFIX='mad4b_rate_view_mode_';
 const DEFAULT='mad4b_rate_view_default_v1';
 const PRECEDENCE='mad4b_rate_view_precedence_v1';
 const LEGACY='tour_rates_display_style';
 const STYLES=['accordion'=>'Accordion','grid'=>'Grid Listing / Card Slider'];
 const FALLBACK_MODES=['tour'=>'Tour','cruise_tour'=>'Cruise Tour','activity'=>'Activity','extra_trip'=>'Extra Trip'];
 static function styles():array { return self::STYLES; }
 static function modes():array {
  $m=self::FALLBACK_MODES;
  if(function_exists('shared_product_unit_glossary')) {
   $v=shared_product_unit_glossary('tours-and-activities','tour_mode');
   if(is_array($v)&&isset($v['values'])&&is_array($v['values'])){
    foreach($v['values'] as $key=>$title)if(is_string($key)&&preg_match('/^[a-z][a-z0-9_]{0,40}$/D',$key))$m[$key]=sanitize_text_field((string)$title);
   }
  }
  return $m;
 }
 static function normalize($style):string {
  $v=strtolower(trim((string)$style));
  return in_array($v,['accordion'],true)?'accordion':(in_array($v,['grid','grid listing','card slider'],true)?'grid':'');
 }
 static function source_id(int $id):int {
  if($id<1||get_post_type($id)!=='tours-and-activities')return 0;
  $source=(int)apply_filters('wpml_object_id',$id,'tours-and-activities',false,(string)apply_filters('wpml_default_language','en'));
  return $source>0 && get_post_type($source)==='tours-and-activities'?$source:$id;
 }
 /** Separate effective-style resolver, never mutates tour fields or rates. */
 static function resolve(int $id,?array $settings=null):array {
  if($id<1||get_post_type($id)!=='tours-and-activities')return ['style'=>'','source'=>'invalid'];
  $current=self::normalize(get_post_meta($id,self::OVERRIDE,true));
  if($current!=='')return ['style'=>$current,'source'=>'tour_override','tour_id'=>$id];
  $source=self::source_id($id);
  if($source!==$id){
   $original=self::normalize(get_post_meta($source,self::OVERRIDE,true));
   if($original!=='')return ['style'=>$original,'source'=>'linked_source_override','tour_id'=>$id,'source_id'=>$source];
  }
  $opts=$settings??get_option(self::OPTION,[]);
  $opts=is_array($opts)?$opts:[];
  $mode=(string)get_post_meta($id,'tour_mode',true);
  if($mode===''&&$source!==$id)$mode=(string)get_post_meta($source,'tour_mode',true);
  $mode_pref=self::normalize($opts[self::MODE_PREFIX.$mode]??'');
  $default=self::normalize($opts[self::DEFAULT]??'');
  $pref=$mode_pref!==''?$mode_pref:$default;
  $legacy=self::normalize(get_post_meta($id,self::LEGACY,true));
  if($legacy===''&&$source!==$id)$legacy=self::normalize(get_post_meta($source,self::LEGACY,true));
  $force=($opts[self::PRECEDENCE]??'preserve')==='apply_to_existing';
  if($pref!=='' && ($force||$legacy===''))return ['style'=>$pref,'source'=>$mode_pref!==''?'tour_mode_policy':'global_default','tour_mode'=>$mode,'tour_id'=>$id];
  if($legacy!=='')return ['style'=>$legacy,'source'=>'original_tour_field','tour_mode'=>$mode,'tour_id'=>$id];
  return ['style'=>$pref!==''?$pref:'grid','source'=>$pref!==''?'global_default':'legacy_grid_fallback','tour_mode'=>$mode,'tour_id'=>$id];
 }
 static function field(string $name,string $title,array $choices,string $default,string $desc=''):array {
  $options=[];foreach($choices as $key=>$label)$options[]=['key'=>$key,'value'=>$label];
  return ['name'=>$name,'title'=>$title,'object_type'=>'field','type'=>'select','width'=>'100%','options_source'=>'manual',
   'options'=>$options,'default_val'=>$default,'description'=>$desc,'repeater-fields'=>[],'isNested'=>false];
 }
 static function look_fields($fields,$page){
  if(!is_array($fields)||!is_object($page)||($page->slug??'')!==self::OPTION)return $fields;
  $fields[]=['name'=>'mad4b_rate_view_preferences_tab_v1','title'=>'Tour Rates · Display Modes','type'=>'text','object_type'=>'tab','tab_layout'=>'vertical','width'=>'100%'];
  $fields[]=self::field(self::DEFAULT,'Default presentation',[
   'inherit'=>'Preserve each Tour original setting (compatibility)',
   'accordion'=>'Accordion','grid'=>'Card Slider / Grid Listing'],'inherit',
   'Fallback for Tour Modes without a configured preference.');
  $fields[]=self::field(self::PRECEDENCE,'Apply preferences when saving Look & Feel',[
   'preserve'=>'Preserve existing Tour styles (apply only when Tour has no saved style)',
   'apply_to_existing'=>'Override all saved Tour display styles with the matching Mode preference (Tour-specific override still wins)'],
   'preserve','Policy-based synchronization: does not rewrite original Tour or WPML metadata. Changes apply immediately after saving.');
  foreach(self::modes() as $mode=>$title){
   $fields[]=self::field(self::MODE_PREFIX.$mode,'Tour Mode: '.$title,[
     'inherit'=>'Inherit default presentation','accordion'=>'Accordion','grid'=>'Card Slider / Grid Listing'],'inherit');
  }
  return $fields;
 }
 static function register_tour_editor():void {
  if(!is_admin() || !current_user_can('manage_options'))return;
  $boxes=get_option('jet_engine_meta_boxes',[]);
  if(!is_array($boxes)||!isset($boxes['mad4b_tour_layout_page_options']['meta_fields'])||!is_array($boxes['mad4b_tour_layout_page_options']['meta_fields']))return;
  foreach($boxes['mad4b_tour_layout_page_options']['meta_fields'] as $f)if(($f['name']??'')===self::OVERRIDE)return;
  $boxes['mad4b_tour_layout_page_options']['meta_fields'][]=self::field(self::OVERRIDE,'Rate view override (this Tour)',[
   'inherit'=>'Inherit Look & Feel / Tour Mode preference','accordion'=>'Force Accordion on this Tour','grid'=>'Force Card Slider on this Tour'],'inherit',
   'Local override has highest priority. Linked WPML translations inherit the source Tour override unless they have their own.');
  update_option('jet_engine_meta_boxes',$boxes,false);
 }
 static function tour_id():int {
  $p=function_exists('shared_presentation_primary_tour_post_v1')?shared_presentation_primary_tour_post_v1():null;
  if($p instanceof WP_Post && $p->post_type==='tours-and-activities')return (int)$p->ID;
  $q=(int)get_queried_object_id();
  return $q>0 && get_post_type($q)==='tours-and-activities'?$q:0;
 }
 static function widget_mode($widget):string {
  if(!is_object($widget)||!method_exists($widget,'get_name')||$widget->get_name()!=='jet-listing-grid')return '';
  $id=(string)$widget->get_settings('_element_id');
  if($id==='tour_rates_accordion')return 'accordion';
  if($id==='tour_rates')return 'grid';
  return '';
 }
 static function editor():bool {
  if(is_admin()||wp_doing_ajax()||(defined('REST_REQUEST')&&REST_REQUEST))return true;
  return class_exists('Elementor\\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode();
 }
 static function should_render($render,$widget){
  if(!$render||self::editor())return $render;
  $view=self::widget_mode($widget);if($view==='')return $render;
  $id=self::tour_id();if(!$id)return $render;
  return self::resolve($id)['style']===$view;
 }
 static function before_widget($widget):void {
  if(self::editor())return;
  $id=self::tour_id();if(!$id)return;
  $view=self::widget_mode($widget);
  if($view!==''){
   // JetEngine's authored condition reads the old saved post field, not the resolved
   // policy. This server-side view guard is authoritative; do not evaluate both.
   $widget->set_settings('jedv_enabled','');
   return;
  }
  if(!is_object($widget)||!method_exists($widget,'get_name')||!str_starts_with((string)$widget->get_name(),'jet-smart-filters-'))return;
  $q=(string)$widget->get_settings('query_id');
  if(!in_array($q,['tour_rates','tour_rates_accordion'],true))return;
  $view=self::resolve($id)['style'];
  $widget->set_settings('query_id',$view==='accordion'?'tour_rates_accordion':'tour_rates');
 }
 static function register():void {
  add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'look_fields'],130,2);
  // Tour Meta Box schema is provisioned once during company setup, never written by admin page loads.
  add_filter('elementor/frontend/widget/should_render',[__CLASS__,'should_render'],95,2);
  // Only one final pass after the legacy query router; no duplicate Elementor actions.
  add_action('elementor/frontend/widget/before_render',[__CLASS__,'before_widget'],50);
 }
}
MAD4B_Tour_Rate_View_Inheritance_V1::register();
}
