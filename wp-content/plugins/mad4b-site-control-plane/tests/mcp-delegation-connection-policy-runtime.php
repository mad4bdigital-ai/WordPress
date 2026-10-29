<?php
// CI must execute these assertions even with zend.assertions=-1.
function mad4b_test_assert($ok) { if (!$ok) { fwrite(STDERR,"FAIL: delegated principal assertion\n"); exit(1); } }
define('ABSPATH','/tmp');
$GLOBALS['mode_setting']=null;
$GLOBALS['site_configured']=true;
$GLOBALS['origin_enrolled']=true;
$GLOBALS['enrolled']=array(11=>true,22=>true,33=>false);
$GLOBALS['caps']=array(
 11=>array('read'=>true,'edit_posts'=>true),
 22=>array('read'=>true,'manage_options'=>true),
 33=>array('read'=>true,'manage_options'=>true)
);
function add_action() {}
function get_option($k,$default){return $GLOBALS['mode_setting']===null?$default:$GLOBALS['mode_setting'];}
function absint($x){return abs((int)$x);}
function sanitize_key($x){return strtolower(trim((string)$x));}
$GLOBALS['restrict_connection_filter']=false;
function has_filter($name){return $name==='mad4b_scp_connection_capability' && $GLOBALS['restrict_connection_filter']?10:false;}
function apply_filters($hook,$value){return $hook==='mad4b_scp_connection_capability' && $GLOBALS['restrict_connection_filter']?'manage_options':$value;}
function get_userdata($id){return isset($GLOBALS['caps'][$id])?(object)array('ID'=>$id):false;}
function user_can($u,$cap){return !empty($GLOBALS['caps'][$u->ID][$cap]);}
class MAD4B_SCP_Site_Profile {
 public static function configured(){return $GLOBALS['site_configured'];}
 public static function origin_enrolled(){return $GLOBALS['origin_enrolled'];}
 public static function user_is_enrolled($id){return !empty($GLOBALS['enrolled'][$id]);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-policy.php';
mad4b_test_assert(MAD4B_SCP_Delegation_Mode::selected_mode()==='delegated_user');
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(11)===true); // verified enrollment, editor need not be admin.
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(22)===true);
$GLOBALS['restrict_connection_filter']=true;
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(11)===false); // Explicit operator admin cap not relaxed.
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(22)===true);
$GLOBALS['restrict_connection_filter']=false;
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(33)===false); // enrollment never bypassed.
$GLOBALS['origin_enrolled']=false;
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(11)===false);
$GLOBALS['origin_enrolled']=true;
$GLOBALS['mode_setting']='independent_nhi';
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(11)===false);
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(22)===true);
$GLOBALS['mode_setting']='INVALID';
mad4b_test_assert(MAD4B_SCP_Delegation_Mode::selected_mode()==='independent_nhi');
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(11)===false);
$GLOBALS['mode_setting']=null;
$GLOBALS['site_configured']=false;
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(11)===false); // no enrolled profile => admin connection cap.
mad4b_test_assert(MAD4B_SCP_Policy::can_connect_user(22)===true);
echo "PASS: DELEGATED_CONNECTION_ENROLLMENT_AND_LEGACY\n";
