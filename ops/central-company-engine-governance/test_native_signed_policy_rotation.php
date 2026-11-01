<?php
/** Hermetic original Ed25519 Native VPS policy-rotation transaction. */
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['opts']=[];
function add_action(...$args){}
function rest_ensure_response($x){return new WP_REST_Response($x);}
function get_option($key,$fallback=false){return array_key_exists($key,$GLOBALS['opts'])?$GLOBALS['opts'][$key]:$fallback;}
function wp_cache_delete(...$args){return true;}
function is_wp_error($x){return $x instanceof WP_Error;}
function maybe_serialize($x){return serialize($x);}
function maybe_unserialize($x){return @unserialize($x);}
function add_option($k,$v,$ignored='',$autoload=false){
 if(array_key_exists($k,$GLOBALS['opts']))return false;
 $GLOBALS['opts'][$k]=$v;return true;
}
class WP_Error {
 public function __construct(public string $code,public string $message,public array $data=[]){}
 public function get_error_code(){return $this->code;}
}
class WP_REST_Response {public function __construct(public $data){}}
class MAD4B_Central_Host_Scope_V1 {
 public static function inspect($which='tours-central'):array{
  return [
   'state'=>'CENTRAL_HOST_IDENTITY_VERIFIED','scope_kind'=>'central_native_host',
   'host_id'=>'srv2030908','site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
   'canonical_origin'=>'https://tours.dev.mad4b.com','environment'=>'staging'
  ];
 }
}
class MAD4B_Central_VPS_Broker_V1{
 const PUBKEY='test-native-pub';
 const JOB='test-job-';
 const CLAIM='test-claim-';
}
class MAD4B_Central_SSH_Command_Intake_V1 {
 const TRANSPORT_PREFIX='test-transport-';
 const PREFIX='test-grant-';
}
class FakeDB {
 public $options='wp_options';
 private $snapshot=null;
 public $failWrites=false;
 public function prepare($format,...$args){return vsprintf(str_replace('%s',"'%s'",$format),array_map('addslashes',$args));}
 public function query($sql){
  if($sql==='START TRANSACTION'){$this->snapshot=$GLOBALS['opts'];return 0;}
  if($sql==='ROLLBACK'){$GLOBALS['opts']=$this->snapshot;return 0;}
  if($sql==='COMMIT')return 0;
  return false;
 }
 public function get_var($sql) {
  if(str_contains($sql,'information_schema.TABLES'))return 'InnoDB';
  if(preg_match("/option_name='([^']+)' FOR UPDATE/",$sql,$m)){
   $x=$GLOBALS['opts'][$m[1]]??null;
   return is_array($x)?serialize($x):null;
  }
  return null;
 }
 public function get_col($sql){return [];}
 public function insert($table,$data,$format) {
  if($this->failWrites||isset($GLOBALS['opts'][$data['option_name']]))return false;
  $GLOBALS['opts'][$data['option_name']]=unserialize($data['option_value']);return 1;
 }
 public function update($table,$data,$where,$format,$whereFormat) {
  if($this->failWrites||!isset($GLOBALS['opts'][$where['option_name']]))return false;
  $GLOBALS['opts'][$where['option_name']]=unserialize($data['option_value']);return 1;
 }
}
$wpdb=new FakeDB();
require __DIR__.'/original-broker-source/323-mad4b-native-vps-principal.php';
function must($cond,$name){if(!$cond){fwrite(STDERR,"FAIL ".$name."\n");exit(1);}}
function denied($x,$code){return $x instanceof WP_Error&&str_contains($x->code,$code);}
$pair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($pair);
$GLOBALS['opts'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=
 base64_encode(sodium_crypto_sign_publickey($pair));
$oldPolicy=str_repeat('a',64);
$newPolicy=str_repeat('b',64);
$tid=str_repeat('c',32);
$gid=str_repeat('d',32);
$fp='SHA256:'.str_repeat('A',43);
$scope=MAD4B_Central_Host_Scope_V1::inspect();
$common=[
 'company'=>'tours-central','site_uuid'=>$scope['site_uuid'],
 'canonical_origin'=>$scope['canonical_origin'],'environment'=>'staging',
 'principal_type'=>'vps_native','principal_id'=>'vps-native:srv2030908',
 'host_policy_sha256'=>$oldPolicy
];
$transport=$common+[
 'transport_id'=>$tid,'state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION',
 'host_key_fingerprint'=>$fp,'host_id'=>'srv2030908',
 'host_principal'=>'root','credential_ref'=>'vault:registry/local-srv2030908'
];
$grant=$common+[
 'grant_id'=>$gid,'transport_id'=>$tid,'grant_mode'=>'persistent',
 'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED','expires_epoch'=>null,
 'operation'=>'maintenance.shell','target_host_id'=>'srv2030908',
 'target_ssh_principal'=>'root'
];
$slot=[
 'principal'=>'vps-native:srv2030908','host_id'=>'srv2030908',
 'site_uuid'=>$scope['site_uuid'],'policy_sha256'=>$oldPolicy,
 'host_key_fingerprint'=>$fp,'transport_id'=>$tid,'grant_id'=>$gid
];
$GLOBALS['opts'][MAD4B_Native_VPS_Principal_V1::SLOT]=$slot;
$GLOBALS['opts'][MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$tid]=$transport;
$GLOBALS['opts'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$gid]=$grant;
must(MAD4B_Native_VPS_Principal_V1::ledger($slot,$scope),'old native ledger valid');
$proof=[
 'contract'=>MAD4B_Native_VPS_Principal_V1::CONTRACT,
 'action'=>'rotate','host_id'=>'srv2030908','company'=>'tours-central',
 'site_uuid'=>$scope['site_uuid'],'canonical_origin'=>$scope['canonical_origin'],
 'environment'=>'staging','operation'=>'maintenance.shell',
 'host_key_fingerprint'=>$fp,'policy_sha256'=>$newPolicy,
 'command_sha256'=>str_repeat('0',64),'correlation_id'=>'enrollment',
 'issued'=>time(),'expires'=>time()+40,'nonce'=>str_repeat('e',32)
];
$fields=MAD4B_Native_VPS_Principal_V1::FIELDS;
$message=implode("\n",array_map(fn($f)=>strval($proof[$f]),$fields));
$proof['signature']=base64_encode(sodium_crypto_sign_detached($message,$secret));
$wrong=$proof;
$wrong['policy_sha256']=str_repeat('f',64);
must(denied(MAD4B_Native_VPS_Principal_V1::rotate($wrong),'signed_original_vps_client_required'),
 'tampered policy denied');
must($GLOBALS['opts'][MAD4B_Native_VPS_Principal_V1::SLOT]===$slot,
 'rejected proof never changes slot');
$read=MAD4B_Native_VPS_Principal_V1::rotate($proof);
must(is_array($read)&&($read['state']??'')==='SIGNED_STANDING_POLICY_ROTATED',
 'signed original policy rotation committed');
$newSlot=MAD4B_Native_VPS_Principal_V1::existing();
must(is_array($newSlot)&&$newSlot['policy_sha256']===$newPolicy,'new policy slot persisted');
must(MAD4B_Native_VPS_Principal_V1::ledger($newSlot,$scope),'new ledger exact');
must($GLOBALS['opts'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$gid]['state']==='REVOKED',
 'old standing grant revoked');
must($GLOBALS['opts'][MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$tid]['state']==='REVOKED',
 'old standing transport revoked');
must($newSlot['grant_id']!==$gid&&$newSlot['transport_id']!==$tid,
 'rotated new grant and transport identifiers');
must(($read['execution_performed']??true)===false,
 'no shell execution performed');
must(denied(MAD4B_Native_VPS_Principal_V1::rotate($proof),'policy_already_current'),
 'reused signed rotation cannot create second grant');
// Force an InnoDB write failure after signature verification and row locks.
// No new grant may survive an aborted atomic policy rotation.
$rollbackProof=$proof;
$rollbackProof['policy_sha256']=str_repeat('c',64);
$rollbackProof['nonce']=str_repeat('f',32);
$rollbackProof['issued']=time();
$rollbackProof['expires']=time()+40;
$rotationMessage=implode("\\n",array_map(
 fn($field)=>strval($rollbackProof[$field]),MAD4B_Native_VPS_Principal_V1::FIELDS));
$rollbackProof['signature']=base64_encode(sodium_crypto_sign_detached($rotationMessage,$secret));
$wpdb->failWrites=true;
$failed=MAD4B_Native_VPS_Principal_V1::rotate($rollbackProof);
must(denied($failed,'signed_policy_rotation_uncertain_or_rejected'),
 'injected transaction failure should deny');
must(MAD4B_Native_VPS_Principal_V1::existing()===$newSlot,
 'failed transaction must preserve prior slot');
must(MAD4B_Native_VPS_Principal_V1::ledger($newSlot,$scope),
 'failed transaction must preserve current grant and transport');
$wpdb->failWrites=false;
echo "PASS signed policy rotation, original host key, InnoDB atomic grant replacement, stale grant revocation, no execution\n";
