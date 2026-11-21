<?php
if(!defined('ABSPATH') || !defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Component_Cost_Contract_V1';
if(!class_exists($cls,false))WP_CLI::error('Missing component cost contract');
$ids=get_posts(['post_type'=>'tours-and-activities',
    'post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids']);
$tour=(int)($ids[0]??0);
if(!$tour)WP_CLI::error('No published test Tour');
$date='2026-12-15';
$make=static function($kind,$index,$currency='EGP')use($cls,$tour,$date){
    return [
        'schema'=>$cls::SCHEMA,
        'tour_id'=>$tour,'service_date'=>$date,
        'kind'=>$kind,'line_key'=>$kind.'-'.$index,
        'supplier_id'=>'self','source'=>'self_cost','role'=>'supplier_cost',
        'currency'=>$currency,'status'=>'approved',
        'quantity'=>1,'amount_minor'=>200000,
        'reviewer_id'=>1,'revision'=>1,
        'source_sha256'=>hash('sha256',$kind.'-'.$index),
        'approved_at'=>'2026-11-20T10:00:00+00:00',
        'valid_until'=>'2026-12-20',
    ];
};
$quotes=[
    $make('accommodation',1),$make('transportation',1),
    $make('guide',1),$make('extras',1)
];
$good=$cls::compose($tour,$date,$quotes);
if(is_wp_error($good) || $good['supplier_cost_total_minor']!==800000 ||
    $good['supplier_cost_currency']!=='EGP' ||
    $good['rate_generation_ready']!==false ||
    $good['checkout_ready']!==false ||
    $good['retail_price_changed']!==false ||
    $good['ready_for_provider_reverification']!==true)
    WP_CLI::error('Valid cost-only preview failed or activated sale incorrectly');
$cases=[
    'retail_rate_disallowed'=>static function($rows){$rows[0]['role']='retail_rate';return $rows;},
    'duplicate_line'=>static function($rows){$rows[1]['line_key']=$rows[0]['line_key'];return $rows;},
    'fx_required'=>static function($rows){$rows[1]['currency']='USD';return $rows;},
    'false_approval'=>static function($rows){$rows[1]['status']='draft';return $rows;},
    'unreviewed_source'=>static function($rows){$rows[1]['source_sha256']='';return $rows;},
    'wrong_tour'=>static function($rows){$rows[1]['tour_id']=9999999;return $rows;},
    'wrong_date'=>static function($rows){$rows[1]['service_date']='2026-12-16';return $rows;},
    'expired_quote'=>static function($rows){$rows[1]['valid_until']='2026-12-01';return $rows;},
    'bad_quantity'=>static function($rows){$rows[1]['quantity']=0;return $rows;},
    'uncertified_zero'=>static function($rows){$rows[1]['amount_minor']=0;return $rows;},
];
$blocked=[];
foreach($cases as $name=>$transform){
    $test=$cls::compose($tour,$date,$transform($quotes));
    if(!is_wp_error($test))WP_CLI::error('Risk not blocked: '.$name);
    $blocked[$name]=$test->get_error_code();
}
$missing=$cls::compose($tour,$date,[$quotes[2]]);
if(is_wp_error($missing) ||
    count($missing['missing_components'])!==3 ||
    $missing['supplier_cost_total_minor']!==null ||
    $missing['rate_generation_ready']!==false)
    WP_CLI::error('Missing component inputs were incorrectly promoted to a total');
$mult=$quotes;$mult[0]['quantity']=2;
$multResult=$cls::compose($tour,$date,$mult);
if(is_wp_error($multResult)||$multResult['supplier_cost_total_minor']!==1000000)
    WP_CLI::error('Quantity multiplication incorrect');
echo 'COMPONENT_COST_CONTRACT_TEST '.wp_json_encode([
  'site'=>home_url(),'tour'=>$tour,
  'valid_cost_total_minor'=>$good['supplier_cost_total_minor'],
  'blocked_risks'=>$blocked,'missing_count'=>count($missing['missing_components']),
  'retail_rate_authorized'=>false,'checkout_ready'=>false,
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('SUPPLIER_COST_PREVIEW_CONTRACT_PASS_WITHOUT_SALE_ACTIVATION');
