<?php
/**
 * Plugin Name: MAD4B Nile Cruise — Program First JetEngine Authoring
 * Version: 1.0.0
 * Description: Presents native Programs taxonomy as the itinerary authority and moves legacy sailing runs under Prices. Does not modify saved Tour/Rate content.
 */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Nile_Program_First_Editor_V1',false)){
final class MAD4B_Nile_Program_First_Editor_V1 {
 const PROGRAM_TAX='programs';
 const ROOT_SLUG='egypt-nile-cruises';
 public static function editor_post():int {
  if(!is_admin())return 0;
  $id=absint($_GET['post']??$_POST['post_ID']??0);
  return $id&&get_post_type($id)==='tours-and-activities'?$id:0;
 }
 /**
  * Program term IDs are canonical references, never translatable labels.
  * Evaluate source-language program eligibility (itinerary and parent), then
  * display the WPML term name in the active editor language.
  * Keep keys as source IDs so Rate/Itinerary relations do not silently change.
  */
 public static function program_options(int $tour=0):array {
  $active=(string)apply_filters('wpml_current_language','');
  $source=(string)apply_filters('wpml_default_language','');
  $switched=$active!==''&&$source!==''&&$active!==$source;
  if($switched)do_action('wpml_switch_language',$source);
  try {$options=self::source_program_options($tour);}
  finally {if($switched)do_action('wpml_switch_language',$active);}
  if(!$switched)return $options;
  foreach($options as &$row){
   $id=absint($row['key']??0);
   if(!$id)continue;
   $base=get_term($id,self::PROGRAM_TAX);
   $mapped=(int)apply_filters('wpml_object_id',$id,self::PROGRAM_TAX,false,$active);
   $localized=$mapped?get_term($mapped,self::PROGRAM_TAX):null;
   if(!$base instanceof WP_Term||!$localized instanceof WP_Term)continue;
   $suffix='';
   $sourceLabel=(string)($row['value']??'');
   if(str_starts_with($sourceLabel,(string)$base->name))
     $suffix=substr($sourceLabel,strlen((string)$base->name));
   $row['value']=(string)$localized->name.$suffix;
  }
  unset($row);
  return $options;
 }
 private static function source_program_options(int $tour=0):array {
  $root=get_terms(['taxonomy'=>self::PROGRAM_TAX,'slug'=>self::ROOT_SLUG,'hide_empty'=>false,'parent'=>0,'number'=>2]);
  $rootid=!is_wp_error($root)&&count($root)===1?(int)$root[0]->term_id:0;
  $nile=[];$other=[];
  $terms=get_terms(['taxonomy'=>self::PROGRAM_TAX,'hide_empty'=>false,'number'=>150]);
  if(is_wp_error($terms))return [];
  foreach($terms as $term){
   $id=(int)$term->term_id;
   $children=get_terms(['taxonomy'=>self::PROGRAM_TAX,'parent'=>$id,'fields'=>'ids','hide_empty'=>false,'number'=>1]);
   if(!is_wp_error($children)&&$children)continue;
   $isreal=get_term_meta($id,'program_itinerary',true)||get_term_meta($id,'program_description',true)||get_term_meta($id,'template_shortcode',true);
   if(!$isreal)continue;
   $row=['key'=>(string)$id,'value'=>$term->name];
   if($rootid&&(int)$term->parent===$rootid){
    if(class_exists('MAD4B_Cruise_Program_Composer_V1',false)
       &&MAD4B_Cruise_Program_Composer_V1::definition($id))
       $row['value'].=' — Combined / Reverse';
    $nile[]=$row;
   }else $other[]=$row;
  }
  usort($nile,fn($a,$b)=>strcmp($a['key'],$b['key']));
  usort($other,fn($a,$b)=>strcmp($a['value'],$b['value']));
  $cruise=$tour&&(string)get_post_meta($tour,'tour_mode',true)==='cruise_tour';
  // The authoring field is now Cruise-only in the native JetEngine CPT definition.
  // Show only child Programs of the Nile Cruise taxonomy root, including when
  // creating a NEW Tour before tour_mode is persisted.
  $choices=$nile;
  // Retain already selected legacy term values during editing; never drop a
  // value only because it falls outside a newer source scope.
  if($cruise){
   $selected=get_post_meta($tour,'related_program_terms',true);
   if(!is_array($selected))$selected=$selected!==''?[$selected]:[];
   $present=array_column($choices,'key');
   foreach($selected as $value){
    $id=absint($value);
    if(!$id||in_array((string)$id,$present,true))continue;
    $term=get_term($id,self::PROGRAM_TAX);
    if($term instanceof WP_Term)$choices[]=['key'=>(string)$id,'value'=>$term->name.' — Existing non-Nile link · review'];
   }
  }
  return $choices;
 }
 public static function reorder($fields,$owner):array {
  if(!is_admin()||!is_array($fields))return $fields;
  $names=array_column($fields,'name');
  foreach(['tour_mode','display_style','related_program_terms','cruise_runs','tour_rates_display_style'] as $name)
   if(!in_array($name,$names,true))return $fields;
  $tour=self::editor_post();
  $program=null;$runs=null;$rest=[];
  foreach($fields as $field){
   $name=(string)($field['name']??'');
   if($name==='related_program_terms'){
    $program=$field;
    $program['title']='Cruise Itinerary — Programs Taxonomy';
    $program['description']='Select by translated Program NAME. Numeric Term IDs are internal references and must be copied, never translated. Edit each language’s NAME in WPML → Taxonomy Translation → Programs. Nile Cruise itineraries are inherited from the original Program; seven-night combined programs require the Rate Route Variant.';
    $choices=self::program_options($tour);
    if($choices){$program['options_source']='manual';$program['options']=$choices;$program['query_id']='';}
    // Preserve the field for non-cruise Tours too: other valid Programs exist.
    continue;
   }
   if($name==='cruise_runs'){
    $runs=$field;
    $runs['title']='Legacy Sailing Runs — optional schedule overrides';
    $runs['description']='This repeater stores legacy sailing schedules, not Program days. The Tour Rate owns departure/date, route selection and prices. Existing saved rows remain intact.';
    continue;
   }
   if($name==='tour_cruise_amenities'){
    $field['title']='Tour Cruise Amenities — verified overrides';
    $field['description']='Nile vessel amenities are inherited from the selected Cruise Property. Use this field only for additional Tour-specific amenities after content verification.';
   }
   $rest[]=$field;
   if($name==='display_style'&&$program){$rest[]=$program;$program=null;}
   if($name==='tour_rates_display_style'&&$runs){$rest[]=$runs;$runs=null;}
  }
  if($program)$rest[]=$program;
  if($runs)$rest[]=$runs;
  return $rest;
 }
 private static function count_meta(int $post,string $key):int {
  $v=get_post_meta($post,$key,true);
  if(is_array($v))return count(array_filter($v,fn($item)=>$item!==null&&$item!==''));
  if(is_string($v)&&$v!=='')return $key==='gallery'?count(array_filter(explode(',',$v))):1;
  return 0;
 }
 public static function checklist(int $tour):array {
  $mode=(string)get_post_meta($tour,'tour_mode',true);
  $id=absint(get_post_meta($tour,'tour_cruise_property_id',true));
  $raw=get_post_meta($tour,'related_program_terms',true);
  if(!is_array($raw))$raw=$raw!==''?[$raw]:[];
  $programs=[];
  foreach($raw as $v){$term=get_term(absint($v),self::PROGRAM_TAX);if($term instanceof WP_Term)$programs[]=$term->term_id;}
  $property=class_exists('MAD4B_Tour_Cruise_Property_Field_V1',false)
      ?MAD4B_Tour_Cruise_Property_Field_V1::context($tour):[];
  $rates=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft','pending'],
      'fields'=>'ids','posts_per_page'=>50,'no_found_rows'=>true,'suppress_filters'=>true,
      'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$tour,'compare'=>'=']]]);
  return ['tour_id'=>$tour,'is_cruise'=>$mode==='cruise_tour',
   'programs'=>$programs,'property_id'=>$id,'effective_amenities'=>count($property['effective_amenity_ids']??[]),
   'gallery'=>self::count_meta($tour,'gallery'),'rate_ids'=>array_map('intval',$rates),
   'extras'=>self::count_meta($tour,'tour_optional_extras')+self::count_meta($tour,'custom_extra_items'),
   'transports'=>self::count_meta($tour,'vehicles_transport_services')+self::count_meta($tour,'train_transport_services')+self::count_meta($tour,'air_transport_services'),
   'legacy_runs'=>self::count_meta($tour,'cruise_runs'),
   'note'=>'Read-only diagnostics; missing data is never fabricated'];
 }
 public static function metabox(WP_Post $post):void {
  if(!current_user_can('edit_post',$post->ID))return;
  $a=self::checklist($post->ID);
  echo '<div style="padding:8px;line-height:1.55">';
  echo '<p><strong>Program Taxonomy → Property → Tour Rate → Extras / Transports → Presentation.</strong> This check does not create or modify data.</p>';
  if(!$a['is_cruise'])echo '<p>Choose <strong>Tour Mode = Cruise Tour</strong> first. For regular tours, the native Itinerary editor remains available.</p>';
  else {
   $steps=[
    'Programs Taxonomy'=>[count($a['programs']),'Choose Nile cruise itinerary term(s), not manual Cruise Runs'],
    'Cruise Property'=>[$a['property_id']?1:0,'Choose the floating hotel / vessel'],
    'Property Amenities'=>[$a['effective_amenities'],'Content Job needed when unverified or empty'],
    'Image Gallery'=>[$a['gallery'],'Cover days and linked destinations with verified photos'],
    'Tour Rates'=>[count($a['rate_ids']),'Check program, route, property and availability at Rate level'],
    'Optional Extras'=>[$a['extras'],'See the Extras & Transfers eligibility preview below'],
    'Transports'=>[$a['transports'],'Vehicle, train and air authoring may include unpublished choices'],
    'Legacy Sailing Overrides'=>[$a['legacy_runs'],'Preserved schedule rows, not the program source']
   ];
   foreach($steps as $title=>$detail){[$count,$note]=$detail;
    echo '<p style="border-top:1px solid #ddd;padding:6px 0;margin:0"><strong>'.($count?'✓':'○').' '.esc_html($title).' ('.esc_html((string)$count).')</strong> — '.esc_html($note).'</p>';
   }
  }
  echo '<p><strong>Translate Program names, not numeric IDs.</strong> Choose a language in WPML Taxonomy Translation and edit the term Name. Relationship IDs and itinerary source remain the original Program.</p>';
  echo '<p><a href="'.esc_url(admin_url('admin.php?page=sitepress-multilingual-cms/menu/taxonomy-translation.php&taxonomy=programs')).'">Translate Programs names (WPML)</a> · <a href="'.esc_url(admin_url('edit-tags.php?taxonomy=programs&post_type=tours-and-activities')).'">Review original Programs taxonomy</a></p>';
  echo '</div>';
 }
 public static function add_box():void {
  add_meta_box('mad4b-nile-program-first-readiness','MAD4B · Cruise Program & Property Readiness',
      [self::class,'metabox'],'tours-and-activities','normal','high');
 }
 public static function cli($args,$assoc):void {
  $task=$args[0]??'options';
  if($task==='options')$result=self::program_options(absint($args[1]??0));
  elseif($task==='checklist')$result=self::checklist(absint($args[1]??0));
  else WP_CLI::error('Supported: options [TOUR_ID] | checklist TOUR_ID');
  WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
}
add_filter('jet-engine/meta-boxes/raw-fields',[MAD4B_Nile_Program_First_Editor_V1::class,'reorder'],35,2);
add_action('add_meta_boxes_tours-and-activities',[MAD4B_Nile_Program_First_Editor_V1::class,'add_box'],25);
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b nile-authoring',[MAD4B_Nile_Program_First_Editor_V1::class,'cli']);
}
