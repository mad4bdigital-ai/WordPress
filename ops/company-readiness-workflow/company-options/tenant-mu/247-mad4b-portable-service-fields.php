<?php
/**
 * Plugin Name: MAD4B Portable Service Activation Fields
 * Description: Schema-only JetEngine shared tour entry/presentation controls. Does not set pricing or activation values.
 */
defined('ABSPATH')||exit;
add_filter('jet-engine/options-pages/raw-fields', static function($fields,$page){
 if(($page->slug??'')!=='service-activation'||!is_array($fields))return $fields;
 $known=[];foreach($fields as $field)if(is_array($field)&&!empty($field['name']))$known[$field['name']]=true;
 $kinds=['half_day','day','activity','extra_trip','package','cruise','other'];
 foreach($kinds as $kind){
  $key='shared_tour_entry_'.$kind;
  if(isset($known[$key]))continue;
  $choices=[
   ['key'=>'direct_per_person','value'=>'Direct per person'],
   ['key'=>'seed_rate_simulation','value'=>'Seed Rate simulation (not published)'],
   ['key'=>'existing_rates','value'=>'Existing source-native Rates'],
   ['key'=>'inquiry','value'=>'Inquiry only']];
  $fields[]=['name'=>$key,'title'=>'Tour entry mode — '.str_replace('_',' ',$kind),
    'type'=>'select','object_type'=>'field','options_source'=>'manual','options'=>$choices,
    'description'=>'Unconfigured uses the engine default; this setting does not enable checkout'];
 }
 if(!isset($known['shared_tour_presentation_mode']))$fields[]=[
  'name'=>'shared_tour_presentation_mode','title'=>'Tour Pricing Presentation Mode',
  'type'=>'select','object_type'=>'field','options_source'=>'manual',
  'options'=>[['key'=>'adaptive','value'=>'Adaptive display'],
             ['key'=>'native','value'=>'Authored native display']],
  'description'=>'Presentation only; no price/rate changes'];
 return $fields;
},35,2);

