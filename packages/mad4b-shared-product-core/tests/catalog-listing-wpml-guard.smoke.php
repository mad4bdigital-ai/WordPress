<?php
/**
 * Read-only WP-CLI smoke for source-backed WPML Listing siblings.
 * Usage: wp --allow-root eval-file tests/catalog-listing-wpml-guard.smoke.php
 * Requires WordPress, JetEngine and WPML with an installed Tours archive.
 */
if (!defined('ABSPATH') || !class_exists('WP_CLI')) { return; }
if (!function_exists('shared_presentation_catalog_jsf_request_v1')) WP_CLI::error('MISSING_CATALOG_CONTRACT');
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
$contract=shared_presentation_catalog_contract_v1();
$source=absint($contract['listing_id']??0);
if (!$source || get_post_type($source)!=='jet-engine' || get_post_status($source)!=='publish') WP_CLI::error('CANONICAL_LISTING_NOT_READY');
$trid=(int)apply_filters('wpml_element_trid',null,$source,'post_jet-engine');
if (!$trid) WP_CLI::error('WPML_TRID_NOT_REGISTERED');
$available=['en'=>$source];
foreach(['es','ar','de','fr','it'] as $lang){
 $id=(int)apply_filters('wpml_object_id',$source,'jet-engine',false,$lang);
 $data=$id?apply_filters('wpml_post_language_details',null,$id):null;
 if($id && get_post_status($id)==='publish' && is_array($data) && ($data['language_code']??'')===$lang) $available[$lang]=$id;
}
$check=static function($lang,$id,$queryId=null,$signature=null,$provider=null)use($contract){
 $_POST=[
  'action'=>'jet_smart_filters',
  'provider'=>$provider??$contract['provider'],
  'settings'=>['lisitng_id'=>(string)$id,'custom_query_id'=>(string)($queryId??$contract['query_id']),
   'jsf_signature'=>$signature??str_repeat('a',32)],
  'referrer'=>['uri'=>($lang==='en'?'':'/'.$lang).'/tours-and-activities/'],
  'query'=>[]
 ];
 $_REQUEST=$_POST;
 return shared_presentation_catalog_jsf_request_v1();
};
$positive=[];foreach($available as $lang=>$id){$out=$check($lang,$id);$positive[$lang]=is_array($out) && ($out['language']??'')===$lang;}
$negative=[];
if(isset($available['es'])){
 $es=$available['es'];
 $negative['es_using_en_listing']=$check('es',$source)===null;
 $negative['en_using_es_listing']=$check('en',$es)===null;
 $negative['wrong_query_id']=$check('es',$es,'9999')===null;
 $negative['invalid_signature']=$check('es',$es,null,'not-a-hex-signature')===null;
 $negative['wrong_provider']=$check('es',$es,null,null,'fake-engine/archive')===null;
}
$out=['positive'=>$positive,'negative_rejected'=>$negative,'canonical_listing'=>$source,
 'translated_languages_tested'=>count($positive)-1,
 'pass'=>!in_array(false,$positive,true)&&!in_array(false,$negative,true) && count($positive)>1];
WP_CLI::line(wp_json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
if(!$out['pass']) WP_CLI::error('WPML_LISTING_CONTRACT_REGRESSION');

