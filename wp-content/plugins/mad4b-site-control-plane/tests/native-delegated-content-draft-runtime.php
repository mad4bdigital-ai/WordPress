<?php
// Hermetic WordPress-CLI principal contract. No WordPress DB, OAuth, I/O, or writes.
define('ABSPATH', '/tmp');
define('WP_CLI', true);
$GLOBALS['who'] = 17;
$GLOBALS['environment'] = 'staging';
$GLOBALS['enrolled'] = true;
$GLOBALS['gate_ready'] = true;
$GLOBALS['schema_ready'] = true;
$GLOBALS['principal_mode'] = 'delegated_user';
$GLOBALS['capabilities'] = array('read'=>true,'edit_posts'=>true,'create_posts'=>true,'edit_post:9'=>true);
$GLOBALS['posts'] = array(9=>(object)array('post_type'=>'tours-and-activities','post_status'=>'draft'),
                         10=>(object)array('post_type'=>'tours-and-activities','post_status'=>'publish'));
function verify($expected, $actual, $label) {
  if ($actual !== $expected) { fwrite(STDERR,'FAIL '.$label.PHP_EOL); exit(1); }
}
function get_option($key,$default){return $GLOBALS['principal_mode'];}
function absint($n){return abs((int)$n);}
function get_current_user_id(){return $GLOBALS['who'];}
function get_userdata($id){return $id===17?(object)array('ID'=>17):false;}
function user_can($user,$cap){return !empty($GLOBALS['capabilities'][$cap]);}
function current_user_can($cap,$id=0){return !empty($GLOBALS['capabilities'][$cap.($id?':'.$id:'')]);}
function sanitize_key($s){return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/','',(string)$s));}
function apply_filters($hook,$default){return $default;}
function has_filter($hook){return false;}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function post_type_exists($type){return 'tours-and-activities'===$type;}
function get_post_type_object($type){
 if(!post_type_exists($type))return null;
 return (object)array('cap'=>(object)array('create_posts'=>'create_posts','edit_posts'=>'edit_posts',
 'edit_others_posts'=>'edit_others_posts','publish_posts'=>'publish_posts'));
}
function wp_get_environment_type(){return $GLOBALS['environment'];}
class MAD4B_SCP_Schema {static function is_ready(){return $GLOBALS['schema_ready'];}}
class MAD4B_SCP_Site_Profile {
 static function configured(){return true;}
 static function current_environment(){return $GLOBALS['environment'];}
 static function origin_enrolled(){return $GLOBALS['enrolled'];}
 static function user_is_enrolled($id){return $id===17&&$GLOBALS['enrolled'];}
 static function governed_write_ready(){return $GLOBALS['gate_ready'];}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-policy.php';
$P='MAD4B_SCP_Policy';
$draft=array('mode'=>'create','post_type'=>'tours-and-activities','post'=>array('post_status'=>'draft','post_title'=>'QA only'));
verify(true,$P::can_plan_mutations(),'enrolled editor plan');
verify(true,$P::can_native_content_draft($draft),'enrolled WP-CLI user create draft');
verify(false,$P::can_mutate(),'native draft never escalates global NHI gate');
$draft['post']['post_status']='pending';verify(true,$P::can_native_content_draft($draft),'pending allowed');
$draft['post']['post_status']='publish';verify(false,$P::can_native_content_draft($draft),'publish denied');
$draft['post']['post_status']='private';verify(false,$P::can_native_content_draft($draft),'private denied');
$draft['post']['post_status']='draft';
$draft['post_id']=9;verify(false,$P::can_native_content_draft($draft),'create cannot select existing ID');
unset($draft['post_id']);
$GLOBALS['capabilities']['create_posts']=false;verify(false,$P::can_native_content_draft($draft),'revoked create cap denies');
$GLOBALS['capabilities']['create_posts']=true;
$draft['post']['post_author']=18;verify(false,$P::can_native_content_draft($draft),'cannot attribute other author');
$GLOBALS['capabilities']['edit_others_posts']=true;verify(true,$P::can_native_content_draft($draft),'authorized other author');
unset($draft['post']['post_author']);
$upd=$draft;$upd['mode']='update';$upd['post_id']=9;
verify(true,$P::can_native_content_draft($upd),'update exact draft');
$upd['post_id']=10;verify(false,$P::can_native_content_draft($upd),'cannot edit published target');
$upd['post_id']=999;verify(false,$P::can_native_content_draft($upd),'cannot edit absent target');
$upd['post_id']=9;$GLOBALS['capabilities']['edit_post:9']=false;
verify(false,$P::can_native_content_draft($upd),'revoked per-post permission');
$GLOBALS['capabilities']['edit_post:9']=true;
$GLOBALS['who']=0;verify(false,$P::can_native_content_draft($draft),'anonymous local CLI denied');
$GLOBALS['who']=17;
$GLOBALS['enrolled']=false;verify(false,$P::can_native_content_draft($draft),'unenrolled site/user denied');
$GLOBALS['enrolled']=true;
$GLOBALS['gate_ready']=false;verify(false,$P::can_native_content_draft($draft),'write kill switch denied');
$GLOBALS['gate_ready']=true;
$GLOBALS['schema_ready']=false;verify(false,$P::can_native_content_draft($draft),'unready schema denied');
$GLOBALS['schema_ready']=true;
$GLOBALS['environment']='production';verify(false,$P::can_native_content_draft($draft),'production fallback denied');
$GLOBALS['environment']='staging';
$GLOBALS['principal_mode']='independent_nhi';verify(false,$P::can_native_content_draft($draft),'NHI is not native WP CLI');
$GLOBALS['principal_mode']='delegated_user';
echo "PASS: NATIVE_DELEGATED_CONTENT_DRAFT_CAPABILITIES\n";
