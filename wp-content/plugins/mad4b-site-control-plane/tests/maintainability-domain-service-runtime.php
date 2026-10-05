<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; private $data; public function __construct($code='',$message='',$data=null){$this->code=$code;$this->data=$data;} public function get_error_code(){return $this->code;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function apply_filters($tag,$value){return $value;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function maybe_serialize($v){return serialize($v);}
function wp_cache_delete($k,$g=''){return true;}
function wp_generate_uuid4(){return '11111111-1111-4111-8111-111111111111';}
$GLOBALS['_opts']=array();
function get_option($n,$d=array()){return array_key_exists($n,$GLOBALS['_opts'])?$GLOBALS['_opts'][$n]:$d;}
function add_option($n,$v,$deprecated='',$autoload=false){if(array_key_exists($n,$GLOBALS['_opts']))return false;$GLOBALS['_opts'][$n]=$v;return true;}
class FixtureWpdb { public $options='wp_options'; public function update($table,$data,$where,$formats=array(),$where_formats=array()){ $n=$where['option_name']; if(!array_key_exists($n,$GLOBALS['_opts'])||serialize($GLOBALS['_opts'][$n])!==$where['option_value'])return 0; $GLOBALS['_opts'][$n]=unserialize($data['option_value']); return 1;} public function delete($table,$where,$formats=array()){ $n=$where['option_name']; if(!array_key_exists($n,$GLOBALS['_opts'])||serialize($GLOBALS['_opts'][$n])!==$where['option_value'])return 0; unset($GLOBALS['_opts'][$n]); return 1;} }
$GLOBALS['wpdb']=new FixtureWpdb();
class MAD4B_SCP_Resource_Constraint_Set { public static function compile($ability,$provider,$input){return array('resource_set_sha256'=>hash('sha256',$ability.'|'.$provider));} }
require __DIR__.'/../includes/class-mad4b-scp-authorization-target-fingerprint.php';
require __DIR__.'/../includes/class-mad4b-scp-managed-skills-lease.php';
function check($ok,$msg){if(!$ok){fwrite(STDERR,$msg."\n");exit(1);}}
$input=array('z'=>2,'a'=>array('b'=>true,'a'=>1));
$fp=MAD4B_SCP_Authorization_Target_Fingerprint::fingerprint('fixture/write','core',$input);
$payload=array('contract'=>'mad4b.authorization-target.v1','ability'=>'fixture/write','provider'=>'core','resource_set_sha256'=>hash('sha256','fixture/write|core'),'input'=>array('a'=>array('a'=>1,'b'=>true),'z'=>2));
check(hash_equals(hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),$fp),'target fingerprint parity failed');
$too_deep=array(); $cursor=&$too_deep; for($i=0;$i<10;$i++){ $cursor['x']=array(); $cursor=&$cursor['x']; }
check(''===MAD4B_SCP_Authorization_Target_Fingerprint::fingerprint('fixture/write','core',$too_deep),'target depth guard failed');
$option='fixture_lock'; $owner=MAD4B_SCP_Managed_Skills_Lease::acquire($option,900);
check(is_string($owner)&&''!==$owner,'skills lease acquire failed');
check(true===MAD4B_SCP_Managed_Skills_Lease::refresh($option,900,$owner),'skills lease refresh failed');
$fenced=MAD4B_SCP_Managed_Skills_Lease::refresh($option,900,'different-owner');
check(is_wp_error($fenced)&&'mad4b_remote_skill_lock_fenced'===$fenced->get_error_code(),'skills lease fencing failed');
MAD4B_SCP_Managed_Skills_Lease::release($option,$owner);
check(array()===get_option($option,array()),'skills lease release failed');
echo "mad4b.maintainability-domain-service-decomposition.v1: PASS\n";
