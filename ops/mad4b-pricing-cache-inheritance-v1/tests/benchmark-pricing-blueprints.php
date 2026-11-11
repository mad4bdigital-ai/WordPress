<?php
define('MAD4B_PRICING_CACHE_QA',true);$_SERVER['REQUEST_METHOD']='GET';
$trials=25;
$compute=function(callable $fn)use($trials) {
 $start=hrtime(true);$last=null;
 for($i=0;$i<$trials;$i++)$last=$fn();
 if(is_wp_error($last))throw new RuntimeException('BENCH_PRICING_RULE_UNAVAILABLE');
 return ['total_ms'=>round((hrtime(true)-$start)/1e6,2),'runs'=>$trials];
};
$mode_orig=$compute(static fn()=>shared_product_mode_policies_uncached());
$mode_cached=$compute(static fn()=>shared_product_mode_policies());
$source_orig=$compute(static fn()=>shared_product_price_source_policy_uncached('tour'));
$source_cached=$compute(static fn()=>shared_product_price_source_policy('tour'));
$metrics=MAD4B_Pricing_Cache_Inheritance_V1::metrics();
echo 'MAD4B_BLUEPRINT_BENCH='.wp_json_encode([
 'contract'=>'mad4b.blueprint-bench.v1',
 'mode_uncached'=>$mode_orig,'mode_cached'=>$mode_cached,
 'source_uncached'=>$source_orig,'source_cached'=>$source_cached,
 'metrics'=>$metrics['counters'],
 'quote_html_cached'=>false
],JSON_UNESCAPED_SLASHES)."\n";
