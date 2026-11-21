<?php
if (!defined('ABSPATH') || !defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;
function lab_preview_schema(){
 $fields=apply_filters('lab_preview_schema',[
 'tour_id'=>['type'=>'number','label'=>'Tour ID','min'=>1],
 'source'=>['type'=>'select','label'=>'Price source','options'=>['rate'=>'Tour Rate','direct'=>'Direct Tour']],
 'rate_id'=>['type'=>'number','label'=>'Rate ID','min'=>1,'show_if'=>['source'=>'rate']],
 'persons'=>['type'=>'number','label'=>'Travelers','min'=>1,'max'=>50,'default'=>3],
 'travel_date'=>['type'=>'date','label'=>'Travel date'],
 'audience'=>['type'=>'select','label'=>'Audience','options'=>['general'=>'General','egyptians'=>'Egyptians']],
 'occupancy'=>['type'=>'select','label'=>'Occupancy','options'=>['double'=>'Double','single'=>'Single','triple'=>'Triple']],
 'booking_mode'=>['type'=>'select','label'=>'Booking mode','options'=>[''=>'Automatic','private'=>'Private','group'=>'Group']],
 'group_size'=>['type'=>'number','label'=>'Group size','min'=>1,'show_if'=>['booking_mode'=>'group']],
 'tier_id'=>['type'=>'number','label'=>'Group tier ID','min'=>1,'show_if'=>['booking_mode'=>'group']],
 'package_pricing_apply'=>['type'=>'checkbox','label'=>'Apply package pricing'],
 'season_term_id'=>['type'=>'number','label'=>'Travel season (existing taxonomy)','min'=>1],
 'package_term_id'=>['type'=>'number','label'=>'Package level ID','min'=>1,'show_if'=>['package_pricing_apply'=>'1']],
 'property_id'=>['type'=>'number','label'=>'Property ID','min'=>1],
 'transport_id'=>['type'=>'number','label'=>'Transport ID','min'=>1]
 ]);
 if(!is_array($fields)||count($fields)>70)return [];
 foreach($fields as $k=>$v){if(!is_string($k)||!preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$k)||!is_array($v)||!in_array($v['type']??null,['number','date','text','select','checkbox'],true)||!is_string($v['label']??null))return [];}
 return $fields;
}

/** Strict server-side form decoder, shared by the preview AJAX endpoint. */
function lab_preview_decode($raw){
 $schema=lab_preview_schema();if(!$schema)return new WP_Error('preview_schema_invalid');
 $out=[];
 foreach($raw as $key=>$value){
  if(in_array($key,['action','nonce'],true))continue;
  if(!isset($schema[$key])||!is_scalar($value))return new WP_Error('preview_unknown_or_structured_field',sanitize_key((string)$key));
  $d=$schema[$key];$v=(string)wp_unslash($value);if($v==='')continue;
  if($d['type']==='number'){
   if(!ctype_digit($v)||(int)$v<($d['min']??1)||(int)$v>($d['max']??99999999))return new WP_Error('preview_invalid_number',$key);
   $out[$key]=(int)$v;
  }elseif($d['type']==='select'){
   if(!array_key_exists($v,$d['options']??[]))return new WP_Error('preview_invalid_option',$key);
   $out[$key]=$v;
  }elseif($d['type']==='checkbox'){
   if($v!=='1')return new WP_Error('preview_invalid_checkbox',$key);
   $out[$key]=true;
  }elseif($d['type']==='date'){
   $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
   if(!$dt||$dt->format('Y-m-d')!==$v)return new WP_Error('preview_invalid_date',$key);
   $out[$key]=$v;
  }else{$out[$key]=sanitize_text_field($v);}
 }
 foreach($out as $key=>$value){
  $d=$schema[$key];if(empty($d['show_if']))continue;
  foreach($d['show_if'] as $parent=>$expected){
   $actual=$out[$parent]??null;
   if(($actual===true?'1':(string)$actual)!==(string)$expected)return new WP_Error('preview_hidden_input_submitted',$key);
  }
 }
 if(isset($out['tier_id'])&&(($out['booking_mode']??null)!=='group'||!isset($out['group_size'])))return new WP_Error('preview_tier_dependency');
 if(isset($out['group_size'])&&($out['booking_mode']??null)!=='group')return new WP_Error('preview_group_dependency');
 if(isset($out['package_term_id'])&&empty($out['package_pricing_apply']))return new WP_Error('preview_package_dependency');
 return $out;
}

/** Explicit mapping contract for supported Booking v2 inputs. No arbitrary paths. */
function lab_preview_booking_map($values){
 $routes=['booking_mode'=>'booking_mode','group_size'=>'group_size','tier_id'=>'tier_id','package_pricing_apply'=>'package_pricing_apply','season_term_id'=>'selections.season_term_id','package_term_id'=>'selections.package_term_id','property_id'=>'selections.property_id','transport_id'=>'selections.transport_id'];
 $routes=apply_filters('etg_lab_preview_booking_routes',$routes);
 $allowed=['booking_mode','group_size','tier_id','package_pricing_apply','selections.season_term_id','selections.package_term_id','selections.property_id','selections.transport_id'];
 $config=[];
 foreach($values as $key=>$value){
  if(!array_key_exists($key,$routes)){
   if(!in_array($key,['tour_id','source','rate_id','persons','travel_date','audience','occupancy'],true))return new WP_Error('preview_booking_route_missing',$key);
   continue;
  }
  $path=$routes[$key];
  if(!is_string($path)||!in_array($path,$allowed,true))return new WP_Error('preview_booking_route_not_allowed',$key);
  if(str_starts_with($path,'selections.'))$config['selections'][substr($path,11)]=$value;
  else $config[$path]=$value;
 }
 return $config;
}

add_action('wp_ajax_etg_lab_preview_choices', static function(){
 if(!current_user_can('manage_options'))wp_send_json_error(['code'=>'forbidden'],403);
 check_ajax_referer('etg_lab_pricing_preview','nonce');
 $tour=absint($_POST['tour_id']??0);$rate=absint($_POST['rate_id']??0);
 $search=isset($_POST['tour_query'])&&is_string($_POST['tour_query'])?sanitize_text_field(wp_unslash($_POST['tour_query'])):'';
 $search=mb_substr($search,0,90);
 $args=['post_type'=>'tours-and-activities','post_status'=>'publish','numberposts'=>100,
   'orderby'=>'modified','order'=>'DESC','fields'=>'ids','suppress_filters'=>true];
 if($search!==''&&!ctype_digit($search))$args['s']=$search;
 $tours=get_posts($args);
 if(ctype_digit($search)&&get_post_type((int)$search)==='tours-and-activities'&&get_post_status((int)$search)==='publish')
   array_unshift($tours,(int)$search);
 if($tour&&get_post_type($tour)==='tours-and-activities'&&get_post_status($tour)==='publish')
   array_unshift($tours,$tour);
 $tours=array_values(array_unique(array_map('intval',$tours)));
 $choices=['tour_id'=>array_map(static fn($id)=>['id'=>(int)$id,'name'=>get_the_title($id)],$tours),'rate_id'=>[],'tier_id'=>[],
  'package_term_id'=>[],'season_term_id'=>[],'booking_mode'=>[['id'=>'','name'=>'Automatic']],'property_id'=>[],'transport_id'=>[]];
 if($tour && get_post_type($tour)==='tours-and-activities'){
  $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','numberposts'=>60,'fields'=>'ids','meta_query'=>[['key'=>'related_tour_id','value'=>(string)$tour,'compare'=>'=']]]);
  $choices['rate_id']=array_map(static fn($id)=>['id'=>(int)$id,'name'=>get_the_title($id)],$rates);
  $tiers=wp_get_post_terms($tour,'group-size-tiers');
  foreach(is_array($tiers)?$tiers:[] as $term)if($term instanceof WP_Term)$choices['tier_id'][]=['id'=>$term->term_id,'name'=>$term->name];
  $modes=shared_product_booking_modes_from_taxonomy($tour);
  if(!is_wp_error($modes))foreach($modes['modes'] as $mode)$choices['booking_mode'][]=['id'=>$mode,'name'=>ucfirst($mode)];
  $packages=wp_get_post_terms($tour,'package_levels');
  if(!is_wp_error($packages))foreach($packages as $t)$choices['package_term_id'][]=['id'=>$t->term_id,'name'=>$t->name];
 }
 if($rate && get_post_type($rate)==='tour-rates' && (int)get_post_meta($rate,'related_tour_id',true)===$tour){
  $seasons=wp_get_post_terms($rate,'season');
  if(is_array($seasons))foreach($seasons as $term)$choices['season_term_id'][]=['id'=>$term->term_id,'name'=>$term->name];
  $related=shared_product_rate_related_options($rate);
  if(!is_wp_error($related)){
   $choices['property_id']=array_map(static fn($row)=>['id'=>$row['id'],'name'=>$row['name']],$related['properties']);
   foreach($related['transportation'] as $rows)foreach($rows as $row)$choices['transport_id'][]=['id'=>$row['id'],'name'=>$row['name']];
  }
 }
 wp_send_json_success(['choices'=>$choices,'note'=>'Only linked published options are listed. Absence means not configured.']);
});
