<?php
/** Original Artifact Registry critical readiness, source identity and bound registration. No DB. */
define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function artifact_test($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
class MAD4B_SCP_Schema {
 const VERSION=10;
 static $critical=true;
 static function critical_ready(){return self::$critical;}
}
class MAD4B_SCP_Site_Profile {
 static $uuid='11111111-2222-4333-8444-555555555555';
 static function site_uuid(){return self::$uuid;}
}
class MAD4B_SCP_Policy {
 static function can_mutate(){return false;}
 static function can_read(){return true;}
}
$GLOBALS['registered']=[];
function add_action($name,$callback,$priority=10){}
function wp_has_ability($name){return isset($GLOBALS['registered'][$name]);}
function wp_register_ability($name,$args){$GLOBALS['registered'][$name]=$args;return true;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-artifacts.php';
$c='MAD4B_SCP_Artifacts';
artifact_test(count($c::artifact_types())>=20&&count($c::relations())>=8,
 'Original first-party Artifact vocabularies must not be empty');
$c::register_abilities();
artifact_test(count($GLOBALS['registered'])===6,'Exactly six first-party Artifact Abilities');
foreach($GLOBALS['registered'] as $name=>$args){
 $read=in_array($name,['mad4b/artifact-list','mad4b/artifact-get','mad4b/artifact-edges'],true);
 artifact_test($args['category']===($read?'mad4b-read':'mad4b-write')
  &&$args['meta']['annotations']['readonly']===$read
  &&$args['permission_callback']===['MAD4B_SCP_Policy',$read?'can_read':'can_mutate']
  &&$args['meta']['mcp']['public']===false,
  'Original read vs write lane stays distinct and private');
}
foreach([new WP_Error('db_unverified'),1,'true',null,false] as $bad){
 MAD4B_SCP_Schema::$critical=$bad;
 $res=$c::append_artifact([]);
 artifact_test(is_wp_error($res)&&$res->get_error_code()==='mad4b_artifact_schema_unavailable',
  'Malformed original Schema readiness must not create a DB transaction');
}
MAD4B_SCP_Schema::$critical=true;
foreach([new WP_Error('site_unknown'),true,[],null] as $bad){
 MAD4B_SCP_Site_Profile::$uuid=$bad;
 $r=$c::append_artifact([]);
 artifact_test(is_wp_error($r)&&$r->get_error_code()==='mad4b_artifact_site_identity_unavailable',
  'Unverified Site UUID cannot reach a transaction');
}
MAD4B_SCP_Site_Profile::$uuid='11111111-2222-4333-8444-555555555555';
artifact_test($c::append_artifact([])->get_error_code()==='mad4b_artifact_job_id_invalid',
 'Invalid job ID cannot enter Artifact write');
echo 'PASS: ARTIFACTS_ORIGINAL '.($GLOBALS['n']??0).' bound WordPress ability and DB preflight checks'.PHP_EOL;
