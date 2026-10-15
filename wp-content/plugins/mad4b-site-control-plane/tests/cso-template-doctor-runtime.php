<?php
/** Synthetic template and doctor plans; no server mutations or monitoring. */
define('ABSPATH','/');
class WP_Error {private $code;function __construct($c,$m='',$d=null){$this->code=$c;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_CSO_Scope {
 static function enabled($k){return in_array($k,array('forms','operations'),true);}
 static function first_party_session(){return $GLOBALS['cookie'];}
 static function current(){return $GLOBALS['scope'];}
 static function assert_current($v){return $v===$GLOBALS['scope']?true:self::error('SCOPE_CHANGED');}
 static function error($v){return new WP_Error($v);}
 static function safe_data($v){
  if(is_array($v)){foreach($v as $k=>$x)if((is_string($k)&&$k==='api_key')||!self::safe_data($x))return false;return true;}
  return true;
 }
 static function bounded($v){return strlen(json_encode($v))<=4096;}
 static function digest($v){return hash('sha256',json_encode($v));}
 static function seal($v,$purpose){return array('material'=>$v,'proof'=>hash_hmac('sha256',$purpose.json_encode($v),'fixture-key'));}
}
class MAD4B_SCP_CSO_Forms {
 static function validate($f,$v){return array('valid'=>isset($v['name'])&&is_string($v['name']));}
}
$GLOBALS['scope']=array('site_uuid'=>'11111111-2222-4333-8444-555555555555',
 'binding_sha256'=>str_repeat('b',64),'actor_sha256'=>str_repeat('a',64),'locale'=>'ar_EG');
$GLOBALS['cookie']=true;
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-templates.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-operations.php';
function ck($v,$label){if(!$v){fwrite(STDERR,'FAIL '.$label."\n");exit(1);}}
$form=array('ability_name'=>'fixture/reader','expected_descriptor_sha256'=>str_repeat('c',64));
$recipe=array('label'=>'Draft tour','locale'=>'ar_EG','values'=>array('name'=>'Nile Tour'));
$r=MAD4B_SCP_CSO_Templates::plan($form,$recipe);
ck(!is_wp_error($r)&&!$r['saved']&&!$r['mutation_performed'],'only nonexecuting template');
ck(is_wp_error(MAD4B_SCP_CSO_Templates::plan($form,array('label'=>'X','values'=>array('api_key'=>'secret')))),'credential recipe refused');
$doctor=MAD4B_SCP_CSO_Operations::doctor_plan(array('scope'=>array('site_uuid'=>$GLOBALS['scope']['site_uuid']),'domain'=>'recovery'));
ck(!is_wp_error($doctor)&&$doctor['status']==='IMPLEMENTATION_NOT_LIVE_CERTIFIED'&&!$doctor['recovery_executed'],'doctor truthful');
ck(is_wp_error(MAD4B_SCP_CSO_Operations::doctor_plan(array('scope'=>array('site_uuid'=>'other')))),'foreign site refused');
ck(is_wp_error(MAD4B_SCP_CSO_Operations::monitor_plan(array())),'monitor not falsely started');
$GLOBALS['cookie']=false;
ck(is_wp_error(MAD4B_SCP_CSO_Templates::plan($form,$recipe)),'template requires first-party actor');
echo "PASS CSO template / scoped doctor / denial\n";
