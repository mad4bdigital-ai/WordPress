<?php
define('MAD4B_PRICING_CACHE_QA',true);
$_SERVER['REQUEST_METHOD']='GET';
require_once '/tmp/mad4b-pricing-cache-inheritance.candidate.php';
$C='MAD4B_Pricing_Cache_Inheritance_V1';
$before=shared_product_mode_policies();
if(is_wp_error($before)||!is_array($before)||count($before)<3)throw new RuntimeException('CANONICAL_MODES_UNAVAILABLE');
$count=0;
$builder=static function()use(&$count){++$count;return shared_product_mode_policies();};
$first=$C::memoize_mode_policies($builder);
$second=$C::memoize_mode_policies($builder);
if($first!==$before||$second!==$before||$count>1)throw new RuntimeException('BLUEPRINT_PARITY_OR_MEMO_FAIL');
$metrics=$C::metrics()['counters'];
if($metrics['hit']<1)throw new RuntimeException('BLUEPRINT_REDIS_HIT_NOT_OBSERVED');
$quotation=$C::decision('customer_quote',['currency'=>'EUR','market_profile'=>'europe','adults'=>2]);
$checkout=$C::decision('checkout_total',['currency'=>'USD']);
$rate=$C::decision('rate_schedule_definition',['rate_id'=>123,'tour_id'=>321]);
foreach([$quotation,$checkout,$rate] as $p)if($p['cacheable']||$p['ttl_seconds']!==0)throw new RuntimeException('DYNAMIC_PRICE_SHARED_CACHE_LEAK');
$public=$C::decision('site_brand_tokens');
if(!$public['cacheable']||$public['ttl_seconds']!==1800)throw new RuntimeException('STATIC_POLICY_WRONG');
$prepared=$C::decision('mode_policy_definition',['tour_mode'=>'tour','language'=>'en']);
if(!$prepared['cacheable'])throw new RuntimeException('MODE_POLICY_NOT_CACHEABLE');
$override=['company'=>['policy_ttl'=>600,'static_ttl'=>1200]];
add_filter('pre_option_mad4b_pricing_cache_inheritance_v1',static function()use(&$override){return $override;},999);
$reduced=$C::decision('mode_policy_definition',['tour_mode'=>'tour']);
if($reduced['ttl_seconds']!==600)throw new RuntimeException('COMPANY_TTL_INHERITANCE_FAILED');
$override=['company'=>['policy_ttl'=>600],'modes'=>['tour'=>['policy_ttl'=>300]]];
$rateScoped=$C::decision('mode_policy_definition',['tour_mode'=>'tour']);
if($rateScoped['ttl_seconds']!==300||!in_array('mode:tour',$rateScoped['inheritance'],true))
 throw new RuntimeException('MODE_INHERITANCE_FAILED');
$forced=$C::decision('customer_quote',['tour_mode'=>'tour']);
if($forced['cacheable'])throw new RuntimeException('QUOTE_OVERRIDE_LEAK');
echo 'MAD4B_POLICY_CACHE_QA='.wp_json_encode([
 'contract'=>'mad4b.pricing-cache-qa.v1',
 'parity'=>true,'normalized_modes'=>count($before),'builder_invocations'=>$count,
 'cache_hits'=>$metrics['hit'],'cache_stores'=>$metrics['stored'],
 'default_ttl'=>$public['ttl_seconds'],'company_ttl'=>$reduced['ttl_seconds'],
 'mode_ttl'=>$rateScoped['ttl_seconds'],'quote_cacheable'=>$quotation['cacheable'],
 'rate_cacheable'=>$rate['cacheable'],'state'=>'PASS'
],JSON_UNESCAPED_SLASHES)."\n";
