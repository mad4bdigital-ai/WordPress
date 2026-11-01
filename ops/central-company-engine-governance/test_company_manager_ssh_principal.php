<?php
declare(strict_types=1);
define('ABSPATH','/');
define('WP_CLI',true);
$GLOBALS['actor']=26;$GLOBALS['now']=time();$GLOBALS['options']=[];
$GLOBALS['ledger_valid']=true;$GLOBALS['calls']=[];
$GLOBALS['manager']='123e4567-e89b-52d3-a456-426614174000';
$GLOBALS['company_uuid']='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
class WP_Error {
 private string $code;
 public function __construct($code,$message='',$data=null){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
class WP_CLI {
 public static function add_command(...$args) {}
 public static function error($reason){throw new RuntimeException($reason);}
 public static function line($message){echo $message.PHP_EOL;}
}
function is_wp_error($a){return $a instanceof WP_Error;}
function get_current_user_id(){return $GLOBALS['actor'];}
function current_user_can($cap){return in_array($cap,['manage_options','activate_plugins','install_plugins'],true);}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function add_option($k,$v,$deprecated='',$autoload=false){if(array_key_exists($k,$GLOBALS['options']))return false;$GLOBALS['options'][$k]=$v;return true;}
function wp_json_encode($x){return json_encode($x);}
class MAD4B_Company_Operation_Admission_V1 {public static function manager_id(){return $GLOBALS['manager'];}}
class MAD4B_Native_VPS_Principal_V1 {
 public static function scope(){return [
  'contract'=>'mad4b.central-host-scope.v1','state'=>'CENTRAL_HOST_IDENTITY_VERIFIED',
  'environment'=>'staging','workload'=>'tours-central',
  'site_uuid'=>$GLOBALS['company_uuid'],'canonical_origin'=>'https://tours.dev.mad4b.com'];}
 public static function existing(){return ['signed'=>true];}
 public static function ledger($a,$b){return $GLOBALS['ledger_valid'];}
}
class MAD4B_Company_Portfolio_Manager_V1 {
 const MANAGERS='managers';const MEMBERS='members';
 public static function staging(){return true;}
 public static function company_binding($code){return [
  'company_code'=>'tours-central','company_site_uuid'=>$GLOBALS['company_uuid'],
  'binding_sha256'=>str_repeat('a',64)];}
 public static function normalize_rights($v){return ['view_company',...$v];}
 public static function propose($v){$GLOBALS['calls'][]=['propose',$v];return ['state'=>'proposed','revision'=>1,'company_code'=>$v['company_code'],'proposal_sha256'=>str_repeat('b',64)];}
 public static function change_assignment($v){$GLOBALS['calls'][]=['change',$v];return ['state'=>$v['action']==='approve'?'approved':'active','revision'=>$v['expected_revision']+1,'company_code'=>$v['company_code']];}
}
require __DIR__.'/original-broker-source/class-mad4b-company-manager-ssh-principal.php';
$class='MAD4B_Company_Manager_SSH_Principal_V1';
$key=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($key);
$GLOBALS['options'][$class::PUBKEY]=base64_encode(sodium_crypto_sign_publickey($key));
$GLOBALS['options']['managers']=[$GLOBALS['manager']=>['state'=>'registered']];
$GLOBALS['options']['members']=[$GLOBALS['manager'].'|26'=>['state'=>'active']];
function signed($operation='propose',$changes=[]) {
 global $secret;
 $class='MAD4B_Company_Manager_SSH_Principal_V1';
 $now=time();
 $v=[
 'contract'=>$class::CONTRACT,'operation'=>$operation,
 'company'=>'tours-central','environment'=>'staging',
 'site_uuid'=>$GLOBALS['company_uuid'],'canonical_origin'=>'https://tours.dev.mad4b.com',
 'binding_sha256'=>str_repeat('a',64),'manager_id'=>$GLOBALS['manager'],
 'actor_user_id'=>26,'expected_revision'=>$operation==='propose'?0:($operation==='approve'?1:2),
 'rights'=>$operation==='propose'?['operations_manage']:null,
 'reason'=>$operation==='propose'?'Staging Demo manager assignment':null,
 'expected_proposal_sha256'=>$operation==='approve'?str_repeat('b',64):null,
 'issued'=>$now,'expires'=>$now+40,'nonce'=>bin2hex(random_bytes(16))];
 foreach($changes as $k=>$value)$v[$k]=$value;
 $raw=json_encode($v,JSON_UNESCAPED_SLASHES);
 $signature=sodium_crypto_sign_detached($class::CONTRACT."\n".$raw,$secret);
 return base64_encode(json_encode(['payload'=>base64_encode($raw),'signature'=>base64_encode($signature)]));
}
function ck($ok,$reason){if(!$ok)throw new RuntimeException('FAIL '.$reason);}
function err($x,$why){ck(is_wp_error($x)&&$x->get_error_code()==='mad4b_manager_ssh_'.$why,$why);}
$proof=signed();$v=$class::evaluate($proof);
ck(is_array($v)&&$v['company']==='tours-central','signed demo proof');
err($class::evaluate(signed('propose',['company'=>'wrong-site'])),'native_host_scope_unverified');
err($class::evaluate(signed('propose',['actor_user_id'=>12])),'wordpress_actor_not_authenticated');
err($class::evaluate(signed('propose',['expires'=>time()-2])),'proof_expired');
err($class::evaluate(signed('propose',['environment'=>'production'])),'scope_schema');
err($class::evaluate(signed('propose',['site_uuid'=>str_repeat('e',36)])),'native_host_scope_unverified');
$GLOBALS['ledger_valid']=false;
err($class::evaluate(signed()),'native_host_scope_unverified');
$GLOBALS['ledger_valid']=true;
$broken=json_decode(base64_decode(signed()),true);
$broken['signature']=base64_encode(str_repeat('x',64));
err($class::evaluate(base64_encode(json_encode($broken))),'signature_invalid');
unset($GLOBALS['options']['members'][$GLOBALS['manager'].'|26']);
err($class::evaluate(signed()),'manager_membership_not_active');
$GLOBALS['options']['members'][$GLOBALS['manager'].'|26']=['state'=>'active'];
$out=$class::apply($proof);
ck(is_array($out)&&$out['state']==='proposed','original propose');
ck($GLOBALS['calls'][0][0]==='propose','provider called');
err($class::apply($proof),'nonce_already_used');
$out=$class::apply(signed('approve'));
ck(is_array($out)&&$out['state']==='approved','original approve');
ck($GLOBALS['calls'][1][1]['expected_proposal_sha256']===str_repeat('b',64),'approved exact proposal');
$out=$class::apply(signed('activate'));
ck(is_array($out)&&$out['state']==='active','original activate');
err($class::evaluate(signed('activate',['rights'=>['operations_manage']])),'transition_invalid');
echo "PASS signed manager SSH identity, exact actor/scope, manager membership, provider-only transitions, nonce replay and negative cases".PHP_EOL;
