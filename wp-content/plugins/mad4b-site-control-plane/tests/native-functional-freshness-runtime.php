<?php
define('ABSPATH',__DIR__.'/');
$GLOBALS['env']='staging';$GLOBALS['owner']=true;$GLOBALS['sha']=str_repeat('a',40);
$GLOBALS['source_ok']=true;$GLOBALS['pref']=str_repeat('b',32);$GLOBALS['items']=[];
$GLOBALS['wpml_enabled']=false;$GLOBALS['wpml_mismatch']=false;
define('ICL_SITEPRESS_VERSION','4.7.0');
function has_filter($hook){return $GLOBALS['wpml_enabled'] ? 1 : false;}
function get_posts($args){return [101];}
function get_post_type($id){return 'page';}
function get_post_status($id){return 'publish';}
function get_permalink($id){return $id===101 ? 'https://staging.example.test/en/about' : 'https://staging.example.test/ar/about';}
function apply_filters($hook,$value,...$args){
 if(!$GLOBALS['wpml_enabled'])return $value;
 if($hook==='wpml_element_trid')return 17;
 if($hook==='wpml_get_element_translations')return [
  'en'=>(object)['element_id'=>101], 'ar'=>(object)['element_id'=>202]];
 if($hook==='wpml_element_language_details')return (object)[
  'trid'=>$GLOBALS['wpml_mismatch']?99:17,
  'language_code'=>$args[0]['element_id']===101?'en':'ar'];
 return $value;
}
function add_action($a,$b,$c=10,$d=1){}
function current_user_can($x){return $GLOBALS['owner'];}
function get_option($k,$fallback=false){return $GLOBALS['items'][$k]??$fallback;}
function home_url($s='/'){return 'https://staging.example.test/';}
function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower($x));}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error{public function __construct($c,$m=''){}}
class MAD4B_SCP_Live_Acceptance_Observer{
 public static function build_provenance_identity_status(){return [
   'source_commit_sha'=>$GLOBALS['sha'],
   'identity_ready'=>$GLOBALS['source_ok'],'manifest_valid'=>$GLOBALS['source_ok']];}
}
class MAD4B_SCP_Browser_Acceptance_Admin_UI{
 public static function public_selection(){return [
   'preference_valid'=>true,'executor'=>'native_hybrid',
   'preference_source'=>'operator_saved_or_legacy',
   'configuration_revision'=>$GLOBALS['pref']];}
}
class MAD4B_SCP_Native_Cron_Acceptance{
 public static function staging(){return $GLOBALS['env']==='staging';}
 public static function status(){return ['job'=>$GLOBALS['cron_job']??[]];}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-native-hybrid-acceptance.php';
$c='MAD4B_SCP_Native_Hybrid_Acceptance';
function checkit($ok,$reason){if(!$ok){fwrite(STDERR,'FAIL '.$reason."\n");exit(1);}}
$e=$c::normalize([
 'route_count'=>2,'js_errors'=>0,'dom_ready'=>true,'http_ok'=>true,
 'same_origin'=>true,'canonical_match'=>true,'hreflang_count'=>2,
 'listing_count'=>3,'listing_unique'=>true,'listing_digest'=>str_repeat('1',64),
 'filter_controls'=>2,'filter_attempted'=>false,'filter_ajax_observed'=>false,
 'filter_dataset_changed'=>false,'filter_dataset_digest'=>'',
 'duration_ms'=>1200,'blocked_reason'=>'none'
]);
checkit(!is_wp_error($e),'browser observation normalized');
$id=$c::current_identity();
checkit($id['build_manifest_current']===true,'source provenance observed');
$e=array_merge($e,$id,['observed_epoch'=>time()]);
$GLOBALS['items'][$c::RESULT_OPTION]=$e;
$GLOBALS['cron_job']=array_merge($id,[
 'state'=>'smoke_observed','http_status'=>200,'checked_epoch'=>time()]);
$s=$c::functional_status(['scope'=>'frontend']);
checkit($s['staging_acceptance_ready']===true
 && $s['scope_statuses']['filter_interaction']==='NOT_APPLICABLE'
 && $s['release_ready']===false,'dynamic core frontend scope passes with unrequested filters not blocking');
$s=$c::functional_status(['scope'=>'server']);
checkit($s['staging_acceptance_ready']===true,'server-only scope passes');
$s=$c::functional_status(['scope'=>'filters']);
checkit(!$s['staging_acceptance_ready']
 && in_array('filter_interaction_not_verified',$s['blocking_reasons'],true),
 'explicit filters need real interaction');
$s=$c::functional_status(['scope'=>'wpml']);
checkit(!$s['staging_acceptance_ready']
 && in_array('wpml_translation_parity_not_verified',$s['blocking_reasons'],true),
 'WPML parity cannot be inferred from marker count');
$GLOBALS['wpml_enabled']=true;
$s=$c::functional_status(['scope'=>'wpml']);
checkit($s['staging_acceptance_ready']===true
 && $s['scope_statuses']['wpml_translation_parity']==='PASS'
 && $s['wpml_linkage_evidence']['sitewide_translation_content_parity']===false,
 'native reciprocal translation links and HTTPS permalinks prove bounded WPML link parity only');
$GLOBALS['wpml_mismatch']=true;
$s=$c::functional_status(['scope'=>'wpml']);
checkit(!$s['staging_acceptance_ready']
 && in_array('wpml_translation_parity_not_verified',$s['blocking_reasons'],true),
 'wrong WPML TRID or language response invalidates parity');
$GLOBALS['wpml_mismatch']=false;
$GLOBALS['wpml_enabled']=false;
$GLOBALS['cron_job']['checked_epoch']=time()-2000;
$s=$c::functional_status(['scope'=>'frontend']);
checkit($s['staging_acceptance_ready'] && !$s['server_http_ready'],
 'frontend-only acceptance ignores unrelated stale Cron evidence');
$s=$c::functional_status(['scope'=>'full']);
checkit(!$s['staging_acceptance_ready'] && !$s['server_http_ready'],
 'full functional scope requires a fresh server result');
$s=$c::functional_status(['scope'=>'server']);
checkit(!$s['staging_acceptance_ready'],'server scope never borrows frontend result');
$GLOBALS['cron_job']['checked_epoch']=time();
$GLOBALS['sha']=str_repeat('f',40);
$s=$c::functional_status(['scope'=>'frontend']);
checkit(!$s['staging_acceptance_ready'],'changed source head invalidates local evidence');
$GLOBALS['sha']=str_repeat('a',40);
$GLOBALS['pref']=str_repeat('e',32);
$s=$c::functional_status(['scope'=>'frontend']);
checkit(!$s['staging_acceptance_ready'],'changed owner preference invalidates local evidence');
$GLOBALS['pref']=str_repeat('b',32);
$GLOBALS['source_ok']=false;
$s=$c::functional_status(['scope'=>'frontend']);
checkit(!$s['staging_acceptance_ready'],'unverified build refuses functional acceptance');
$GLOBALS['source_ok']=true;$GLOBALS['env']='production';
$s=$c::functional_status(['scope'=>'frontend']);
checkit(!$s['staging_acceptance_ready'],'production always forbidden');
echo "NATIVE_FUNCTIONAL_FRESHNESS_RUNTIME_PASS\n";
