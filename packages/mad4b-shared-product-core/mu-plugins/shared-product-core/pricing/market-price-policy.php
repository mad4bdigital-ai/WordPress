<?php
/**
 * Portable dynamic pricing market authority. Country and nationality are
 * separate, site-approved/evidence-bound claims, never IP based.
 *
 * Rules belong to WooCommerce Booking Experience; MAD4B remains the ONLY
 * Market Profile approval authority. Do not infer money from a switcher.
 */
if(!defined('ABSPATH'))return;

function shared_product_market_price_rules() {
 $rules=get_option('booking_experience_market_price_rules_v1',[]);
 return is_array($rules)?$rules:[];
}
function shared_product_market_current_context(){
 $empty=['status'=>'no_explicit_approved_market','profile_id'=>null,'country'=>null,
  'language'=>null,'nationality'=>null,'source'=>'none','nationality_source'=>'none'];
 if(!class_exists('MAD4B_SCP_Market_Profiles')||!class_exists('MAD4B_SCP_Market_Context'))return $empty;
 $context=MAD4B_SCP_Market_Context::current();
 $valid_sources=['explicit_woocommerce_session','approved_geo_country_default','approved_country_selection_default',
  'approved_unique_language_default','approved_configured_language_default',
  'approved_saved_billing_country_default'];
 if(!is_array($context)||!in_array($context['selected_source']??'', $valid_sources,true))return $empty;
 $market=$context['selected_market']??null;
 if(!is_array($market)||!isset($market['profile_id']))return $empty;
 $approved=MAD4B_SCP_Market_Profiles::approved((string)$market['profile_id']);
 if(!$approved || ($context['language']??'')!==$approved['language']
   ||($market['site_uuid']??'')!==$approved['site_uuid']
   ||($market['revision']??null)!==$approved['revision'])return $empty;
 // A manually chosen country must not disagree with an approved Market Profile.
 if(class_exists('MAD4B_Country_Switcher')){
  $selected=MAD4B_Country_Switcher::saved_country();
  if($selected!=='' && $selected!==$approved['country'])return $empty+['reason'=>'country_profile_mismatch'];
 }
 $nationality='';
 if(class_exists('ETG_Traveller_Nationality_Switcher')){
  $raw=ETG_Traveller_Nationality_Switcher::selected();
  if($raw!=='' && is_string($raw))$nationality=ETG_Traveller_Nationality_Switcher::valid($raw);
 }
 $automatic=($context['selected_source']??'')!=='explicit_woocommerce_session';
 if($automatic && empty($context['auto_approved']))return $empty;
 return ['status'=>$automatic?'auto_matched_site_approved_market':'explicit_site_approved_market',
  'profile_id'=>$approved['profile_id'],
  'profile_revision'=>$approved['revision'],'site_uuid'=>$approved['site_uuid'],
  'country'=>$approved['country'],'language'=>$approved['language'],
  'nationality'=>$nationality?:null,'source'=>$context['selected_source'],
  'nationality_source'=>$nationality?'self_declared_signed_preference':'none'];
}

function shared_product_market_rule_validate($row){
 if(!is_array($row)||array_diff(array_keys($row),[
  'enabled','target_id','profile_id','nationality','percent','currency','note','post_type','profile_revision'
 ]))return new WP_Error('market_rule_invalid_fields');
 $enabled=in_array($row['enabled']??false,[true,1,'1','on','yes'],true);
 $id=filter_var($row['target_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if($id===false||$id===null)return new WP_Error('market_rule_target_invalid');
 $type=get_post_type((int)$id);
 if(!$type||get_post_status($id)!=='publish')return new WP_Error('market_rule_product_missing');
 $accepted=['product','tours-and-activities','tour-rates'];
 if(function_exists('BookingExperience\booking_experience_managed_types'))
  $accepted=array_values(array_unique(array_merge($accepted,BookingExperience\booking_experience_managed_types())));
 $accepted=apply_filters('shared_product_market_supported_post_types',$accepted);
 if(!is_array($accepted)||!in_array($type,$accepted,true))return new WP_Error('market_rule_unmanaged_type');
 if(in_array($type,['properties','property-rates'],true)){
  // The native Accommodation Booking Core validates all stay eligibility and
  // computes money only from a published Property Rate. Never substitute the
  // editorial Property price fields or a Woo product price for its quote.
  if(!function_exists('abk_accommodation_shadow_quote')||!function_exists('abk_meta'))
   return new WP_Error('market_rule_accommodation_provider_unavailable');
  if($type==='properties'){
   // A published Property without a real Accommodation Rate is not a price source.
   $rates=get_posts(['post_type'=>'property-rates','post_status'=>'publish',
    'fields'=>'ids','posts_per_page'=>1,
    'meta_query'=>[['key'=>'prop_related_property_id','value'=>(string)$id,'compare'=>'=']]]);
   if(!$rates)return new WP_Error('market_rule_cpt_price_source_unavailable');
  }
  if($type==='property-rates'){
   $parent=absint(abk_meta($id,'related_property'));
   $unit=absint(abk_meta($id,'related_unit'));
   $amount=abk_meta($id,'base_amount');$cur=(string)abk_meta($id,'currency');
   if(get_post_type($parent)!=='properties'||get_post_status($parent)!=='publish'||
      get_post_type($unit)!=='units'||get_post_status($unit)!=='publish'||
      !is_numeric($amount)||(float)$amount<=0||!preg_match('/^[A-Z]{3}$/D',$cur))
    return new WP_Error('market_rule_accommodation_rate_not_certified');
  }
 }elseif($type==='transportations'){
  // Native transport CPT rates need a revision-pinned approved price source;
  // an indicative trans_start_price is not sale authority.
  $rates=function_exists('BookingExperience\\transport_rate_candidates')
   ?\BookingExperience\transport_rate_candidates($id):[];
  $approved=false;
  if($rates){foreach($rates as $rate)
   if(\BookingExperience\transport_rate_approval_verified($rate->ID)){$approved=true;break;}
  }else $approved=function_exists('BookingExperience\\transport_price_approval_verified')&&
   \BookingExperience\transport_price_approval_verified($id);
  if(!$approved)return new WP_Error('market_rule_transport_approval_missing_or_stale');
 }elseif(!in_array($type,['product','tours-and-activities','tour-rates'],true)){
  $mapped=class_exists('TinySolutions\cptwooint\Helpers\Fns') &&
    TinySolutions\cptwooint\Helpers\Fns::is_supported($type);
  $wc=$mapped&&function_exists('wc_get_product')?wc_get_product((int)$id):null;
  if(!$wc instanceof WC_Product||!is_numeric($wc->get_price())||(float)$wc->get_price()<=0)
   return new WP_Error('market_rule_cpt_price_source_unavailable');
 }
 $profile=sanitize_key((string)($row['profile_id']??''));
 if(!$profile||!class_exists('MAD4B_SCP_Market_Profiles'))return new WP_Error('market_rule_profile_missing');
 $approved=MAD4B_SCP_Market_Profiles::approved($profile);
 if(!$approved)return new WP_Error('market_rule_profile_not_approved');
 if(isset($row['profile_revision']) && $row['profile_revision']!==$approved['revision'])return new WP_Error('market_rule_approval_revision_changed');
 if(isset($row['post_type']) && $row['post_type']!==$type)return new WP_Error('market_rule_target_type_changed');
 $nationality=$row['nationality']??'';
 if(!is_string($nationality))return new WP_Error('market_rule_nationality_invalid');
 $nationality=strtoupper(trim($nationality));
 if($nationality!=='' && (!class_exists('ETG_Traveller_Nationality_Switcher') ||
   ETG_Traveller_Nationality_Switcher::valid($nationality)!==$nationality))
   return new WP_Error('market_rule_nationality_invalid');
 $pct=$row['percent']??null;
 if(!is_numeric($pct)||!is_finite((float)$pct)||(float)$pct<=0||(float)$pct>300)
   return new WP_Error('market_rule_percent_invalid');
 $currency=(string)($row['currency']??'');
 // No new currency authority: WCML/Price Presenter retains conversion.
 if($currency!=='' && (!preg_match('/^[A-Z]{3}$/D',$currency)||$currency!==''))
  return new WP_Error('market_rule_currency_must_inherit');
 return ['enabled'=>$enabled,'target_id'=>(int)$id,'post_type'=>$type,
  'profile_id'=>$profile,'profile_revision'=>$approved['revision'],
  'nationality'=>$nationality,'percent'=>round((float)$pct,2),
  'currency'=>'','note'=>sanitize_text_field((string)($row['note']??''))];
}
function shared_product_market_rules_validate($rows){
 if(!is_array($rows)||count($rows)>100)return new WP_Error('market_rules_invalid_list');
 $result=[];$keys=[];
 foreach($rows as $row){
  $r=shared_product_market_rule_validate($row);
  if(is_wp_error($r))return $r;
  $key=$r['target_id'].'/'.$r['profile_id'].'/'.$r['nationality'];
  if(isset($keys[$key]))return new WP_Error('market_rule_duplicate_target_profile_nationality');
  $keys[$key]=true;$result[]=$r;
 }
 return $result;
}

/** Resolve a single product-specific Market policy; Rate supersedes Tour. */
function shared_product_market_price_apply($tour_or_product,$rate,$base_amount,$currency,$context=null,$traveler_count=1){
 if(!is_numeric($base_amount)||!is_finite((float)$base_amount)||(float)$base_amount<=0)
   return new WP_Error('market_base_amount_invalid');
 if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))
   return new WP_Error('market_price_currency_invalid');
 $context=is_array($context)?$context:shared_product_market_current_context();
 if(!in_array($context['status']??'', ['explicit_site_approved_market','auto_matched_site_approved_market'],true))
   return ['status'=>'no_explicit_approved_market','amount'=>round((float)$base_amount,2),'applied'=>false];
 $id=sanitize_key((string)($context['profile_id']??''));
 $approved=class_exists('MAD4B_SCP_Market_Profiles')?MAD4B_SCP_Market_Profiles::approved($id):[];
 if(!$approved || $approved['revision']!==($context['profile_revision']??null)
  || $approved['site_uuid']!==($context['site_uuid']??null)
  || $approved['country']!==($context['country']??null)
  || $approved['language']!==($context['language']??null))
   return new WP_Error('market_context_unapproved_or_stale');
 $source_ids=[];
 if($rate!==null)$source_ids[]=(int)$rate;
 $source_ids[]=(int)$tour_or_product;
 foreach(array_unique($source_ids) as $target){
  $matched=[];
  foreach(shared_product_market_price_rules() as $row){
   $r=shared_product_market_rule_validate($row);
   // Stale/unapproved persisted rules are never used to price.
   if(is_wp_error($r)||!$r['enabled']||$r['target_id']!==$target||$r['profile_id']!==$id)continue;
   if($r['nationality']!==''){
    $source=$context['nationality_source']??'none';
    $verified_source=is_int($traveler_count)&&$traveler_count>0&&
     ($traveler_count===1?in_array($source,['self_declared_passenger_list',
      'self_declared_passenger_list_all_same'],true):$source==='self_declared_passenger_list_all_same');
    if(!$verified_source)continue;
    if($r['nationality']!==($context['nationality']??''))continue;
   }
   $matched[]=$r;
  }
  if(!$matched)continue;
  usort($matched,static fn($a,$b)=>(int)($b['nationality']!=='')<=>(int)($a['nationality']!==''));
  if(count($matched)>1&&($matched[0]['nationality']!=='' )===($matched[1]['nationality']!=='' ))
   return new WP_Error('market_rules_ambiguous');
  $rule=$matched[0];
  $final=round((float)$base_amount*$rule['percent']/100,2);
  return ['status'=>'approved_market_rule_applied','applied'=>true,'amount'=>$final,
   'receipt'=>['target_id'=>$target,'target_type'=>$rule['post_type'],
    'market_profile_id'=>$id,'market_revision'=>$approved['revision'],
    'market_country'=>$approved['country'],'market_language'=>$approved['language'],
    'nationality_scope'=>$rule['nationality']?:'any',
    'market_selection_source'=>$context['source']??'none',
    'market_auto_matched'=>($context['status']==='auto_matched_site_approved_market'),
    'nationality_source'=>$context['nationality_source']??'none',
    'percent'=>$rule['percent'],'base_amount'=>round((float)$base_amount,2),
    'adjusted_amount'=>$final,'currency'=>$currency,
    'status'=>'preview_only_requires_commercial_certification']];
 }
 return ['status'=>'no_market_rule_for_profile','amount'=>round((float)$base_amount,2),'applied'=>false,
  'market_profile_id'=>$id];
}
