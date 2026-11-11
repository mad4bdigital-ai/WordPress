<?php
/** Pure language resolver tests; no WordPress site or outgoing messages. */
$GLOBALS['mode']=1;
$GLOBALS['site']='https://wayfatours.com/';
$GLOBALS['languages']=['en'=>[],'es'=>[]];
function get_post_type($id){return 'tours-and-activities';}
function home_url($path='/'){return rtrim($GLOBALS['site'],'/').'/'.ltrim($path,'/');}
function get_option($key,$fallback=[]){
 if($key==='icl_sitepress_settings')return ['language_negotiation_type'=>$GLOBALS['mode']];
 return $fallback;
}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function apply_filters($hook,$value,...$args){
 if($hook==='wpml_element_language_code')return $GLOBALS['sourceLang']??'en';
 if($hook==='wpml_default_language')return 'en';
 if($hook==='wpml_active_languages')return $GLOBALS['languages'];
 return $value;
}
require dirname(__DIR__).'/wp-content/plugins/commerce-composition/includes/RequestLifecycle.php';
$cls=\Commerce\Composition\RequestLifecycle::class;
function context($url){return ['source_id'=>101,'row'=>['source_url'=>$url]];}
function check($result,$message){if(!$result){fwrite(STDERR,"FAIL: ".$message."\n");exit(2);}}
check($cls::requestLanguage(context('https://wayfatours.com/es/tours-and-activities/test/'))==='es','Spanish submitted page');
check($cls::requestLanguage(context('https://wayfatours.com/en/tour/'))==='en','English submitted page');
check($cls::requestLanguage(context('https://wayfatours.com/tour/'))==='en','Default language page');
$GLOBALS['sourceLang']='es';
check($cls::requestLanguage(context('https://untrusted.example/es/tour/'))==='es','Foreign URL cannot claim a language');
check($cls::requestLanguage(context('http://wayfatours.com/es/tour/'))==='es','Insecure URL ignored');
check($cls::requestLanguage(['source_id'=>101])==='es','Source translated language fallback');
$GLOBALS['sourceLang']='en';
check($cls::requestLanguage(context('https://wayfatours.com/ar/tour/'))==='en','Unavailable locale rejected');
$GLOBALS['mode']=3;
check($cls::requestLanguage(context('https://wayfatours.com/tour/?lang=es'))==='es','WPML parameter mode');
check($cls::requestLanguage(context('https://wayfatours.com/tour/?lang=fr'))==='en','Unavailable parameter rejected');
$GLOBALS['site']='https://wayfatours.com/site/';
$GLOBALS['mode']=1;
check($cls::requestLanguage(context('https://wayfatours.com/site/es/tour/'))==='es','Subdirectory site');
check($cls::requestLanguage(context('https://wayfatours.com/other/es/tour/'))==='en','Different WordPress base rejected');
echo json_encode(['state'=>'PASS','cases'=>11,'site_calls'=>0,'mail_sent'=>0]).PHP_EOL;
