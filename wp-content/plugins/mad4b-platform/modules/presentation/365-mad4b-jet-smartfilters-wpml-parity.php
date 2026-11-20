<?php
/**
 * Plugin Name: MAD4B Native JetSmartFilters WPML Structural Parity
 * Description: Keep the six source JetSmartFilters controls consistent across WPML while retaining localized labels and actual translated term choices.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_Jet_Filters_Locale_Parity_V1 {
 const SOURCES=[15035,66031,15902,15032,15903,15904];
 const LOCALES=['ar','de','es','fr','it'];
 const STRUCTURE=[
  '_filter_type','_data_source','_source_taxonomy','_query_var',
  '_terms_orderby','_terms_order','_terms_relational_operator',
  '_group_by_parent','_group_collapsible','_show_empty_terms','_only_child',
  '_is_hierarchical','_query_builder_query','_custom_query_var',
  '_ih_terms_orderby','_ih_terms_order'
 ];
 private static $busy=false;
 public static function scope():bool {
  return wp_get_environment_type()==='staging' &&
   wp_parse_url(home_url('/'),PHP_URL_HOST)==='tours.dev.mad4b.com';
 }
 public static function preferred_labels():array {
  return [
   15035=>['it'=>'Tipo di viaggio','de'=>'Reiseart','es'=>'Tipo de viaje','fr'=>'Type de circuit','ar'=>'نوع الرحلة'],
   66031=>['it'=>'Stagione del tour','de'=>'Reisesaison','es'=>'Temporada del viaje','fr'=>'Saison du voyage','ar'=>'موسم الرحلة'],
   15902=>['it'=>'Ideale per','de'=>'Geeignet für','es'=>'Ideal para','fr'=>'Idéal pour','ar'=>'مناسب لـ'],
   15032=>['it'=>'Stile di viaggio','de'=>'Reisestil','es'=>'Estilo de viaje','fr'=>'Style de voyage','ar'=>'نمط الرحلة'],
   15903=>['it'=>'Giorno della settimana','de'=>'Wochentag','es'=>'Día de la semana','fr'=>'Jour de la semaine','ar'=>'يوم الأسبوع'],
   15904=>['it'=>'Momento della giornata','de'=>'Tageszeit','es'=>'Momento del día','fr'=>'Moment de la journée','ar'=>'وقت اليوم']
  ];
 }
 public static function schedule($meta_id,$post_id,$meta_key=null):void {
  if(!self::scope()||self::$busy||!in_array((int)$post_id,self::SOURCES,true))return;
  if($meta_key!==null && !in_array((string)$meta_key,array_merge(self::STRUCTURE,['_filter_label']),true))return;
  if(!wp_next_scheduled('mad4b_jet_filters_locale_parity_v1'))wp_schedule_single_event(time()+25,'mad4b_jet_filters_locale_parity_v1');
 }
 public static function after_save($id):void {
  if(in_array((int)$id,self::SOURCES,true))self::schedule(null,(int)$id);
 }
 public static function reconcile():array {
  if(!self::scope()||self::$busy)return [];
  self::$busy=true;$changed=[];$labels=self::preferred_labels();
  try {
   foreach(self::SOURCES as $source) {
    $post=get_post($source);
    if(!$post||$post->post_type!=='jet-smart-filters'||$post->post_status!=='publish')continue;
    $source_trid=(int)apply_filters('wpml_element_trid',null,$source,'post_jet-smart-filters');
    if(!$source_trid)continue;
    foreach(self::LOCALES as $lang){
     $target=(int)apply_filters('wpml_object_id',$source,'jet-smart-filters',false,$lang);
     $details=$target?apply_filters('wpml_post_language_details',null,$target):null;
     $found=$target && $target!==$source && get_post_type($target)==='jet-smart-filters' &&
      is_array($details)&&($details['language_code']??'')===$lang;
     $new=false;
     if(!$found) {
      $target=wp_insert_post([
        'post_type'=>'jet-smart-filters','post_status'=>'draft','post_author'=>(int)$post->post_author,
        'post_title'=>$post->post_title.' — '.strtoupper($lang),
        'post_content'=>$post->post_content,
        'post_name'=>sanitize_title($post->post_name.'-'.$lang)
      ],true);
      if(is_wp_error($target)||!$target)continue;
      do_action('wpml_set_element_language_details',[
        'element_id'=>$target,'element_type'=>'post_jet-smart-filters','trid'=>$source_trid,
        'language_code'=>$lang,'source_language_code'=>'en'
      ]);
      // The underlying JetSmartFilters options are native post metadata.
      // Clone once, then only reconcile structural settings in later cycles.
      foreach(get_post_meta($source) as $key=>$values){
       if(in_array($key,['_edit_lock','_edit_last','_wp_old_slug','_wpml_media_duplicate','_wpml_media_featured'],true))continue;
       foreach((array)$values as $value)add_post_meta($target,$key,maybe_unserialize($value));
      }
      $new=true;
     }
     $difference=[];
     foreach(self::STRUCTURE as $key) {
      $expected=get_post_meta($source,$key,true);
      $actual=get_post_meta($target,$key,true);
      if($expected!==$actual)$difference[$key]=['old'=>$actual,'new'=>$expected];
     }
     $current_label=(string)get_post_meta($target,'_filter_label',true);
     $desired=(string)($labels[$source][$lang]??'');
     $invalid_labels=['Schwacher Tag','Día de la Debilidad','Jour de Faiblesse','Estilo de recorrido de filtros','Día'];
     $replace_label=$current_label==='' || $new || in_array($current_label,$invalid_labels,true);
     if(!$new && !$difference && !$replace_label)continue;
     $backup='mad4b_jsf_locale_parity_20261102_'.$target;
     if(!$new && get_option($backup,false)===false) {
      add_option($backup,[
       'source'=>$source,'target'=>$target,'lang'=>$lang,
       'before'=>array_merge(array_fill_keys(self::STRUCTURE,null),['_filter_label'=>$current_label]),
       'at'=>gmdate('c')
      ],false);
      // Correct snapshot includes original structural values.
      $snapshot=get_option($backup);
      foreach(self::STRUCTURE as $k)$snapshot['before'][$k]=get_post_meta($target,$k,true);
      update_option($backup,$snapshot,false);
     }
     foreach($difference as $key=>$value)update_post_meta($target,$key,$value['new']);
     if($replace_label && $desired!=='')update_post_meta($target,'_filter_label',$desired);
     if($new){
      $verified=apply_filters('wpml_post_language_details',null,$target);
      if(!is_array($verified)||($verified['language_code']??'')!==$lang ||
       (int)apply_filters('wpml_element_trid',null,$target,'post_jet-smart-filters')!==$source_trid)continue;
      wp_update_post(['ID'=>$target,'post_status'=>'publish']);
     }
     $changed[]=['source'=>$source,'locale'=>$lang,'target'=>$target,
       'new'=>$new,'structure_keys'=>array_keys($difference),'label_changed'=>$replace_label];
    }
   }
  } finally {self::$busy=false;}
  return $changed;
 }
 public static function boot():void {
  if(!self::scope())return;
  add_action('mad4b_jet_filters_locale_parity_v1',[__CLASS__,'reconcile']);
  add_action('save_post_jet-smart-filters',[__CLASS__,'after_save'],30,1);
  add_action('updated_post_meta',[__CLASS__,'schedule'],30,4);
  add_action('added_post_meta',[__CLASS__,'schedule'],30,4);
 }
}
add_action('init',['MAD4B_Jet_Filters_Locale_Parity_V1','boot'],35);
