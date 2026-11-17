<?php
/** Native Tour Rate planning: partial evidence, existing Rate owner and MAD4B readiness. */
defined('ABSPATH')||exit;
final class MAD4B_Rate_Planner {
 const SOURCE='mad4b-rate-planner';
 const TYPE='mad4b_rate_plan';
 private static bool $writing=false;
 public static function allowed():bool {if(get_current_user_id()&&get_user_meta(get_current_user_id(),'mad4b_company_suspension_v1',true))return false;return class_exists('MAD4B_Lifecycle_Access')&&MAD4B_Lifecycle_Access::can('commerce_manage_pricing');}
 private static function date($v):bool {if(!is_string($v)||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$v))return false;$d=is_string($v)?DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC')):false;return $d&&$d->format('Y-m-d')===$v;}
 public static function canonical(int $id,string $type):int {return (int)apply_filters('wpml_object_id',$id,$type,true,apply_filters('wpml_default_language',null));}
 private static function terms(int $id,string $tax):array {$r=wp_get_object_terms($id,$tax,['fields'=>'ids']);if(is_wp_error($r))return [];return array_values(array_unique(array_map(static fn($v)=>self::canonical((int)$v,$tax),$r)));}
 public static function options(array $o):array|WP_Error {
  if(array_diff(array_keys($o),['tour_ids','from','to','locale_mode','auto_recheck','accommodation']))return new WP_Error('rate_plan_unknown_option');
  if(!self::date($o['from']??null)||!self::date($o['to']??null)||$o['from']>$o['to'])return new WP_Error('rate_plan_period_invalid');
  if(isset($o['auto_recheck'])&&!is_bool($o['auto_recheck']))return new WP_Error('rate_plan_auto_recheck_invalid');
  $ids=$o['tour_ids']??[];if(!is_array($ids))return new WP_Error('rate_plan_ids_invalid');
  foreach($ids as $id)if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1)return new WP_Error('rate_plan_ids_invalid');
  $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids);
  $stay=$o['accommodation']??'auto';if(!in_array($stay,['auto','include','exclude'],true))return new WP_Error('rate_plan_accommodation_invalid');
  $locale=$o['locale_mode']??'inherit';if(!in_array($locale,['inherit','independent'],true))return new WP_Error('rate_plan_locale_invalid');
  return ['tour_ids'=>$ids,'from'=>$o['from'],'to'=>$o['to'],'locale_mode'=>$locale,'auto_recheck'=>!empty($o['auto_recheck']),'accommodation'=>$stay];
 }
 private static function issue(array &$plan,int $tour,string $reason,string $label,string $context,string $group='pricing',string $severity='high',?string $variant=null):void {
  $code='rate_plan_'.sanitize_key($reason).'_'.substr(hash('sha256',$context),0,12);$id=hash('sha256',get_current_blog_id().'|tours-and-activities|'.$tour.'|'.$code);
  if(!isset($plan['issues'][$id]))$plan['issues'][$id]=['id'=>$id,'post_type'=>'tours-and-activities','object_id'=>$tour,'code'=>$code,'severity'=>$severity,'label'=>mb_substr($label,0,230),'source'=>self::SOURCE,'source_evidence'=>['planner_scope'=>$plan['scope'],'reason'=>$reason,'owner_group'=>$group,'fix_url'=>get_edit_post_link($tour,'raw'),'variant_keys'=>[]]];
  if($variant)$plan['issues'][$id]['source_evidence']['variant_keys'][]=$variant;
 }
 private static function windows(array $seasons,array $o):array {
  if(!$seasons)return [['season_id'=>0,'from'=>$o['from'],'to'=>$o['to'],'evidence'=>'explicit_period_no_season']];$rows=[];
  foreach($seasons as $sid){if(get_term_meta($sid,'season_enabled',true)!=='1')continue;$mode=(string)get_term_meta($sid,'season_mode',true);if($mode!==''&&$mode!=='travel')continue;
   $sm=(int)get_term_meta($sid,'season_start_month',true);$sd=(int)get_term_meta($sid,'season_start_day',true);$em=(int)get_term_meta($sid,'season_end_month',true);$ed=(int)get_term_meta($sid,'season_end_day',true);$cross=get_term_meta($sid,'season_cross_year',true)==='1';
   for($y=(int)substr($o['from'],0,4)-1;$y<=(int)substr($o['to'],0,4);$y++){if(!checkdate($sm,$sd,$y)||!checkdate($em,$ed,$y+($cross?1:0)))continue;$a=sprintf('%04d-%02d-%02d',$y,$sm,$sd);$b=sprintf('%04d-%02d-%02d',$y+($cross?1:0),$em,$ed);$a=max($a,$o['from']);$b=min($b,$o['to']);if($a<=$b)$rows[]=['season_id'=>$sid,'from'=>$a,'to'=>$b,'evidence'=>'native_season_calendar'];}
  }
  return $rows?:[['season_id'=>0,'from'=>null,'to'=>null,'evidence'=>'no_valid_season_window']];
 }
 private static function policy(int $tour,?int $rate=null):array {
  $r=Commerce\Composition\CompanyCommercePolicy::resolve($tour,$rate);
  if(is_wp_error($r))return ['status'=>'unresolved','error'=>$r->get_error_code(),'digest'=>hash('sha256',wp_json_encode([$r->get_error_code(),get_option(Commerce\Composition\CompanyCommercePolicy::OPTION,[])])),'effective'=>[]];
  return ['status'=>'resolved','digest'=>$r['policy_digest'],'revision'=>$r['policy_revision'],'effective'=>$r['effective'],'provenance'=>$r['provenance']];
 }
 private static function source(int $tour,array $rates,array $window,int $package):array {
  $direct=null;$rateChoices=[];$amount=get_post_meta($tour,'start_price',true);$currency=(string)get_post_meta($tour,'tour_base_currency',true);$basis=get_post_meta($tour,'tour_price_unit',true);
  if(is_numeric($amount)&&(float)$amount>0&&$basis==='per_person'&&isset(get_woocommerce_currencies()[$currency]))$direct=['kind'=>'tour_initial_retail','id'=>$tour,'amount'=>(string)$amount,'currency'=>$currency,'factors'=>shared_product_tour_seed_factors($tour),'occupancy_cost_verified'=>false];
  foreach($rates as $rid){if((int)get_post_meta($rid,'related_tour_id',true)!==$tour)continue;
   if(in_array(strtolower((string)get_post_meta($rid,'stop_sale',true)),['1','true','yes','on'],true)||(string)get_post_meta($rid,'avilabile_status',true)==='0')continue;
   if(function_exists('shared_product_rate_locale_decision')&&!shared_product_rate_locale_decision((int)$rid,$tour)['allowed'])continue;
   if($package&&self::canonical((int)get_post_meta($rid,'related_package_term_id',true),'package_levels')!==$package)continue;
   $a=(int)get_post_meta($rid,'tour_rate_start_date',true);$b=(int)get_post_meta($rid,'tour_rate_end_date',true);if(!$window['from']||!$a||!$b||gmdate('Y-m-d',$a)>$window['from']||gmdate('Y-m-d',$b)<$window['to'])continue;
   if(get_post_meta($rid,'unit_type',true)!=='per_person')continue;$money=[];foreach(['single','double','triple'] as $key){$v=get_post_meta($rid,$key.'_price',true);if(is_numeric($v)&&(float)$v>0)$money[$key]=(string)$v;}
   $cc=(string)get_post_meta($rid,'base_currency',true);if(!isset(get_woocommerce_currencies()[$cc])||!$money)continue;$base=$money['double']??reset($money);$f=[];foreach($money as $key=>$v)$f[$key]=number_format((float)$v/(float)$base,4,'.','');
   $rateChoices[]=['kind'=>'existing_rate_retail','id'=>(int)$rid,'amount'=>$base,'currency'=>$cc,'factors'=>$f,'known_prices'=>$money,'occupancy_cost_verified'=>false];
  }
  $rate=null;$rateError=null;$signatures=[];foreach($rateChoices as $r){$normal=array_map(static fn($v)=>number_format((float)$v,4,'.',''),$r['known_prices']);$signatures[hash('sha256',wp_json_encode([$r['currency'],$normal]))]=true;}
  if(count($signatures)>1)$rateError='ambiguous_native_rate_sources';elseif($rateChoices){usort($rateChoices,static fn($a,$b)=>$a['id']<=>$b['id']);$rate=$rateChoices[0];}
  $mode=(string)get_post_meta($tour,'tour_mode',true);$candidates=[];if($direct)$candidates['direct']=['amount'=>$direct['amount'],'currency'=>$direct['currency']];if($rate)$candidates['rate']=['amount'=>$rate['amount'],'currency'=>$rate['currency']];
  $decision=function_exists('shared_product_resolve_price_sources')?shared_product_resolve_price_sources($candidates,$mode):new WP_Error('native_price_source_provider_missing');
  if(!is_wp_error($decision)){$chosen=$decision['source']==='rate'?$rate:$direct;$chosen['source_policy']=$decision['policy'];$chosen['source_decision']=$decision['source'];if($rateError)$chosen['source_review']=$rateError;return $chosen;}
  $reference=$direct??($rateChoices[0]??null);if(!$reference&&is_numeric($amount)&&(float)$amount>0&&isset(get_woocommerce_currencies()[$currency])&&$basis!=='per_person')$reference=['id'=>$tour,'amount'=>(string)$amount,'currency'=>$currency];if($reference)return ['kind'=>'held_reference','id'=>$reference['id'],'amount'=>$reference['amount'],'currency'=>$reference['currency'],'factors'=>[],'source_review'=>$rateError??($basis!=='per_person'?'price_unit_model_requires_review':$decision->get_error_code()),'reference_only'=>true];
  return ['kind'=>'missing','amount'=>null,'currency'=>null,'factors'=>[],'source_review'=>$decision->get_error_code()];
 }
 public static function plan(array $options):array|WP_Error {
  if(!self::allowed())return new WP_Error('rate_plan_permission_denied');$o=self::options($options);if(is_wp_error($o))return $o;
  if(!function_exists('mad4b_rate_authoring_enabled')||!mad4b_rate_authoring_enabled())return new WP_Error('rate_plan_authoring_disabled');
  $ids=$o['tour_ids']?:get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true]);
  $scopeOptions=$o;unset($scopeOptions['auto_recheck']);$scope=substr(hash('sha256',wp_json_encode($scopeOptions)),0,24);$p=['contract'=>'mad4b.rate-plan.v1','options'=>$o,'scope'=>$scope,'origin'=>home_url('/'),'candidates'=>[],'issues'=>[],'covered_ids'=>[],'families'=>[],'counts'=>[],'checkout_ready'=>false];$seen=[];
  foreach($ids as $id){$id=(int)$id;if(get_post_type($id)!=='tours-and-activities'||get_post_status($id)!=='publish'||!current_user_can('edit_post',$id)){if($o['tour_ids'])return new WP_Error('rate_plan_tour_permission_denied');continue;}
   $family=self::canonical($id,'tours-and-activities');if(!$family)$family=$id;if(isset($seen[$family]))continue;$seen[$family]=true;
   $siblings=function_exists('shared_product_assignment_tour_siblings')?shared_product_assignment_tour_siblings($id):[$id];$parent=in_array($family,$siblings,true)&&get_post_status($family)==='publish'?$family:$id;$p['families'][$family]=$siblings;
   $baseGeo=shared_product_assignment_cities($parent);foreach($siblings as $sibling){$siblingGeo=shared_product_assignment_cities((int)$sibling);if(is_array($baseGeo)&&is_array($siblingGeo)&&array_keys($baseGeo)!==array_keys($siblingGeo))self::issue($p,$parent,'locale_geo_drift','Translated Tour destination scopes differ; canonical Tour drives this plan.',$scope,'wpml','high');}
   $parents=$o['locale_mode']==='independent'?$siblings:[$parent];
   foreach($parents as $tour){$tour=(int)$tour;if(!current_user_can('edit_post',$tour))continue;$p['covered_ids'][]=$tour;
    $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true,'meta_query'=>[['key'=>'related_tour_id','value'=>array_map('strval',$siblings),'compare'=>'IN']]]);
    $packages=self::terms($tour,'package_levels');$seasons=self::terms($tour,'season');
    if(!$packages)foreach($rates as $rid){$v=(int)get_post_meta($rid,'related_package_term_id',true);if($v)$packages[]=self::canonical($v,'package_levels');}
    if(!$seasons)foreach($rates as $rid)$seasons=array_merge($seasons,self::terms($rid,'season'));
    $packages=array_values(array_unique(array_filter($packages)));$seasons=array_values(array_unique(array_filter($seasons)));
    if(!$packages)self::issue($p,$tour,'package_missing','No package split was assigned; preserving only the default draft variant.',$scope,'pricing','low');
    if(!$seasons)self::issue($p,$tour,'season_missing','No assigned season; explicit requested period is the only period authority.',$scope,'pricing','medium');
    $policy=self::policy($tour);if($policy['status']!=='resolved')self::issue($p,$tour,'policy_unresolved','Company policy needs review: '.$policy['error'],$scope);
    $windows=self::windows($seasons,$o);
    foreach($packages?:[0] as $package)foreach($windows as $w){
     $lang=apply_filters('wpml_element_language_code',null,['element_id'=>$tour,'element_type'=>'post_tours-and-activities']);
     $identity=['family'=>$family,'period'=>[$w['from'],$w['to']],'package'=>$package,'season'=>$w['season_id'],'price_unit'=>(get_post_meta($tour,'tour_price_unit',true)?:'unconfigured'),'price_locale'=>$o['locale_mode']==='independent'?$lang:'inherit'];$key=hash('sha256',wp_json_encode($identity));
     $source=self::source($tour,$rates,$w,$package);$stayRequested=$o['accommodation']==='include';if($o['accommodation']==='auto')foreach($rates as $rr){$rp=(int)get_post_meta($rr,'related_package_term_id',true);if($package&&$rp&&self::canonical($rp,'package_levels')!==$package)continue;if((string)get_post_meta($rr,'has_properties',true)==='1'||get_post_meta($rr,'_booking_experience_stay_stages_v1',true)){$stayRequested=true;break;}}
     $assignment=$stayRequested?shared_product_accommodation_assignment($tour,0,$package):['status'=>'not_requested','property_ids'=>[]];
     $c=['key'=>$key,'identity'=>$identity,'tour_id'=>$tour,'family_id'=>$family,'languages'=>[],'window'=>$w,'package_id'=>$package,'policy'=>$policy,'policy_review'=>['context'=>$identity,'state'=>$policy['status']==='resolved'?'resolved_intent_price_unverified':'missing_policy_partial_allowed','automatic_margin_applied'=>false,'price_approved'=>false],'source'=>$source,'assignment'=>is_wp_error($assignment)?['status'=>$assignment->get_error_code()]:$assignment,'state'=>'proposal','input'=>null,'existing_ids'=>[],'overlap_ids'=>[],'reasons'=>[]];
     foreach($siblings as $s)$c['languages'][]=apply_filters('wpml_element_language_code',null,['element_id'=>$s,'element_type'=>'post_tours-and-activities']);$c['languages']=array_values(array_unique(array_filter($c['languages'])));
     if(!empty($source['source_review']))self::issue($p,$tour,'source_policy_review','Source policy needs review: '.$source['source_review'],$key,'pricing','high',$key);
     if($policy['status']==='resolved'&&in_array($policy['effective']['pricing_source']??'',['supplier_contract','manual_quote'],true)&&$source['kind']!=='missing')self::issue($p,$tour,'policy_source_review','Policy requires supplier/quote evidence; the available retail reference remains an unapproved draft.',$scope,'pricing','high',$key);
     if($w['evidence']==='no_valid_season_window'){$c['reasons'][]='period_missing';self::issue($p,$tour,'period_missing','Season calendars provide no usable period; keeping an unpriced proposal.',$key,'pricing','high',$key);}
     if($source['kind']==='missing'){self::issue($p,$tour,'price_missing','No usable original selling price; no zero price was substituted.',$scope,'pricing','high',$key);$c['reasons'][]='price_missing';}
     else {
      self::issue($p,$tour,'profit_unverified','Retail reference available; supplier costs and profitability remain unverified.',$scope,'pricing','medium',$key);
      if(count($source['factors'])<3){$c['reasons'][]='occupancy_missing';self::issue($p,$tour,'occupancy_missing','Source Rate lacks an occupancy tier; unknown prices remain unset.',$key,'pricing','high',$key);}
      elseif($w['from']){$input=['kind'=>'tour','parent_id'=>$tour,'amount'=>$source['amount'],'currency'=>$source['currency'],'price_unit'=>'per_person','valid_from'=>$w['from'],'valid_to'=>$w['to'],'single_factor'=>$source['factors']['single'],'double_factor'=>$source['factors']['double'],'triple_factor'=>$source['factors']['triple'],'accommodation_assignment_mode'=>$stayRequested?'auto':'manual'];if($package)$input['package_term_id']=$package;if($w['season_id'])$input['season_term_id']=$w['season_id'];if($o['locale_mode']==='independent'){$input['pricing_locale_mode']='independent';$input['pricing_language']=$lang;}
       $prepared=Shared_Product_Rate_Generation::prepare($input);if(is_wp_error($prepared)){self::issue($p,$tour,'native_prepare','Native draft preparation needs review: '.$prepared->get_error_code(),$key,'pricing','high',$key);$c['reasons'][]=$prepared->get_error_code();}else{if($source['kind']==='existing_rate_retail')foreach($source['known_prices'] as $tier=>$expected)if(abs((float)$prepared['meta'][$tier.'_price']-(float)$expected)>0.00001){self::issue($p,$tour,'occupancy_precision','Occupancy ratio precision would change a source price; preserving exact known prices in a proposal.',$key,'pricing','high',$key);$input=null;break;}$c['input']=$input;$c['state']=$input?'priced_draft':'proposal';$c['prepared_fingerprint']=$prepared['fingerprint'];$owned=get_posts(['post_type'=>'tour-rates','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'suppress_filters'=>true,'meta_key'=>'_mad4b_rate_plan_key','meta_value'=>$key]);$overlap=Shared_Product_Rate_Generation::overlap_review($prepared,(int)($owned[0]??0));if(is_wp_error($overlap)){self::issue($p,$tour,'overlap_unknown','Existing Rate overlap could not be fully checked.',$key,'pricing','high',$key);$c['reasons'][]='overlap_unknown';}else $c['overlap_ids']=array_map('intval',array_column($overlap['matches'],'rate_id'));}
      }
     }
     foreach($rates as $rid){$rid=(int)$rid;$rp=(int)get_post_meta($rid,'related_package_term_id',true);$rp=$rp?self::canonical($rp,'package_levels'):0;$a=(int)get_post_meta($rid,'tour_rate_start_date',true);$b=(int)get_post_meta($rid,'tour_rate_end_date',true);
      if($rp===$package&&$a&&$b&&$w['from']&&gmdate('Y-m-d',$a)<=$w['from']&&gmdate('Y-m-d',$b)>=$w['to']&&($o['locale_mode']==='inherit'||(int)get_post_meta($rid,'related_tour_id',true)===$tour))$c['existing_ids'][]=$rid;
     }
     if($c['existing_ids'])$c['state']='existing_review';elseif($c['overlap_ids']){self::issue($p,$tour,'overlap','Existing Rates overlap this proposed period; no existing Rate will be overwritten.',$key,'pricing','high',$key);$c['reasons'][]='overlap';}
     if(!in_array($c['assignment']['status'],['ready','not_requested'],true))self::issue($p,$tour,'stay_'.sanitize_key($c['assignment']['status']),'Accommodation needs review: '.$c['assignment']['status'],$scope,'operations','high',$key);
     if(!empty($c['assignment']['classification_review_required']))self::issue($p,$tour,'hotel_classification','Hotel package/star classification needs verification.',$scope,'pricing','high',$key);
     if($stayRequested&&$package&&!shared_product_assignment_stars_tier($package))self::issue($p,$tour,'stars_policy_missing','Package has no stars-tier rule; no hotel category was invented.',$scope,'pricing','medium',$key);
     $ownedRate=get_posts(['post_type'=>'tour-rates','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'suppress_filters'=>true,'meta_key'=>'_mad4b_rate_plan_key','meta_value'=>$key]);
     if($ownedRate&&get_post_status((int)$ownedRate[0])==='draft'&&get_post_meta((int)$ownedRate[0],'_mad4b_rate_plan_owned_snapshot',true)!==self::managedSnapshot((int)$ownedRate[0]))self::issue($p,$tour,'generation_review','Draft generation requires review: draft_manually_changed_review_required',$key,'pricing','high',$key);
     $c['evidence_digest']=hash('sha256',wp_json_encode([$c['identity'],$source,$policy,$c['assignment'],$c['existing_ids'],$c['overlap_ids']]));$p['candidates'][]=$c;
    }
   }
  }
  for($i=0;$i<count($p['candidates']);$i++)for($j=$i+1;$j<count($p['candidates']);$j++){
   $a=$p['candidates'][$i];$b=$p['candidates'][$j];if($a['family_id']!==$b['family_id']||$a['package_id']!==$b['package_id']||$a['identity']['price_locale']!==$b['identity']['price_locale']||$a['window']['season_id']===$b['window']['season_id']||!$a['window']['from']||!$b['window']['from'])continue;
   if($a['window']['from']<=$b['window']['to']&&$b['window']['from']<=$a['window']['to']){self::issue($p,$a['tour_id'],'season_overlap','Assigned season windows overlap; no seasonal priority or price adjustment was assumed.',$a['key'],'pricing','high',$a['key']);$p['candidates'][$i]['reasons'][]='season_overlap';$p['candidates'][$j]['reasons'][]='season_overlap';}
  }
  $p['covered_ids']=array_values(array_unique($p['covered_ids']));foreach($p['candidates'] as $c)$p['counts'][$c['state']]=($p['counts'][$c['state']]??0)+1;
  foreach($p['issues'] as &$issue)$issue['source_evidence']['variant_keys']=array_values(array_unique($issue['source_evidence']['variant_keys']));unset($issue);
  $p['counts']['gaps']=count($p['issues']);$p['digest']=hash('sha256',wp_json_encode($p));return $p;
 }
 public static function persist(array $p,bool $generate=false):array|WP_Error {
  if(!self::allowed()||($p['origin']??'')!==home_url('/'))return new WP_Error('rate_plan_permission_denied');
  // Re-plan immediately before writes; client-supplied candidates never authorize writes.
  $fresh=self::plan($p['options']);if(is_wp_error($fresh))return $fresh;if(!hash_equals($p['digest'],$fresh['digest']))return new WP_Error('rate_plan_changed_replan_required');$p=$fresh;
  if(!class_exists('MAD4B_SCP_Content_Readiness_Alerts')||!method_exists('MAD4B_SCP_Content_Readiness_Alerts','reconcile_rate_plan'))return new WP_Error('rate_plan_alert_owner_missing');
  $issues=MAD4B_SCP_Content_Readiness_Alerts::reconcile_rate_plan(array_values($p['issues']),$p['covered_ids'],$p['scope']);if(is_wp_error($issues))$issues=['status'=>'native_alerts_pending','error'=>$issues->get_error_code()];
  self::$writing=true;
  try {
   $run=wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'private','post_title'=>'Rate plan '.wp_date('Y-m-d H:i:s'),'post_author'=>get_current_user_id()],true);if(is_wp_error($run))return $run;
   $results=[];foreach(get_posts(['post_type'=>self::TYPE,'post_status'=>'private','fields'=>'ids','posts_per_page'=>-1,'meta_key'=>'_rate_plan_scope','meta_value'=>$p['scope']]) as $oldRun)update_post_meta($oldRun,'_rate_plan_auto',0);
   foreach($p['candidates'] as $c){if(!$generate){$results[]=['key'=>$c['key'],'state'=>$c['state']];continue;}$results[]=self::emit($c);}
   foreach($results as $r)if(!empty($r['error']))self::issue($p,(int)($p['candidates'][array_search($r['key'],array_column($p['candidates'],'key'),true)]['tour_id']??0),'generation_review','Draft generation requires review: '.$r['error'],$r['key'],'pricing','high',$r['key']);
   if(array_filter($results,static fn($r)=>!empty($r['error']))){$more=MAD4B_SCP_Content_Readiness_Alerts::reconcile_rate_plan(array_values($p['issues']),$p['covered_ids'],$p['scope']);$issues=is_wp_error($more)?['status'=>'native_alerts_pending','error'=>$more->get_error_code()]:$more;}
   update_post_meta($run,'_rate_plan_scope',$p['scope']);update_post_meta($run,'_rate_plan_snapshot',$p);update_post_meta($run,'_rate_plan_results',$results);update_post_meta($run,'_rate_plan_actor',get_current_user_id());update_post_meta($run,'_rate_plan_generate',$generate?1:0);update_post_meta($run,'_rate_plan_auto',$p['options']['auto_recheck']?1:0);
   do_action('mad4b/rate-planner/completed',$run,$p,$results);
   return ['run_id'=>$run,'plan_digest'=>$p['digest'],'counts'=>$p['counts'],'results'=>$results,'native_alerts'=>$issues,'checkout_ready'=>false];
  } finally {self::$writing=false;}
 }
 private static function emit(array $c):array {
  $result=['key'=>$c['key'],'state'=>$c['state'],'post_id'=>null];
  // A Rate Plan may report a missing-price finding; it must never create
  // an empty tour-rates CPT object as a stand-in for commercial evidence.
  if(empty($c['input']))return array_replace($result,[
   'state'=>'price_pending_no_rate',
   'reason'=>'positive_priced_rate_input_required','rate_created'=>false]);
  if($c['existing_ids'])return $result+['existing_ids'=>$c['existing_ids']];
  if(!current_user_can('edit_post',$c['tour_id']))return $result+['error'=>'parent_permission_denied'];global $wpdb;$lock='rate_plan_'.substr($c['key'],0,48);if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return $result+['error'=>'rate_plan_busy'];
  try {
   $found=get_posts(['post_type'=>'tour-rates','post_status'=>'any','fields'=>'ids','posts_per_page'=>2,'suppress_filters'=>true,'meta_key'=>'_mad4b_rate_plan_key','meta_value'=>$c['key']]);if(count($found)>1)return $result+['error'=>'duplicate_semantic_key'];
   if($found){$id=(int)$found[0];if(get_post_status($id)!=='draft')return $result+['error'=>'existing_rate_not_draft'];$stored=get_post_meta($id,'_mad4b_rate_plan_evidence',true);if($stored!==$c['evidence_digest'])return self::refreshDraft($id,$c,$result);return array_replace($result,['post_id'=>$id,'state'=>'reused']);}
   $r=Shared_Product_Rate_Generation::create($c['input']);
   if(is_wp_error($r))return $result+['error'=>$r->get_error_code()];
   $id=(int)$r['post_id'];
   if($r['status']==='reused' && get_post_meta($id,'_mad4b_rate_plan_key',true)!==$c['key'])
    return array_replace($result,['post_id'=>$id,'state'=>'existing_native_draft_review']);
   update_post_meta($id,'_mad4b_rate_plan_owned_snapshot',self::managedSnapshot($id));
   update_post_meta($id,'_mad4b_rate_plan_key',$c['key']);update_post_meta($id,'_mad4b_rate_plan_evidence',$c['evidence_digest']);update_post_meta($id,'_mad4b_rate_plan_snapshot',$c);update_post_meta($id,'_mad4b_rate_plan_price_state','reference_retail_profit_unverified');
   return array_replace($result,['post_id'=>(int)$id,'state'=>'created_draft']);
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 private static function managedKeys():array {return ['related_tour_id','related_package_term_id','related_program_term_id','tour_rate_start_date','tour_rate_end_date','stop_sale','is_on_sale','avilabile_status','unit_type','base_currency','single_price','double_price','triple_price','has_properties','related_properties_id','has_multiple_accommodation_stages','_booking_experience_stay_stages_v1','_accommodation_assignment_status','_accommodation_assignment_receipt','_shared_generated_rate_key','_shared_generation_schema','_shared_generation_source','_shared_generation_season_term_id','_mad4b_unpriced_proposal'];}
 private static function managedSnapshot(int $id):array {$out=[];foreach(self::managedKeys() as $k)$out[$k]=metadata_exists('post',$id,$k)?get_post_meta($id,$k,true):null;$out['season_terms']=self::terms($id,'season');return $out;}
 private static function refreshDraft(int $id,array $c,array $result):array {
  if(get_post_meta($id,'_mad4b_rate_plan_owned_snapshot',true)!==self::managedSnapshot($id))return array_replace($result,['post_id'=>$id,'error'=>'draft_manually_changed_review_required']);
  if(!$c['input']){
   $known=self::managedSnapshot($id);if(!empty($known['single_price'])||!empty($known['double_price'])||!empty($known['triple_price']))return array_replace($result,['post_id'=>$id,'error'=>'source_price_removed_review_required']);
   update_post_meta($id,'_mad4b_rate_plan_snapshot',$c);update_post_meta($id,'_mad4b_rate_plan_evidence',$c['evidence_digest']);return array_replace($result,['post_id'=>$id,'state'=>'updated_proposal']);
  }
  $native=Shared_Product_Rate_Generation::prepare($c['input']);if(is_wp_error($native))return array_replace($result,['post_id'=>$id,'error'=>$native->get_error_code()]);
  $collision=get_posts(['post_type'=>'tour-rates','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true,'meta_key'=>Shared_Product_Rate_Generation::META,'meta_value'=>$native['fingerprint']]);if(array_diff(array_map('intval',$collision),[$id]))return array_replace($result,['post_id'=>$id,'error'=>'existing_native_draft_requires_reconciliation']);
  foreach(self::managedKeys() as $k)if(!array_key_exists($k,$native['meta']))delete_post_meta($id,$k);
  foreach($native['meta'] as $k=>$v)update_post_meta($id,$k,$v);
  wp_set_object_terms($id,$c['window']['season_id']?[$c['window']['season_id']]:[],'season');
  update_post_meta($id,'_mad4b_rate_plan_snapshot',$c);update_post_meta($id,'_mad4b_rate_plan_evidence',$c['evidence_digest']);update_post_meta($id,'_mad4b_rate_plan_price_state','reference_retail_profit_unverified');update_post_meta($id,'_mad4b_rate_plan_owned_snapshot',self::managedSnapshot($id));
  return array_replace($result,['post_id'=>$id,'state'=>'updated_draft']);
 }
 public static function recheck(int $run):void {
  $p=get_post_meta($run,'_rate_plan_snapshot',true);$actor=(int)get_post_meta($run,'_rate_plan_actor',true);if(!is_array($p))return;if(!user_can($actor,'commerce_manage_pricing')||get_user_meta($actor,'mad4b_company_suspension_v1',true)){update_post_meta($run,'_rate_plan_held','actor_permission_revoked');return;}
  $old=get_current_user_id();wp_set_current_user($actor);try{$fresh=self::plan($p['options']);if(!is_wp_error($fresh)){$r=self::persist($fresh,(bool)get_post_meta($run,'_rate_plan_generate',true));update_post_meta($run,'_rate_plan_rechecked_at',gmdate('c'));if(!is_wp_error($r)){update_post_meta($run,'_rate_plan_auto',0);update_post_meta($run,'_rate_plan_successor',$r['run_id']);}}}finally{wp_set_current_user($old);}
 }
 public static function schedule():void {
  if(self::$writing)return;foreach(get_posts(['post_type'=>self::TYPE,'post_status'=>'private','fields'=>'ids','posts_per_page'=>-1,'meta_key'=>'_rate_plan_auto','meta_value'=>'1']) as $run)if(!wp_next_scheduled('mad4b_rate_plan_recheck',[(int)$run]))wp_schedule_single_event(time()+60,'mad4b_rate_plan_recheck',[(int)$run]);
 }
 public static function schema():array {return ['type'=>'object','required'=>['from','to'],'properties'=>['tour_ids'=>['type'=>'array','items'=>['type'=>'integer','minimum'=>1]],'from'=>['type'=>'string','format'=>'date'],'to'=>['type'=>'string','format'=>'date'],'locale_mode'=>['type'=>'string','enum'=>['inherit','independent']],'accommodation'=>['type'=>'string','enum'=>['auto','include','exclude']],'auto_recheck'=>['type'=>'boolean']],'additionalProperties'=>false];}
 public static function cli(array $args,array $assoc):void {
  if(!self::allowed())WP_CLI::error('Pricing permission required; use an authorized --user.');
  $op=$args[0]??'preview';if($op==='recheck'){self::recheck((int)($assoc['run']??0));WP_CLI::line('Recheck completed or held by actor/source permissions.');return;}
  if(!in_array($op,['preview','generate'],true))WP_CLI::error('Use preview, generate or recheck.');
  $ids=isset($assoc['tour-ids'])?array_values(array_filter(array_map('trim',explode(',',$assoc['tour-ids'])))):[];
  $p=self::plan(['tour_ids'=>$ids,'from'=>$assoc['from']??'','to'=>$assoc['to']??'','locale_mode'=>$assoc['locale-mode']??'inherit','auto_recheck'=>isset($assoc['auto-recheck']),'accommodation'=>$assoc['accommodation']??'auto']);
  if(is_wp_error($p))WP_CLI::error($p->get_error_code());
  $r=$op==='generate'||isset($assoc['record-gaps'])?self::persist($p,$op==='generate'):$p;
  if(is_wp_error($r))WP_CLI::error($r->get_error_code());WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b rate-plan',[self::class,'cli']);
  add_action('init',static function(){register_post_type(self::TYPE,['label'=>'Rate Planning Runs','public'=>false,'show_ui'=>false,'supports'=>['title'],'capability_type'=>['commerce_rate','commerce_rates'],'map_meta_cap'=>true]);});
  add_action('mad4b_rate_plan_recheck',[self::class,'recheck']);
  foreach(['updated_post_meta','added_post_meta','deleted_post_meta'] as $hook)add_action($hook,static function($mid,$id,$key){if(str_starts_with($key,'_rate_plan_')||str_starts_with($key,'_mad4b_rate_plan'))return;if(in_array(get_post_type($id),['tours-and-activities','tour-rates','properties','units','property-rates'],true))self::schedule();},100,3);
  foreach(['updated_term_meta','added_term_meta','deleted_term_meta'] as $hook)add_action($hook,static function(){self::schedule();},100);
  add_action('save_post',static function($id){if(in_array(get_post_type($id),['tours-and-activities','tour-rates','properties','units','property-rates'],true))self::schedule();},100);
  add_action('set_object_terms',static function(){self::schedule();},100);add_action('edited_term',static function(){self::schedule();},100);foreach(['added_option','updated_option','deleted_option'] as $hook)add_action($hook,static function($key){if(str_contains($key,'site_profile')||preg_match('/(?:^|_)shared_/',$key)||in_array($key,['commerce_company_policy_registry_v1','service-activation','commerce_management_company_definitions_v1','mad4b_content_readiness_workflow_v1','commerce_request_review_board'],true))self::schedule();},100);
  add_filter('mad4b/content_readiness/external_issues',static function($rows){if(!is_array($rows))return $rows;foreach((array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]) as $r)if(($r['source']??'')===self::SOURCE&&($r['status']??'open')!=='resolved')$rows[]=array_intersect_key($r,array_flip(['id','post_type','object_id','code','severity','label','source']));return $rows;},35);
  add_action('wp_abilities_api_init',static function(){if(!function_exists('wp_register_ability'))return;foreach(['preview'=>false,'generate'=>true] as $name=>$write)wp_register_ability('mad4b/rate-plan-'.$name,['label'=>'Rate plan '.$name,'description'=>'Native partial-data Tour Rate plan with company policy review and MAD4B gaps. No publication, payment or supplier booking.','category'=>$write?'mad4b-write':'mad4b-read','input_schema'=>['type'=>'object','required'=>['options'],'properties'=>['options'=>self::schema()],'additionalProperties'=>false],'output_schema'=>['type'=>'object','additionalProperties'=>true],'permission_callback'=>[self::class,'allowed'],'execute_callback'=>static function($input)use($write){$p=self::plan((array)($input['options']??[]));return is_wp_error($p)||!$write?$p:self::persist($p,true);},'meta'=>['show_in_rest'=>true,'mcp'=>['public'=>false,'type'=>'tool','surface'=>$write?'write':'read'],'annotations'=>['readonly'=>!$write,'destructive'=>false,'idempotent'=>!$write]]]);},61);
  add_action('rest_api_init',static function(){register_rest_route('shared-product/v1','/rate-plan',['methods'=>'POST','permission_callback'=>[self::class,'allowed'],'callback'=>static function($r){$body=$r->get_json_params();if(!is_array($body)||array_diff(array_keys($body),['options','generate','persist'])||(isset($body['generate'])&&!is_bool($body['generate']))||(isset($body['persist'])&&!is_bool($body['persist'])))return new WP_Error('rate_plan_request_invalid','Invalid request',['status'=>400]);$p=self::plan((array)($body['options']??[]));if(is_wp_error($p))return $p;return rest_ensure_response(!empty($body['persist'])?self::persist($p,!empty($body['generate'])):$p);}]);});
  add_action('admin_menu',static function(){add_submenu_page('edit.php?post_type=tour-rates','Commercial Rate Plan','Plan & Generate','commerce_manage_pricing','mad4b-rate-planner',[self::class,'page']);});
 }
 public static function page():void {
  if(!self::allowed())wp_die('Forbidden',403);echo '<div class="wrap"><h1>Commercial Rate Plan</h1><p>Generate available draft prices and unpriced proposals. Missing evidence becomes a MAD4B gap; no Rate is published.</p>';
  if(($_SERVER['REQUEST_METHOD']??'')==='POST'){check_admin_referer('mad4b_rate_plan');$raw=wp_unslash($_POST);$ids=array_filter(array_map('trim',explode(',',(string)($raw['tour_ids']??''))));$p=self::plan(['tour_ids'=>array_values($ids),'from'=>$raw['from']??'','to'=>$raw['to']??'','locale_mode'=>$raw['locale_mode']??'inherit','auto_recheck'=>isset($raw['auto_recheck']),'accommodation'=>$raw['accommodation']??'auto']);if(is_wp_error($p))echo '<p>'.esc_html($p->get_error_code()).'</p>';else {$r=self::persist($p,($raw['operation']??'')==='generate');echo '<pre>'.esc_html(wp_json_encode(is_wp_error($r)?['error'=>$r->get_error_code()]:$r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';}}
  echo '<h2>MAD4B planning gaps</h2><table class="widefat striped"><tr><th>Tour</th><th>Gap</th><th>Owner group</th><th>Boards task</th></tr>';foreach((array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]) as $gap)if(($gap['source']??'')===self::SOURCE&&($gap['status']??'open')!=='resolved'&&current_user_can('edit_post',(int)$gap['object_id']))echo '<tr><td><a href="'.esc_url(get_edit_post_link((int)$gap['object_id'])).'">#'.(int)$gap['object_id'].'</a></td><td>'.esc_html($gap['label']).'</td><td>'.esc_html($gap['source_evidence']['owner_group']??'pricing').'</td><td>'.(int)($gap['board_task_id']??0).'</td></tr>';echo '</table>';
  echo '<form method="post">';wp_nonce_field('mad4b_rate_plan');echo '<p>Tour IDs (comma-separated; empty means all published Tour families) <input name="tour_ids"></p><p>From <input type="date" name="from" required value="'.esc_attr(wp_date('Y-m-d')).'"> To <input type="date" name="to" required value="'.esc_attr(wp_date('Y-m-d',strtotime('+1 year'))).'"></p><p><select name="accommodation"><option value="auto">Accommodation: only native linked stay evidence</option><option value="include">Plan accommodation explicitly</option><option value="exclude">No accommodation in this plan</option></select></p><p><select name="locale_mode"><option value="inherit">Shared commercial price family</option><option value="independent">Independent published language variants</option></select></p><p><label><input type="checkbox" name="auto_recheck" value="1">Recheck after source data or policy changes</label></p><button class="button" name="operation" value="preview">Review plan and gaps</button> <button class="button button-primary" name="operation" value="generate">Generate draft Rates</button></form></div>';
 }
}
MAD4B_Rate_Planner::boot();
