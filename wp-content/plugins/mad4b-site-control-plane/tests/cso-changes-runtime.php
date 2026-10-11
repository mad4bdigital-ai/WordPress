<?php
/** Synthetic immutable change plan: no fake write callback is ever invoked. */
define( 'ABSPATH', '/' );
class WP_Error {private $c;function __construct($c,$m='',$d=array()){$this->c=$c;}function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_CSO_Scope {
 static function enabled($k){return in_array($k,array('single_write','forms'),true);}
 static function first_party_session(){return $GLOBALS['cookie'];}
 static function current(){return $GLOBALS['scope'];}
 static function assert_current($s){return $s===$GLOBALS['scope']?true:self::error('SCOPE_CHANGED');}
 static function safe_data($v){return !isset($v['api_key']);}
 static function digest($v){return hash('sha256',json_encode($v));}
 static function error($v){return new WP_Error($v);}
 static function seal($v,$p){return array('material'=>$v,'proof'=>hash_hmac('sha256',$p.json_encode($v),'fixture-key'));}
 static function unseal($v,$p){return isset($v['material'],$v['proof'])&&hash_equals(hash_hmac('sha256',$p.json_encode($v['material']),'fixture-key'),$v['proof'])?$v['material']:self::error('TAMPERED');}
}
$GLOBALS['scope']=array('binding_sha256'=>str_repeat('b',64),'actor_sha256'=>str_repeat('a',64));
$GLOBALS['cookie']=true;
class MAD4B_SCP_CSO_Storage_Adapters {
 static function snapshot($provider,$target){
  if($provider!=='certified-fixture'||$target!==array('post_id'=>101))return new WP_Error('UNKNOWN_PROVIDER');
  return array('revision'=>'v1-revision','descriptor'=>array(
   'descriptor_sha256'=>str_repeat('c',64),'native_write_ability'=>'fixture/approved-write',
   'fields'=>array(array('key'=>'title','type'=>'string','minimum'=>null,'maximum'=>null,'enum'=>null,'max_length'=>80)),
   ),'values'=>array('title'=>'Old title'),'observed_at'=>time());
 }
}
class MAD4B_SCP_CSO01_Read_Foundation {
 static function validate_values($fields,$values){
  return count($values)===1&&isset($values['title'])&&is_string($values['title'])
   ?array('valid'=>true):array('valid'=>false);
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-changes.php';
function ck($v,$why){if(!$v){fwrite(STDERR,'FAIL: '.$why."\n");exit(1);}}
$form=array('provider_id'=>'certified-fixture','target'=>array('post_id'=>101),
 'descriptor_sha256'=>str_repeat('c',64));
$plan=MAD4B_SCP_CSO_Changes::plan($form,array('title'=>'New title'),'v1-revision');
ck(!is_wp_error($plan)&&!$plan['write_authorized']&&!$plan['mutation_performed']
 &&count($plan['field_changes'])===1,'bounded nonexecuting plan');
ck(is_wp_error(MAD4B_SCP_CSO_Changes::plan($form,array('title'=>'New title'),'v0-stale')),'stale target revision denied');
ck(is_wp_error(MAD4B_SCP_CSO_Changes::plan($form,array('title'=>'Old title'),'v1-revision')),'no-op denied');
ck(is_wp_error(MAD4B_SCP_CSO_Changes::plan($form,array('api_key'=>'value'),'v1-revision')),'credential field denied');
ck(is_wp_error(MAD4B_SCP_CSO_Changes::commit($plan,array('grant'=>'forged'))),'generic commit is always denied');
$verified=MAD4B_SCP_CSO_Changes::verify($plan['plan']);
ck(!is_wp_error($verified)&&!$verified['values_match']&&!$verified['execution_receipt_verified'],'readback without execution not mislabelled');
$GLOBALS['scope']['actor_sha256']=str_repeat('d',64);
ck(is_wp_error(MAD4B_SCP_CSO_Changes::verify($plan['plan'])),'foreign actor denied');
$GLOBALS['scope']['actor_sha256']=str_repeat('a',64);
$forged=$plan['plan'];$forged['material']['values']['title']='injected';
ck(is_wp_error(MAD4B_SCP_CSO_Changes::verify($forged)),'tampered plan denied');
$GLOBALS['cookie']=false;
ck(is_wp_error(MAD4B_SCP_CSO_Changes::plan($form,array('title'=>'New title'),'v1-revision')),'no cookie cannot create private plan');
echo "PASS CSO change planning / revision / tamper / no-write\n";
