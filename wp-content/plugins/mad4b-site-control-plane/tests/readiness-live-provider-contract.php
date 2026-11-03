<?php
/**
 * Standalone, no-DB/no-network provider acceptance. The live native source
 * supplied the exact read-only pricing, Geo and locale intake contracts.
 */
define('ABSPATH','/tmp/');
$GLOBALS['filters']=[];
function add_filter($name,$fn,$priority=10,$argc=1) {$GLOBALS['filters'][$name]=$fn;}
function has_filter($name) {return false;}
function apply_filters($name,$value,...$args) {
    return isset($GLOBALS['filters'][$name])
        ? ($GLOBALS['filters'][$name])($value,...$args):$value;
}
function get_post_status($id) {return in_array((int)$id,[5,7,9],true)?'publish':'draft';}
function get_post_type($id) {return match((int)$id){5=>'transportations',7=>'stations',9=>'tours-and-activities',default=>null};}
function get_post_field($field,$id) {return '2026-11-03 00:00:00';}
function get_option($key) {return $key==='woocommerce_calc_taxes'?'no':false;}
function wp_json_encode($payload,$flags=0) {return json_encode($payload,$flags);}
function absint($id) {return abs((int)$id);}
function sanitize_key($key) {return strtolower(preg_replace('/[^a-z0-9_\\-]/','',(string)$key));}
class MAD4B_SCP_Site_Profile {public static function site_uuid(){return 'stub-site-uuid';}}
class MAD4B_SCP_Locale_Experience_Alerts {public static function current(){return true;}}
require (getenv('MAD4B_PROVIDER_FILE') ?: dirname(__DIR__,3).'/mu-plugins/mad4b-readiness-pricing-geo-locale-adapter.php');
function check($ok,$label){
    if(!$ok)throw new RuntimeException('FAILED '.$label);
    $GLOBALS['passed'][]=$label;
}
function row($code,$src,$type='site',$id=0){
    return ['id'=>hash('sha256',$code.'|'.$id),'code'=>$code,'source'=>$src,
      'post_type'=>$type,'object_id'=>$id,'label'=>'Source review required',
      'severity'=>'high','source_evidence'=>['observations'=>[['id'=>'source-1']]]];
}
function intake($row,$previous=[]){
    return apply_filters('mad4b/content_readiness/provider_job_intake',
        ['id'=>$row['id'],'status'=>'open'],$row,$previous,$row['id']);
}
$GLOBALS['passed']=[];
$site=row('pricing_market_profile_missing','mad4b-pricing-workbench');
check(apply_filters('mad4b/content_readiness/valid_pricing_alert',false,$site)===true,'market_profile_valid');
$a=intake($site);
check(($a['content_job_request']['contract']??null)==='mad4b.pricing-source-contentjob-intake.v1'
    && ($a['content_job_request']['priority']??null)===75
    && ($a['content_job_request']['financial_authorization']??true)===false
    && ($a['content_job_request']['publication_authorized']??true)===false,'pricing_research_read_only');
$bad=row('pricing_native_rate_missing','mad4b-pricing-workbench','post',9);
check(apply_filters('mad4b/content_readiness/valid_pricing_alert',false,$bad)===false
    && !isset(intake($bad)['content_job_request']),'unsupported_post_type_rejected');
$transport=row('pricing_transport_rate_unapproved','mad4b-pricing-workbench','transportations',5);
check(apply_filters('mad4b/content_readiness/valid_pricing_alert',false,$transport)===true,'transport_native_source_valid');
$state=intake($transport,['content_job_id'=>'old','content_job_request'=>['source_sha256'=>'old-fingerprint']]);
check(($state['content_job_request']['status']??null)==='SOURCE_CHANGED_REVIEW'
    && ($state['content_job_request']['checkout_authorized']??true)===false,'linked_price_source_change_requires_review');
$geo=row('geo_station_coordinates_invalid','mad4b-geo-quality','stations',7);
$g=intake($geo);
check(($g['content_job_request']['contract']??null)==='mad4b.geo-fact-verification-intake.v1'
    && ($g['content_job_request']['price_inference_authorized']??true)===false,'geo_source_review_only');
$locale=row('lexp_filter_visibility_drift_es','mad4b-locale-experience');
$l=intake($locale);
check(($l['content_job_request']['contract']??null)==='mad4b.locale-experience-contentjob-intake.v1'
    && ($l['content_job_request']['language']??null)==='es'
    && ($l['content_job_request']['auto_publication_authorized']??true)===false,'locale_review_only');
$unknown=row('unrelated_status','other');
check(!isset(intake($unknown)['content_job_request']),'unknown_source_inert');
echo json_encode(['contract'=>'mad4b.readiness-live-provider-contract.v1','passed'=>count($GLOBALS['passed']),'tests'=>$GLOBALS['passed']],JSON_UNESCAPED_SLASHES).PHP_EOL;
