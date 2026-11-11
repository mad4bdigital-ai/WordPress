<?php
/**
 * MAD4B Source Component Option Relations v1
 * A published, commerce-approved source child may be related to its Tour.
 * Does not create rates, grant bookings or mark draft services as available.
 */
defined('ABSPATH') || exit;
final class MAD4B_Source_Component_Options_V1 {
 const CONTRACT='mad4b.source-component-options.v1';
 const REVIEW='_mad4b_source_component_option_approved_v1';
 const RECEIPT='_mad4b_source_component_option_links_v1';
 private static function err($code) {return new WP_Error('mad4b_source_option_'.$code);}
 private static function gate($tour) {
  return defined('WP_CLI') && WP_CLI &&
   wp_get_environment_type()==='staging' &&
   current_user_can('manage_options') &&
   current_user_can('edit_post',$tour) &&
   get_post_type($tour)==='tours-and-activities' &&
   in_array(get_post_status($tour),['draft','publish'],true) &&
   class_exists('MAD4B_SCP_Site_Profile') &&
   MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
 }
 private static function relation($kind,$mode) {
  if($kind==='extra_trip')return ['field'=>'tour_optional_extras',
   'status'=>'tour_extras_status','required_post_type'=>'tours-and-activities',
   'mode'=>'extra_trip'];
  if($kind!=='transfer')return null;
  $names=['vehicle'=>['vehicles_transport_services','tour_vehicles_transport_status'],
   'train'=>['train_transport_services','tour_train_transport_status'],
   'air'=>['air_transport_services','tour_air_transport_status']];
  if(!isset($names[$mode]))return null;
  return ['field'=>$names[$mode][0],'status'=>$names[$mode][1],
   'required_post_type'=>'transportations','mode'=>$mode];
 }
 public static function plan($tour_id,$component_id) {
  $tour_id=(int)$tour_id;$component_id=(int)$component_id;
  if(!self::gate($tour_id) || !current_user_can('edit_post',$component_id))
   return self::err('site_authority_missing');
  if(!class_exists('MAD4B_Source_Components_V1') ||
     !class_exists('MAD4B_Scraping_Source_Profiles_V1'))
   return self::err('source_module_missing');
  $child=get_post($component_id);
  if(!$child || $child->post_status!=='publish')
   return self::err('component_not_published');
  if((int)get_post_meta($component_id,MAD4B_Source_Components_V1::OWNER,true)!==$tour_id)
   return self::err('component_not_owned_by_parent_tour');
  $proof=get_post_meta($component_id,MAD4B_Source_Components_V1::PROOF,true);
  if(!is_array($proof) || ($proof['contract']??'')!=='mad4b.source-components.v1.provenance' ||
     ($proof['source_url']??'')!==
      (string)get_post_meta($tour_id,'_mad4b_source_content_loop_url_v1',true) ||
     empty($proof['fields']['optional']) || !empty($proof['fields']['included']))
   return self::err('optional_source_provenance_required');
  $kind=(string)($proof['kind']??'');
  $field=(string)($proof['fields']['mode']??'');
  $rel=self::relation($kind,$field);
  if(!$rel || $child->post_type!==$rel['required_post_type'])
   return self::err('unsupported_optional_relation');
  $source_profile=MAD4B_Scraping_Source_Profiles_V1::resolve($proof['source_url']);
  $source_field=$kind==='extra_trip'?'extra_trips':'transfers';
  if(is_wp_error($source_profile) ||
     !MAD4B_Scraping_Source_Profiles_V1::field_allowed(
       $source_profile,$source_field))
   return self::err('source_profile_option_excluded');
  if(!in_array((string)($source_profile['field_trust'][$source_field]??'D'),
     ['A','B'],true))return self::err('source_profile_option_trust_insufficient');
  if(get_post_meta($component_id,self::REVIEW,true)!=='1')
   return self::err('original_commerce_approval_required');
  if($kind==='extra_trip') {
   if(!in_array(get_post_meta($component_id,'tour_mode',true),
        ['activity','extra_trip'],true))
    return self::err('extra_trip_mode_invalid');
   $price=get_post_meta($component_id,'start_price',true);
   $currency=get_post_meta($component_id,'tour_base_currency',true);
   if(!is_scalar($price)||!is_numeric($price)||(float)$price<=0 ||
      !is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))
    return self::err('commercial_extra_price_required');
  } elseif((string)get_post_meta($component_id,'trans_available_status',true)!=='1')
   return self::err('transport_provider_availability_required');
  $raw=get_post_meta($tour_id,$rel['field'],true);
  if($raw==='' || $raw===null)$raw=[];
  if(is_string($raw))$raw=maybe_unserialize($raw);
  if(!is_array($raw)||count($raw)>60)return self::err('relation_meta_unexpected');
  $existing=[];
  foreach($raw as $v) {
   if(!is_scalar($v)||!ctype_digit((string)$v))return self::err('relation_id_format_unexpected');
   $existing[]=(int)$v;
  }
  $existing=array_values(array_unique($existing));
  $planned=array_values(array_unique(array_merge($existing,[$component_id])));
  if(count($planned)>60)return self::err('too_many_related_options');
  $snapshot=[
   'contract'=>self::CONTRACT.'.plan',
   'tour_id'=>$tour_id,'component_id'=>$component_id,
   'kind'=>$kind,'mode'=>$rel['mode'],
   'field'=>$rel['field'],'status_field'=>$rel['status'],
   'current_ids'=>$existing,'planned_ids'=>$planned,
   'current_relation_sha256'=>hash('sha256',wp_json_encode($raw)),
   'source_profile_sha256'=>$source_profile['profile_digest'],
   'component_modified_gmt'=>$child->post_modified_gmt,
   'tour_modified_gmt'=>get_post($tour_id)->post_modified_gmt,
   'source_provenance_sha256'=>hash('sha256',wp_json_encode($proof)),
   'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid()
  ];
  $sha=hash('sha256',wp_json_encode($snapshot,
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $snapshot+['plan_sha256'=>$sha,
   'state'=>in_array($component_id,$existing,true)?'ALREADY_LINKED':'READY_FOR_EXACT_APPLY',
   'mutation_performed'=>false];
 }
 public static function apply($tour,$child,$expected,$confirmation) {
  if($confirmation!=='LINK VERIFIED OPTIONAL SOURCE COMPONENT')
   return self::err('confirmation_required');
  $p=self::plan($tour,$child);
  if(is_wp_error($p))return $p;
  if(!preg_match('/^[a-f0-9]{64}$/D',(string)$expected) ||
     !hash_equals($p['plan_sha256'],(string)$expected))
   return self::err('binding_plan_stale');
  if($p['state']==='ALREADY_LINKED')
   return ['contract'=>self::CONTRACT.'.apply','state'=>'ALREADY_LINKED',
    'post_id'=>(int)$tour,'component_id'=>(int)$child];
  update_post_meta((int)$tour,$p['field'],$p['planned_ids']);
  update_post_meta((int)$tour,$p['status_field'],'1');
  $read=get_post_meta((int)$tour,$p['field'],true);
  if($read!==$p['planned_ids'])
   return self::err('relation_readback_failed');
  $receipt=['contract'=>self::CONTRACT.'.apply',
   'state'=>'LINKED','post_id'=>(int)$tour,
   'component_id'=>(int)$child,'kind'=>$p['kind'],'mode'=>$p['mode'],
   'meta_key'=>$p['field'],'linked_ids'=>$read,
   'plan_sha256'=>$expected,
   'source_profile_sha256'=>$p['source_profile_sha256'],
   'rate_created'=>false,'booking_authority_granted'=>false,
   'applied_at'=>gmdate('c')];
  update_post_meta((int)$tour,self::RECEIPT,$receipt);
  return $receipt;
 }
}
if(defined('WP_CLI') && WP_CLI) {
 WP_CLI::add_command('mad4b-component-options',new class {
  private function show($r) {
   if(is_wp_error($r))WP_CLI::error($r->get_error_code());
   WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  }
  public function plan($a,$o) {
   $this->show(MAD4B_Source_Component_Options_V1::plan(
    (int)($a[0]??0),(int)($a[1]??0)));
  }
  public function apply($a,$o) {
   $this->show(MAD4B_Source_Component_Options_V1::apply(
    (int)($a[0]??0),(int)($a[1]??0),
    (string)($o['expected-plan']??''),(string)($o['confirm']??'')));
  }
 });
}
