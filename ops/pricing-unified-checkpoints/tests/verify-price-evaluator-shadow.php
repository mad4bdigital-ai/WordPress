<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cost='MAD4B_Component_Cost_Contract_V1';
$cls='MAD4B_Price_Evaluator_Shadow_V1';
if(!class_exists($cost,false)||!class_exists($cls,false))
 WP_CLI::error('Cost/evaluator contract classes not loaded');
$tour=(int)(get_posts(['post_type'=>'tours-and-activities',
    'post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids'])[0]??0);
if(!$tour)WP_CLI::error('Missing published Tour');
$date='2026-12-15';
$receipts=[];
foreach($cost::KINDS as $name){
 $receipts[]=[
  'schema'=>$cost::SCHEMA,'tour_id'=>$tour,'service_date'=>$date,
  'kind'=>$name,'line_key'=>$name.'-01','supplier_id'=>'self',
  'source'=>'self_cost','role'=>'supplier_cost','currency'=>'EGP',
  'status'=>'approved','quantity'=>1,'amount_minor'=>200000,
  'reviewer_id'=>1,'revision'=>1,
  'source_sha256'=>hash('sha256',$name.'-01'),
  'approved_at'=>'2026-11-20T10:00:00+00:00','valid_until'=>'2026-12-20',
 ];
}
$composition=$cost::compose($tour,$date,$receipts);
if(is_wp_error($composition))WP_CLI::error('Could not compose cost fixture');
$rule=static fn($kind,$bps,$id='')=>[
 'kind'=>$kind,'basis_points'=>$bps,'id'=>$id?:$kind.'-01',
 'status'=>'approved','revision'=>1,
 'source_sha256'=>hash('sha256',$kind.'-01'),
];
$stack=[$rule('base_markup',2000),$rule('tour_type_markup',2500),
 $rule('package_markup',1500),$rule('promotion_discount',1000)];
$q=$cls::simulate($composition,$stack,1400);
if(is_wp_error($q))WP_CLI::error($q->get_error_code());
if($q['supplier_cost_minor']!==800000 || $q['subtotal_minor']!==1242000 ||
 $q['tax_minor']!==173880 || $q['simulated_total_minor']!==1415880 ||
 $q['checkout_ready']!==false || $q['rate_generation_ready']!==false ||
 $q['preview_only']!==true)
 WP_CLI::error('Deterministic 20% markup x 25% Type x 15% Package - 10% Discount + 14% tax incorrect');
$margin=$cls::simulate($composition,[$rule('base_margin',2000)]);
$markup=$cls::simulate($composition,[$rule('base_markup',2000)]);
if(is_wp_error($margin)||is_wp_error($markup)||
 $margin['subtotal_minor']!==1000000||$markup['subtotal_minor']!==960000)
 WP_CLI::error('Markup on cost incorrectly equated with gross margin');
$missing=$cost::compose($tour,$date,[$receipts[2]]);
$cases=[
 'missing_component'=>[$missing,[$rule('base_markup',2000)],0],
 'double_base'=>[$composition,[$rule('base_markup',2000),$rule('base_margin',2000)],0],
 'two_package'=>[$composition,[$rule('base_markup',2000),
   $rule('package_markup',1500,'package-a'),$rule('package_markup',1100,'package-b')],0],
 'duplicate_policy'=>[$composition,[$rule('base_markup',2000),
   $rule('tour_type_markup',1000,'base_markup-01')],0],
 'no_base'=>[$composition,[$rule('package_markup',2000)],0],
 'unbounded_tax'=>[$composition,[$rule('base_markup',2000)],3100],
 'margin_ge_100'=>[$composition,[$rule('base_margin',10000)],0],
];
$blocked=[];
foreach($cases as $name=>[$basis,$policies,$tax]){
 $r=$cls::simulate($basis,$policies,$tax);
 if(!is_wp_error($r))WP_CLI::error('Unverified simulation accepted: '.$name);
 $blocked[$name]=$r->get_error_code();
}
echo 'PRICE_EVALUATOR_SHADOW_TEST '.wp_json_encode([
 'site'=>home_url(),'tour_id'=>$tour,
 'example_total_minor'=>$q['simulated_total_minor'],
 'markup_20pct'=>$markup['subtotal_minor'],
 'margin_20pct'=>$margin['subtotal_minor'],
 'blocked'=>$blocked,
 'any_commercial_price_changed'=>false,
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('DETERMINISTIC_COST_TO_PRICE_SHADOW_ACCEPTED');
