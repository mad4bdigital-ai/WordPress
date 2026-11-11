<?php
/**
 * MAD4B Company Tour Publishing + Commerce + SEO v1
 *
 * Company-scoped publishing plan/apply for source-backed Tours.
 * The native Content Experience Brand Core, Media Rights and exact
 * Publish plan/apply retain final authority. No approval bypass.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Tour_Publishing_SEO_V1 {
 const CONTRACT='mad4b.company-tour-publication.v1';
 const SEO='_mad4b_company_tour_seo_v1';
 const PRICE='_mad4b_company_tour_commerce_state_v1';
 const PUBLISH='_mad4b_company_tour_publish_receipt_v1';
 const STAGING='staging';
 private static function err($key) {return new WP_Error('mad4b_company_tour_publish_'.$key);}
 private static function context($id,$write=false) {
  $id=(int)$id;
  if(!class_exists('MAD4B_SCP_Site_Profile') ||
     !MAD4B_SCP_Site_Profile::origin_enrolled() ||
     wp_get_environment_type()!==self::STAGING ||
     get_post_type($id)!=='tours-and-activities')
   return self::err('site_or_tour_invalid');
  $site=MAD4B_SCP_Site_Profile::profile();
  $origin=rtrim((string)($site['canonical_origin']??''),'/');
  $configured=rtrim((string)get_option('home',''),'/');
  $configured_site=rtrim((string)get_option('siteurl',''),'/');
  if(!$origin || $configured!==$origin || $configured_site!==$origin)
   return self::err('company_origin_drift');
  // HTML/REST reads cannot derive enrollment from a synthetic CLI request.
  // For any mutation, the original Site Profile checks the live origin.
  if($write && !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
   return self::err('mutation_site_origin_unverified');
  $post=get_post($id);
  $source=(string)get_post_meta($id,'_mad4b_source_content_loop_url_v1',true);
  if(!$post || !$source || !wp_http_validate_url($source) ||
     !get_post_meta($id,'_mad4b_source_content_loop_key_v1',true))
   return self::err('enrolled_source_missing');
  if($write && (!defined('WP_CLI') || !WP_CLI ||
    !current_user_can('manage_options') || !current_user_can('edit_post',$id) ||
    !class_exists('MAD4B_SCP_Policy') || !MAD4B_SCP_Policy::can_mutate()))
    return self::err('enrolled_admin_wpcli_required');
  if(!class_exists('MAD4B_Scraping_Source_Profiles_V1') ||
     !class_exists('MAD4B_Tour_Archetype_Registry_V1'))
   return self::err('source_schema_missing');
  $sp=MAD4B_Scraping_Source_Profiles_V1::resolve($source);
  $family=MAD4B_Tour_Archetype_Registry_V1::select($id);
  if(is_wp_error($sp)||($sp['profile_state']??'')!=='enrolled' ||
    is_wp_error($family)||($family['state']??'')!=='matched')
   return self::err('source_or_family_unregistered');
  return ['post'=>$post,'source'=>$source,'source_profile'=>$sp,
   'family'=>$family,'site_uuid'=>$site['site_uuid']??'',
   'site_origin'=>$site['canonical_origin']??'',
   'site_name'=>get_bloginfo('name')];
 }
 public static function pricing($tour_id) {
  $id=(int)$tour_id;
  $rates=get_posts(['post_type'=>'tour-rates',
    'post_status'=>['draft','publish','pending','private'],
    'posts_per_page'=>200,'fields'=>'ids',
    'meta_key'=>'related_tour_id','meta_value'=>(string)$id,
    'suppress_filters'=>true]);
  $valid=[];$unapproved=[];
  foreach($rates as $rate) {
   $rid=(int)$rate;
   $currency=(string)get_post_meta($rid,'base_currency',true);
   $unit=(string)get_post_meta($rid,'unit_type',true);
   $amount=get_post_meta($rid,'double_price',true);
   $start=(int)get_post_meta($rid,'tour_rate_start_date',true);
   $end=(int)get_post_meta($rid,'tour_rate_end_date',true);
   $stop=(string)get_post_meta($rid,'stop_sale',true)==='1';
   $out=(string)get_post_meta($rid,'avilabile_status',true)==='0';
   $qualifies=$currency==='USD' && $unit==='per_person' &&
      is_numeric($amount) && is_finite((float)$amount) &&
      (float)$amount>0 && $start>0 && $end>=time() &&
      $start<=time()+366*86400 && !$stop && !$out;
   $details=['rate_id'=>$rid,'amount'=>$qualifies?
    number_format((float)$amount,2,'.',''):null,'currency'=>$currency,
    'status'=>get_post_status($rid),
    'price_qualified'=>$qualifies,'booking_verified'=>false];
   if($qualifies && get_post_status($rid)==='publish')$valid[]=$details;
   else $unapproved[]=$details;
  }
  usort($valid,static fn($a,$b)=>
   (float)$a['amount']<=>(float)$b['amount']);
  $pending=(array)get_post_meta($id,'_mad4b_source_price_pending_v1',true);
  $days=array_values(array_unique(array_filter(array_map(
    static fn($x)=>(int)($x['duration_days']??0),$pending),
    static fn($x)=>$x>0)));
  sort($days,SORT_NUMERIC);
  $start_price=get_post_meta($id,'start_price',true);
  $linked=$valid[0]??null;
  $has_valid=$linked!==null;
  $derived_owner=(int)get_post_meta($id,
    '_mad4b_source_starting_price_derived_rate_v1',true);
  $public_price_valid=$has_valid && is_numeric($start_price) &&
    (float)$start_price>0 &&
    abs((float)$start_price-(float)$linked['amount'])<0.011 &&
    $derived_owner===(int)$linked['rate_id'];
  $state=$has_valid?'published_rate_needs_commerce_validation':
    ($unapproved?'rate_review_pending':'price_on_request');
  if(!$has_valid && $days)$state='price_pending_supplier_evidence';
  return [
   'contract'=>'mad4b.company-tour-pricing-evidence.v1',
   'tour_id'=>$id,'state'=>$state,'rate_count'=>count($rates),
   'eligible_published_rate_count'=>count($valid),
   'review_rate_count'=>count($unapproved),'starting_price'=>$start_price,
   'starting_price_matches_derived_rate'=>$public_price_valid,
   'lowest_eligible_rate'=>$linked,
   'all_eligible_rate_ids'=>array_column($valid,'rate_id'),
   'pending_duration_days'=>$days,'price_on_request'=>!$has_valid,
   'checkout_ready'=>false,'price_offers_schema_allowed'=>false,
   'source'=>'native_tour_rates','no_price_fabrication'=>true];
 }
 private static function destination_plan($ctx) {
  $post=$ctx['post'];
  $current=wp_get_post_terms($post->ID,'destinations',['fields'=>'ids']);
  if(is_wp_error($current))return self::err('destination_taxonomy_not_available');
  $current=array_values(array_unique(array_map('intval',$current)));
  sort($current,SORT_NUMERIC);
  $target=$current;
  $evidence=['source'=>'existing_editor_terms'];
  if(($ctx['family']['family']??'')==='nile_cruise') {
   $profile=$ctx['source_profile'];
   if(!MAD4B_Scraping_Source_Profiles_V1::field_allowed($profile,'taxonomies'))
    return self::err('source_profile_destination_excluded');
   $key=(string)get_post_meta($post->ID,
     '_mad4b_source_content_loop_key_v1',true);
   $sources=(array)get_option('mad4b_source_content_loop_v1',[]);
   $source=$sources[$key]??null;
   if(!is_array($source) || ($source['url']??'')!==$ctx['source'] ||
      empty($source['capture_summary']['route_confirmed']) ||
      !preg_match('/\bLuxor\b/i',$post->post_content) ||
      !preg_match('/\bAswan\b/i',$post->post_content))
    return self::err('source_itinerary_route_unverified');
   $expected=['Luxor','Aswan'];
   $ids=[];
   foreach($expected as $name) {
    $term=get_term_by('name',$name,'destinations');
    if(!$term || is_wp_error($term) ||
       mb_strtolower((string)$term->name)!==mb_strtolower($name))
     return self::err('site_destination_term_unavailable');
    $ids[]=(int)$term->term_id;
   }
   $target=array_values(array_unique(array_merge($target,$ids)));
   sort($target,SORT_NUMERIC);
   $evidence=['source'=>'verified_supplier_itinerary',
     'names'=>$expected,'matched_local_term_ids'=>$ids,
     'source_route_confirmed'=>true];
  }
  return ['prior_ids'=>$current,'target_ids'=>$target,
   'needs_change'=>$target!==$current,'evidence'=>$evidence,
   'prior_sha256'=>hash('sha256',wp_json_encode($current))];
 }
 private static function seo_candidate($ctx,$price) {
  $post=$ctx['post'];
  $site=sanitize_text_field((string)$ctx['site_name']);
  $title=sanitize_text_field((string)$post->post_title);
  $max=max(30,58-mb_strlen($site)-3);
  $title_short=mb_strlen($title)>$max?mb_substr($title,0,$max):$title;
  $seo_title=$title_short.' | '.$site;
  $description='';
  $family=(string)($ctx['family']['family']??'');
  if($family==='nile_cruise') {
   $days=$price['pending_duration_days'];
   $choices=$days?implode(', ',array_map(static fn($d)=>$d.'-day',$days)).
    ' Nile itineraries':'Nile sailing itineraries';
   $description='Explore '.$title.' with '.$choices.
    ' between Luxor and Aswan, cabins and guided excursions. '.
    'Request current prices.';
  } else {
   $description=trim((string)$post->post_excerpt);
   if($description==='')$description=wp_strip_all_tags($post->post_content);
   $description=trim((string)preg_replace('/\s+/u',' ',strip_shortcodes($description)));
   $description=mb_substr($description,0,156);
   if($price['price_on_request'])$description=rtrim(mb_substr($description,0,115),'. ').
     '. Request current availability and prices.';
  }
  if(mb_strlen($description)>158) {
   $description=mb_substr($description,0,154);
   $description=preg_replace('/\s+\S*$/u','',$description).'…';
  }
  $published=clone $post;
  $published->post_status='publish';
  $canonical=get_permalink($published);
  if(!is_string($canonical) || !wp_http_validate_url($canonical) ||
     wp_parse_url($canonical,PHP_URL_HOST)!==
       wp_parse_url(home_url('/'),PHP_URL_HOST))
   $canonical='';
  return ['seo_title'=>$seo_title,
    'seo_description'=>$description,
    'focus_keyword'=>mb_substr($title,0,90),
    'robots'=>['noindex','follow'],
    'indexing_reason'=>'staging_source_duplicate_prevention',
    'canonical'=>$canonical,
    'schema'=>'TouristTrip_without_unverified_Offer',
    'content_language'=>'en'];
 }
 private static function locale_state($tour_id) {
  global $wpdb;
  $tr=$wpdb->prefix.'icl_translations';
  $languages=(array)(get_option('icl_sitepress_settings',[])['active_languages']??[]);
  $langs=[];
  foreach($languages as $key=>$value) {
   if(is_string($key) && preg_match('/^[a-z]{2}$/D',$key))$langs[]=$key;
   elseif(is_string($value)&&preg_match('/^[a-z]{2}$/D',$value))$langs[]=$value;
  }
  $langs=array_values(array_unique($langs));
  $trid=$wpdb->get_var($wpdb->prepare(
   "SELECT trid FROM $tr WHERE element_type=%s AND element_id=%d LIMIT 1",
   'post_tours-and-activities',$tour_id));
  $ready=[];$missing=[];
  foreach($langs as $lang) {
   $row=$trid?$wpdb->get_row($wpdb->prepare(
    "SELECT element_id FROM $tr WHERE trid=%d AND element_type=%s ".
    "AND language_code=%s LIMIT 1",
    (int)$trid,'post_tours-and-activities',$lang)):null;
   $translation=$row?(int)$row->element_id:0;
   if($translation && get_post_status($translation)==='publish')$ready[$lang]=$translation;
   else $missing[]=$lang;
  }
  return ['source_lang'=>'en',
   'wpml_trid'=>(int)$trid,
   'published_locales'=>$ready,'pending_locales'=>$missing,
   'auto_duplicate_english'=>false,
   'translation_publication_authorized'=>false];
 }
 private static function demo_brand_mode($ctx) {
  $file=WP_PLUGIN_DIR.'/mad4b-site-control-plane/data/central-neutral-demo-authoring-v1.json';
  if(!is_readable($file))return 'site_brand_core';
  $raw=file_get_contents($file);
  $registry=is_string($raw)&&strlen($raw)<16000?json_decode($raw,true):null;
  if(!is_array($registry) ||
     ($registry['contract']??'')!=='mad4b.central-demo-authoring-brand.v1')
   return 'site_brand_core';
  foreach((array)($registry['assignments']??[]) as $row) {
   if(!is_array($row))continue;
   if(($row['site_uuid']??'')===$ctx['site_uuid'] &&
     rtrim((string)($row['canonical_origin']??''),'/')===
     rtrim((string)$ctx['site_origin'],'/') &&
     ($row['environment']??'')==='staging' &&
     ($row['mode']??'')==='central_neutral_demo' &&
     !empty($row['default']))
    return 'central_neutral_demo';
  }
  return 'site_brand_core';
 }
 private static function brand_preflight() {
  if(!class_exists('MAD4B_SCP_Brand_Core_Control_Loop'))
   return ['ready'=>false,'error'=>'brand_core_module_missing'];
  $p=MAD4B_SCP_Brand_Core_Control_Loop::publication_preflight([
    'operation'=>'publish','post_type'=>'tours-and-activities']);
  return is_wp_error($p)?
   ['ready'=>false,'error'=>$p->get_error_code(),'details'=>$p->get_error_data()]:
   ['ready'=>true,'evidence'=>$p];
 }
 public static function inspect($tour_id) {
  $id=(int)$tour_id;
  $ctx=self::context($id);
  if(is_wp_error($ctx))return $ctx;
  $post=$ctx['post'];
  $price=self::pricing($id);
  $seo=self::seo_candidate($ctx,$price);
  $actual=[];
  foreach(['rank_math_title','rank_math_description',
    'rank_math_focus_keyword','rank_math_robots',
    'rank_math_canonical_url'] as $field)
   $actual[$field]=get_post_meta($id,$field,true);
  $destinations=self::destination_plan($ctx);
  if(is_wp_error($destinations))return $destinations;
  $destination_ready=!$destinations['needs_change'];
  $seo_ready=$destination_ready &&
    $actual['rank_math_title']===$seo['seo_title'] &&
   $actual['rank_math_description']===$seo['seo_description'] &&
   $actual['rank_math_focus_keyword']===$seo['focus_keyword'] &&
   $actual['rank_math_robots']===$seo['robots'] &&
   $actual['rank_math_canonical_url']===$seo['canonical'];
  $brand=self::brand_preflight();
  $source=$ctx['source_profile'];
  $source_review=($source['content_policy']??'')==='manual_review';
  $brand_mode=self::demo_brand_mode($ctx);
  $site_ok=$brand_mode==='central_neutral_demo';
  $content_ready=mb_strlen(wp_strip_all_tags($post->post_content))>=350 &&
   mb_strlen(trim((string)$post->post_excerpt))>=70 &&
   has_post_thumbnail($id);
  $blocking=[];
  if(!$content_ready)$blocking[]='tour_content_or_featured_media_not_ready';
  if(!$site_ok)$blocking[]='neutral_company_brand_policy_not_ready';
  if(!$brand['ready'])$blocking[]=$brand['error'];
  if(!$seo_ready)$blocking[]='seo_metadata_preparation_needed';
  if(!$seo['canonical'])$blocking[]='site_canonical_unavailable';
  return ['contract'=>self::CONTRACT.'.inspection',
   'tour_id'=>$id,'post_status'=>$post->post_status,
   'source_url'=>$ctx['source'],'site_uuid'=>$ctx['site_uuid'],
   'company_name'=>$ctx['site_name'],
   'company_brand_mode'=>$brand_mode,
   'source_profile_id'=>$source['source_profile_id']??'',
   'source_manual_editorial_review'=>$source_review,
   'archetype'=>$ctx['family']['family'],
   'source_profile_sha256'=>$source['profile_digest'],
   'archetype_profile_sha256'=>$ctx['family']['profile_digest'],
   'price'=>$price,'seo_expected'=>$seo,
   'seo_actual'=>$actual,'seo_ready'=>$seo_ready,
   'destinations'=>$destinations,
   'destinations_ready'=>$destination_ready,
   'brand_preflight'=>$brand,'wpml'=>self::locale_state($id),
   'editorial_content_ready'=>$content_ready,
   'publication_blockers'=>$blocking,
   'publication_ready'=>!$blocking,
   'staging_noindex_required'=>true,
   'unpriced_content_publish_eligible'=>true,
   'bookings_enabled'=>false];
 }
 public static function seo_plan($tour_id) {
  $id=(int)$tour_id;$ctx=self::context($id,true);
  if(is_wp_error($ctx))return $ctx;
  if($ctx['post']->post_status!=='draft')
   return self::err('seo_plan_requires_draft');
  $state=self::inspect($id);
  if(is_wp_error($state))return $state;
  $candidate=$state['seo_expected'];
  if(!$candidate['canonical'])return self::err('canonical_missing');
  $before=$state['seo_actual'];
  $payload=['contract'=>self::CONTRACT.'.seo-plan',
   'tour_id'=>$id,'site_uuid'=>$ctx['site_uuid'],
   'post_modified_gmt'=>$ctx['post']->post_modified_gmt,
   'post_content_sha256'=>hash('sha256',$ctx['post']->post_content),
   'source_profile_sha256'=>$state['source_profile_sha256'],
   'archetype_profile_sha256'=>$state['archetype_profile_sha256'],
   'seo_prior_sha256'=>hash('sha256',wp_json_encode($before)),
   'destinations_before'=>$state['destinations']['prior_ids'],
   'destinations_after'=>$state['destinations']['target_ids'],
   'destination_evidence'=>$state['destinations']['evidence'],
   'destination_prior_sha256'=>$state['destinations']['prior_sha256'],
   'seo_after'=>$candidate,
   'price_evidence'=>$state['price'],
   'publish_performed'=>false];
  $sha=hash('sha256',wp_json_encode($payload,
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  set_transient('mad4b_company_tour_seo_plan_'.$id,
   ['sha'=>$sha,'payload'=>$payload],900);
  return $payload+['plan_sha256'=>$sha,'state'=>'READY_FOR_SEO_ONLY'];
 }
 public static function seo_apply($tour_id,$sha,$confirm) {
  $id=(int)$tour_id;
  if($confirm!=='APPLY COMPANY TOUR SEO DRAFT')
   return self::err('exact_confirmation_missing');
  $cache=get_transient('mad4b_company_tour_seo_plan_'.$id);
  if(!is_array($cache)||!preg_match('/^[a-f0-9]{64}$/D',(string)$sha) ||
     !hash_equals((string)($cache['sha']??''),(string)$sha))
   return self::err('plan_expired_or_mismatch');
  $current=self::seo_plan($id);
  if(is_wp_error($current) || !hash_equals(
   (string)($current['plan_sha256']??''),(string)$sha))
   return self::err('seo_target_changed_replan');
  $after=$cache['payload']['seo_after'];
  $prior_dest=(array)$cache['payload']['destinations_before'];
  $target_dest=(array)$cache['payload']['destinations_after'];
  $current_dest=wp_get_post_terms($id,'destinations',['fields'=>'ids']);
  if(is_wp_error($current_dest))return self::err('destinations_unreadable');
  $current_dest=array_values(array_unique(array_map('intval',$current_dest)));
  sort($current_dest,SORT_NUMERIC);
  if($current_dest!==$prior_dest)return self::err('destinations_editorial_drift');
  if($current_dest!==$target_dest) {
   $assigned=wp_set_object_terms($id,$target_dest,'destinations',false);
   if(is_wp_error($assigned))return self::err('destination_assignment_failed');
   $readback=wp_get_post_terms($id,'destinations',['fields'=>'ids']);
   sort($readback,SORT_NUMERIC);
   if($readback!==$target_dest) {
    wp_set_object_terms($id,$prior_dest,'destinations',false);
    return self::err('destination_readback_failed_reverted');
   }
  }
  $fields=['rank_math_title'=>$after['seo_title'],
   'rank_math_description'=>$after['seo_description'],
   'rank_math_focus_keyword'=>$after['focus_keyword'],
   'rank_math_robots'=>$after['robots'],
   'rank_math_canonical_url'=>$after['canonical']];
  foreach($fields as $name=>$value)
   update_post_meta($id,$name,$value);
  $receipt=['contract'=>self::CONTRACT.'.seo-receipt',
   'tour_id'=>$id,'plan_sha256'=>$sha,'updated_at'=>gmdate('c'),
   'site_uuid'=>$cache['payload']['site_uuid'],
   'source_profile_sha256'=>$cache['payload']['source_profile_sha256'],
   'archetype_profile_sha256'=>$cache['payload']['archetype_profile_sha256'],
   'seo_fields'=>$fields,
   'destinations_added'=>array_values(array_diff($target_dest,$prior_dest)),
   'destination_evidence'=>$cache['payload']['destination_evidence'],
   'staging_noindex'=>true,
   'publication_granted'=>false];
  update_post_meta($id,self::SEO,$receipt);
  update_post_meta($id,self::PRICE,[
   'contract'=>'mad4b.company-tour-price-publication.v1',
   'state'=>$cache['payload']['price_evidence']['state'],
   'pending_durations'=>$cache['payload']['price_evidence']['pending_duration_days'],
   'eligible_rate_ids'=>$cache['payload']['price_evidence']['all_eligible_rate_ids'],
   'price_on_request'=>$cache['payload']['price_evidence']['price_on_request'],
   'checkout_authority_granted'=>false,
   'source'=>'native_tour_rates','reviewed_at'=>gmdate('c')]);
  $actual=self::inspect($id);
  if(is_wp_error($actual) || empty($actual['seo_ready']))
   return self::err('seo_readback_failed');
  delete_transient('mad4b_company_tour_seo_plan_'.$id);
  return ['contract'=>self::CONTRACT.'.seo-apply',
   'state'=>'SEO_DRAFT_VERIFIED','tour_id'=>$id,
   'seo_ready'=>true,'price_state'=>$actual['price']['state'],
   'brand_ready'=>$actual['brand_preflight']['ready'],
   'remaining_blockers'=>$actual['publication_blockers'],
   'publication_performed'=>false,
   'seo_receipt_sha256'=>hash('sha256',wp_json_encode($receipt))];
 }
 public static function publish_plan($tour_id) {
  $id=(int)$tour_id;$ctx=self::context($id,true);
  if(is_wp_error($ctx))return $ctx;
  if($ctx['post']->post_status!=='draft')
   return self::err('publish_requires_draft');
  $state=self::inspect($id);
  if(is_wp_error($state))return $state;
  $summary=[
   'contract'=>self::CONTRACT.'.publish-plan',
   'post_id'=>$id,'post_status'=>$ctx['post']->post_status,
   'modified_gmt'=>$ctx['post']->post_modified_gmt,
   'site_uuid'=>$ctx['site_uuid'],
   'company_brand_mode'=>$state['company_brand_mode'],
   'manual_editorial_review_required'=>$state['source_manual_editorial_review'],
   'price_state'=>$state['price']['state'],
   'price_on_request'=>$state['price']['price_on_request'],
   'checkout_enabled'=>false,
   'seo_ready'=>$state['seo_ready'],
   'wpml_pending'=>$state['wpml']['pending_locales'],
   'blockers'=>$state['publication_blockers'],
   'native_content_experience_plan_sha256'=>null,
   'mutation_performed'=>false];
  if($summary['blockers'])return $summary+['state'=>'BLOCKED'];
  $plan=MAD4B_SCP_Content_Experience_Runtime::operation_plan(
   'tours-and-activities','publish',[
    'post_id'=>$id,'expected_modified_gmt'=>$ctx['post']->post_modified_gmt,
    'post_status'=>'publish']);
  if(is_wp_error($plan))
   return $summary+['state'=>'BLOCKED',
    'native_blocker'=>$plan->get_error_code(),
    'native_details'=>$plan->get_error_data()];
  $summary['native_content_experience_plan_sha256']=$plan['plan_sha256'];
  $summary['state']='READY_FOR_MANUAL_EDITORIAL_PUBLISH';
  $summary['publish_plan_sha256']=hash('sha256',
   wp_json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $summary;
 }
 public static function publish_apply($tour_id,$expected,$confirm) {
  if($confirm!=='PUBLISH COMPANY TOUR EDITORIAL REVIEWED')
   return self::err('manual_editorial_confirmation_missing');
  $plan=self::publish_plan($tour_id);
  if(is_wp_error($plan))return $plan;
  if(($plan['state']??'')!=='READY_FOR_MANUAL_EDITORIAL_PUBLISH')
   return self::err('native_publish_preflight_blocked');
  if(!is_string($expected)||!hash_equals(
    (string)$plan['publish_plan_sha256'],$expected))
   return self::err('publish_plan_stale');
  $id=(int)$tour_id;
  $native=MAD4B_SCP_Content_Experience_Runtime::operation_apply(
   'tours-and-activities','publish',[
    'post_id'=>$id,'expected_modified_gmt'=>$plan['modified_gmt'],
    'post_status'=>'publish','plan_sha256'=>
      $plan['native_content_experience_plan_sha256']]);
  if(is_wp_error($native))return $native;
  if(get_post_status($id)!=='publish')return self::err('publish_readback_failed');
  $receipt=['contract'=>self::CONTRACT.'.publish-receipt',
    'post_id'=>$id,'published_gmt'=>gmdate('c'),
    'site_uuid'=>$plan['site_uuid'],
    'price_state'=>$plan['price_state'],
    'manual_editorial_review'=>true,
    'staging_noindex'=>true,
    'native_plan_sha256'=>$plan['native_content_experience_plan_sha256'],
    'company_plan_sha256'=>$expected];
  update_post_meta($id,self::PUBLISH,$receipt);
  return ['state'=>'PUBLISHED_VERIFIED','post_id'=>$id,
   'permalink'=>get_permalink($id),
   'price_state'=>$plan['price_state'],
   'staging_noindex'=>true,'commerce_checkout_enabled'=>false];
 }
 public static function price_shortcode($args=[]) {
  $post=get_queried_object();
  $id=$post instanceof WP_Post?$post->ID:0;
  if(!$id || get_post_type($id)!=='tours-and-activities')
   return '';
  $price=self::pricing($id);
  if($price['price_on_request'])
   return '<div class="mad4b-tour-price-status" data-price-state="on-request">'.
    '<strong>'.esc_html__('Price on request','mad4b').'</strong> '.
    '<span>'.esc_html__('Contact us for the current departure, cabin and availability.','mad4b').'</span>'.
    '</div>';
  $lowest=$price['lowest_eligible_rate'];
  $amount=$lowest['amount']??'';
  if(!$amount)return '';
  return '<div class="mad4b-tour-price-status" data-price-state="rate-evidenced">'.
   '<strong>'.esc_html__('From','mad4b').' '.
   esc_html($lowest['currency'].' '.$amount).'</strong> '.
   '<span>'.esc_html__('per person; confirm dates and availability','mad4b').'</span>'.
   '</div>';
 }
 public static function issue_filter($rows,$cfg) {
  if(!is_array($rows))return $rows;
  $ids=get_posts(['post_type'=>'tours-and-activities',
    'post_status'=>['draft','publish'],'posts_per_page'=>150,
    'fields'=>'ids','meta_query'=>[[
     'key'=>'_mad4b_source_content_loop_key_v1','compare'=>'EXISTS']]]);
  foreach($ids as $id) {
   $r=self::inspect((int)$id);
   if(is_wp_error($r))continue;
   $add=static function($code,$label,$severity='high')use(&$rows,$id) {
    $rows[]=['id'=>hash('sha256',get_current_blog_id().'|tours-and-activities|'.$id.'|'.$code),
     'post_type'=>'tours-and-activities','object_id'=>(int)$id,
     'code'=>$code,'severity'=>$severity,'source'=>'mad4b-company-tour-publication',
     'label'=>$label];
   };
   if(!$r['brand_preflight']['ready'])
    $add('brand_core_source_publish_blocked','Brand Core first-party review not ready');
   if(!$r['seo_ready'])
    $add('tour_rank_math_seo_pending','Source tour Rank Math title, description or canonical missing');
   foreach($r['wpml']['pending_locales'] as $lang)
    if($lang!=='en')$add('tour_wpml_seo_'.$lang,
     'Tour translation and localized SEO pending ['.$lang.']','medium');
   if($r['price']['price_on_request'])
    $add('tour_supplier_rate_evidence_pending',
     'Supplier Tour Rates require numeric verified price before live price display','medium');
  }
  return $rows;
 }
 public static function boot() {
  add_shortcode('mad4b_tour_price_status',[__CLASS__,'price_shortcode']);
  add_filter('mad4b/content_readiness/external_issues',
   [__CLASS__,'issue_filter'],26,2);
  add_filter('rank_math/frontend/robots',static function($robots){
   if(wp_get_environment_type()!=='staging' ||
     !is_singular('tours-and-activities'))return $robots;
   $id=get_queried_object_id();
   if(!get_post_meta($id,'_mad4b_source_content_loop_key_v1',true))
    return $robots;
   if(!is_array($robots))$robots=[];
   $robots=array_diff($robots,['index']);
   $robots['index']='noindex';
   $robots['follow']='follow';
   return $robots;
  },101);
  add_action('add_meta_boxes_tours-and-activities',static function($post){
   if(!current_user_can('edit_post',$post->ID))return;
   add_meta_box('mad4b-company-tour-publish',
    'MAD4B Company · Publishing & SEO',static function($post){
     $state=MAD4B_Company_Tour_Publishing_SEO_V1::inspect($post->ID);
     if(is_wp_error($state)){echo '<p>Site profile unavailable.</p>';return;}
     echo '<p><b>Brand mode:</b> '.esc_html($state['company_brand_mode']).
      '<br><b>Price:</b> '.esc_html($state['price']['state']).
      '<br><b>SEO:</b> '.($state['seo_ready']?'Ready':'Needs metadata').
      '<br><b>Staging crawl:</b> Noindex, follow'.
      '<br><b>Locales pending:</b> '.esc_html(
        implode(', ',$state['wpml']['pending_locales'])).'</p>';
     if($state['publication_blockers'])
      echo '<p><strong>Publication blockers:</strong> '.
       esc_html(implode(', ',$state['publication_blockers'])).'</p>';
     else echo '<p>Preflight ready for native Publish plan/apply.</p>';
    },'tours-and-activities','side','high');
  });
 }
}
MAD4B_Company_Tour_Publishing_SEO_V1::boot();
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b-company-tour-publish',new class {
 private function show($r) {
  if(is_wp_error($r))WP_CLI::error($r->get_error_code());
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
 }
 public function status($args,$opts){
  $this->show(MAD4B_Company_Tour_Publishing_SEO_V1::inspect((int)($args[0]??0)));
 }
 public function seo_plan($args,$opts){
  $this->show(MAD4B_Company_Tour_Publishing_SEO_V1::seo_plan((int)($args[0]??0)));
 }
 public function seo_apply($args,$opts){
  $this->show(MAD4B_Company_Tour_Publishing_SEO_V1::seo_apply(
   (int)($args[0]??0),(string)($opts['expected-plan']??''),
   (string)($opts['confirm']??'')));
 }
 public function publish_plan($args,$opts){
  $this->show(MAD4B_Company_Tour_Publishing_SEO_V1::publish_plan((int)($args[0]??0)));
 }
 public function publish_apply($args,$opts){
  $this->show(MAD4B_Company_Tour_Publishing_SEO_V1::publish_apply(
   (int)($args[0]??0),(string)($opts['expected-plan']??''),
   (string)($opts['confirm']??'')));
 }
});

