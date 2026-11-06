<?php
$results=[];
$check=static function(string $name,bool $pass) use (&$results) {
    $results[$name]=$pass;
    if(!$pass)throw new RuntimeException('failed_'.$name);
};
$d=MAD4B_LSC_Local_Provider_V1::css_delivery_diagnostics_v2();
$check('contract',($d['contract']??'')==='mad4b.lsc.css-effective-state.v2');
$check('source_mode',MAD4B_LSC_Local_Provider_V1::scope()&&MAD4B_LSC_Local_Provider_V1::mode()==='local_first');
$check('resolved_keys',isset($d['configured']['optm-css_async'],$d['resolved']['optm-css_async'],$d['configured']['optm-ucss_inline']));
foreach(['optm-ucss','optm-css_async','optm-ucss_inline','optm-css_async_inline'] as $key) {
 $v=(string)get_option('litespeed.conf.'.$key,'0')==='1';
 $check('configured_value_'.$key,$v===$d['configured'][$key]);
}
$check('has_dormant_ucss',!$d['configured']['optm-ucss_inline']||$d['resolved']['optm-ucss']||in_array('UCSS_INLINE_PARENT_OFF',$d['drift_codes'],true));
$check('html_observer_present',count($d['observed_frontend']??[])>=1);
$private=false;
foreach($d['observed_frontend'] as $row)if(str_contains((string)($row['id']??''),'/.well-known/'))$private=true;
$check('no_protocol_endpoint_in_observer',!$private);
$check('native_option_hooks',has_action('update_option_litespeed.conf.optm-ucss')!==false && has_action('update_option_litespeed.conf.optm-css_async')!==false);
$check('css_manifest_bundle_drift_detected',in_array('CSS_BUNDLE_NOT_IN_CERTIFIED_MANIFEST',$d['drift_codes'],true));
$check('bundle_drift_has_source',($d['bundle_drift']['site_host']??'')===wp_parse_url((string)get_option('home'),PHP_URL_HOST));
$oldEligibility=MAD4B_LSC_Local_Provider_V1::local_css_eligible();
$filter=static fn($v)=>true;
add_filter('litespeed_conf_load_option_optm-css_async',$filter,999);
try {
 $override=MAD4B_LSC_Local_Provider_V1::css_delivery_diagnostics_v2();
 $check('resolved_override_discovered',($override['resolved']['optm-css_async']??false)===true
    && in_array('RESOLVED_OVERRIDE_optm-css_async',$override['drift_codes'],true));
 $check('double_owner_prevented',MAD4B_LSC_Local_Provider_V1::local_css_eligible()===false);
} finally {remove_filter('litespeed_conf_load_option_optm-css_async',$filter,999);}
$check('recovered_owner_eligibility',MAD4B_LSC_Local_Provider_V1::local_css_eligible()===$oldEligibility);
$rec=get_option('mad4b_perf_css_policy_receipt_v2',[]);
$check('no_unverified_public_async',($rec['approved_count']??-1)===0 && get_option('mad4b_perf_predictive_css_v2','off')!=='on');
echo json_encode(['tests'=>count($results),'passed'=>count(array_filter($results)),
 'all_passed'=>!in_array(false,$results,true)],JSON_UNESCAPED_SLASHES)."\n";
