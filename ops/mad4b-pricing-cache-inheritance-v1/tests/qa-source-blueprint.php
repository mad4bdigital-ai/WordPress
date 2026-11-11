<?php
define('MAD4B_PRICING_CACHE_QA',true);
$_SERVER['REQUEST_METHOD']='GET';
$K='MAD4B_Pricing_Cache_Inheritance_V1';
$expected=shared_product_price_source_policy_uncached('tour');
if(is_wp_error($expected))throw new RuntimeException('SOURCE_NATIVE_UNAVAILABLE');
$count=0;
$producer=static function()use(&$count){++$count;return shared_product_price_source_policy_uncached('tour');};
$a=$K::memoize_source_policy('tour',$producer);
$b=$K::memoize_source_policy('tour',$producer);
if($a!==$expected||$b!==$expected||$count>1)
  throw new RuntimeException('SOURCE_POLICY_CACHE_VALUE_MISMATCH');
$metrics=$K::metrics()['counters'];
if($metrics['hit']<1)throw new RuntimeException('NO_HIT_SOURCE_POLICY');
$mode=$K::decision('pricing_source_definition',['tour_mode'=>'tour']);
if(!$mode['cacheable']||!$mode['persistent_cache_activated'])throw new RuntimeException('SOURCE_DEFINITION_NOT_CACHEABLE');
$quote=$K::decision('customer_quote',['currency'=>'EUR','market_profile'=>'eu','occupancy'=>'double']);
if($quote['cacheable']||$quote['ttl_seconds']!==0)throw new RuntimeException('USER_QUOTE_LEAK');
echo 'MAD4B_SOURCE_BLUEPRINT_QA='.wp_json_encode(['contract'=>'mad4b.pricing-cache-source-blueprint.v1',
 'actual'=>$a,'producer_invocations'=>$count,'redis_hits'=>$metrics['hit'],
 'quote_shared'=>false,'state'=>'PASS'],JSON_UNESCAPED_SLASHES)."\n";
