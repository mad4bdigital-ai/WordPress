<?php
/**
 * MAD4B Source Component Intake v1
 * Source URL -> typed Included Services / Draft Entity Candidates.
 * Source Profile, Archetype and exact Site Profile determine eligibility.
 * Never publishes, prices, books or fabricates a payable option.
 */
defined('ABSPATH') || exit;

final class MAD4B_Source_Components_V1 {
 const CONTRACT='mad4b.source-components.v1';
 const RECEIPT='_mad4b_source_components_receipt_v1';
 const KEY='_mad4b_source_component_identity_v1';
 const OWNER='_mad4b_source_component_parent_tour_id_v1';
 const PROOF='_mad4b_source_component_evidence_v1';
 const PENDING='_mad4b_source_component_pending_v1';
 const PLAN_TTL=900;
 private static function err($code) {return new WP_Error('mad4b_source_components_'.$code);}
 private static function admin_gate($id) {
  return defined('WP_CLI') && WP_CLI &&
   wp_get_environment_type()==='staging' &&
   current_user_can('manage_options') &&
   current_user_can('edit_post',(int)$id) &&
   get_post_type($id)==='tours-and-activities' &&
   get_post_status($id)==='draft' &&
   class_exists('MAD4B_SCP_Site_Profile') &&
   MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
 }
 private static function context($id) {
  $id=(int)$id;
  if(!self::admin_gate($id))return self::err('enrolled_staging_admin_draft_required');
  $source=(string)get_post_meta($id,'_mad4b_source_content_loop_url_v1',true);
  $key=(string)get_post_meta($id,'_mad4b_source_content_loop_key_v1',true);
  $rows=get_option('mad4b_source_content_loop_v1',[]);
  if(!$source || !wp_http_validate_url($source) ||
   !is_array($rows) || !is_array($rows[$key]??null) ||
   ($rows[$key]['url']??'')!==$source ||
   (int)($rows[$key]['post_id']??0)!==$id)return self::err('enrolled_source_not_bound');
  if(!class_exists('MAD4B_Scraping_Source_Profiles_V1') ||
    !class_exists('MAD4B_Tour_Archetype_Registry_V1'))
    return self::err('required_profiles_unavailable');
  $profile=MAD4B_Scraping_Source_Profiles_V1::resolve($source);
  $family=MAD4B_Tour_Archetype_Registry_V1::select($id);
  if(is_wp_error($profile)||is_wp_error($family) ||
    ($profile['profile_state']??'')!=='enrolled' ||
    ($family['state']??'')!=='matched')
    return self::err('source_or_archetype_unverified');
  $types=MAD4B_Tour_Archetype_Registry_V1::definitions();
  $type=$types[$family['family']]??[];
  if(!$type || !is_array($type))return self::err('archetype_definition_missing');
  return ['source'=>$source,'source_profile'=>$profile,
    'family'=>$family,'definition'=>$type,
    'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid()];
 }
 private static function extract_list(DOMXPath $xp,$id) {
  $list=$xp->query("//*[@id='$id']//li");
  $out=[];
  if(!$list || $list->length>90)return [];
  foreach($list as $node) {
   $text=trim((string)preg_replace('/\s+/u',' ',
    html_entity_decode((string)$node->textContent,ENT_QUOTES|ENT_HTML5)));
   if(strlen($text)>=6 && strlen($text)<=280)$out[]=$text;
  }
  return array_values(array_unique($out));
 }
 private static function memphis(DOMDocument $dom,$context) {
  $xp=new DOMXPath($dom);
  $included=self::extract_list($xp,'include');
  $excluded=self::extract_list($xp,'exclude');
  $cabin=self::extract_list($xp,'cruise');
  $transport=[];$excursions=[];$extras=[];$units=[];
  foreach($included as $line) {
   if(preg_match('/\btransfers?\b/i',$line))$transport[]=[
    'kind'=>'transfer','included'=>true,'optional'=>false,
    'mode'=>preg_match('/private.*(?:vehicle|car)/i',$line)?'vehicle':'unknown',
    'label'=>$line,'evidence_sha256'=>hash('sha256',$line),
    'operator_verified'=>false,'route_verified'=>false,
    'reason'=>'included_service_not_a_priced_transfer'];
   if(preg_match('/^all\s+.+\bexcursions?\b.*\bitinerary\b/i',$line))$excursions[]=[
    'kind'=>'excursion','included'=>true,'optional'=>false,
    'label'=>$line,'evidence_sha256'=>hash('sha256',$line),
    'reason'=>'included_itinerary_not_optional_extra'];
  }
  foreach($excluded as $line) {
   if(preg_match('/\bextras?\b/i',$line))$extras[]=[
    'kind'=>'unlisted_extras','included'=>false,'optional'=>false,
    'label'=>$line,'evidence_sha256'=>hash('sha256',$line),
    'reason'=>'generic_exclusion_no_specific_optional_product'];
  }
  $cabin_lines=array_values(array_filter($cabin,
   static fn($text)=>preg_match('/\bcabins?\b/i',$text)));
  if($cabin_lines) {
   $units[]=['kind'=>'cabin_group','included'=>true,
    'unit_name'=>'Merit Dahabiya cabin configuration',
    'evidence_lines'=>$cabin_lines,
    'evidence_sha256'=>hash('sha256',wp_json_encode($cabin_lines)),
    'vessel_variant_confirmed'=>false,
    'occupancy_confirmed'=>false,
    'source_suggested_cabins'=>true,
    'reason'=>'merit_vessel_1_vs_2_inventory_unverified'];
  }
  return [
   'adapter'=>'memphis_tours_v1','included_transfers'=>$transport,
   'included_excursions'=>$excursions,'non_optional_exclusions'=>$extras,
   'properties'=>[],'units'=>$units,
   'optional_transfers'=>[],'optional_extra_trips'=>[],
   'provider_source_line_count'=>count($included)+count($excluded)+count($cabin)];
 }
 public static function normalize($raw,$context) {
  $shape=['properties','units','optional_transfers','optional_extra_trips',
    'included_transfers','included_excursions','non_optional_exclusions'];
  if(!is_array($raw))return self::err('adapter_payload_invalid');
  foreach($shape as $field)if(!is_array($raw[$field]??null) ||
    count($raw[$field])>25)return self::err('adapter_field_bounds');
  $profile=$context['source_profile'];
  $family=$context['definition'];
  $out=['adapter'=>(string)($raw['adapter']??''),
    'included_transfers'=>[],'included_excursions'=>[],
    'non_optional_exclusions'=>[],'pending'=>[],'eligible'=>[]];
  foreach(['included_transfers'=>'transfers','included_excursions'=>'extra_trips',
    'non_optional_exclusions'=>'extra_trips'] as $field=>$source_field) {
   if(!MAD4B_Scraping_Source_Profiles_V1::field_allowed($profile,$source_field))continue;
   $tier=(string)($profile['field_trust'][$source_field]??'D');
   $trusted=in_array($tier,['A','B'],true);
   foreach($raw[$field] as $item) {
    if(!is_array($item))continue;
    $hash=(string)($item['evidence_sha256']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$hash))continue;
    $fact=[
     'kind'=>(string)($item['kind']??''),
     'label'=>sanitize_text_field((string)($item['label']??'')),
     'evidence_sha256'=>$hash,
     'included'=>$trusted && (bool)($item['included']??false),
     'selectable'=>false,'price_authorized'=>false,
     'source_tier'=>$tier,
     'reason'=>$trusted?(string)($item['reason']??'not_a_payable_option'):
       'source_field_trust_below_B_review'];
    if($trusted)$out[$field][]=$fact;
    else $out['pending'][]=[
     'kind'=>$fact['kind'],'state'=>'source_trust_review',
     'reason'=>$fact['reason'],'identity_sha256'=>$hash,
     'fields'=>['label'=>$fact['label']],
     'post_type'=>null];
   }
  }
  $target=[
   'properties'=>['property','properties','property'],
   'units'=>['unit','units','units'],
   'optional_transfers'=>['transfer','transfers','transfers'],
   'optional_extra_trips'=>['extra_trip','extra_trips','extra_trips']];
  foreach($target as $source_field=>[$kind,$policy_field,$archetype_component]) {
   if(empty($family['components'][$archetype_component]) ||
     !MAD4B_Scraping_Source_Profiles_V1::field_allowed($profile,$policy_field))
    continue;
   $tier=(string)($profile['field_trust'][$policy_field]??'D');
   $trusted=in_array($tier,['A','B'],true);
   foreach($raw[$source_field] as $item) {
    if(!is_array($item))continue;
    $verdict=self::evaluate_candidate($kind,$item,$context);
    if(!$trusted){
     $verdict['eligible']=false;
     $verdict['reason']='source_field_trust_below_B_review';
    }
    $record=[
     'kind'=>$kind,'state'=>$verdict['eligible']?'eligible_draft':'evidence_pending',
     'reason'=>$verdict['reason'],
     'identity_sha256'=>$verdict['identity_sha256'],
     'fields'=>$verdict['fields'],
     'post_type'=>$verdict['post_type']
    ];
    $out[$verdict['eligible']?'eligible':'pending'][]=$record;
   }
  }
  $out['needs_review_count']=count($out['pending']);
  $out['draft_candidates_count']=count($out['eligible']);
  return $out;
 }
 private static function evaluate_candidate($kind,$item,$context) {
  $types=['property'=>'properties','unit'=>'units',
   'transfer'=>'transportations','extra_trip'=>'tours-and-activities'];
  $pt=$types[$kind]??'';
  $id=(string)($item['identity_sha256']??'');
  $title=sanitize_text_field((string)($item['title']??''));
  $desc=sanitize_textarea_field((string)($item['description']??''));
  $evidence=(string)($item['evidence_sha256']??'');
  $fields=['title'=>$title,'description'=>$desc,
   'property_id'=>(int)($item['property_id']??0),
   'source_identifier'=>sanitize_text_field((string)($item['supplier_item_id']??'')),
   'vessel_id'=>sanitize_text_field((string)($item['vessel_id']??'')),
   'unit_type'=>sanitize_text_field((string)($item['unit_type']??'')),
   'occupancy'=>(int)($item['occupancy']??0),
   'pickup'=>sanitize_text_field((string)($item['pickup']??'')),
   'dropoff'=>sanitize_text_field((string)($item['dropoff']??'')),
   'operator'=>sanitize_text_field((string)($item['operator']??'')),
   'mode'=>(string)($item['mode']??''),
   'optional'=>(bool)($item['optional']??false),
   'included'=>(bool)($item['included']??false),
   'evidence_sha256'=>$evidence];
  $safe=is_string($id) && preg_match('/^[a-f0-9]{64}$/D',$id) &&
   preg_match('/^[a-f0-9]{64}$/D',$evidence) &&
   strlen($title)>=5 && strlen($title)<=150 &&
   strlen($desc)>=20 && strlen($desc)<=2200 &&
   strlen($fields['source_identifier'])>=3;
  $reason='requires_source_identity_title_details';
  if($safe) {
   switch($kind) {
    case 'property':
     $types=(array)($item['property_types']??[]);
     $safe=(bool)array_intersect($types,
       (array)($context['definition']['property_type_slugs']??[]));
     $reason=$safe?'verified_property_draft':'property_type_not_verified';
     $fields['property_types']=array_values(array_filter($types,'is_string'));
     break;
    case 'unit':
     $property=$fields['property_id'];
     $safe=$property>0 && get_post_type($property)==='properties' &&
      get_post_status($property)==='publish' &&
      strlen($fields['vessel_id'])>=3 &&
      strlen($fields['unit_type'])>=3 &&
      $fields['occupancy']>=1 && $fields['occupancy']<=12 &&
      !empty($item['vessel_variant_confirmed']) &&
      !empty($item['occupancy_confirmed']);
     $reason=$safe?'verified_unit_draft':'vessel_occupancy_or_property_unverified';
     break;
    case 'transfer':
     $safe=$fields['optional'] && !$fields['included'] &&
      strlen($fields['pickup'])>=3 && strlen($fields['dropoff'])>=3 &&
      strlen($fields['operator'])>=3 &&
      in_array($fields['mode'],['vehicle','train','air'],true);
     $reason=$safe?'verified_optional_transfer_draft':'transfer_route_operator_or_option_unverified';
     break;
    case 'extra_trip':
     $safe=$fields['optional'] && !$fields['included'] &&
      !empty($item['independent_itinerary_confirmed']);
     $reason=$safe?'verified_optional_extra_draft':'extra_trip_option_or_itinerary_unverified';
     break;
   }
  }
  return ['eligible'=>(bool)$safe,'reason'=>$reason,
   'identity_sha256'=>$id,'fields'=>$fields,'post_type'=>$pt];
 }
 private static function fetch($source,$context) {
  $host=strtolower((string)wp_parse_url($source,PHP_URL_HOST));
  $adapters=apply_filters('mad4b_source_component_adapters_v1',[
   'memphistours.com'=>[__CLASS__,'memphis']
  ]);
  if(!is_array($adapters))return self::err('adapter_registry_invalid');
  $canonical=preg_replace('/^www\./','',$host);
  $callback=$adapters[$canonical]??null;
  if(!is_callable($callback))return self::err('source_component_adapter_unregistered');
  $response=wp_safe_remote_get($source,['timeout'=>30,'redirection'=>2,
   'limit_response_size'=>1050000,'sslverify'=>true,
   'headers'=>['Accept'=>'text/html','User-Agent'=>'MAD4B-Source-Components/1.0']]);
  if(is_wp_error($response) ||
    (int)wp_remote_retrieve_response_code($response)!==200)
    return self::err('source_document_fetch_failed');
  $html=(string)wp_remote_retrieve_body($response);
  if(strlen($html)<8000)return self::err('source_document_incomplete');
  $doc=new DOMDocument();
  if(!@$doc->loadHTML('<?xml encoding="utf-8"?>'.$html,
   LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))
   return self::err('source_document_parse_failed');
  $raw=call_user_func($callback,$doc,$context);
  $normalized=self::normalize($raw,$context);
  if(is_wp_error($normalized))return $normalized;
  $normalized['document_sha256']=hash('sha256',$html);
  return $normalized;
 }
 public static function plan($tour_id) {
  $tour_id=(int)$tour_id;$c=self::context($tour_id);
  if(is_wp_error($c))return $c;
  $found=self::fetch($c['source'],$c);if(is_wp_error($found))return $found;
  $owned=[];
  foreach($found['eligible'] as $candidate) {
   $id=$candidate['identity_sha256'];
   $existing=get_posts(['post_type'=>$candidate['post_type'],'post_status'=>'any',
    'posts_per_page'=>3,'fields'=>'ids',
    'meta_key'=>self::KEY,'meta_value'=>$id]);
   if(count($existing)>1)return self::err('duplicate_source_component_identity');
   $owned[$id]=$existing?(int)$existing[0]:0;
  }
  $previous=get_post_meta($tour_id,self::RECEIPT,true);
  $snapshot=[
   'contract'=>self::CONTRACT.'.plan','tour_id'=>$tour_id,
   'tour_modified_gmt'=>get_post($tour_id)->post_modified_gmt,
   'site_uuid'=>$c['site_uuid'],'source_url'=>$c['source'],
   'source_profile_digest'=>$c['source_profile']['profile_digest'],
   'archetype_family'=>$c['family']['family'],
   'archetype_digest'=>$c['family']['profile_digest'],
   'source_evidence'=>$found,'prior_source_owned'=>$owned,
   'prior_receipt_sha256'=>hash('sha256',wp_json_encode($previous,
     JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))
  ];
  $sha=hash('sha256',wp_json_encode($snapshot,
   JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  set_transient('mad4b_source_components_plan_'.$tour_id,
   ['plan'=>$snapshot,'sha'=>$sha],self::PLAN_TTL);
  return $snapshot+['plan_sha256'=>$sha,'mutation_performed'=>false,
   'action'=>'record_evidence_and_create_only_qualified_drafts'];
 }
 public static function apply($tour_id,$expected,$confirm) {
  $tour_id=(int)$tour_id;
  if($confirm!=='MATERIALIZE VERIFIED SOURCE COMPONENT DRAFTS')
   return self::err('exact_confirmation_required');
  $cache=get_transient('mad4b_source_components_plan_'.$tour_id);
  if(!is_array($cache)|| !preg_match('/^[a-f0-9]{64}$/D',(string)$expected) ||
    !hash_equals((string)($cache['sha']??''),(string)$expected))
   return self::err('stale_or_missing_plan');
  $plan=$cache['plan'];$c=self::context($tour_id);
  if(is_wp_error($c))return $c;
  if($c['source']!==$plan['source_url'] ||
   $c['site_uuid']!==$plan['site_uuid'] ||
   get_post($tour_id)->post_modified_gmt!==$plan['tour_modified_gmt'] ||
   $c['source_profile']['profile_digest']!==$plan['source_profile_digest'] ||
   $c['family']['profile_digest']!==$plan['archetype_digest'])
   return self::err('source_or_policy_changed_replan');
  $current=get_post_meta($tour_id,self::RECEIPT,true);
  if(hash('sha256',wp_json_encode($current,
   JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))!==$plan['prior_receipt_sha256'])
   return self::err('receipt_changed_replan');
  $candidates=$plan['source_evidence']['eligible'];
  // Preflight every candidate BEFORE creating any Draft. A conflict in a
  // later component must not leave partially created earlier components.
  global $wpdb;
  $keys=[];
  foreach($candidates as $candidate) {
   $key=(string)($candidate['identity_sha256']??'');
   $pt=(string)($candidate['post_type']??'');
   if(isset($keys[$key]))return self::err('duplicate_candidates_in_plan');
   $keys[$key]=true;
   if(!in_array($pt,['properties','units','transportations','tours-and-activities'],true))
    return self::err('unsupported_component_post_type');
   $existing=get_posts(['post_type'=>$pt,'post_status'=>'any',
    'posts_per_page'=>3,'fields'=>'ids','meta_key'=>self::KEY,'meta_value'=>$key]);
   if(count($existing)>1)return self::err('duplicate_existing_source_identity');
   if($existing) {
    $target=(int)$existing[0];
    if((int)get_post_meta($target,self::OWNER,true)!==$tour_id ||
      get_post_status($target)==='trash')
     return self::err('existing_identity_owner_or_status_conflict');
   } else {
    $title=(string)$candidate['fields']['title'];
    $matching=(int)$wpdb->get_var($wpdb->prepare(
     "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_title=%s ".
     "AND post_status NOT IN ('trash','auto-draft','inherit') LIMIT 1",
     $pt,$title));
    if($matching)return self::err('existing_named_entity_requires_reconciliation');
   }
  }
  $created=[];$reused=[];$failed=[];
  foreach($candidates as $candidate) {
   $id=$candidate['identity_sha256'];
   $pt=$candidate['post_type'];$fields=$candidate['fields'];
   $existing=get_posts(['post_type'=>$pt,'post_status'=>'any',
    'posts_per_page'=>3,'fields'=>'ids','meta_key'=>self::KEY,'meta_value'=>$id]);
   if(count($existing)>1)return self::err('existing_identity_duplicates');
   if($existing) {
    $child=(int)$existing[0];
    if((int)get_post_meta($child,self::OWNER,true)!==$tour_id)
     return self::err('source_identity_owner_conflict');
    $reused[]=$child;continue;
   }
   if(!current_user_can('edit_posts'))return self::err('draft_post_creation_capability_missing');
   $title=$fields['title'];$description=$fields['description'];
   $child=wp_insert_post(['post_type'=>$pt,'post_status'=>'draft',
    'post_title'=>$title,'post_content'=>$description],true);
   if(is_wp_error($child)||!(int)$child) {
    $failed[]=['kind'=>$candidate['kind'],'reason'=>'draft_creation_failed'];
    continue;
   }
   $child=(int)$child;
   update_post_meta($child,self::KEY,$id);
   update_post_meta($child,self::OWNER,$tour_id);
   update_post_meta($child,self::PROOF,[
    'contract'=>self::CONTRACT.'.provenance',
    'kind'=>$candidate['kind'],'source_url'=>$c['source'],
    'source_sha256'=>$plan['source_evidence']['document_sha256'],
    'evidence_sha256'=>$fields['evidence_sha256'],
    'source_profile_sha256'=>$plan['source_profile_digest'],
    'archetype_sha256'=>$plan['archetype_digest'],
    'fields'=>$fields,'commercially_authorized'=>false]);
   if($candidate['kind']==='property')
    foreach($fields['property_types'] as $slug)
     if($term=get_term_by('slug',$slug,'property-types'))
      wp_set_object_terms($child,(int)$term->term_id,'property-types',true);
   if($candidate['kind']==='unit') {
    update_post_meta($child,'unit_related_property_id',$fields['property_id']);
    update_post_meta($child,'unit_max_occupancy',(string)$fields['occupancy']);
    update_post_meta($child,'unit_available','false');
    update_post_meta($child,'unit_readiness','source_draft_review');
   }
   if($candidate['kind']==='transfer') {
    update_post_meta($child,'trans_available_status','0');
    update_post_meta($child,'_mad4b_source_transfer_mode_v1',$fields['mode']);
   }
   if($candidate['kind']==='extra_trip') {
    update_post_meta($child,'tour_mode','extra_trip');
    update_post_meta($child,'tour_extras_status','0');
   }
   if(get_post_status($child)!=='draft')
    return self::err('created_component_status_readback_failed');
   $created[]=$child;
  }
  $receipt=[
   'contract'=>self::CONTRACT.'.apply','state'=>count($failed)?'PARTIAL':'VERIFIED',
   'source_url'=>$c['source'],'tour_id'=>$tour_id,
   'document_sha256'=>$plan['source_evidence']['document_sha256'],
   'source_profile_sha256'=>$plan['source_profile_digest'],
   'archetype_family'=>$c['family']['family'],
   'archetype_profile_sha256'=>$plan['archetype_digest'],
   'included_transfers'=>$plan['source_evidence']['included_transfers'],
   'included_excursions'=>$plan['source_evidence']['included_excursions'],
   'non_optional_exclusions'=>$plan['source_evidence']['non_optional_exclusions'],
   'pending'=>$plan['source_evidence']['pending'],
   'created_draft_ids'=>$created,'reused_draft_ids'=>$reused,
   'failures'=>$failed,'plan_sha256'=>$expected,
   'commercially_authorized'=>false,
   'relation_to_optional_extras_enabled'=>false,
   'relation_to_transport_choices_enabled'=>false,
   'updated_at'=>gmdate('c')
  ];
  update_post_meta($tour_id,self::RECEIPT,$receipt);
  update_post_meta($tour_id,self::PENDING,$receipt['pending']);
  $saved=get_post_meta($tour_id,self::RECEIPT,true);
  if(($saved['plan_sha256']??'')!==$expected)
   return self::err('receipt_write_readback_failed');
  delete_transient('mad4b_source_components_plan_'.$tour_id);
  return ['contract'=>self::CONTRACT.'.apply-readback',
   'post_id'=>$tour_id,'state'=>$receipt['state'],
   'included_transfer_count'=>count($receipt['included_transfers']),
   'included_excursion_count'=>count($receipt['included_excursions']),
   'optional_extra_count'=>count($created),
   'pending_count'=>count($receipt['pending']),
   'created_draft_ids'=>$created,'reused_draft_ids'=>$reused,
   'publish_performed'=>false,'booking_enabled'=>false,
   'receipt_sha256'=>hash('sha256',wp_json_encode($receipt,
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))];
 }
 public static function tick($max=1) {
  $max=max(1,min(2,(int)$max));
  if(!defined('WP_CLI') || !WP_CLI ||
    wp_get_environment_type()!=='staging' ||
    !current_user_can('manage_options') ||
    !class_exists('MAD4B_SCP_Site_Profile') ||
    !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
   return self::err('tick_site_authority_missing');
  $rows=get_option('mad4b_source_content_loop_v1',[]);
  if(!is_array($rows))return self::err('registry_invalid');
  $result=[];$visited=0;
  foreach($rows as $key=>$entry) {
   if(count($result)>=$max)break;
   if(!is_array($entry) || $key!==($entry['key']??'') ||
     empty($entry['post_id']) || empty($entry['url']))continue;
   $id=(int)$entry['post_id'];
   if(get_post_type($id)!=='tours-and-activities' ||
     get_post_status($id)!=='draft' ||
     get_post_meta($id,'_mad4b_source_content_loop_url_v1',true)!==$entry['url'])
    continue;
   $receipt=get_post_meta($id,self::RECEIPT,true);
   $last=is_array($receipt)?(string)($receipt['updated_at']??''):'';
   // A source/profile or archetype policy update invalidates a cached
   // observation even inside the regular 24h revisit window.
   $context=self::context($id);
   if(is_wp_error($context))continue;
   $same_policy=is_array($receipt) &&
    ($receipt['source_profile_sha256']??'')===
       $context['source_profile']['profile_digest'] &&
    ($receipt['archetype_profile_sha256']??'')===
       $context['family']['profile_digest'];
   if($last && $same_policy && strtotime($last)>time()-86400)continue;
   $lock='mad4b_source_component_tick_'.$id;
   if(get_transient($lock))continue;
   set_transient($lock,1,190);
   try {
    $visited++;
    $plan=self::plan($id);
    if(is_wp_error($plan)) {
     $result[]=['post_id'=>$id,'state'=>'plan_error',
       'code'=>$plan->get_error_code()];continue;
    }
    $applied=self::apply($id,$plan['plan_sha256'],
      'MATERIALIZE VERIFIED SOURCE COMPONENT DRAFTS');
    $result[]=is_wp_error($applied)
      ?['post_id'=>$id,'state'=>'apply_error','code'=>$applied->get_error_code()]
      :['post_id'=>$id,'state'=>$applied['state'],
        'created_draft_ids'=>$applied['created_draft_ids'],
        'included_transfer_count'=>$applied['included_transfer_count'],
        'pending_count'=>$applied['pending_count']];
   } finally {
    delete_transient($lock);
   }
  }
  return ['contract'=>self::CONTRACT.'.tick','inspected'=>$visited,
   'results'=>$result,'max'=>$max];
 }
 public static function status($tour_id) {
  $id=(int)$tour_id;
  return ['contract'=>self::CONTRACT.'.status','post_id'=>$id,
   'post_status'=>get_post_status($id),
   'receipt'=>get_post_meta($id,self::RECEIPT,true),
   'pending'=>get_post_meta($id,self::PENDING,true)];
 }
}
// Compact, read-only native Tour editor panel: no misleading save controls.
add_action('add_meta_boxes_tours-and-activities',static function($post){
 if(!current_user_can('edit_post',$post->ID))return;
 add_meta_box('mad4b-source-components-v1','MAD4B Source Components',
  static function($p){
   $data=MAD4B_Source_Components_V1::status($p->ID);
   $r=$data['receipt'];
   if(!is_array($r)){
    echo '<p>No source components have been verified for this Tour.</p>';
    return;
   }
   $types=[
    'included_transfers'=>'Included transfers (not paid options)',
    'included_excursions'=>'Included excursions (not optional extras)',
    'pending'=>'Awaiting source evidence / inventory'
   ];
   echo '<p><strong>Source:</strong> '.esc_html(
    (string)wp_parse_url((string)($r['source_url']??''),PHP_URL_HOST)).
    ' · <strong>Lifecycle:</strong> '.esc_html((string)($r['state']??'unknown')).'</p>';
   foreach($types as $key=>$title){
    $rows=(array)($r[$key]??[]);
    echo '<details style="margin:8px 0"'.($key==='pending'&&$rows?' open':'').
      '><summary><strong>'.esc_html($title).' ('.count($rows).')</strong></summary>';
    if(!$rows)echo '<p>None evidenced by the supplier.</p>';
    else{
     echo '<ul style="list-style:disc;padding-left:22px">';
     foreach($rows as $row){
      if(!is_array($row))continue;
      $label=$row['label']??$row['fields']['title']??$row['kind']??'Component';
      $reason=$row['reason']??'';
      echo '<li>'.esc_html((string)$label).
       ($reason?' <small>('.esc_html((string)$reason).')</small>':'').'</li>';
     }
     echo '</ul>';
    }
    echo '</details>';
   }
   $count=count((array)($r['created_draft_ids']??[]));
   echo '<p>New component drafts: <strong>'.absint($count).
    '</strong>. Prices, availability and booking remain separately governed.</p>';
  },'tours-and-activities','side','default');
},10,1);
if(defined('WP_CLI') && WP_CLI) {
 WP_CLI::add_command('mad4b-source-components',new class {
  private function show($value) {
   if(is_wp_error($value))WP_CLI::error($value->get_error_code());
   WP_CLI::line(wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  }
  public function plan($a,$o) {
   $this->show(MAD4B_Source_Components_V1::plan((int)($a[0]??0)));
  }
  public function apply($a,$o) {
   $this->show(MAD4B_Source_Components_V1::apply(
    (int)($a[0]??0),(string)($o['expected-plan']??''),
    (string)($o['confirm']??'')));
  }
  public function status($a,$o) {
   $this->show(MAD4B_Source_Components_V1::status((int)($a[0]??0)));
  }
  public function tick($a,$o) {
   $this->show(MAD4B_Source_Components_V1::tick((int)($o['max']??1)));
  }
 });
}


// Governed read-only status. Remote reads never invoke source fetch,
// mutate Tour meta, or create commercial authority.
add_action('wp_abilities_api_init',static function(){
 if(!function_exists('wp_register_ability') || !function_exists('wp_has_ability') ||
    wp_has_ability('mad4b/source-components-status'))return;
 wp_register_ability('mad4b/source-components-status',[
  'label'=>'Source Components Status',
  'description'=>'Read source transfer, extra trip, Property and Unit evidence.',
  'category'=>'mad4b-read',
  'execute_callback'=>static function($input) {
   $id=(int)($input['post_id']??0);
   if(get_post_type($id)!=='tours-and-activities')
    return new WP_Error('mad4b_source_components_invalid_post');
   return MAD4B_Source_Components_V1::status($id);
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
