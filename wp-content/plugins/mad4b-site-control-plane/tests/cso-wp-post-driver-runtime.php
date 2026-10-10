<?php
/** WordPress post adapter denial-first source regression, no live DB changes. */
define('ABSPATH','/');define('ARRAY_A','ARRAY_A');
class WP_Error {private $c;function __construct($c,$m='',$d=null){$this->c=$c;}function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
function get_post($id){return $id===37? $GLOBALS['post'] : null;}
function get_post_meta($id,$key,$single){return $GLOBALS['owner'][$key]??'';}
function current_user_can($cap,$id=null){return $GLOBALS['allowed'];}
function wp_strip_all_tags($v){return strip_tags($v);}
function add_action($hook,$call,$prio){$GLOBALS['hooks'][]=$hook;}
function wp_has_ability($name){return isset($GLOBALS['abilities'][$name]);}
function wp_register_ability($name,$args){$GLOBALS['abilities'][$name]=$args;return (object)$args;}
class MAD4B_SCP_CSO_Scope {
 static function enabled($component){return true;}
 static function current(){return $GLOBALS['scope'];}
 static function first_party_session(){return true;}
 static function assert_current($scope){return $scope===$GLOBALS['scope'];}
 static function safe_data($data){return is_array($data)&&!isset($data['api_key']);}
 static function digest($data){return hash('sha256',json_encode($data));}
 static function error($code){return new WP_Error($code);}
}
class MAD4B_SCP_CSO_Policy_Sentinel {}
class MAD4B_SCP_Policy {static function can_mutate(){return $GLOBALS['mutate'];}}
class MAD4B_SCP_Database_Topology {static function assert_write_ready($strict){return array('ready'=>true);}}
class MAD4B_SCP_CSO_Native_Executor {
 static function native_permit_matches($input,$consume=false){$ok=!empty($GLOBALS['permit']);if($consume)$GLOBALS['permit']=false;return $ok;}
}
interface MAD4B_SCP_CSO_Storage_Provider {
 public function provider_key();
 public function describe($target,$scope);
 public function read($target,$scope);
}
define('MAD4B_CSO_STORAGE_ADAPTER_CLASSES',array('wp_post_core'=>'MAD4B_SCP_CSO_WP_Post_Driver'));
define('MAD4B_CSO_WP_POST_WRITER_ENABLED',true);
$GLOBALS['hooks']=array();$GLOBALS['abilities']=array();
$GLOBALS['allowed']=true;$GLOBALS['mutate']=true;
$GLOBALS['scope']=array('site_uuid'=>'11111111-2222-4333-8444-555555555555',
 'brand_ref'=>str_repeat('b',32),'environment'=>'staging');
$GLOBALS['owner']=array('_mad4b_cso_site_uuid'=>$GLOBALS['scope']['site_uuid'],
 '_mad4b_cso_brand_id'=>$GLOBALS['scope']['brand_ref']);
$GLOBALS['post']=(object)array('ID'=>37,'post_status'=>'publish','post_type'=>'post',
 'post_title'=>'Old title','post_excerpt'=>'Old excerpt','post_modified_gmt'=>'2026-10-10 04:00:00');
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-wp-post-driver.php';
function ck($x,$name){if(!$x){fwrite(STDERR,'FAIL '.$name."\n");exit(1);}}
MAD4B_SCP_CSO_WP_Post_Driver::boot();
ck(count($GLOBALS['hooks'])===1,'opt-in registration hook');
MAD4B_SCP_CSO_WP_Post_Driver::register();
ck(count($GLOBALS['abilities'])===2,'only narrow core read/write Abilities');
ck($GLOBALS['abilities'][MAD4B_SCP_CSO_WP_Post_Driver::WRITE]['meta']['mcp']['public']===false,'write not public MCP');
$provider=new MAD4B_SCP_CSO_WP_Post_Driver();
$target=array('post_id'=>37);
$descriptor=$provider->describe($target,$GLOBALS['scope']);
ck(!is_wp_error($descriptor)&&count($descriptor['fields'])===2&&$descriptor['fields'][0]['required']===false,'bounded title/excerpt descriptor');
ck(is_array($provider->read($target,$GLOBALS['scope'])),'enrolled post readable');
$GLOBALS['owner']['_mad4b_cso_brand_id']=str_repeat('e',32);
ck(is_wp_error($provider->describe($target,$GLOBALS['scope'])),'foreign-brand post denied');
$GLOBALS['owner']['_mad4b_cso_brand_id']=$GLOBALS['scope']['brand_ref'];
$GLOBALS['allowed']=false;
ck(is_wp_error($provider->read($target,$GLOBALS['scope'])),'revoked editor denied');
$GLOBALS['allowed']=true;
$payload=array('provider_id'=>'wp_post_core','target'=>$target,
 'values'=>array('title'=>'New title'), 'expected_revision'=>$descriptor['revision'],
 'scope_sha256'=>MAD4B_SCP_CSO_Scope::digest($GLOBALS['scope']));
ck(!MAD4B_SCP_CSO_WP_Post_Driver::write_permission($payload),'native permission requires claimed one-use ticket');
ck(is_wp_error(MAD4B_SCP_CSO_WP_Post_Driver::write_native($payload)),'direct WordPress write method denied');
ck($GLOBALS['post']->post_title==='Old title','no side effect');
function wp_slash($v){return $v;}
function clean_post_cache($id){}
function wp_update_post($args,$error=false){
 $GLOBALS['updates']++;
 foreach(array('post_title','post_excerpt')as$key)
  if(isset($args[$key]))$GLOBALS['post']->$key=$args[$key];
 $GLOBALS['post']->post_modified_gmt='2026-10-10 14:00:0'.$GLOBALS['updates'];
 return 37;
}
class MockPostDB {
 public $posts='wp_posts';public $postmeta='wp_postmeta';
 function prepare($sql,...$args){return array($sql,$args);}
 function query($sql){return in_array($sql,array('START TRANSACTION','COMMIT','ROLLBACK'),true)?0:false;}
 function get_row($v,$format){return (array)$GLOBALS['post'];}
 function get_results($v,$format){
  $out=array();foreach($GLOBALS['owner']as$key=>$value)
   $out[]=array('meta_key'=>$key,'meta_value'=>$value);
  return $out;
 }
}
$GLOBALS['post']->ID=37;$GLOBALS['updates']=0;$GLOBALS['permit']=false;
$GLOBALS['wpdb']=new MockPostDB();
$payload=array('provider_id'=>'wp_post_core','target'=>$target,
 'values'=>array('title'=>'New title'),'expected_revision'=>$descriptor['revision'],
 'scope_sha256'=>MAD4B_SCP_CSO_Scope::digest($GLOBALS['scope']));
ck(is_wp_error(MAD4B_SCP_CSO_WP_Post_Driver::write_native($payload)),'direct call blocked');
ck($GLOBALS['updates']===0,'not written without approved permit');
$GLOBALS['permit']=true;
$write=MAD4B_SCP_CSO_WP_Post_Driver::write_native($payload);
ck(!is_wp_error($write)&&$write['status']==='written','committed narrowed title update');
ck($GLOBALS['post']->post_title==='New title'&&$GLOBALS['updates']===1,'one effect');
ck(!$GLOBALS['permit'],'permit consumed at effect');
$GLOBALS['permit']=true;
ck(is_wp_error(MAD4B_SCP_CSO_WP_Post_Driver::write_native($payload)),'stale revision blocked');
ck($GLOBALS['updates']===1,'stale revision cannot overwrite newer edit');
echo "PASS CSO WP post bound register/read/one-use write/revision conflict\n";

