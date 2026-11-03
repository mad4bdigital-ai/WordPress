<?php
/**
 * Read-only WP-CLI acceptance for JSF request-scoped validation cache.
 * Run on the real installed shared-product-core implementation.
 */
if (!defined('ABSPATH') || !class_exists('WP_CLI')) return;
if (!defined('DOING_AJAX')) define('DOING_AJAX',true);
if (!function_exists('shared_presentation_catalog_jsf_request_v1')) WP_CLI::error('MISSING_JSF_CONTEXT');
$contract=shared_presentation_catalog_contract_v1();
$listing=(int)$contract['listing_id'];
$ids=get_terms(['taxonomy'=>'destinations','fields'=>'ids','hide_empty'=>false,'number'=>2]);
if (is_wp_error($ids) || !is_array($ids) || count($ids)<2) WP_CLI::error('REQUIRES_TWO_DESTINATION_TERMS');
$ids=array_map('strval',array_slice($ids,0,2));
$_POST=[
 'action'=>'jet_smart_filters',
 'provider'=>$contract['provider'],
 'settings'=>[
   'lisitng_id'=>(string)$listing,
   'custom_query_id'=>(string)$contract['query_id'],
   'jsf_signature'=>str_repeat('a',32)
 ],
 'referrer'=>['uri'=>'/tours-and-activities/'],
 'query'=>['_tax_query_destinations'=>$ids]
];
$_REQUEST=$_POST;
$reentryObserved=false;$reentryRejected=false;
$reenter=static function($trid,$id,$type)use(&$reentryObserved,&$reentryRejected){
 if(!$reentryObserved && $type==='post_jet-engine'){
  $reentryObserved=true;
  $reentryRejected=shared_presentation_catalog_jsf_request_v1()===null;
 }
 return $trid;
};
add_filter('wpml_element_trid',$reenter,10000,3);
$valid=shared_presentation_catalog_jsf_request_v1();
remove_filter('wpml_element_trid',$reenter,10000);
$initial=is_array($valid) && ($valid['language']??null)==='en';
$repeat=shared_presentation_catalog_jsf_request_v1()===$valid;
$_POST['settings']['lisitng_id']='999999';
$changedListingRejected=shared_presentation_catalog_jsf_request_v1()===null;
$_POST['settings']['lisitng_id']=(string)$listing;
$restored=shared_presentation_catalog_jsf_request_v1()===$valid;
$_POST['query']['_tax_query_destinations']=array_merge($ids,['operator_XOR']);
$invalidOperatorRejected=shared_presentation_catalog_jsf_request_v1()===null;
$_POST['query']['_tax_query_destinations']=array_merge($ids,['operator_AND']);
$validAnd=shared_presentation_catalog_jsf_request_v1()===$valid;
$out=compact('reentryObserved','reentryRejected','initial','repeat','changedListingRejected','restored','invalidOperatorRejected','validAnd');
$out['pass']=!in_array(false,$out,true);
WP_CLI::line(wp_json_encode($out));
if(!$out['pass'])WP_CLI::error('JSF_REQUEST_MEMO_REGRESSION');
