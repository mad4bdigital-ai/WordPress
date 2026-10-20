<?php
// Exercise the real plan/apply transaction with a deterministic options DB.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS',86400);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS',3600);
$GLOBALS['uuid_seq']=0;
$GLOBALS['o']=array(); $GLOBALS['env']='production'; $GLOBALS['admin']=true; $GLOBALS['user']=7;
$GLOBALS['ids']=array(7); $GLOBALS['bound']=true; $GLOBALS['write']=true; $GLOBALS['oauth_opt']=true;
$GLOBALS['bearer']=false; $GLOBALS['step']=false; $GLOBALS['audit']=true; $GLOBALS['persist']=true;
$GLOBALS['revision']=1; $GLOBALS['sha']=str_repeat('a',40); $GLOBALS['nonce']=true; $GLOBALS['events']=array();
class WP_Error { public $code,$message; function __construct($code,$message='',$data=null){$this->code=$code;$this->message=$message;} function get_error_code(){return $this->code;} function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($k,$d=false){return $GLOBALS['o'][$k]??$d;}
function add_option($k,$v,$deprecated='',$autoload=false){if(!$GLOBALS['persist']||isset($GLOBALS['o'][$k]))return false;$GLOBALS['o'][$k]=$v;return true;}
function update_option($k,$v,$a=false){if(!$GLOBALS['persist'])return false;$GLOBALS['o'][$k]=$v;return true;}
function delete_option($k){if(!isset($GLOBALS['o'][$k]))return false;unset($GLOBALS['o'][$k]);return true;}
function get_userdata($id){return $id===7?(object)array('ID'=>7):false;}
function user_can($id,$cap){return $id===7&&($GLOBALS['owner_alive']??true);}
function wp_set_current_user($id){$GLOBALS['user']=$id;}
function wp_generate_uuid4(){return sprintf('11111111-1111-4111-8111-%012d',++$GLOBALS['uuid_seq']);}
function wp_next_scheduled($h){return $GLOBALS['scheduled']??false;}
function wp_schedule_single_event($at,$h){$GLOBALS['scheduled']=$at;return true;}
function maybe_serialize($v){return serialize($v);}
function wp_cache_delete(...$a){}
function wp_json_encode($v,...$flags){return json_encode($v);}
function sanitize_key($v){return $v;}
function current_user_can($v){return $GLOBALS['admin'];}
function get_current_user_id(){return $GLOBALS['user'];}
function is_admin(){return $GLOBALS['admin'];}
function check_admin_referer($a){if(!$GLOBALS['nonce'])throw new Exception('nonce');}
function wp_die($m,...$a){throw new Exception($m);}
function wp_unslash($s){return stripslashes($s);}
class DB {public $options='options';function prepare($s,...$args){return array($s,$args);}function query($p){if(!$GLOBALS['persist'])return 0;[$s,$a]=$p;if(strpos($s,'DELETE')===0){[$k,$expected]=$a;if(serialize(get_option($k))!==$expected)return 0;unset($GLOBALS['o'][$k]);return 1;}[$value,$k,$expected]=$a;if(serialize(get_option($k))!==$expected)return 0;$GLOBALS['o'][$k]=unserialize($value);return 1;}}
$GLOBALS['wpdb']=new DB();
class MAD4B_SCP_Environment {static function effective(){return $GLOBALS['env'];}}
class MAD4B_SCP_Site_Profile {
 static function current_environment(){return $GLOBALS['env'];}static function current_origin(){return 'https://'.$GLOBALS['env'].'.example.test';}static function configured(){return true;}static function origin_enrolled(){return $GLOBALS['bound'];}static function site_urls_match_enrollment(){return $GLOBALS['bound'];}
 static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}static function revision(){return $GLOBALS['revision'];}static function profile_digest(){return str_repeat('b',64);}
 static function write_enabled(){return $GLOBALS['write'];}static function oauth_user_ids(){return $GLOBALS['ids'];}static function user_is_enrolled($id){return in_array($id,$GLOBALS['ids'],true);}}
class MAD4B_SCP_Staging_OAuth_Autoconfig {static function production_profile_enabled(){return $GLOBALS['oauth_opt'];}}
class MAD4B_SCP_Policy {static function can_breakglass(){return false;}static function can_mutate(){return true;}}
class MAD4B_SCP_OAuth_Resource_Bridge {const AUTHORITY_STEP_UP_SCOPE='step';static function verified_bearer_active(){return $GLOBALS['bearer'];}static function verified_bearer_has_scope($v){return $GLOBALS['step'];}static function verified_bearer_client_is($v){return true;}}
class MAD4B_SCP_Local_OAuth_Server {const CHATGPT_CIMD_CLIENT_ID='chatgpt';}
class MAD4B_SCP_Audit {static function storage_status(){return array('ready'=>$GLOBALS['audit']);}static function record($e,$v,$s){$GLOBALS['events'][]=$v;return $GLOBALS['audit']&&($GLOBALS['audit_fail_event']??'')!==$e?true:new WP_Error('audit');}}
class MAD4B_SCP_Live_Acceptance_Observer {static function build_provenance_status(){return array('manifest_present'=>true,'manifest_valid'=>true,'runtime_manifest_match'=>true,'source_commit_sha'=>$GLOBALS['sha'],'build_fingerprint'=>str_repeat('c',64));}}
