<?php
/** Real original typed mutation principal and undo authorization, no actual site. */
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=null){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function esc_sql($x){return (string)$x;}
function absint($x){return abs((int)$x);}
function sanitize_key($x){return preg_replace('/[^a-z0-9_]/','',strtolower((string)$x));}
function get_current_user_id(){return $GLOBALS['native_uid'];}
function apply_filters($key,$default){return false;}
function get_post($id){return $id===42?(object)['ID'=>42]:false;}
function get_post_type($id){return $id===42?'tours-and-activities':null;}
function user_can($user,$cap,$id=null){return $GLOBALS['native_user_capable'];}
function get_taxonomy($tax){return $tax==='destination'?(object)['cap'=>(object)['manage_terms'=>'manage_categories','assign_terms'=>'assign_terms']]:null;}
function record_check($assertion,$reason) {
 if(!$assertion){fwrite(STDERR,"FAIL: ".$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class NativeMutationDb {
 public function get_results($query,$mode) {
  if(!$GLOBALS['native_schema_ready'])return array();
  return array(
    array('Field'=>'principal_type','Null'=>'NO'),
    array('Field'=>'agent_id','Null'=>'YES'),
    array('Field'=>'wp_user_id','Null'=>'YES'),
  );
 }
}
$wpdb=new NativeMutationDb();
class MAD4B_SCP_Schema {
 public static function tables(){return ['mutations'=>'wp_mad4b_native_mutations'];}
}
class MAD4B_SCP_Site_Profile {
 public static function site_uuid(){return $GLOBALS['native_site'];}
}
class MAD4B_SCP_Policy {
 public static function can_mutate(){
  if($GLOBALS['native_policy']==='throw')throw new RuntimeException('policy source unverified');
  return $GLOBALS['native_policy'];
 }
}
class MAD4B_SCP_Delegation_Mode {
 public static function verified_wordpress_user($identity) {
  if($GLOBALS['native_wp_user'] && is_array($identity) &&
    ($identity['subject_type']??'')==='oauth' && !empty($identity['authenticated']))
    return (object)['ID'=>17];
  return null;
 }
 public static function authorize_ability($identity,$ability,$input) {
  return $GLOBALS['native_ability_allowed'];
 }
}
class MAD4B_SCP_Agent_Registry {
 public static function resolve_agent($identity){
  if($GLOBALS['native_agent']==='throw')throw new RuntimeException('registry not ready');
  return $GLOBALS['native_agent'];
 }
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mutation-principal.php';
$principal='MAD4B_SCP_Mutation_Principal';
function reset_principal() {
 $GLOBALS['native_uid']=0;
 $GLOBALS['native_schema_ready']=true;
 $GLOBALS['native_policy']=true;
 $GLOBALS['native_site']='11111111-1111-4111-8111-111111111111';
 $GLOBALS['native_wp_user']=false;
 $GLOBALS['native_user_capable']=true;
 $GLOBALS['native_ability_allowed']=true;
 $GLOBALS['native_agent']=array('id'=>7,'status'=>'enabled',
    'public_id'=>'11111111-1111-4111-8111-111111111111');
}
reset_principal();
record_check(count($principal::allowed_native_abilities())===6,
 'Original WordPress user is limited to six exact, reversible native abilities');
record_check(is_wp_error($principal::resolve('bad','seo/update-meta')),
 'Non-array user/Agent identity is never authenticated');
$native_identity=array('authenticated'=>true,'subject_type'=>'host_operator',
 'subject_fingerprint'=>str_repeat('a',64));
$host=$principal::resolve($native_identity,'media/set-featured');
record_check(is_array($host) && $host['principal_type']===$principal::NHI
 && $host['agent_id']===7 && $host['wp_user_id']===0,
 'Existing Host NHI is independent of WordPress user');
$GLOBALS['native_agent']=new WP_Error('unknown_subject');
record_check(is_wp_error($principal::resolve($native_identity,'media/set-featured')),
 'Missing enrolled NHI subject cannot mutate');
foreach(array(null,false,array(),array('id'=>0,'status'=>'enabled'),
 array('id'=>7,'status'=>'disabled')) as $bad_agent){
 reset_principal();$GLOBALS['native_agent']=$bad_agent;
 record_check(is_wp_error($principal::resolve($native_identity,'media/set-featured')),
  'Non-positive, missing, disabled or invalid NHI Registry binding must deny mutation');
}
reset_principal();
$GLOBALS['native_wp_user']=true;
$GLOBALS['native_uid']=17;
$wp_identity=array('authenticated'=>true,'subject_type'=>'oauth',
 'subject_fingerprint'=>str_repeat('b',64));
$wp=$principal::resolve($wp_identity,'seo/update-meta');
record_check(is_array($wp) && $wp['principal_type']===$principal::WP_USER
 && $wp['agent_id']===null && $wp['wp_user_id']===17
 && strlen($wp['subject_fingerprint'])===64,
 'Existing enrolled WordPress user is an explicit native principal, not a synthetic Agent');
record_check(is_wp_error($principal::resolve($wp_identity,'mad4b/unmapped-write')),
 'No implicit WordPress grant for unknown native ability');
foreach(array(false,null,1,new WP_Error('policy_unavailable'),'throw') as $policy){
 reset_principal();$GLOBALS['native_uid']=17;$GLOBALS['native_wp_user']=true;
 $GLOBALS['native_policy']=$policy;
 record_check(is_wp_error($principal::resolve($wp_identity,'seo/update-meta')),
  'Only exact policy boolean true admits a WP user: '.(is_object($policy)?'WP_Error':(string)$policy));
}
reset_principal();$GLOBALS['native_uid']=17;$GLOBALS['native_wp_user']=true;
$GLOBALS['native_ability_allowed']=new WP_Error('cap_denied');
record_check(is_wp_error($principal::resolve($wp_identity,'seo/update-meta')),
 'Original per-object capability error cannot be bypassed');
reset_principal();$GLOBALS['native_uid']=17;$GLOBALS['native_wp_user']=true;
$GLOBALS['native_schema_ready']=false;
record_check(is_wp_error($principal::resolve($wp_identity,'seo/update-meta')),
 'Original mutation SQL schema must have nullable Agent ID');
reset_principal();$GLOBALS['native_uid']=17;$GLOBALS['native_wp_user']=true;
$GLOBALS['native_site']='';
record_check(is_wp_error($principal::resolve($wp_identity,'seo/update-meta')),
 'WordPress user principal cannot be minted without site UUID');
reset_principal();
$record=array('principal_type'=>$principal::NHI,'agent_id'=>8,
  'ability_name'=>'media/set-featured');
record_check(is_wp_error($principal::owner_for_undo($native_identity,$record,null,array())),
 'Different NHI cannot restore another Agent mutation');
$record['agent_id']=7;
$owner=$principal::owner_for_undo($native_identity,$record,null,array());
record_check(is_array($owner)&&$owner['agent_id']===7,
 'Original exact NHI owner can be projected for independent undo authorization');
foreach(array(null,false,array(),array('id'=>0,'status'=>'enabled'),
 array('id'=>7,'status'=>'disabled')) as $bad_agent){
 reset_principal();$GLOBALS['native_agent']=$bad_agent;
 record_check(is_wp_error($principal::owner_for_undo($native_identity,$record,null,array())),
   'Bad NHI Registry row cannot claim original mutation undo');
}
reset_principal();$GLOBALS['native_wp_user']=true;$GLOBALS['native_uid']=17;
$wp_record=['principal_type'=>$principal::WP_USER,'agent_id'=>null,
 'wp_user_id'=>17,'ability_name'=>'seo/update-meta',
 'subject_fingerprint'=>$principal::fingerprint($GLOBALS['native_site'],17)];
$restored=$principal::owner_for_undo($wp_identity,$wp_record,null,['post_id'=>42]);
record_check(is_array($restored)&&$restored['wp_user_id']===17,
 'Exact original WordPress owner can request permission-checked SEO undo');
$wp_record['wp_user_id']=31;
record_check(is_wp_error($principal::owner_for_undo($wp_identity,$wp_record,null,['post_id'=>42])),
 'Other WordPress user cannot undo source owner SEO');
$wp_record['wp_user_id']=17;
foreach(array(null,1,new WP_Error('policy_error'),'throw') as $policy){
 reset_principal();$GLOBALS['native_wp_user']=true;$GLOBALS['native_uid']=17;
 $GLOBALS['native_policy']=$policy;
 record_check(is_wp_error($principal::owner_for_undo($wp_identity,$wp_record,null,['post_id'=>42])),
  'Unknown original WP undo Policy cannot authorize restoration');
}
echo 'PASS: MUTATION_PRINCIPAL_ORIGINAL '.($GLOBALS['checks']??0).
 ' native WordPress/NHI owner and undo authorization assertions'.PHP_EOL;
