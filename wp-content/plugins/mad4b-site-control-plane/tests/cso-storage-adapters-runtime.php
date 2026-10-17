<?php
/** CSO storage adapter admission/permission/revision tests without writes. */
define('ABSPATH','/');
class WP_Error {private $code;function __construct($code,$m='',$d=null){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_CSO_Scope {
 static function enabled($k){return 'forms'===$k;}
 static function current(){return $GLOBALS['scope'];}
 static function assert_current($s){return $s===$GLOBALS['scope']?true:self::error('SCOPE_CHANGED');}
 static function safe_data($v){return is_array($v)&&!isset($v['api_key']);}
 static function digest($v){return hash('sha256',json_encode($v));}
 static function error($reason){return new WP_Error($reason);}
}
class NativeRead {
 public function check_permissions($target){return $GLOBALS['read_allowed'];}
}
function wp_get_ability($name){return in_array($name,array('fake/read','fake/write'),true)?new NativeRead():null;}
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-storage-adapters.php';
class Fake_CSO_Provider implements MAD4B_SCP_CSO_Storage_Provider {
 public function provider_key(){return 'fake-driver';}
 public function describe($target,$scope){return array(
  'provider_id'=>'fake-driver','target'=>$target,'descriptor_sha256'=>str_repeat('c',64),
  'revision'=>'revision00001',
  'fields'=>array(array('key'=>'title','type'=>'string')),
  'native_read_ability'=>'fake/read','native_write_ability'=>'fake/write');}
 public function read($target,$scope){return array('revision'=>$GLOBALS['provider_revision'],
  'values'=>array('title'=>'Current'),'observed_at'=>time());}
}
define('MAD4B_CSO_STORAGE_ADAPTER_CLASSES',array('fake-driver'=>'Fake_CSO_Provider'));
$GLOBALS['scope']=array('binding_sha256'=>str_repeat('a',64),'actor_sha256'=>str_repeat('b',64));
$GLOBALS['read_allowed']=true;$GLOBALS['provider_revision']='revision00001';
function ck($v,$msg){if(!$v){fwrite(STDERR,'FAIL '.$msg."\n");exit(1);}}
$target=array('post_id'=>101);
$entry=MAD4B_SCP_CSO_Storage_Adapters::discover('fake-driver',$target);
ck(!is_wp_error($entry)&&!$entry['write_authorized']&&!$entry['mutation_performed'],'admission only, never grant');
$r=MAD4B_SCP_CSO_Storage_Adapters::snapshot('fake-driver',$target);
ck(!is_wp_error($r)&&$r['values']['title']==='Current'&&!$r['authorizing'],'bounded read only');
ck(is_wp_error(MAD4B_SCP_CSO_Storage_Adapters::discover('foreign-driver',$target)),'foreign provider denied');
ck(is_wp_error(MAD4B_SCP_CSO_Storage_Adapters::discover('fake-driver',array('api_key'=>'secret'))),'unsafe target refused');
$GLOBALS['read_allowed']=false;
ck(is_wp_error(MAD4B_SCP_CSO_Storage_Adapters::snapshot('fake-driver',$target)),'native read grant revoked');
$GLOBALS['read_allowed']=true;
$GLOBALS['provider_revision']='revision00002';
ck(is_wp_error(MAD4B_SCP_CSO_Storage_Adapters::snapshot('fake-driver',$target)),'stale revision blocked');
echo "PASS CSO storage adapter allowlist/native read/revision/scope denies\n";
