<?php
define('ABSPATH',__DIR__);
class WP_Error{private $c,$m,$d;function __construct($c,$m='',$d=array()){$this->c=$c;$this->m=$m;$this->d=$d;}function get_error_code(){return $this->c;}function get_error_message(){return $this->m;}function get_error_data(){return $this->d;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function sanitize_text_field($v){return trim((string)$v);}
function absint($v){return abs((int)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function apply_filters($tag,$value,...$args){return $value;}
function get_post($id){return null;}
function user_can($user,$cap){return is_object($user)&&!empty($user->allcaps[$cap]);}
function get_userdata($id){if(empty($GLOBALS['subject_exists'])||7!==(int)$id)return false;return (object)array('ID'=>7,'allcaps'=>$GLOBALS['subject_caps']);}
$GLOBALS['subject_exists']=true;$GLOBALS['subject_caps']=array('manage_options'=>true,'edit_posts'=>true);$GLOBALS['subject_fp']=str_repeat('1',64);$GLOBALS['profile_revision']=4;$GLOBALS['profile_app']='plugin_asdk_app_fixture';$GLOBALS['profile_users']=array(7);$GLOBALS['authority_snapshot']=str_repeat('9',64);

final class MAD4B_SCP_Identity_Context{static function current(){return array('authenticated'=>true,'subject_type'=>'oauth','subject_fingerprint'=>$GLOBALS['subject_fp'],'issuer_fingerprint'=>str_repeat('2',64),'client_fingerprint'=>str_repeat('3',64),'auth_method'=>'oauth2_bearer','wp_user_id'=>7);}}
final class MAD4B_SCP_Runtime_Compatibility_Profile{static function assert_governed_write_ready($r=true){return array('contract'=>'mad4b.runtime-compatibility-profile.v1','ready'=>true,'profile_sha256'=>str_repeat('4',64),'execution_context'=>'rest','remote_transport_server_id'=>'mad4b-chatgpt','object_cache'=>array('external'=>false,'contract_verified'=>true),'database_topology'=>array('database_dropin_present'=>false),'security_firewall'=>array('plugins'=>array()),'maintenance'=>array('active'=>false));}}
final class MAD4B_SCP_Schema{static function transactional_storage_status($keys=array(),$refresh=false){return array('contract'=>'mad4b.database-transactional-storage.v1','ready'=>true,'connection_fingerprint'=>str_repeat('5',64),'engines'=>array('approvals'=>'innodb'),'collations'=>array('approvals'=>'utf8mb4_unicode_ci'),'identity_comparison_policy'=>'canonical_application_identity_plus_binary_sql_for_security_keys','read_your_writes'=>true,'database_topology'=>array('connection_fingerprint'=>str_repeat('5',64)));}}
final class MAD4B_SCP_Agent_Registry{static $enabled=true;static function get_agent_by_public_id($id){return self::$enabled?array('id'=>11,'public_id'=>$id,'status'=>'enabled','environment'=>'staging','role'=>'writer'):array('id'=>11,'public_id'=>$id,'status'=>'disabled','environment'=>'staging','role'=>'writer');}static function exact_grant($id,$server,$ability,$provider){return self::$enabled?array('id'=>22,'effect'=>'allow','server_id'=>$server,'ability_name'=>$ability,'provider'=>$provider):new WP_Error('grant_revoked','revoked');}}
final class MAD4B_SCP_Site_Profile{static function configured(){return true;}static function status(){return array('configured'=>true);}static function origin_enrolled(){return true;}static function user_is_enrolled($id){return in_array((int)$id,$GLOBALS['profile_users'],true);}static function site_uuid(){return '123e4567-e89b-42d3-a456-426614174000';}static function revision(){return $GLOBALS['profile_revision'];}static function profile_digest(){return hash('sha256',json_encode(array('revision'=>$GLOBALS['profile_revision'],'app'=>$GLOBALS['profile_app'],'users'=>$GLOBALS['profile_users'])));}static function current_environment(){return 'staging';}static function current_origin(){return 'https://site.test';}static function write_enabled(){return true;}}
final class MAD4B_SCP_Approval_Tickets{static function get($id){return array('ticket_id'=>$id,'ticket_class'=>'mutation','status'=>'executing','payload_sha256'=>str_repeat('6',64),'expires_at'=>gmdate('Y-m-d H:i:s',time()+600),'candidate_binding_contract'=>'mad4b.approval-candidate-binding.v2','candidate_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64),'binding_environment'=>'staging','binding_host'=>'site.test','site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),'site_profile_revision'=>MAD4B_SCP_Site_Profile::revision(),'site_profile_digest'=>MAD4B_SCP_Site_Profile::profile_digest(),'bound_at'=>gmdate('Y-m-d H:i:s'));}static function candidate_binding_from_ticket($ticket){return array('site_uuid'=>$ticket['site_uuid'],'profile_revision'=>$ticket['site_profile_revision'],'profile_digest'=>$ticket['site_profile_digest']);}}
final class MAD4B_SCP_Live_Acceptance_Observer{static function build_provenance_status(){return array('version'=>'test','source_commit_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64),'package_manifest_digest'=>str_repeat('c',64),'runtime_manifest_match'=>true,'stale'=>false);}}
final class MAD4B_SCP_Policy{static function connection_capability(){return 'manage_options';}static function can_connect_user($id){$u=get_userdata($id);return $u&&MAD4B_SCP_Site_Profile::origin_enrolled()&&MAD4B_SCP_Site_Profile::user_is_enrolled($id)&&user_can($u,self::connection_capability());}static function mutation_gate_status(){return array('effective'=>true,'source'=>'fixture');}static function can_mutate(){return true;}static function can_developer(){return true;}static function can_developer_breakglass(){return true;}static function can_breakglass(){return true;}}
final class MAD4B_SCP_Multi_Authority_Registry{static function snapshot(){return array('authority_snapshot_sha256'=>$GLOBALS['authority_snapshot']);}}

require dirname(__DIR__).'/includes/class-mad4b-scp-execution-commit-guard.php';
$check=static function($c,$m){if(!$c){fwrite(STDERR,"FAIL subject-lifecycle-commit-guard: {$m}\n");exit(1);}};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$claim=array('ability'=>'fixture/mutate','provider'=>'core','server_id'=>'mad4b-write','agent_public_id'=>'agent-fixture','target_fingerprint'=>str_repeat('d',64),'approval_required'=>true,'approval_ticket_id'=>'11111111-1111-4111-8111-111111111111','subject_fingerprint'=>$GLOBALS['subject_fp']);
$input=array('target'=>'fixture');
$snapshot=MAD4B_SCP_Execution_Commit_Guard::capture($claim,$input);
$check(is_array($snapshot)&&!empty($snapshot['material_sha256']),'baseline commit snapshot failed');
$claim['commit_guard_snapshot']=$snapshot;
$ok=MAD4B_SCP_Execution_Commit_Guard::revalidate($claim,$input);
$check(is_array($ok)&&'COMMIT_ALLOWED'===($ok['verdict']??''),'unchanged subject lifecycle did not commit');

$GLOBALS['subject_caps']=array('edit_posts'=>true);
$demoted=MAD4B_SCP_Execution_Commit_Guard::revalidate($claim,$input);
$check('mad4b_commit_guard_reapproval_required'===$code($demoted),'role/capability demotion failed open');
$GLOBALS['subject_caps']=array('manage_options'=>true,'edit_posts'=>true);

$GLOBALS['subject_exists']=false;
$deleted=MAD4B_SCP_Execution_Commit_Guard::revalidate($claim,$input);
$check('mad4b_commit_guard_reapproval_required'===$code($deleted),'deleted WordPress subject failed open');
$GLOBALS['subject_exists']=true;

$GLOBALS['profile_users']=array(8);
$unenrolled=MAD4B_SCP_Execution_Commit_Guard::revalidate($claim,$input);
$check('mad4b_commit_guard_reapproval_required'===$code($unenrolled),'Site Profile user unenrollment failed open');
$GLOBALS['profile_users']=array(7);

$GLOBALS['profile_app']='plugin_asdk_app_remapped';$GLOBALS['profile_revision']=5;
$remapped=MAD4B_SCP_Execution_Commit_Guard::revalidate($claim,$input);
$check('mad4b_commit_guard_reapproval_required'===$code($remapped),'App/profile remap failed open');
$GLOBALS['profile_app']='plugin_asdk_app_fixture';$GLOBALS['profile_revision']=4;

$GLOBALS['authority_snapshot']=str_repeat('8',64);
$authority=MAD4B_SCP_Execution_Commit_Guard::revalidate($claim,$input);
$check('mad4b_commit_guard_reapproval_required'===$code($authority),'authority registry/key-generation drift failed open');

echo "mad4b.subject-lifecycle-commit-guard.runtime.v1: PASS\n";
