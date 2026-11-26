<?php
/**
 * Native JetEngine Tour Rate transportation-composition authoring fields.
 * Additive and reversible; does NOT change Rate content or price. Mode 0/off
 * by default for all existing Tour Rate posts. Separate from hotel stages.
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
    WP_CLI::error('site_not_allowed');
if($site==='https://wayfatours.com' &&
   !(defined('MAD4B_RATE_STAGE_WAYFA_APPROVED')&&MAD4B_RATE_STAGE_WAYFA_APPROVED===true))
    WP_CLI::error('wayfa_explicit_guard_missing');
$apply=defined('MAD4B_RATE_STAGE_APPLY')&&MAD4B_RATE_STAGE_APPLY===true;
$rollback=defined('MAD4B_RATE_STAGE_ROLLBACK')&&MAD4B_RATE_STAGE_ROLLBACK===true;
$metaFields=['has_transportations','transport_assignment_mode',
    'transport_selection_mode','related_transportations_id',
    'transport_price_policy','_mad4b_transport_stages_v1'];
$backupKey='mad4b_tour_rate_transport_stage_schema_backup_20261119_v1';
$rules=[
    ['field'=>'has_transportations','operator'=>'equal','value'=>'1','values'=>[]]
];
$forms=[
 [
  'name'=>'has_transportations','title'=>'Has Transportation Components',
  'object_type'=>'field','type'=>'radio','options_source'=>'glossary',
  'glossary_id'=>'321','default_val'=>'0','width'=>'50%',
  'description'=>'Explicitly enable a transportation plan on this Tour Rate. Existing rates default No. Does not approve a price.',
  'id'=>930001,'isNested'=>false
 ],
 [
  'name'=>'transport_assignment_mode','title'=>'Transportation Assignment Mode',
  'object_type'=>'field','type'=>'select','options_source'=>'manual',
  'default_val'=>'manual','width'=>'50%',
  'options'=>[
    ['key'=>'manual','value'=>'Manual composition'],
    ['key'=>'auto','value'=>'Auto-suggest from itinerary + PDF destinations (requires review)']
  ],
  'description'=>'Auto discovers matching Yes components; it does not publish, buy, or price unavailable services.',
  'conditional_logic'=>true,'conditions'=>$rules,
  'conditional_relation'=>'AND','id'=>930002,'isNested'=>false
 ],
 [
  'name'=>'transport_selection_mode','title'=>'Transportation Selection Mode',
  'object_type'=>'field','type'=>'select','options_source'=>'manual',
  'default_val'=>'simple','width'=>'50%',
  'options'=>[
    ['key'=>'simple','value'=>'Simple (OR alternatives)'],
    ['key'=>'stages','value'=>'Stages (AND required stages; OR/AND within stages)']
  ],
  'description'=>'Simple chooses one alternative. Stages are itinerary-day groups with required AND across days and OR/AND per group.',
  'conditional_logic'=>true,'conditions'=>$rules,
  'conditional_relation'=>'AND','id'=>930003,'isNested'=>false
 ],
 [
  'name'=>'related_transportations_id','title'=>'Simple Transportation Alternatives (OR)',
  'object_type'=>'field','type'=>'posts','options_source'=>'manual',
  'search_post_type'=>['transportations'],'is_multiple'=>true,
  'description'=>'Choose alternatives from Status Yes and route-matched services only. Any Draft still needs publishing and Rate approval.',
  'width'=>'100%','conditional_logic'=>true,'conditional_relation'=>'AND',
  'conditions'=>array_merge($rules,[[
      'field'=>'transport_selection_mode','operator'=>'equal','value'=>'simple','values'=>[]
  ]]),
  'id'=>930004,'isNested'=>false
 ],
 [
  'name'=>'transport_price_policy','title'=>'Transportation Price Composition',
  'object_type'=>'field','type'=>'select','options_source'=>'manual',
  'default_val'=>'inquiry','width'=>'50%',
  'options'=>[
    ['key'=>'inquiry','value'=>'Inquiry only — no payable quote'],
    ['key'=>'included','value'=>'Included — never add to package twice'],
    ['key'=>'additional','value'=>'Additional — native approved Transport Rate required']
  ],
  'description'=>'Tour Rate owns inclusions. Transport Rate owns price. No automated selling from source PDF alone.',
  'conditional_logic'=>true,'conditions'=>$rules,
  'conditional_relation'=>'AND','id'=>930005,'isNested'=>false
 ],
 [
  'name'=>'_mad4b_transport_stages_v1','title'=>'Transportation Stages',
  'object_type'=>'field','type'=>'repeater','options_source'=>'manual',
  'width'=>'100%','repeater_save_separate'=>false,'repeater_title_field'=>'label',
  'description'=>'One row per itinerary stage. Required stages join as AND. Stage alternatives use OR, or multiple required components using AND. Explicit origin/destination term IDs are mandatory for route matching; station/hotel endpoints remain review-required.',
  'conditional_logic'=>true,'conditional_relation'=>'AND',
  'conditions'=>array_merge($rules,[[
      'field'=>'transport_selection_mode','operator'=>'equal','value'=>'stages','values'=>[]
  ]]),
  'repeater-fields'=>[
      ['name'=>'key','title'=>'Stable stage key','type'=>'text','id'=>930101],
      ['name'=>'label','title'=>'Stage label','type'=>'text','id'=>930102],
      ['name'=>'day_offset','title'=>'Day offset','type'=>'number',
       'min_value'=>0,'max_value'=>365,'step_value'=>1,'id'=>930103],
      ['name'=>'origin_term_id','title'=>'Origin Destination term ID','type'=>'number',
       'min_value'=>1,'step_value'=>1,'id'=>930104],
      ['name'=>'destination_term_id','title'=>'Dropoff / target Destination term ID','type'=>'number',
       'min_value'=>0,'step_value'=>1,'id'=>930105],
      ['name'=>'return_to_origin','title'=>'Return to origin?','type'=>'radio',
       'options_source'=>'glossary','glossary_id'=>'321',
       'default_val'=>'0','id'=>930106],
      ['name'=>'required','title'=>'Required stage?','type'=>'radio',
       'options_source'=>'glossary','glossary_id'=>'321','default_val'=>'1','id'=>930107],
      ['name'=>'join','title'=>'Within-stage composition','type'=>'select',
       'options_source'=>'manual','default_val'=>'OR',
       'options'=>[['key'=>'OR','value'=>'OR — choose one'],
                   ['key'=>'AND','value'=>'AND — all needed']],
       'id'=>930108],
      ['name'=>'service_kind','title'=>'Service category','type'=>'select',
       'options_source'=>'manual','default_val'=>'any',
       'options'=>[['key'=>'any','value'=>'All transport'],
                   ['key'=>'transfer','value'=>'Point-to-point transfer'],
                   ['key'=>'sightseeing','value'=>'Day/sightseeing vehicle'],
                   ['key'=>'facility','value'=>'Airport / station'],
                   ['key'=>'extra','value'=>'Operational extra']],
       'id'=>930109],
      ['name'=>'price_policy','title'=>'Stage price policy','type'=>'select',
       'options_source'=>'manual','default_val'=>'inquiry',
       'options'=>[['key'=>'inquiry','value'=>'Inquiry'],
                   ['key'=>'included','value'=>'Included'],
                   ['key'=>'additional','value'=>'Additional']],
       'id'=>930110],
      ['name'=>'transport_ids','title'=>'Selected transport candidates','type'=>'posts',
       'options_source'=>'manual','search_post_type'=>['transportations'],
       'is_multiple'=>true,'id'=>930111]
  ],
  'id'=>930006,'isNested'=>false
 ]
];
global $wpdb;
$table=$wpdb->prefix.'jet_post_types';
$row=$wpdb->get_row($wpdb->prepare(
    "SELECT id,status,meta_fields FROM {$table} WHERE slug=%s",'tour-rates'),ARRAY_A);
if(!$row||$row['status']!=='publish'||(int)$row['id']!==328)
    WP_CLI::error('unexpected_tour_rate_definition');
$beforeRaw=(string)$row['meta_fields'];
$fields=(array)maybe_unserialize($beforeRaw);
$names=array_column($fields,'name');
$position=array_search('_booking_experience_stay_stages_v1',$names,true);
$indexOfEnd=array_search('show_rate_departure_details',$names,true);
if($position!==44||$indexOfEnd!==45)WP_CLI::error('tour_rate_field_layout_changed');
$present=array_intersect($metaFields,$names);
if($present && count($present)!==count($metaFields))
    WP_CLI::error('partial_schema_not_allowed');
if(!$present){
 if(count($fields)!==46)WP_CLI::error('before_field_count_changed');
 $sourceIds=array_column($fields,'id');
 foreach($forms as $f)if(in_array($f['id'],$sourceIds,true))
     WP_CLI::error('jetengine_field_id_collision');
 array_splice($fields,45,0,$forms);
}else{
 if(count($fields)!==52 || array_slice($names,45,6)!==$metaFields)
     WP_CLI::error('existing_field_contract_drift');
}
$afterRaw=serialize($fields);
$settings=get_option('icl_sitepress_settings',[]);
$translation=$settings['translation-management']['custom_fields_translation']??[];
$beforeTranslation=[];
foreach($metaFields as $k)$beforeTranslation[$k]=$translation[$k]??null;
$backup=get_option($backupKey,false);
if($rollback){
 if(!is_array($backup) || count($present)!==6 ||
    hash('sha256',$beforeRaw)!==$backup['after_hash'])
    WP_CLI::error('rollback_schema_conflict');
 foreach($metaFields as $name)
    if(($translation[$name]??null)!==1)
       WP_CLI::error('rollback_wpml_conflict');
 if($wpdb->update($table,['meta_fields'=>$backup['before_raw']],
      ['id'=>328],['%s'],['%d'])!==1)
    WP_CLI::error('rollback_db_failed');
 foreach($backup['wpml_before'] as $k=>$v){
    if($v===null)unset($translation[$k]);else $translation[$k]=$v;
 }
 $settings['translation-management']['custom_fields_translation']=$translation;
 update_option('icl_sitepress_settings',$settings);
 WP_CLI::log(wp_json_encode(['status'=>'schema_rolled_back','restored_fields'=>46]));
 return;
}
if(!$apply){
 WP_CLI::log(wp_json_encode([
 'status'=>'dry_run','site'=>home_url('/'),'before'=>count($present)?52:46,
 'after'=>52,'stage_field'=>'_mad4b_transport_stages_v1',
 'simple_or'=>true,'stages_or_and'=>true,'existing_rate_posts_changed'=>0,
 'initial_rate_enabled'=>false
 ]));return;
}
if($backup===false){
 if($present)WP_CLI::error('already_installed_without_backup');
 if(!add_option($backupKey,[
   'at'=>gmdate('c'),'before_raw'=>$beforeRaw,
   'after_hash'=>hash('sha256',$afterRaw),
   'wpml_before'=>$beforeTranslation
 ],'',false)) WP_CLI::error('backup_failed');
}elseif(!count($present))WP_CLI::error('existing_backup_conflict');
if(!$present && $wpdb->update($table,['meta_fields'=>$afterRaw],
 ['id'=>328],['%s'],['%d'])!==1) WP_CLI::error('jetengine_rate_fields_write_failed');
foreach($metaFields as $k)$translation[$k]=1;
$settings['translation-management']['custom_fields_translation']=$translation;
update_option('icl_sitepress_settings',$settings);
$check=$wpdb->get_var($wpdb->prepare("SELECT meta_fields FROM {$table} WHERE id=%d",328));
$current=get_option('icl_sitepress_settings',[]);
if(count((array)maybe_unserialize($check))!==52 ||
    array_slice(array_column((array)maybe_unserialize($check),'name'),45,6)!==$metaFields)
    WP_CLI::error('tour_rate_stage_definition_readback_failed');
foreach($metaFields as $k)
 if(($current['translation-management']['custom_fields_translation'][$k]??null)!==1)
    WP_CLI::error('wpml_copy_readback_failed_'.$k);
WP_CLI::log(wp_json_encode([
 'status'=>'tour_rate_transport_fields_applied','site'=>home_url('/'),
 'new_fields'=>6,'cpt_fields'=>52,'simple_or'=>true,'multi_stage_and_or'=>true,
 'rate_posts_modified'=>0,'native_booking_contract_modified'=>false,
 'default_enabled'=>false,'checkout_authorized'=>false
]));
