<?php
// CI must execute these assertions even with zend.assertions=-1.
function mad4b_test_mad4b_test_assert($ok) { if (!$ok) { fwrite(STDERR,"FAIL: delegated principal assertion\n"); exit(1); } }
define( 'ABSPATH', '/tmp/' );
define( 'REST_REQUEST', true );
$GLOBALS['mode_setting'] = null;
$GLOBALS['verified_oauth'] = true;
$GLOBALS['verified_context'] = array();
$GLOBALS['current_id'] = 11;
$GLOBALS['enrolled'] = array( 11 => true, 22 => true, 33 => false );
$GLOBALS['users'] = array(
    11 => array( 'read' => true, 'edit_posts' => true, 'edit_post:17' => true ),
    22 => array( 'read' => true, 'edit_posts' => true, 'publish_posts' => true, 'edit_post:17' => true, 'manage_options' => true ),
    33 => array( 'read' => true, 'edit_posts' => true, 'manage_options' => true ),
);
$GLOBALS['posts'] = array( 17 => (object) array( 'post_type' => 'post' ), 18 => (object) array( 'post_type' => 'post' ) );
class WP_Error { public $code; function __construct($c,$message=''){ $this->code=$c; } function get_error_code(){return $this->code;} }
function add_action() {}
function get_option($name,$default){return null=== $GLOBALS['mode_setting']?$default:$GLOBALS['mode_setting'];}
function get_current_user_id(){return $GLOBALS['current_id'];}
function absint($v){return abs((int)$v);}
function is_ssl(){return true;}
function wp_get_environment_type(){return 'staging';}
function sanitize_key($v){return strtolower(trim($v));}
function get_userdata($id){return isset($GLOBALS['users'][$id]) ? (object)array('ID'=>$id) : false;}
function user_can($user,$cap,$id=0){return !empty($GLOBALS['users'][$user->ID][$cap.($id?':'.$id:'')]);}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_type_object($type){return $type==='post'?(object)array('cap'=>(object)array('edit_posts'=>'edit_posts','publish_posts'=>'publish_posts')):null;}
class MAD4B_SCP_Policy {
    static function can_connect_user($id){return !empty($GLOBALS['enrolled'][$id]) && user_can(get_userdata($id),'read');}
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    static function verified_bearer_active(){return $GLOBALS['verified_oauth'];}
    static function verified_bearer_has_scope($s){return $s==='mad4b:read'&&$GLOBALS['verified_oauth'];}
}
class MAD4B_SCP_OAuth_Subject_User_Bridge {static function mapped_context(){return $GLOBALS['verified_context'];}}
class MAD4B_SCP_Application_Password_Agent_Bridge {static function verified_context(){return $GLOBALS['verified_app']??array();}}
require dirname(__DIR__).'/includes/class-mad4b-scp-delegation-mode.php';
$C='MAD4B_SCP_Delegation_Mode';
mad4b_test_assert($C::selected_mode()==='delegated_user');
$identity=array(
    'authenticated'=>true, 'subject_type'=>'oauth','subject_fingerprint'=>str_repeat('a',64),
    'auth_method'=>'oauth2_bearer','origin'=>'mcp','wp_user_id'=>11,
    'token_scopes'=>array('mad4b:read'),
);
$GLOBALS['verified_context']=array('authenticated'=>true,'wp_user_id'=>11,'subject_fingerprint'=>$identity['subject_fingerprint']);
mad4b_test_assert($C::mode_for_identity($identity)==='delegated_user');
mad4b_test_assert($C::principal_allowed($identity));
mad4b_test_assert($C::agent_matches_user($identity,array('wp_user_id'=>11))===true);
mad4b_test_assert($C::agent_matches_user($identity,array('wp_user_id'=>22))===false);
mad4b_test_assert($C::agent_matches_user($identity,array())===false);
mad4b_test_assert($C::authorize_ability($identity,'mad4b/content-update-post',array('post_id'=>17))===true);
mad4b_test_assert($C::authorize_ability($identity,'mad4b/content-update-post',array('post_id'=>18)) instanceof WP_Error);
mad4b_test_assert($C::authorize_ability($identity,'mad4b/content-update-post',array('post_id'=>17,'post_status'=>'publish')) instanceof WP_Error);
mad4b_test_assert($C::authorize_ability($identity,'mad4b/content-create-post',array('post_type'=>'post','post_status'=>'draft'))===true);
mad4b_test_assert($C::authorize_ability($identity,'mad4b/content-create-post',array('post_type'=>'post','post_status'=>'publish')) instanceof WP_Error);
mad4b_test_assert($C::authorize_ability($identity,'mad4b/filesystem-write',array()) instanceof WP_Error);
mad4b_test_assert($C::authorize_ability($identity,'mad4b/plugin-activate',array()) instanceof WP_Error);
mad4b_test_assert($C::authorize_ability($identity,'mad4b/approval-plan',array('ability'=>'mad4b/content-update-post','input'=>array('post_id'=>17)))===true);
mad4b_test_assert($C::authorize_ability($identity,'mad4b/write-execute',array('ability'=>'mad4b/write-execute','input'=>array())) instanceof WP_Error);
$GLOBALS['verified_oauth']=false;
mad4b_test_assert(!$C::principal_allowed($identity));
mad4b_test_assert($C::authorize_ability($identity,'mad4b/content-update-post',array('post_id'=>17)) instanceof WP_Error);
$GLOBALS['verified_oauth']=true;
$GLOBALS['current_id']=22;
mad4b_test_assert(!$C::principal_allowed($identity));
$GLOBALS['current_id']=11;
$fake=$identity;$fake['wp_user_id']=33;$GLOBALS['current_id']=33;
mad4b_test_assert(!$C::principal_allowed($fake));
$GLOBALS['current_id']=11;
$GLOBALS['mode_setting']='independent_nhi';
mad4b_test_assert($C::mode_for_identity($identity)==='independent_nhi');
mad4b_test_assert($C::principal_allowed($identity));
mad4b_test_assert($C::authorize_ability($identity,'mad4b/plugin-activate',array())===true); // Legacy NHI still separately checks grants.
$GLOBALS['mode_setting']='invalid_mode';
mad4b_test_assert($C::selected_mode()==='independent_nhi');
$GLOBALS['mode_setting']=null;
$GLOBALS['current_id']=22;
$admin=$identity;$admin['wp_user_id']=22;$admin['subject_fingerprint']=str_repeat('b',64);
$GLOBALS['verified_context']=array('authenticated'=>true,'wp_user_id'=>22,'subject_fingerprint'=>$admin['subject_fingerprint']);
mad4b_test_assert($C::authorize_ability($admin,'mad4b/content-update-post',array('post_id'=>17,'post_status'=>'publish'))===true);
mad4b_test_assert($C::authorize_ability($admin,'mad4b/content-create-post',array('post_type'=>'post','post_status'=>'publish'))===true);
mad4b_test_assert($C::authorize_ability($admin,'mad4b/plugin-activate',array()) instanceof WP_Error); // activate_plugins not granted.
mad4b_test_assert($C::authorize_ability($admin,'mad4b/approval-plan',array('ability'=>'mad4b/content-update-post','input'=>array('post_id'=>18))) instanceof WP_Error);
echo "PASS: DELEGATED_PRINCIPAL_DEFAULT_AND_LEGACY_NHI\n";
