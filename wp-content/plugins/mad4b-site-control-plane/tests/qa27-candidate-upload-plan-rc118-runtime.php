<?php
error_reporting(E_ALL);
$root=sys_get_temp_dir().'/qa27-plan-'.bin2hex(random_bytes(4));mkdir($root);
define('ABSPATH',$root.'/');
define('WP_ENVIRONMENT_TYPE','staging');
define('MAD4B_SCP_STAGING_CANDIDATE_UPDATES_ENABLED',true);
define('MAD4B_SCP_DIR',realpath(dirname(__DIR__)).'/');
define('MAD4B_SCP_FILE',MAD4B_SCP_DIR.'mad4b-site-control-plane.php');
class WP_Error {
 private $code; public function __construct($code,$msg=''){ $this->code=$code; }
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($x){return abs((int)$x);}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$x));}
function sanitize_text_field($x){return trim((string)$x);}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function plugin_basename($x){return basename(dirname($x)).'/'.basename($x);}
function current_user_can($cap){return in_array($cap,['update_plugins','manage_options'],true);}
function wp_get_environment_type(){return 'staging';}
function get_current_user_id(){return 1;}
class MAD4B_SCP_Site_Profile {
 public static function environment_allowed($env,$op){return in_array('staging',$env,true) && $op==='write';}
 public static function wordpress_environment_explicit(){return true;}
 public static function user_is_enrolled($id){return $id===1;}
 public static function status(){return ['site_uuid'=>'d745d81f-6fc4-5c6a-99dd-d953c92137bf','deployment_binding_configured'=>true,'same_origin_clone_protection'=>true];}
}
function wp_safe_remote_get($url,$args){
 if(!str_starts_with($url,'https://api.github.com/repos/mad4bdigital-ai/WordPress/commits/'))return new WP_Error('foreign_source');
 $ref=substr($url,strrpos($url,'/')+1);
 if($ref===str_repeat('f',40))return new WP_Error('disconnected');
 return ['code'=>200,'body'=>json_encode(['sha'=>$ref])];
}
function wp_remote_retrieve_response_code($response){return $response['code'];}
function wp_remote_retrieve_body($response){return $response['body'];}
require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-self-update.php';
$sha='a'.str_repeat('1',39);
$input=[
 'version'=>'0.4.0-rc.120','source_commit_sha'=>$sha,
 'archive_sha256'=>str_repeat('b',64),'build_fingerprint'=>str_repeat('c',64),
 'package_manifest_digest'=>str_repeat('d',64),'size_bytes'=>4096,
 'reason'=>'test host allowlisted repository staging plan','channel'=>'staging_candidate_upload',
 'candidate_source'=>['repository'=>'mad4bdigital-ai/WordPress','type'=>'commit','reference'=>$sha]
];
$check=function($name,$input,$expected) {
 $p=MAD4B_SCP_Self_Update::upload_plan($input);
 if(is_wp_error($p))throw new Exception($name.':wp_error:'.$p->get_error_code());
 if($p['eligible']!==$expected)throw new Exception($name.':eligible='.json_encode($p['eligible']).' blockers='.json_encode($p['blockers']));
 if($expected && (empty($p['candidate_source']['remote_verified'])||$p['release_channel_bound']||$p['production_allowed']))throw new Exception($name.':unsafe_flags');
 return $p;
};
try{
 $plan=$check('remote_verified_staging_exact_match',$input,true);
 $unauthorized=MAD4B_SCP_Self_Update::upload_apply(array_merge($input,[
  'expected_plan_sha256'=>$plan['plan_sha256'],
  'candidate_confirmation'=>'INSTALL EXACT STAGING CANDIDATE',
  'package_base64'=>'AAECAwQFBgcICQoLDA0ODw=='
 ]));
 if(!is_wp_error($unauthorized)||$unauthorized->get_error_code()!=='mad4b_self_update_staging_owner_step_up_required')
  throw new Exception('direct_apply_skipped_oauth_step_up');
 $bad=$input;$bad['source_commit_sha']='f'.str_repeat('1',39);
 $p=$check('source_mismatch',$bad,false);
 if(!in_array('staging_candidate_source_sha_mismatch',$p['blockers'],true))throw new Exception('missing_source_mismatch');
 $bad=$input;$bad['candidate_source']['repository']='mad4b-local/staging';
 $p=$check('legacy_signed_local_namespace_denied_even_with_optin',$bad,false);
 if(!in_array('mad4b_staging_source_repository_denied',$p['blockers'],true))throw new Exception('legacy_namespace_inadvertently_reenabled');
 $bad=$input;$bad['candidate_source']['repository']='untrusted/repository';
 $p=$check('untrusted_repository_denied',$bad,false);
 if(!in_array('mad4b_staging_source_repository_denied',$p['blockers'],true))throw new Exception('missing_repository_denied');
 $bad=$input;$bad['candidate_source']['reference']=str_repeat('f',40);
 $p=$check('remote_lookup_fails_closed',$bad,false);
 if(!in_array('mad4b_staging_source_lookup_failed',$p['blockers'],true))throw new Exception('missing_remote_lookup_blocker');
 echo "QA27_RC118_GITHUB_SOURCE_UPLOAD_PLAN_PASS\n";
}finally{@rmdir($root);}
