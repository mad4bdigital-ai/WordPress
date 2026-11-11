<?php
/**
 * MAD4B Tour Archetype -> Property mapping v1.
 * Type Profile defines entity relationships; Source Profile controls trust;
 * policy controls mutability; the same source loop orchestrates each type.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Archetype_Property_V1 {
 const CONTRACT='mad4b.tour-archetype-property.v1';
 const BIND='_mad4b_tour_archetype_property_id_v1';
 const SOURCE='_mad4b_tour_archetype_property_source_url_v1';
 const PROFILE='_mad4b_tour_archetype_property_profile_v1';
 const RECEIPT='_mad4b_tour_archetype_property_receipt_v1';
 const AMENITY_SOURCE='_mad4b_property_amenity_source_evidence_v1';
 const TOUR_TYPE='nile_cruise';
 public static function profiles() {
  $cruise=['contract'=>self::CONTRACT,'type'=>self::TOUR_TYPE,
   'tour_taxonomy'=>'tour-types',
   'tour_slugs'=>['nile-cruises','dahabiya-nile-cruise'],
   'property_type_slugs'=>['cruise','nile-cruise','dahabiya-nile-cruise'],
   'property_matching'=>'exact_enrolled_source_binding_reuse_first',
   'rate_property_meta'=>'related_properties_id',
   'rate_property_format'=>'array_of_ids',
   'amenities_taxonomy'=>'amenities',
   'price_gate'=>'priced_rate_only',
   'vessel_variant_gate'=>'separate_vessel_inventory_confirmation',
   'publish_authority'=>'original_company_content_and_commerce_preflight'];
  $catalog=class_exists('MAD4B_Tour_Archetype_Registry_V1')
   ?MAD4B_Tour_Archetype_Registry_V1::definitions():[];
  if(!is_array($catalog))$catalog=[];
  // Backwards-compatible Cruise API; families are defined in the shared registry.
  $catalog[self::TOUR_TYPE]=array_replace($cruise,
    (array)($catalog[self::TOUR_TYPE]??[]));
  return apply_filters('mad4b_tour_archetype_profiles_registry_v1',$catalog);
 }
 public static function profile($type=self::TOUR_TYPE) {
  $profiles=self::profiles();
  return is_array($profiles) && is_array($profiles[$type]??null)
   ?$profiles[$type]:null;
 }
 private static function err($name) {return new WP_Error('mad4b_archetype_property_'.$name);}
 const LOCAL_BINDINGS='mad4b_tour_archetype_local_property_bindings_v1';
 private static function exact_bindings() {
  if(!class_exists('MAD4B_SCP_Site_Profile') ||
    !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())return [];
  $site=MAD4B_SCP_Site_Profile::profile();
  $rows=get_option(self::LOCAL_BINDINGS,[]);
  if(!is_array($rows))return [];
  $out=[];
  foreach($rows as $source=>$binding) {
   if(!is_array($binding)|| !is_string($source) ||
     ($binding['site_uuid']??'')!==($site['site_uuid']??'') ||
     rtrim((string)($binding['site_origin']??''),'/')!==
      rtrim((string)($site['canonical_origin']??''),'/') ||
     !wp_http_validate_url($source) ||
     (string)wp_parse_url($source,PHP_URL_SCHEME)!=='https')continue;
   $out[$source]=[
    'property_id'=>(int)($binding['property_id']??0),
    'expected_slug'=>(string)($binding['expected_slug']??''),
    'expected_title'=>(string)($binding['expected_title']??'')
   ];
  }
  return $out;
 }
 public static function binding_plan($tour_id,$property_id) {
  $tour_id=(int)$tour_id;$property_id=(int)$property_id;
  $source=self::site_gate($tour_id);
  if(is_wp_error($source))return $source;
  if(!current_user_can('manage_options') ||
    !current_user_can('edit_post',$property_id))
   return self::err('property_binding_admin_required');
  $profile=self::source_profile($source);
  if(is_wp_error($profile))return $profile;
  $post=get_post($property_id);
  if(!$post || $post->post_type!=='properties' ||
     $post->post_status!=='publish')return self::err('binding_property_unavailable');
  $types=wp_get_post_terms($property_id,'property-types',['fields'=>'slugs']);
  if(is_wp_error($types)||!in_array('nile-cruise',$types,true)||
     !in_array('dahabiya-nile-cruise',$types,true))
   return self::err('binding_property_type_invalid');
  $site=MAD4B_SCP_Site_Profile::profile();
  $rows=get_option(self::LOCAL_BINDINGS,[]);
  if(!is_array($rows))return self::err('binding_registry_corrupt');
  $prior=$rows[$source]??null;
  if($prior && (int)($prior['property_id']??0)!==$property_id)
   return self::err('binding_conflicting_previous_identity');
  $payload=[
   'contract'=>self::CONTRACT.'.binding-plan',
   'tour_id'=>$tour_id,
   'tour_modified_gmt'=>get_post($tour_id)->post_modified_gmt,
   'tour_source'=>$source,
   'source_profile_digest'=>$profile['profile_digest'],
   'property_id'=>$property_id,
   'property_slug'=>$post->post_name,
   'property_title'=>$post->post_title,
   'property_modified_gmt'=>$post->post_modified_gmt,
   'site_uuid'=>$site['site_uuid']??'',
   'site_origin'=>$site['canonical_origin']??'',
   'prior_sha256'=>hash('sha256',wp_json_encode($prior,
     JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))
  ];
  $hash=hash('sha256',wp_json_encode($payload,
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $payload+['plan_sha256'=>$hash,
   'state'=>'READY_FOR_EXACT_BINDING',
   'mutation_performed'=>false];
 }
 public static function binding_apply($tour_id,$property_id,$expected,$confirmation) {
  if($confirmation!=='REGISTER EXACT SOURCE PROPERTY')
   return self::err('binding_confirmation_required');
  $plan=self::binding_plan($tour_id,$property_id);
  if(is_wp_error($plan))return $plan;
  if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||
     !hash_equals($plan['plan_sha256'],$expected))
   return self::err('binding_plan_stale');
  $rows=get_option(self::LOCAL_BINDINGS,[]);
  if(!is_array($rows) || count($rows)>=100)return self::err('binding_registry_capacity');
  $source=$plan['tour_source'];
  $binding=[
   'site_uuid'=>$plan['site_uuid'],'site_origin'=>$plan['site_origin'],
   'property_id'=>$plan['property_id'],
   'expected_slug'=>$plan['property_slug'],
   'expected_title'=>$plan['property_title'],
   'source_profile_sha256'=>$plan['source_profile_digest'],
   'bound_at'=>gmdate('c')
  ];
  $rows[$source]=$binding;
  update_option(self::LOCAL_BINDINGS,$rows,false);
  $saved=(array)get_option(self::LOCAL_BINDINGS,[]);
  if(($saved[$source]??null)!==$binding)
   return self::err('binding_storage_readback_failed');
  return ['contract'=>self::CONTRACT.'.binding-apply',
   'state'=>'SOURCE_PROPERTY_BINDING_VERIFIED',
   'site_uuid'=>$plan['site_uuid'],'tour_id'=>$plan['tour_id'],
   'property_id'=>$plan['property_id'],'source_url'=>$source,
   'expected_plan_sha256'=>$expected,
   'source_profile_sha256'=>$plan['source_profile_digest'],
   'no_tour_rate_or_publication_mutation'=>true];
 }
 private static function site_gate($tour_id) {
  if(!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='staging' ||
     !current_user_can('edit_post',$tour_id) ||
     !current_user_can('manage_categories') ||
     !class_exists('MAD4B_SCP_Site_Profile') ||
     !MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
     get_post_type($tour_id)!=='tours-and-activities' ||
     get_post_status($tour_id)!=='draft')return self::err('site_or_editor_gate');
  $terms=wp_get_post_terms($tour_id,'tour-types',['fields'=>'slugs']);
  if(is_wp_error($terms))return self::err('tour_taxonomy_unreadable');
  if(!in_array('nile-cruises',$terms,true) &&
     !in_array('dahabiya-nile-cruise',$terms,true))
   return self::err('not_nile_cruise');
  $source=(string)get_post_meta($tour_id,'_mad4b_source_content_loop_url_v1',true);
  if(!$source || !wp_http_validate_url($source))return self::err('source_url_missing');
  return $source;
 }
 private static function candidate($source) {
  $records=apply_filters('mad4b_tour_archetype_property_source_bindings_v1',
    self::exact_bindings());
  if(!is_array($records) || !is_array($records[$source]??null))
   return self::err('property_binding_not_enrolled');
  $b=$records[$source];$id=(int)($b['property_id']??0);
  $post=$id?get_post($id):null;
  if(!$post || $post->post_type!=='properties' || $post->post_status!=='publish' ||
     $post->post_name!==($b['expected_slug']??'') ||
     $post->post_title!==($b['expected_title']??''))
    return self::err('property_identity_mismatch');
  $types=wp_get_post_terms($id,'property-types',['fields'=>'slugs']);
  if(is_wp_error($types)||!in_array('nile-cruise',$types,true)||
    !in_array('dahabiya-nile-cruise',$types,true))
   return self::err('property_type_mismatch');
  $previous=(string)get_post_meta($id,'_mad4b_property_amenity_source_url_v1',true);
  if($previous && $previous!==$source)return self::err('property_source_conflict');
  return $post;
 }
 private static function source_profile($source) {
  if(!class_exists('MAD4B_Scraping_Source_Profiles_V1'))
   return self::err('scraping_profiles_missing');
  $profile=MAD4B_Scraping_Source_Profiles_V1::resolve($source);
  if(is_wp_error($profile) || ($profile['profile_state']??'')!=='enrolled')
   return self::err('scraping_profile_unregistered');
  if(!MAD4B_Scraping_Source_Profiles_V1::field_allowed($profile,'amenities'))
   return self::err('amenities_excluded_by_source_profile');
  $tier=(string)($profile['field_trust']['amenities']??'D');
  if(!in_array($tier,['A','B'],true))return self::err('amenity_confidence_insufficient');
  return $profile;
 }
 private static function facilities($source) {
  $host=strtolower((string)wp_parse_url($source,PHP_URL_HOST));
  if($host!=='www.memphistours.com' && $host!=='memphistours.com')
   return self::err('facilities_provider_adapter_missing');
  $res=wp_safe_remote_get($source,['timeout'=>32,'redirection'=>2,
   'limit_response_size'=>1000000,'sslverify'=>true,
   'headers'=>['Accept'=>'text/html','User-Agent'=>'MAD4B-Source-Intake/1.0']]);
  if(is_wp_error($res))return self::err('source_fetch_failed');
  $html=(string)wp_remote_retrieve_body($res);
  if((int)wp_remote_retrieve_response_code($res)!==200 || strlen($html)<9000)
   return self::err('source_html_not_ready');
  $dom=new DOMDocument();
  if(!@$dom->loadHTML('<?xml encoding="utf-8"?>'.$html,
   LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))
   return self::err('source_dom_invalid');
  $xp=new DOMXPath($dom);
  $list=$xp->query("//div[@id='cruise' and contains(concat(' ', normalize-space(@class), ' '), ' program_box_desc ')]//li");
  if(!$list || $list->length<8 || $list->length>70)
   return self::err('source_cruise_facilities_section_missing');
  $lines=[];
  foreach($list as $li) {
   $line=trim((string)preg_replace('/\s+/u',' ',html_entity_decode(
    (string)$li->textContent,ENT_QUOTES|ENT_HTML5)));
   if($line!=='' && strlen($line)<250)$lines[]=$line;
  }
  // Canonical exact amenity meanings, never infer pool, free Wi-Fi or
  // higher cabin capacity from nearby cross-sell or itinerary prose.
  $dictionary=[
   'onboard-dining'=>['label'=>'Onboard Dining','pattern'=>'/dining facilities/i'],
   'onboard-bar'=>['label'=>'Onboard Bar','pattern'=>'/\bbars?\b/i'],
   'reading-lounge'=>['label'=>'Reading Lounge','pattern'=>'/reading lounge/i'],
   'open-air-jacuzzi'=>['label'=>'Open-air Jacuzzi','pattern'=>'/open.air jacuzzi/i'],
   'laundry-service-on-site'=>['label'=>'Laundry service (on-site)','pattern'=>'/same day laundry service/i'],
   'doctor-on-call'=>['label'=>'Doctor on Call','pattern'=>'/doctor on call 24 hours/i'],
   'internet-access-paid'=>['label'=>'Internet Access (Paid)','pattern'=>'/internet access \(chargeable\)/i'],
   'gift-shop'=>['label'=>'Gift shop','pattern'=>'/boutique and gift shop/i']
  ];
  $amenities=[];
  foreach($dictionary as $slug=>$spec) {
   foreach($lines as $line) {
    if(!preg_match($spec['pattern'],$line))continue;
    $amenities[$slug]=['slug'=>$slug,'label'=>$spec['label'],
     'source_line'=>$line,'source_line_sha256'=>hash('sha256',$line)];
    break;
   }
  }
  if(count($amenities)<6)return self::err('facility_evidence_insufficient');
  return ['source_html_sha256'=>hash('sha256',$html),
   'section_sha256'=>hash('sha256',wp_json_encode($lines,JSON_UNESCAPED_UNICODE)),
   'line_count'=>count($lines),'amenities'=>array_values($amenities),
   'confidence'=>'B','cabin_capacity_variants'=>[
      'Merit 1'=>9,'Merit 2'=>8],
   'cabin_capacity_authorized_for_pricing'=>false];
 }
 public static function plan($tour_id) {
  $tour_id=(int)$tour_id;
  $source=self::site_gate($tour_id);if(is_wp_error($source))return $source;
  $profile=self::source_profile($source);if(is_wp_error($profile))return $profile;
  $property=self::candidate($source);if(is_wp_error($property))return $property;
  $facilities=self::facilities($source);if(is_wp_error($facilities))return $facilities;
  $current=wp_get_object_terms($property->ID,'amenities',['fields'=>'ids']);
  if(is_wp_error($current))return self::err('property_amenities_unreadable');
  $existing=[];
  foreach($facilities['amenities'] as $row) {
   $term=get_term_by('slug',$row['slug'],'amenities');
   $row['term_id']=$term?(int)$term->term_id:0;
   $existing[]=$row;
  }
  sort($current,SORT_NUMERIC);
  $material=[
   'contract'=>self::CONTRACT.'.plan','tour_id'=>$tour_id,
   'tour_status'=>get_post_status($tour_id),'tour_modified_gmt'=>get_post($tour_id)->post_modified_gmt,
   'property_id'=>$property->ID,'property_status'=>$property->post_status,
   'property_slug'=>$property->post_name,'property_modified_gmt'=>$property->post_modified_gmt,
   'property_original_amenity_ids'=>array_values($current),
   'source_url'=>$source,'source_facilities'=>$existing,
   'source_section_sha256'=>$facilities['section_sha256'],
   'source_html_sha256'=>$facilities['source_html_sha256'],
   'source_profile_digest'=>$profile['profile_digest'],
   'type_profile'=>self::profile(),
   'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid()];
  $sha=hash('sha256',wp_json_encode($material,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  set_transient('mad4b_cruise_property_'.$tour_id,['material'=>$material,'sha'=>$sha],900);
  return $material+['plan_sha256'=>$sha,'state'=>'READY_FOR_EXACT_APPLY',
   'would_create_property'=>false,
   'new_amenity_terms'=>count(array_filter($existing,static fn($r)=>!$r['term_id'])),
   'existing_amenity_terms'=>count(array_filter($existing,static fn($r)=>!!$r['term_id'])),
   'active_source_rates'=>self::rate_ids($tour_id,$source),
   'mutation_performed'=>false];
 }
 private static function rate_ids($tour_id,$source) {
  $ids=get_posts(['post_type'=>'tour-rates','post_status'=>['draft','publish','pending','private'],
   'posts_per_page'=>100,'fields'=>'ids',
   'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$tour_id],
    ['key'=>'_mad4b_source_rate_source_url_v1','value'=>$source]]]);
  return array_values(array_map('intval',$ids));
 }
 public static function rate_link_meta($tour_id,$source) {
  $tour_id=(int)$tour_id;
  if(get_post_type($tour_id)!=='tours-and-activities' ||
   (string)get_post_meta($tour_id,self::SOURCE,true)!==$source)return [];
  $property=(int)get_post_meta($tour_id,self::BIND,true);
  if(!$property || get_post_type($property)!=='properties' ||
   get_post_status($property)!=='publish')return [];
  $registered=self::candidate($source);
  if(is_wp_error($registered)||$registered->ID!==$property)return [];
  return ['has_properties'=>'1','related_properties_id'=>[$property],
   '_mad4b_source_rate_property_profile_v1'=>self::TOUR_TYPE];
 }
 public static function apply($tour_id,$expected,$confirmation) {
  $tour_id=(int)$tour_id;
  if($confirmation!=='LINK CRUISE PROPERTY AND AMENITIES')
   return self::err('confirmation_required');
  $transient=get_transient('mad4b_cruise_property_'.$tour_id);
  if(!is_array($transient) || !is_array($transient['material']??null) ||
    !preg_match('/^[a-f0-9]{64}$/D',(string)$expected) ||
    !hash_equals((string)$transient['sha'],(string)$expected))
   return self::err('plan_expired_or_mismatch');
  $m=$transient['material'];
  $source=self::site_gate($tour_id);if(is_wp_error($source))return $source;
  $profile=self::source_profile($source);if(is_wp_error($profile))return $profile;
  if(!hash_equals((string)$m['source_profile_digest'],(string)$profile['profile_digest']))
   return self::err('source_profile_changed_replan');
  $property=self::candidate($source);if(is_wp_error($property))return $property;
  if($source!==$m['source_url'] || $property->ID!==(int)$m['property_id'] ||
    get_post($tour_id)->post_modified_gmt!==$m['tour_modified_gmt'] ||
    $property->post_modified_gmt!==$m['property_modified_gmt'])
   return self::err('source_or_target_changed_replan');
  $original=wp_get_object_terms($property->ID,'amenities',['fields'=>'ids']);
  if(is_wp_error($original))return self::err('original_amenities_unavailable');
  sort($original,SORT_NUMERIC);
  if($original!==$m['property_original_amenity_ids'])
   return self::err('amenity_editorial_change_replan');
  $existing_binding=(int)get_post_meta($tour_id,self::BIND,true);
  if($existing_binding && $existing_binding!==$property->ID)
   return self::err('tour_property_identity_conflict');
  $rates=self::rate_ids($tour_id,$source);
  // Do not override a vendor-specific ship or pre-existing multi-property
  // assignment on a Rate; review it as a separate commercial question.
  foreach($rates as $id) {
   $rel=get_post_meta($id,'related_properties_id',true);
   if($rel && $rel!==[$property->ID] && $rel!==(string)$property->ID)
    return self::err('rate_existing_property_conflict');
  }
  $backup='mad4b_cruise_property_backup_'.substr($expected,0,24);
  if(!add_option($backup,[
    'contract'=>'mad4b.cruise-property-reversible-backup.v1',
    'plan'=>$m,'previous_tour_property_id'=>$existing_binding,
    'previous_property_evidence'=>get_post_meta($property->ID,self::AMENITY_SOURCE,true),
    'previous_tour_receipt'=>get_post_meta($tour_id,self::RECEIPT,true)
   ],'',false))return self::err('backup_already_exists');
  $terms_added=[];$links=[];
  foreach($m['source_facilities'] as $facility) {
   $slug=(string)$facility['slug'];$label=(string)$facility['label'];
   $term=get_term_by('slug',$slug,'amenities');
   if(!$term){
    $added=wp_insert_term($label,'amenities',['slug'=>$slug]);
    if(is_wp_error($added)){
     if($added->get_error_code()!=='term_exists')return self::err('amenity_term_creation_failed');
     $term=get_term((int)$added->get_error_data(),'amenities');
    } else {
     $terms_added[]=(int)$added['term_id'];
     $term=get_term((int)$added['term_id'],'amenities');
    }
   }
   if(!$term || is_wp_error($term))return self::err('amenity_term_resolution_failed');
   $links[]=(int)$term->term_id;
  }
  $final=array_values(array_unique(array_merge($original,$links)));
  $set=wp_set_object_terms($property->ID,$final,'amenities',false);
  if(is_wp_error($set))return self::err('amenities_assignment_failed');
  $after=wp_get_object_terms($property->ID,'amenities',['fields'=>'ids']);sort($after,SORT_NUMERIC);
  sort($final,SORT_NUMERIC);
  if($after!==$final){
   wp_set_object_terms($property->ID,$original,'amenities',false);
   return self::err('amenity_readback_failed_restored');
  }
  $evidence=['contract'=>self::CONTRACT.'.amenity-evidence',
    'source_url'=>$source,'source_html_sha256'=>$m['source_html_sha256'],
    'section_sha256'=>$m['source_section_sha256'],
    'matched_facilities'=>$m['source_facilities'],
    'source_profile_sha256'=>$m['source_profile_digest'],
    'captured_at'=>gmdate('c')];
  update_post_meta($property->ID,self::AMENITY_SOURCE,$evidence);
  update_post_meta($property->ID,'_mad4b_property_amenity_source_url_v1',$source);
  update_post_meta($tour_id,self::BIND,$property->ID);
  update_post_meta($tour_id,self::SOURCE,$source);
  update_post_meta($tour_id,self::PROFILE,self::TOUR_TYPE);
  foreach($rates as $id) {
   update_post_meta($id,'has_properties','1');
   update_post_meta($id,'related_properties_id',[$property->ID]);
  }
  $receipt=['contract'=>self::CONTRACT.'.apply','state'=>'VERIFIED',
    'tour_id'=>$tour_id,'tour_status'=>get_post_status($tour_id),
    'property_id'=>$property->ID,'property_status'=>get_post_status($property->ID),
    'property_types'=>wp_get_post_terms($property->ID,'property-types',['fields'=>'slugs']),
    'amenity_term_ids'=>$final,'new_amenity_term_ids'=>$terms_added,
    'source_rate_ids_linked'=>$rates,'future_source_rate_binding_enabled'=>true,
    'source_url'=>$source,'source_profile_digest'=>$m['source_profile_digest'],
    'plan_sha256'=>$expected,'backup_option'=>$backup,
    'booking_authorized'=>false,'rates_created'=>0,
    'review_needed_for_vessel_1_vs_2'=>true];
  update_post_meta($tour_id,self::RECEIPT,$receipt);
  return $receipt;
 }
 public static function maybe_link_priced_rate($rate_id) {
  $rate_id=(int)$rate_id;
  if(wp_get_environment_type()!=='staging' ||
     get_post_type($rate_id)!=='tour-rates' ||
     !in_array(get_post_status($rate_id),['draft','publish','pending','private'],true) ||
     !class_exists('MAD4B_SCP_Site_Profile') ||
     !MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
     !current_user_can('edit_post',$rate_id))return ['state'=>'not_eligible'];
  $tour=(int)get_post_meta($rate_id,'related_tour_id',true);
  if(!$tour || get_post_type($tour)!=='tours-and-activities')
   return ['state'=>'tour_binding_missing'];
  $source=(string)get_post_meta($tour,self::SOURCE,true);
  if(!$source)return ['state'=>'no_source_property_binding'];
  $assignment=self::rate_link_meta($tour,$source);
  if(!$assignment)return ['state'=>'source_property_binding_invalid'];
  $positive=false;
  foreach(['single_price','double_price','triple_price'] as $field) {
   $v=get_post_meta($rate_id,$field,true);
   if(is_scalar($v) && is_numeric($v) && is_finite((float)$v) && (float)$v>0)
    $positive=true;
  }
  if(!$positive)return ['state'=>'rate_not_priced_no_assignment'];
  $old=get_post_meta($rate_id,'related_properties_id',true);
  if($old!=='' && $old!==[] && $old!==null) {
   $current=is_array($old)?array_map('intval',$old):[(int)$old];
   if($current===$assignment['related_properties_id'])
    return ['state'=>'already_linked','rate_id'=>$rate_id];
   update_post_meta($rate_id,'_mad4b_cruise_property_binding_review_v1',
     'existing_properties_must_not_be_overwritten');
   return ['state'=>'property_conflict_review_required','rate_id'=>$rate_id];
  }
  update_post_meta($rate_id,'has_properties','1');
  update_post_meta($rate_id,'related_properties_id',$assignment['related_properties_id']);
  update_post_meta($rate_id,'_mad4b_tour_archetype_rate_property_v1',
   ['contract'=>self::CONTRACT.'.rate-binding',
    'tour_id'=>$tour,'property_id'=>$assignment['related_properties_id'][0],
    'source_url_sha256'=>hash('sha256',$source),'linked_at'=>gmdate('c'),
    'booking_authorized'=>false]);
  $read=get_post_meta($rate_id,'related_properties_id',true);
  return ['state'=>$read===$assignment['related_properties_id']?'linked':'readback_error',
   'rate_id'=>$rate_id,'property_ids'=>$read];
 }
 public static function status($tour_id) {
  $id=(int)$tour_id;$property=(int)get_post_meta($id,self::BIND,true);
  return ['contract'=>self::CONTRACT.'.status','tour_id'=>$id,
   'tour_status'=>get_post_status($id),'property_id'=>$property,
   'property_name'=>$property?get_the_title($property):'',
   'property_terms'=>$property?wp_get_post_terms($property,'amenities',['fields'=>'names']):[],
   'rate_source_bindings'=>($property?self::rate_ids($id,(string)get_post_meta($id,self::SOURCE,true)):[]),
   'receipt'=>get_post_meta($id,self::RECEIPT,true)];
 }
}
// Automatic relation-only default for a *priced* Cruise Rate with one
// previously verified exact source Property binding. Does not create a Rate,
// change amount, publish it, or overwrite another supplier's Property choice.
foreach(['added_post_meta','updated_post_meta'] as $hook) {
 add_action($hook,static function($meta_id,$rate_id,$meta_key){
  if(!in_array((string)$meta_key,
     ['related_tour_id','single_price','double_price','triple_price'],true))return;
  MAD4B_Tour_Archetype_Property_V1::maybe_link_priced_rate((int)$rate_id);
 },55,3);
}
if(defined('WP_CLI') && WP_CLI){
 WP_CLI::add_command('mad4b-tour-property',new class {
  private function show($r) {
   if(is_wp_error($r))WP_CLI::error($r->get_error_code());
   WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  }
  public function plan($args,$opts) {$this->show(MAD4B_Tour_Archetype_Property_V1::plan((int)($args[0]??0)));}
  public function apply($args,$opts) {$this->show(MAD4B_Tour_Archetype_Property_V1::apply(
   (int)($args[0]??0),(string)($opts['expected-plan']??''),
   (string)($opts['confirm']??'')));}
  public function binding_plan($args,$opts) {
   $this->show(MAD4B_Tour_Archetype_Property_V1::binding_plan(
    (int)($args[0]??0),(int)($args[1]??0)));
  }
  public function binding_apply($args,$opts) {
   $this->show(MAD4B_Tour_Archetype_Property_V1::binding_apply(
    (int)($args[0]??0),(int)($args[1]??0),
    (string)($opts['expected-plan']??''),(string)($opts['confirm']??'')));
  }
  public function status($args,$opts) {$this->show(MAD4B_Tour_Archetype_Property_V1::status((int)($args[0]??0)));}
 });
}

