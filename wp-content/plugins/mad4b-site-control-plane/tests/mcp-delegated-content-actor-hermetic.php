<?php
// Hermetic delegated principal tests: no DB/network/real WordPress writes.
define('ABSPATH', '/tmp');
define('REST_REQUEST', true);
$GLOBALS['mode']='delegated_user';
$GLOBALS['user_id']=7;
$GLOBALS['site_authorized']=true;
$GLOBALS['trusted_oauth']=true;
$GLOBALS['write_scoped']=true;
$GLOBALS['environment']='staging';
$GLOBALS['users']=array(7=>array('read'=>true,'edit_posts'=>true,'create_posts'=>true,'edit_post:20'=>true));
$GLOBALS['agents']=array();
$GLOBALS['subjects']=array();
$GLOBALS['grants']=array();
$GLOBALS['id']=1;
$GLOBALS['posts']=array(20=>(object)array('post_type'=>'tours-and-activities','post_status'=>'draft'));
function mad4b_verify($v,$reason){if(!$v){fwrite(STDERR,'FAIL '.$reason.PHP_EOL);exit(1);}}
class WP_Error {
  private $code;
  function __construct($c,$message=''){ $this->code=$c; }
  function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(){}
function get_option($k,$default){return $GLOBALS['mode'];}
function absint($v){return abs((int)$v);}
function get_current_user_id(){return $GLOBALS['user_id'];}
function get_userdata($id){return isset($GLOBALS['users'][$id])?(object)array('ID'=>$id):false;}
function user_can($u,$cap,$id=0){return !empty($GLOBALS['users'][$u->ID][$cap.($id?':'.$id:'')]);}
function sanitize_key($v){return strtolower((string)$v);}
function is_ssl(){return true;}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_type_object($type){
 if($type!=='tours-and-activities')return null;
 return (object)array('cap'=>(object)array('edit_posts'=>'edit_posts','create_posts'=>'create_posts',
   'publish_posts'=>'publish_posts','edit_others_posts'=>'edit_others_posts'));
}
function wp_get_environment_type(){return $GLOBALS['environment'];}
class MAD4B_SCP_Policy {
 static function can_connect_user($id){return $GLOBALS['site_authorized'] && !empty($GLOBALS['users'][$id]['read']);}
}
class MAD4B_SCP_Schema {static function is_ready(){return true;}}
class MAD4B_SCP_Environment {static function effective(){return $GLOBALS['environment'];}}
class MAD4B_SCP_OAuth_Resource_Bridge {
 static function verified_bearer_active(){return $GLOBALS['trusted_oauth'];}
 static function verified_bearer_has_scope($s){return $GLOBALS['trusted_oauth'] &&
   ($s==='mad4b:read'||($GLOBALS['write_scoped'] && $s==='mad4b:write'));}
}
class MAD4B_SCP_OAuth_Subject_User_Bridge {
 static function mapped_context(){return $GLOBALS['trusted_oauth']?
  array('authenticated'=>true,'wp_user_id'=>7,'subject_fingerprint'=>str_repeat('a',64)):array();}
}
class MAD4B_SCP_Servers {
 static function provider_for_ability($server,$ability){
  return in_array($server,array('mad4b-content','mad4b-write'),true)
   && $ability==='mad4b/content-apply-bundle'?'core':null;
 }
}
class MAD4B_SCP_Agent_Registry {
 static function get_agent_by_slug($slug){return $GLOBALS['agents'][$slug]??null;}
 static function create_agent($args){
  $slug=$args['slug'];
  if(isset($GLOBALS['agents'][$slug]))return new WP_Error('duplicate');
  $agent=array('id'=>$GLOBALS['id']++,'public_id'=>'delegate-'.count($GLOBALS['agents']),
    'slug'=>$slug,'status'=>$args['status'],'wp_user_id'=>$args['wp_user_id'],
    'revision'=>1,'environment'=>$args['environment']);
  $GLOBALS['agents'][$slug]=$agent;
  return $agent;
 }
 static function update_agent($id,$changes,$revision){
  foreach($GLOBALS['agents'] as $slug=>$agent){
    if($agent['public_id']===$id && $agent['revision']===$revision){
      $GLOBALS['agents'][$slug]['status']=$changes['status'];
      $GLOBALS['agents'][$slug]['revision']++;
      return $GLOBALS['agents'][$slug];
    }
  }
  return new WP_Error('agent_update_failed');
 }
 static function subject_binding($type,$fingerprint){
  return $GLOBALS['subjects'][$type.'|'.$fingerprint]??null;
 }
 static function bind_subject($id,$type,$fingerprint,$label){
  foreach($GLOBALS['agents'] as $a){
    if($a['public_id']===$id){
      $GLOBALS['subjects'][$type.'|'.$fingerprint]=array('agent_id'=>$a['id'],'status'=>'enabled');
      return true;
    }
  }
  return new WP_Error('agent_missing');
 }
 static function resolve_agent($identity){
  $s=self::subject_binding($identity['subject_type'],$identity['subject_fingerprint']);
  if(!$s || $s['status']!=='enabled')return new WP_Error('no_subject');
  foreach($GLOBALS['agents'] as $a)if($a['id']===$s['agent_id'] && $a['status']==='enabled')return $a;
  return new WP_Error('disabled');
 }
 static function grants_for_agent($id,$server){
  return array_values(array_filter($GLOBALS['grants'],function($g)use($id,$server){
    return $g['agent_id']===$id && $g['server_id']===$server;
  }));
 }
 static function grant_ability($public,$server,$ability,$provider,$constraints,$effect,$env){
  foreach($GLOBALS['agents'] as $a)if($a['public_id']===$public){
    $GLOBALS['grants'][]=array('agent_id'=>$a['id'],'server_id'=>$server,
      'ability_name'=>$ability,'provider'=>$provider,'environment'=>$env,'effect'=>$effect);
    return true;
  }
  return new WP_Error('agent_missing');
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-delegation-mode.php';
$C='MAD4B_SCP_Delegation_Mode';
$identity=array('authenticated'=>true,'subject_type'=>'oauth',
 'subject_fingerprint'=>str_repeat('a',64),'wp_user_id'=>7,
 'auth_method'=>'oauth2_bearer','origin'=>'mcp','token_scopes'=>array('mad4b:read'));
$bundle=array('post_type'=>'tours-and-activities','mode'=>'create',
 'post'=>array('post_title'=>'Original testing draft','post_status'=>'draft'));
mad4b_verify($C::selected_mode()==='delegated_user','default');
mad4b_verify($C::authorize_ability($identity,'mad4b/content-apply-bundle',$bundle)===true,'draft');
$published=$bundle;$published['post']['post_status']='publish';
mad4b_verify(is_wp_error($C::authorize_ability($identity,'mad4b/content-apply-bundle',$published)),'publish denied');
$GLOBALS['write_scoped']=false;
mad4b_verify(is_wp_error($C::ensure_content_actor($identity)),'read only scope denied');
mad4b_verify(count($GLOBALS['agents'])===0,'no actor from read token');
$GLOBALS['write_scoped']=true;
$GLOBALS['trusted_oauth']=false;
mad4b_verify(is_wp_error($C::ensure_content_actor($identity)),'unverified bearer denied');
$GLOBALS['trusted_oauth']=true;
$GLOBALS['environment']='production';
mad4b_verify(is_wp_error($C::ensure_content_actor($identity)),'production auto provisioning denied');
$GLOBALS['environment']='staging';
$a=$C::ensure_content_actor($identity);
mad4b_verify(!is_wp_error($a) && $a['wp_user_id']===7 && $a['status']==='enabled','actor for real user');
mad4b_verify(count($GLOBALS['grants'])===2,'two exact draft grants');
foreach($GLOBALS['grants'] as $g)mad4b_verify($g['ability_name']==='mad4b/content-apply-bundle'
 && $g['effect']==='allow' && $g['environment']==='staging','narrow grants');
$b=$C::ensure_content_actor($identity);
mad4b_verify(!is_wp_error($b) && count($GLOBALS['grants'])===2,'repeat idempotent');
$GLOBALS['users'][7]['create_posts']=false;
mad4b_verify(is_wp_error($C::authorize_ability($identity,'mad4b/content-apply-bundle',$bundle)),'revoked post permission');
$GLOBALS['users'][7]['create_posts']=true;
$upd=$bundle;$upd['mode']='update';$upd['post_id']=20;
mad4b_verify($C::authorize_ability($identity,'mad4b/content-apply-bundle',$upd)===true,'own existing draft');
$upd['post_id']=21;
mad4b_verify(is_wp_error($C::authorize_ability($identity,'mad4b/content-apply-bundle',$upd)),'wrong draft denied');
$GLOBALS['users'][7]['edit_post:20']=false;
$upd['post_id']=20;
mad4b_verify(is_wp_error($C::authorize_ability($identity,'mad4b/content-apply-bundle',$upd)),'lost edit cap denied');
$GLOBALS['users'][7]['edit_post:20']=true;
mad4b_verify(is_wp_error($C::authorize_ability($identity,'mad4b/plugin-activate',array())),'plugin denied');
mad4b_verify(is_wp_error($C::authorize_ability($identity,'mad4b/filesystem-write',array())),'unknown denied');
$GLOBALS['mode']='independent_nhi';
mad4b_verify($C::mode_for_identity($identity)==='independent_nhi','legacy independent mode');
mad4b_verify($C::authorize_ability($identity,'mad4b/filesystem-write',array())===true,'legacy checks separately');
$GLOBALS['mode']='delegated_user';
$GLOBALS['subjects']['oauth|'.str_repeat('a',64)]['status']='disabled';
mad4b_verify(is_wp_error($C::ensure_content_actor($identity)),'revoked subject denied');
echo "PASS: DELEGATED_USER_ACTOR_STAGING_AND_NHI_LEGACY\n";
