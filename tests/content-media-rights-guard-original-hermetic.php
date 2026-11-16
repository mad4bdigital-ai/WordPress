<?php
/** First-party publish media rights guard. No actual media publication. */
define('ABSPATH',__DIR__.'/');
class WP_Error{private $code;function __construct($code,$m='',$d=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_date($format){return '2026-11-16';}
function get_option($key,$fallback='advisory'){return $GLOBALS['policy'];}
function apply_filters($key,$value){return $value;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function absint($v){return max(0,(int)$v);}
function media_check($yes,$why){if(!$yes){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-experience-media-rights.php';
$c='MAD4B_SCP_Content_Experience_Media_Rights';
$GLOBALS['policy']='advisory';
$fields=['gallery_usage'=>['kind'=>'gallery_usage','publish_rights_policy'=>'require_valid']];
foreach([new WP_Error('date_unknown'),[],true,123] as $bad){
 $r=$c::publish_guard($fields,[],$bad);
 media_check(is_wp_error($r) &&$r->get_error_code()==='mad4b_content_experience_media_rights_date_invalid',
  'Unverified evaluation date cannot become a publish-valid media rights decision');
}
$r=$c::publish_guard($fields,['gallery_usage'=>[
 ['license'=>'rights-managed','license_expires_on'=>'2025-01-01']]],'2026-11-16');
media_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_content_experience_media_rights_expired',
 'Explicit expired media license blocks publication even in advisory mode');
foreach([new WP_Error('policy_unknown'),[],true,123] as $bad){
 $GLOBALS['policy']=$bad;
 $r=$c::publish_guard($fields,[],'2026-11-16');
 media_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_media_rights_policy_invalid',
  'Original unverified media rights policy may not be inferred as advisory');
}
$GLOBALS['policy']='advisory';
$r=$c::publish_guard($fields,[],'2026-11-16');
media_check(is_array($r)&&($r['legal_reuse_permission']??null)==='not_determined_by_system'
 &&$r['license_warning_count']>=1,'Missing legal rights never become an affirmative license');
foreach(['restricted','denied','no_reuse','all_rights_reserved'] as $state){
 $r=$c::publish_guard($fields,['gallery_usage'=>[['attachment_id'=>1,'license'=>$state]]],'2026-11-16');
 media_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_content_experience_media_rights_explicit_restriction',
  'Explicit rights restriction must block in advisory mode');
}
$r=$c::publish_guard($fields,['gallery_usage'=>[['attachment_id'=>1,'license'=>'','license_expires_on'=>'2026-02-30']]],'2026-11-16');
media_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_content_experience_media_rights_expired',
 'Impossible but formatted calendar date is never a valid rights grant');
echo 'PASS: CONTENT_MEDIA_RIGHTS_GUARD_ORIGINAL '.($GLOBALS['n']??0).' original policy/date/expired rights cases'.PHP_EOL;
