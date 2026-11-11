<?php
/**
 * MAD4B Tour Archetype Registry v1.
 *
 * Declarative, site-portable tour family profiles. The Source Profile
 * decides what can be read; the Archetype describes entities/relationships;
 * native Content/Commerce authorization decides which writes are permitted.
 *
 * No autonomous publication, rate creation or Property provisioning here.
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Archetype_Registry_V1 {
 const CONTRACT='mad4b.tour-archetype-registry.v1';
 const META='_mad4b_tour_archetype_selection_v1';
 const META_REVIEW='_mad4b_tour_archetype_review_v1';

 private static function common() {
  return [
   'relationship_mode'=>'none',
   'property_required'=>false,
   'rate_policy'=>'positive_evidenced_rate_only',
   'publication_policy'=>'original_content_and_commerce_authority',
   'price_without_evidence'=>'price_pending_not_rate',
   'auto_publish'=>false,
   'auto_booking'=>false,
   'source_mapping'=>'source_profile_field_allowlist',
   'override_policy'=>'tighten_only'
  ];
 }
 public static function definitions() {
  $common=self::common();
  $profiles=[
   'lake_nasser_cruise'=>[
    'post_type'=>'tours-and-activities',
    'tour_type_slugs'=>['lake-nasser-cruises'],
    'property_type_slugs'=>['lake-nasser-cruises','cruise'],
    'relationship_mode'=>'single_vessel_property',
    'property_required'=>true,
    'amenity_policy'=>'source_evidenced_property_amenities',
    'inventory_policy'=>'vessel_units_separately_verified',
    'content_subtitle'=>'Lake Nasser sailing itinerary'
   ],
   'felucca_cruise'=>[
    'post_type'=>'tours-and-activities',
    'tour_type_slugs'=>['felucca-nile-cruise'],
    'property_type_slugs'=>['felucca-nile-cruise','boat'],
    'relationship_mode'=>'single_vessel_property',
    'property_required'=>true,
    'amenity_policy'=>'source_evidenced_property_amenities',
    'inventory_policy'=>'vessel_units_separately_verified',
    'content_subtitle'=>'Felucca sailing itinerary'
   ],
   'nile_cruise'=>[
    'post_type'=>'tours-and-activities',
    'tour_type_slugs'=>[
     'dahabiya-nile-cruise','nile-cruises','deluxe-nile-cruises',
     'luxury-nile-cruises','standard-nile-cruises',
     'ultra-deluxe-nile-cruises','ultra-luxury-nile-cruises'],
    'property_type_slugs'=>['cruise','nile-cruise','dahabiya-nile-cruise'],
    'relationship_mode'=>'single_vessel_property',
    'property_required'=>true,
    'amenity_policy'=>'source_evidenced_property_amenities',
    'inventory_policy'=>'ship_and_cabin_variant_confirmation',
    'content_subtitle'=>'Nile sailing itinerary',
    'integrator'=>'MAD4B_Tour_Archetype_Property_V1'
   ],
   'combined_tour'=>[
    'post_type'=>'tours-and-activities',
    'tour_type_slugs'=>['combined-tours'],
    'property_type_slugs'=>[],
    'relationship_mode'=>'multi_stage_property_ids',
    'property_required'=>false,
    'amenity_policy'=>'per_stage_not_global',
    'inventory_policy'=>'stage_specific_accommodation_assignment',
    'content_subtitle'=>'Multi-destination travel program'
   ],
   'multi_day_package'=>[
    'post_type'=>'tours-and-activities',
    'tour_type_slugs'=>['packages'],
    'property_type_slugs'=>[],
    'relationship_mode'=>'multi_stage_property_ids',
    'property_required'=>false,
    'amenity_policy'=>'per_property_not_tour_global',
    'inventory_policy'=>'rate_and_stay_stage_assignment',
    'content_subtitle'=>'Multi-day travel program'
   ],
   'day_tour'=>[
    'post_type'=>'tours-and-activities',
    'tour_type_slugs'=>['half-day','day-tours'],
    'property_type_slugs'=>[],
    'relationship_mode'=>'no_property_by_default',
    'property_required'=>false,
    'amenity_policy'=>'tour_only_unless_verified_transport_or_vessel',
    'inventory_policy'=>'activity_schedule_capacity',
    'content_subtitle'=>'Day tour itinerary'
   ],
   'cruise_property'=>[
    'post_type'=>'properties',
    'tour_type_slugs'=>[],
    'property_type_slugs'=>['dahabiya-nile-cruise','nile-cruise',
     'cruise','felucca-nile-cruise','lake-nasser-cruises'],
    'relationship_mode'=>'property_to_unit_to_rate',
    'property_required'=>true,
    'amenity_policy'=>'source_evidenced_property_amenities',
    'inventory_policy'=>'vessel_and_cabin_unit_confirmation',
    'content_subtitle'=>'Cruise vessel and onboard amenities'
   ],
   'stay_property'=>[
    'post_type'=>'properties',
    'tour_type_slugs'=>[],
    'property_type_slugs'=>['hotel','resort','apartment','villa','vila','hostel'],
    'relationship_mode'=>'property_to_unit_to_rate',
    'property_required'=>true,
    'amenity_policy'=>'property_amenities',
    'inventory_policy'=>'unit_type_and_stay_availability',
    'content_subtitle'=>'Accommodation'
   ]
  ];
  foreach($profiles as &$profile) {
   $profile=array_replace($common,$profile);
   $profile['components']=[
    'property'=>in_array($profile['relationship_mode'],
      ['single_vessel_property','multi_stage_property_ids',
       'property_to_unit_to_rate'],true),
    'units'=>in_array($profile['relationship_mode'],
      ['single_vessel_property','multi_stage_property_ids',
       'property_to_unit_to_rate'],true),
    'transfers'=>$profile['post_type']==='tours-and-activities',
    'extra_trips'=>$profile['post_type']==='tours-and-activities'
   ];
   $profile['component_creation_policy']='evidence_qualified_draft_only';
   $profile['included_services_policy']='capture_inclusion_not_paid_extra';
  }
  unset($profile);
  // Only trusted first-party code can add families. Never load executable
  // policy from remote scraping HTML or a company's source text.
  $profiles=apply_filters('mad4b_tour_archetype_registry_definitions_v1',$profiles);
  if(!is_array($profiles) || count($profiles)>30)return [];
  $result=[];
  foreach($profiles as $id=>$config) {
   if(!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{2,48}$/D',$id) ||
      !is_array($config) || !in_array(($config['post_type']??''),
       ['tours-and-activities','properties'],true) ||
      !in_array(($config['relationship_mode']??''),
       ['none','single_vessel_property','multi_stage_property_ids',
        'no_property_by_default','property_to_unit_to_rate'],true))continue;
   $result[$id]=$config;
  }
  return $result;
 }
 public static function classify_slugs($slugs,$post_type='tours-and-activities') {
  if(!is_array($slugs) ||
     !in_array($post_type,['tours-and-activities','properties'],true))
   return ['family'=>'unclassified','state'=>'invalid_taxonomy_input'];
  $profiles=self::definitions();
  foreach($profiles as $key=>$profile) {
   if($profile['post_type']!==$post_type)continue;
   $expected=$post_type==='properties'
    ?(array)($profile['property_type_slugs']??[])
    :(array)($profile['tour_type_slugs']??[]);
   if(array_intersect($slugs,$expected))
    return ['family'=>$key,'state'=>'matched',
      'relationship_mode'=>$profile['relationship_mode'],
      'content_subtitle'=>$profile['content_subtitle'],
      'profile_sha256'=>hash('sha256',wp_json_encode($profile,
       JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))];
  }
  return ['family'=>'unclassified','state'=>'manual_type_profile_review'];
 }
 private static function err($code) {
  return new WP_Error('mad4b_archetype_registry_'.$code);
 }
 public static function select($post_id) {
  $id=(int)$post_id;
  $post=get_post($id);
  if(!$post || !in_array($post->post_type,['tours-and-activities','properties'],true))
   return self::err('target_invalid');
  $profiles=self::definitions();
  $taxonomy=$post->post_type==='properties'?'property-types':'tour-types';
  $slugs=wp_get_post_terms($id,$taxonomy,['fields'=>'slugs']);
  if(is_wp_error($slugs))return self::err('taxonomy_unavailable');
  $candidates=[];
  foreach($profiles as $key=>$profile) {
   if($profile['post_type']!==$post->post_type)continue;
   $terms=$post->post_type==='properties'
    ?(array)($profile['property_type_slugs']??[])
    :(array)($profile['tour_type_slugs']??[]);
   $matches=array_values(array_intersect($slugs,$terms));
   if(!$matches)continue;
   $candidates[]=['id'=>$key,'matched_slugs'=>$matches,
    'priority'=>count($profiles)-array_search($key,array_keys($profiles),true)];
  }
  if(!$candidates)return [
   'contract'=>self::CONTRACT.'.selection','post_id'=>$id,
   'post_type'=>$post->post_type,'taxonomy'=>$taxonomy,'taxonomy_slugs'=>$slugs,
   'family'=>'unclassified','state'=>'manual_type_profile_review',
   'relationship_mode'=>'none','property_required'=>false,
   'profile_digest'=>'','candidates'=>[]
  ];
  usort($candidates,static fn($a,$b)=>$b['priority']<=>$a['priority']);
  $winner=$candidates[0];
  $family=$winner['id'];
  $profile=$profiles[$family];
  $result=[
   'contract'=>self::CONTRACT.'.selection','post_id'=>$id,
   'post_type'=>$post->post_type,'taxonomy'=>$taxonomy,
   'taxonomy_slugs'=>$slugs,
   'family'=>$family,'state'=>'matched',
   'matched_slugs'=>$winner['matched_slugs'],
   'relationship_mode'=>$profile['relationship_mode'],
   'property_required'=>(bool)$profile['property_required'],
   'source_mapping'=>$profile['source_mapping'],
   'amenity_policy'=>$profile['amenity_policy'],
   'inventory_policy'=>$profile['inventory_policy'],
   'rate_policy'=>$profile['rate_policy'],
   'publication_policy'=>$profile['publication_policy'],
   'content_subtitle'=>$profile['content_subtitle'],
   'profile_digest'=>hash('sha256',wp_json_encode($profile,
     JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
   'candidates'=>array_column($candidates,'id')
  ];
  return $result;
 }
 public static function inspect($post_id) {
  $selection=self::select($post_id);
  if(is_wp_error($selection))return $selection;
  $id=(int)$post_id;
  $source=(string)get_post_meta($id,
   '_mad4b_source_content_loop_url_v1',true);
  $profile=$source && class_exists('MAD4B_Scraping_Source_Profiles_V1')
   ?MAD4B_Scraping_Source_Profiles_V1::resolve($source):null;
  if(is_wp_error($profile))$profile=null;
  $property_id=(int)get_post_meta($id,
   '_mad4b_tour_archetype_property_id_v1',true);
  $property_valid=$property_id && get_post_type($property_id)==='properties'
    && get_post_status($property_id)==='publish';
  $mode=$selection['relationship_mode'];
  $decision=$selection['state']==='matched'?'type_recognized_no_mutation':
   'type_review_required';
  if($selection['state']==='matched') {
   if($mode==='single_vessel_property')
    $decision=$property_valid?'verified_existing_property_binding':
      'exact_property_resolution_required';
   elseif($mode==='multi_stage_property_ids')
    $decision='rate_stay_stage_mapping_required';
   elseif($mode==='no_property_by_default')
    $decision='activity_only_no_property_creation';
   elseif($mode==='property_to_unit_to_rate')
    $decision='unit_inventory_requires_separate_proof';
  }
  $source_allowed=$profile && ($profile['profile_state']??'')==='enrolled';
  $amenities_allowed=$source_allowed &&
    MAD4B_Scraping_Source_Profiles_V1::field_allowed($profile,'amenities');
  $result=[
   'contract'=>self::CONTRACT.'.inspection',
   'post_id'=>$id,'post_status'=>get_post_status($id),
   'selection'=>$selection,'source_url'=>$source,
   'source_profile_id'=>$profile['source_profile_id']??'',
   'source_profile_digest'=>$profile['profile_digest']??'',
   'amenities_source_allowed'=>(bool)$amenities_allowed,
   'property_id'=>$property_valid?$property_id:0,
   'property_binding_verified'=>(bool)$property_valid,
   'decision'=>$decision,
   'autocreate_property'=>false,'autocreate_rate'=>false,
   'auto_publish'=>false,'auto_booking'=>false,
   'meta_snapshot'=>get_post_meta($id,self::META,true)
  ];
  return $result;
 }
 private static function can_sync($id) {
  return defined('WP_CLI') && WP_CLI &&
   wp_get_environment_type()==='staging' &&
   class_exists('MAD4B_SCP_Site_Profile') &&
   MAD4B_SCP_Site_Profile::site_urls_match_enrollment() &&
   current_user_can('edit_post',$id) &&
   get_post_status($id)==='draft' &&
   get_post_type($id)==='tours-and-activities';
 }
 public static function sync($post_id) {
  $id=(int)$post_id;
  if(!self::can_sync($id))return self::err('staging_admin_draft_required');
  $result=self::inspect($id);
  if(is_wp_error($result))return $result;
  if(!$result['source_url'] || !$result['source_profile_digest'])
   return self::err('enrolled_source_profile_required');
  if($result['selection']['state']!=='matched')
   return self::err('tour_family_unclassified');
  $normalized=[
   'contract'=>self::CONTRACT.'.sync',
   'post_id'=>$id,
   'family'=>$result['selection']['family'],
   'profile_sha256'=>$result['selection']['profile_digest'],
   'source_profile_sha256'=>$result['source_profile_digest'],
   'relationship_mode'=>$result['selection']['relationship_mode'],
   'source_url_sha256'=>hash('sha256',$result['source_url']),
   'property_id'=>$result['property_id'],
   'decision'=>$result['decision'],
   'updated_at'=>gmdate('c'),
   'publication_granted'=>false
  ];
  $existing=get_post_meta($id,self::META,true);
  $compare=$normalized;unset($compare['updated_at']);
  $prior=is_array($existing)?$existing:[];
  unset($prior['updated_at']);
  if($prior===$compare)
   return ['state'=>'UNCHANGED','post_id'=>$id,'snapshot'=>$existing];
  update_post_meta($id,self::META,$normalized);
  $saved=get_post_meta($id,self::META,true);
  if(!is_array($saved) ||
     ($saved['profile_sha256']??'')!==$normalized['profile_sha256'])
   return self::err('sync_readback_failed');
  return ['state'=>'SYNCED','post_id'=>$id,'snapshot'=>$saved];
 }
 public static function catalog() {
  $profiles=self::definitions();
  return ['contract'=>self::CONTRACT.'.catalog',
   'profiles'=>array_map(static fn($p)=>[
    'entity'=>$p['post_type'],
    'relationship_mode'=>$p['relationship_mode'],
    'property_required'=>$p['property_required'],
    'tour_type_slugs'=>$p['tour_type_slugs']??[],
    'property_type_slugs'=>$p['property_type_slugs']??[],
    'rate_policy'=>$p['rate_policy'],
    'components'=>$p['components']??[],
    'component_creation_policy'=>$p['component_creation_policy']??'',
    'publication_policy'=>$p['publication_policy']
   ],$profiles),
   'family_count'=>count($profiles),
   'authority'=>'unchanged_native_content_and_commerce',
   'company_property_ids_shared'=>false];
 }
}
if(defined('WP_CLI') && WP_CLI) {
 WP_CLI::add_command('mad4b-tour-archetypes',new class {
  private function show($result) {
   if(is_wp_error($result))WP_CLI::error($result->get_error_code());
   WP_CLI::line(wp_json_encode($result,
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  }
  public function catalog($a,$o) {
   $this->show(MAD4B_Tour_Archetype_Registry_V1::catalog());
  }
  public function inspect($a,$o) {
   $this->show(MAD4B_Tour_Archetype_Registry_V1::inspect((int)($a[0]??0)));
  }
  public function sync($a,$o) {
   $this->show(MAD4B_Tour_Archetype_Registry_V1::sync((int)($a[0]??0)));
  }
 });
}
add_action('wp_abilities_api_init',static function() {
 if(!function_exists('wp_register_ability')||!function_exists('wp_has_ability') ||
    wp_has_ability('mad4b/tour-archetype-inspect'))return;
 wp_register_ability('mad4b/tour-archetype-inspect',[
  'label'=>'Inspect Tour Archetype',
  'description'=>'Read resolved tour family, Property relations and original commercial gates.',
  'category'=>'mad4b-read',
  'execute_callback'=>static function($input) {
   return MAD4B_Tour_Archetype_Registry_V1::inspect((int)($input['post_id']??0));
  },
  'permission_callback'=>static function() {
   return class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_read();
  },
  'input_schema'=>['type'=>'object','properties'=>[
   'post_id'=>['type'=>'integer','minimum'=>1]],
    'required'=>['post_id'],'additionalProperties'=>false],
  'output_schema'=>['type'=>'object','additionalProperties'=>true],
  'meta'=>['public'=>false,'show_in_rest'=>false,
   'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
   'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
 ]);
},95);
