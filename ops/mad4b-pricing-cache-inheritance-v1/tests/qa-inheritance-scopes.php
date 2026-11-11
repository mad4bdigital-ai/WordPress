<?php
$K='MAD4B_Pricing_Cache_Inheritance_V1';
$types=['tours-and-activities','tour-rates'];
$found=[];
foreach($types as $type){
 $ids=get_posts(['post_type'=>$type,'post_status'=>'publish','posts_per_page'=>8,'fields'=>'ids']);
 foreach($ids as $id) {
  if($type==='tour-rates') {
   $parent=(int)get_post_meta($id,'related_tour_id',true);
   if($parent<1 || get_post_type($parent)!=='tours-and-activities')continue;
   $found['rate']=(int)$id;$found['tour']=$parent;
   break;
  }
 }
}
assert(isset($found['rate'],$found['tour']));
$mode=(string)get_post_meta($found['tour'],'tour_mode',true);
if(!preg_match('/^[a-z][a-z0-9_]{1,50}$/D',$mode))$mode='tour';
$programs=get_terms(['taxonomy'=>'programs','hide_empty'=>false,'number'=>1,'fields'=>'ids']);
$seasons=get_terms(['taxonomy'=>'season','hide_empty'=>false,'number'=>1,'fields'=>'ids']);
$program=!is_wp_error($programs)&&$programs?(int)$programs[0]:0;
$season=!is_wp_error($seasons)&&$seasons?(int)$seasons[0]:0;
$raw=['company'=>['policy_ttl'=>1200,'mode'=>'auto'],
 'modes'=>[$mode=>['policy_ttl'=>1000]],
 'terms'=>['programs'=>[],'season'=>[]],
 'tours'=>[$found['tour']=>['policy_ttl'=>400]],
 'rates'=>[$found['rate']=>['policy_ttl'=>200]]];
if($program)$raw['terms']['programs'][$program]=['policy_ttl'=>700];
if($season)$raw['terms']['season'][$season]=['policy_ttl'=>500];
add_filter('pre_option_mad4b_pricing_cache_inheritance_v1',static function()use(&$raw){return $raw;},PHP_INT_MAX);
$ctx=['tour_id'=>$found['tour'],'rate_id'=>$found['rate'],'tour_mode'=>$mode,
 'program_term_id'=>$program,'season_id'=>$season];
$selected=$K::decision('mode_policy_definition',$ctx);
assert($selected['ttl_seconds']===200);
assert(in_array('rate:'.$found['rate'],$selected['inheritance'],true));
$ctx['rate_id']=0;
$tour=$K::decision('mode_policy_definition',$ctx);
assert($tour['ttl_seconds']===400);
$ctx['tour_id']=0;
$seasonResult=$K::decision('mode_policy_definition',$ctx);
assert($seasonResult['ttl_seconds']===($season?500:($program?700:1000)));
$ctx['season_id']=0;
$programResult=$K::decision('mode_policy_definition',$ctx);
assert($programResult['ttl_seconds']===($program?700:1000));
$ctx['program_term_id']=0;
$modeResult=$K::decision('mode_policy_definition',$ctx);
assert($modeResult['ttl_seconds']===1000);
$ctx['tour_mode']='';
$companyResult=$K::decision('mode_policy_definition',$ctx);
assert($companyResult['ttl_seconds']===1200);
$raw['rates'][$found['rate']]=['mode'=>'private_only','policy_ttl'=>200];
$ctx=['tour_id'=>$found['tour'],'rate_id'=>$found['rate'],'tour_mode'=>$mode];
$private=$K::decision('mode_policy_definition',$ctx);
assert(!$private['cacheable'] && $private['ttl_seconds']===0);
foreach(['customer_quote','checkout_total','live_availability','market_price_adjustment',
         'rate_price_amount','extras_selection'] as $dynamic){
 $test=$K::decision($dynamic,$ctx);
 assert(!$test['cacheable'] && $test['ttl_seconds']===0);
}
$raw['rates'][$found['rate']]=['policy_ttl'=>200];
$ctx['tour_id']=0; // prevent inheriting an override without a valid tour context
$rateOnly=$K::decision('mode_policy_definition',$ctx);
assert($rateOnly['ttl_seconds']===200);
echo 'MAD4B_SCOPED_INHERITANCE='.wp_json_encode([
 'contract'=>'mad4b.pricing-cache-scoped-hierarchy.v1',
 'company'=>1200,'mode'=>1000,'program'=>$program?700:null,'season'=>$season?500:null,
 'tour'=>400,'rate'=>200,'private_override_cacheable'=>$private['cacheable'],
 'dynamic_scopes_cached'=>false,'test_rate_id'=>$found['rate'],'test_tour_id'=>$found['tour'],
 'state'=>'PASS'
],JSON_UNESCAPED_SLASHES)."\n";
