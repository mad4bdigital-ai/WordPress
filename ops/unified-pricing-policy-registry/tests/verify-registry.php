<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Unified_Pricing_Policy_Registry_V1';
if(!class_exists($cls,false))WP_CLI::error('Unified Registry class not loaded');
$data=$cls::snapshot();
if(is_wp_error($data))WP_CLI::error('Failed registry readback '.$data->get_error_code());
if($data['schema']!==$cls::SCHEMA||
  $data['read_only']!==true||
  $data['stores_unmodified']!==true||
  $data['checkout_activation_unproven']!==true)
 WP_CLI::error('Read-only contract did not hold');
$source=$data['sources'];
foreach(['tour_type_markup','package_levels','tour_mode','price_source',
'pricing_lab','native_presets','components','guide_costs','transport_self'] as $name)
 if(!isset($source[$name]))WP_CLI::error('Missing unified source '.$name);
$families=[];
foreach($data['rows'] as $entry){
 if(($entry['commercial_active']??null)!==false||
    ($entry['authorized_to_change_rate']??null)!==false||
    empty($entry['source'])||empty($entry['status']))
 WP_CLI::error('Pricing Registry row mislabeled as Checkout eligible');
 $families[$entry['family']][]=$entry;
}
$site=home_url();
if(str_contains($site,'wayfatours.com')){
 if(count($families['tour_type']??[])!==4 ||
    count($families['package_level']??[])!==3)
 WP_CLI::error('WPML canonical 8 Type rows / 6 package rows not consolidated as 4+3');
 $expect=['Silver'=>110,'Gold'=>115,'Diamond'=>120];
 foreach($families['package_level'] as $row){
  $label=$row['name'];
  if(!isset($expect[$label])||
   ($row['data']['percent']??-1)!==$expect[$label] &&
     (float)($row['data']['percent']??-1)!==(float)$expect[$label] ||
     $row['status']!=='preview_eligible' ||
     count($row['data']['source_term_ids']??[])!==2)
    WP_CLI::error('Package canonical rule inconsistent '.$label);
 }
 if(count(array_filter($families['tour_type'],static fn($r)=>
    $r['status']==='preview_eligible'))!==4)
    WP_CLI::error('Type Markup preview eligibility lost');
 $supplier=$families['transport_self'][0]??[];
 $liveSource=get_option('mad4b_transport_self_pricing_controls_v1',[]);
 $expected=($liveSource['price_role']??'')==='self_cost' &&
    ($liveSource['currency']??'')==='EGP'
    ?'configured_supplier_cost':'reference_only_no_cost_authority';
 if(($supplier['status']??'')!==$expected)
  WP_CLI::error('Wayfa transport registry state disagrees with live source');
 WP_CLI::log('PASS Wayfatours 8 Type / 6 Package language entries canonicalize to 4 + 3 preview rules');
 WP_CLI::log('PASS Wayfatours transport dynamically follows live price_role='.$liveSource['price_role'].' currency='.$liveSource['currency']);
}else{
 if(count($families['tour_type']??[])!==0||
    count($families['package_level']??[])!==0)
 WP_CLI::error('Source staging unexpectedly has activated package or Tour Type markups');
 $supplier=$families['transport_self'][0]??[];
 if(($supplier['status']??'')!=='configured_supplier_cost')
  WP_CLI::error('Staging transport supplier source not recognized');
 WP_CLI::log('PASS Staging no copied Wayfa previews; native cost source recognized');
}
if(count($families['guide_cost']??[])!==5||
   count($families['component']??[])!==3||
   count($families['tour_mode']??[])!==4)
 WP_CLI::error('Guide/Component/Mode families not unified');
if(count($data['by_dimension']['taxonomy:guide-languages']??[])!==5)
 WP_CLI::error('Guide cost terms unavailable in unified readback');
WP_CLI::log('PASS Guide 5, component 3, Tour Mode 4 retained as different authority classes');
$summary=MAD4B_Pricing_Rules_Visibility_V1::snapshot();
if(is_wp_error($summary)||
   $summary['unified_registry']['source_fingerprint']!==$data['source_fingerprint'])
 WP_CLI::error('Legacy status inspector not connected to unified registry');
WP_CLI::log('PASS existing Pricing Controls inspector consumes same normalized registry');
wp_set_current_user(0);
$forbidden=$cls::snapshot();
if(!is_wp_error($forbidden)||$forbidden->get_error_code()!=='unified_pricing_registry_forbidden')
 WP_CLI::error('Guest could read company pricing rule registry');
WP_CLI::log('PASS privileged-only pricing registry read');
echo 'UNIFIED_REGISTRY '.wp_json_encode([
 'site'=>$site,'source_count'=>$data['source_count'],
 'financial_rules'=>$data['financial_rule_count'],
 'counts'=>$data['status_counts'],
 'families'=>array_map('count',$families),
 'warnings'=>$data['warnings'],'fingerprint'=>$data['source_fingerprint']
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('MAD4B_UNIFIED_POLICY_REGISTRY_ACCEPTED_NO_WRITES');
