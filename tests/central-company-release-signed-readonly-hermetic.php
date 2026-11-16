<?php
/**
 * Original Central Staging RSA release Readback and Company Production
 * compatibility planner. Synthetic public/private key entirely in memory.
 * No real key, credential, network, deployment, WordPress writes or approval.
 */
define('ABSPATH',__DIR__.'/');
$tmp=sys_get_temp_dir().'/mad4b-central-release-src-'.getmypid().'.json';
file_put_contents($tmp,json_encode(array(
 'contract'=>'mad4b.central-staging-origin.v1',
 'origin'=>'https://central.example.invalid')));
define('MAD4B_CENTRAL_STAGING_SOURCE_FILE',$tmp);
$GLOBALS['release_env']='production';
$GLOBALS['release_roles']=array();
$GLOBALS['release_abilities']=array();
$GLOBALS['release_routes']=array();
$GLOBALS['release_http_calls']=0;
$GLOBALS['release_mutations']=0;
function add_action($name,$callback,$priority=10){$GLOBALS['release_roles'][]=array($name,$callback);}
function wp_register_ability($name,$cfg){$GLOBALS['release_abilities'][$name]=$cfg;}
function wp_has_ability($name){return isset($GLOBALS['release_abilities'][$name]);}
function register_rest_route($ns,$route,$cfg){$GLOBALS['release_routes'][$ns.$route]=$cfg;}
function __return_true(){return true;}
function current_user_can($cap){return false;}
function wp_get_environment_type(){return $GLOBALS['release_env'];}
function home_url($path=''){return 'https://company.example.invalid'.($path==='/'?'/':'');}
function wp_safe_remote_get($url,$options){
 ++$GLOBALS['release_http_calls'];return new WP_Error('fixture_no_network');
}
function wp_remote_retrieve_response_code($r){return 0;}
function wp_remote_retrieve_body($r){return '';}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_plugins(){return array(
 'elementor/elementor.php'=>array('Version'=>'3.33.0'),
 'company/sample.php'=>array('Version'=>'1.0.0'));}
function is_plugin_active($name){return $name==='elementor/elementor.php';}
function get_bloginfo($what){return '7.1.3';}
function get_role($name){return null;}
function get_option($name,$default=array()){return $default;}
function rest_ensure_response($data){return new FixtureReply($data);}
class FixtureReply {
 public $data;public $headers=array();
 public function __construct($data){$this->data=$data;}
 public function header($name,$value){$this->headers[$name]=$value;}
}
class WP_Error{
 private $code;public function __construct($code,$reason='',$extra=array()){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Site_Profile {
 public static function status(){return array('site_uuid'=>'12345678-1234-4123-8123-123456789abc');}
 public static function origin_enrolled(){return true;}
 public static function current_environment(){return $GLOBALS['release_env'];}
}
class MAD4B_SCP_Policy {
 public static function can_read(){return false;}
 public static function can_mutate(){return false;}
}
function rcheck($yes,$reason){
 if(!$yes)throw new RuntimeException('FAIL CENTRAL RELEASE SOURCE: '.$reason);
}
$root=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/';
require $root.'class-mad4b-scp-central-release-registry.php';
require $root.'class-mad4b-scp-central-company-rollout.php';
require $root.'class-mad4b-scp-company-release-status.php';
$registry='MAD4B_SCP_Central_Release_Registry';
$rollout='MAD4B_SCP_Central_Company_Rollout';
$status='MAD4B_SCP_Company_Release_Status';
rcheck($registry::central_origin()==='https://central.example.invalid',
 'Origin must inherit host-owned fixture, not caller or site address');
$rollout::register_ability();
$registry::register_route();
rcheck(count($GLOBALS['release_abilities'])===1
 && isset($GLOBALS['release_abilities'][$rollout::ABILITY]),
 'One original read-only rollout Ability and no deployment actions');
$ability=$GLOBALS['release_abilities'][$rollout::ABILITY];
rcheck($ability['permission_callback']===array('MAD4B_SCP_Policy','can_read')
 && $ability['meta']['annotations']['readonly']===true
 && $ability['input_schema']['additionalProperties']===false
 && $ability['input_schema']['required']===array('release_sha256')
 && $ability['meta']['mcp']['surface']==='read',
 'Release compatibility may not create write authority');
$registered=$GLOBALS['release_routes'];
rcheck(count($registered)===1
 && ($registered['mad4b/v1/central-release/(?P<release_sha256>[a-f0-9]{64})']['methods']??null)==='GET',
 'Public signed endpoint registers one immutable hash route');
$invalid=$registry::serve_signed_readback(array());
rcheck($invalid instanceof WP_Error
 && $invalid->get_error_code()==='mad4b_central_release_not_source_site',
 'Company Production must never serve Central Staging readbacks');
$bad=$rollout::plan(array('release_sha256'=>str_repeat('a',64),'caller_grant'=>true));
rcheck($bad instanceof WP_Error
 && $bad->get_error_code()==='mad4b_central_rollout_input_invalid',
 'Caller-supplied authorization, receipt and package paths denied');
$hash=str_repeat('a',64);
$receipt=array(
 'contract'=>'mad4b.central-staging-release-readback.v1',
 'staging_origin'=>'https://central.example.invalid',
 'environment'=>'staging','artifact_sha256'=>$hash,
 'file_manifest_sha256'=>str_repeat('b',64),
 'source_type'=>'local_git','source_commit'=>str_repeat('c',40),
 'rollout_scope'=>'company_production',
 'staging_pass'=>true,'checks'=>array('install'=>true,'smoke'=>true,
   'rollback'=>true,'schema_rollback'=>true),
 'tested_at'=>time()-120,
 'requirements'=>array(
  'deployment_role'=>'company_plugin','target_plugin_file'=>'company/sample.php',
  'target_version'=>'2.0.0','php_min'=>'7.4','wordpress_min'=>'6.0',
  'required_plugins'=>array(array('file'=>'elementor/elementor.php',
    'min_version'=>'3.0.0','active_required'=>true))
  )
);
$private=openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA));
rcheck($private!==false,'Ephemeral local RSA test key required');
$info=openssl_pkey_get_details($private);
$public=$info['key'];
$canon=function($value)use(&$canon) {
 if(is_array($value)){
  if($value && array_keys($value)!==range(0,count($value)-1))ksort($value,SORT_STRING);
  foreach($value as &$v)$v=$canon($v);
 }return $value;
};
$sign=function($r)use($private,$canon) {
 $signature='';
 $json=json_encode($canon($r),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 if(openssl_sign($json,$signature,$private,OPENSSL_ALGO_SHA256)!==true)
  throw new RuntimeException('Ephemeral local signature failed');
 return array('contract'=>'mad4b.central-release-evidence-envelope.v1',
  'receipt'=>$r,'signature_b64'=>base64_encode($signature));
};
$sealed=$sign($receipt);
$verified=$registry::verify_envelope($sealed,$hash,'https://central.example.invalid',$public);
rcheck(is_array($verified)
 && !isset($verified['registry_readback_verified']),
 'Valid detached RS256 receipt cannot inherit caller-set verification flag');
$forged=$sealed;
$forged['receipt']['source_commit']=str_repeat('d',40);
$tampered=$registry::verify_envelope($forged,$hash,'https://central.example.invalid',$public);
rcheck($tampered instanceof WP_Error &&
 $tampered->get_error_code()==='mad4b_central_evidence_signature_untrusted',
 'Changed source identity must invalidate detached RSA signature');
$claimed=$receipt;$claimed['registry_readback_verified']=true;
$claimedVerified=$registry::verify_envelope($sign($claimed),$hash,
 'https://central.example.invalid',$public);
rcheck(!isset($claimedVerified['registry_readback_verified']),
 'Even authenticated publisher cannot force the consumer readback flag');
$expired=$receipt;$expired['tested_at']=time()-700000;
$stale=$registry::verify_envelope($sign($expired),$hash,
 'https://central.example.invalid',$public);
rcheck($stale instanceof WP_Error &&
 $stale->get_error_code()==='mad4b_central_evidence_tests_unverified',
 'Even correctly signed stale release tests must fail');
$wrong=$registry::verify_envelope($sealed,$hash,'https://another.example.invalid',$public);
rcheck($wrong instanceof WP_Error &&
 $wrong->get_error_code()==='mad4b_central_evidence_source_mismatch',
 'Signed receipt for different Central Staging origin must fail');
$site=array(
 'environment'=>'production','enrolled_environment'=>'production',
 'origin_enrolled'=>true,'site_uuid'=>'12345678-1234-4123-8123-123456789abc',
 'origin'=>'https://company.example.invalid','php_version'=>PHP_VERSION,
 'wordpress_version'=>'7.1.3',
 'plugins'=>array('elementor/elementor.php'=>array('version'=>'3.33.0','active'=>true),
   'company/sample.php'=>array('version'=>'1.0.0','active'=>true))
);
$notverified=$rollout::assess($hash,$receipt,$site);
rcheck(in_array('trusted_central_release_readback_unavailable',$notverified['blockers'],true)
 && !$notverified['ready_for_separate_owner_authorization'],
 'Caller-supplied unsigned receipt cannot authorize a rollout');
$checked=$verified;$checked['registry_readback_verified']=true;
$plan=$rollout::assess($hash,$checked,$site,true);
rcheck($plan['ready_for_separate_owner_authorization']===true
 && $plan['production_mutation_authorized']===false
 && $plan['mutation_performed']===false
 && $plan['authorizing']===false
 && $plan['executor']==='',
 'Successful RSA and compatibility check must still require a separate Owner authority');
$noScope=$checked;$noScope['rollout_scope']='staging_only';
$notpromotable=$rollout::assess($hash,$noScope,$site,true);
rcheck(in_array('central_release_promotion_scope_not_granted',$notpromotable['blockers'],true),
 'Staging success cannot silently become Production promotion');
$badSite=$site;$badSite['enrolled_environment']='staging';
$wrongCompany=$rollout::assess($hash,$checked,$badSite,true);
rcheck(in_array('company_production_identity_unverified',$wrongCompany['blockers'],true),
 'Site-profile mismatch must stop Production recommendation');
$downgrade=$site;$downgrade['plugins']['company/sample.php']['version']='3.0.0';
$oldVersion=$rollout::assess($hash,$checked,$downgrade,true);
rcheck(in_array('company_target_downgrade_denied',$oldVersion['blockers'],true),
 'Newest site-local plugin must not be overwritten by an older Central release');
$noRollback=$checked;$noRollback['checks']['schema_rollback']=false;
$noSchema=$rollout::assess($hash,$noRollback,$site,true);
$noSchemaAlso=$noRollback;
$noSchemaAlso['requirements']['requires_schema_migration']=true;
$schema=$rollout::assess($hash,$noSchemaAlso,$site,true);
rcheck(in_array('company_schema_migration_rollback_unverified',$schema['blockers'],true),
 'Schema migration cannot be recommended without rollback proof');
$preflight=$status::status(null);
rcheck($preflight instanceof FixtureReply
 && $preflight->data['operational_rollout_ready']===false
 && $preflight->data['no_mutation_performed']===true
 && $preflight->headers['Cache-Control']==='private, no-store',
 'Company release status cannot grant roles or present stale-public readiness');
rcheck($GLOBALS['release_mutations']===0,
 'No original release source method may modify WordPress or issue execution');
unlink($tmp);
echo "PASS: CENTRAL_RELEASE_RSA_AND_COMPANY_COMPAT source signature, company downgrade, no promotion grant\n";
