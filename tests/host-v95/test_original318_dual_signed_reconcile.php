<?php
/** Self-contained Original318 dual-signed Staging test. No real keys or WP DB.
 * Only fixture test source substitutes synthetic independent313 PUBLIC key.
 */
declare(strict_types=1);
error_reporting(E_ALL);
define('ABSPATH','/source');
define('WP_CLI',true);
class WP_CLI {public static function add_command(...$a){}}
$GLOBALS['options']=[];
$GLOBALS['writes']=[];
class TestDB {
 public string $options='wp_options';
 public function prepare($sql,...$args):array{return [$sql,$args];}
 public function query($st):int {
   [$sql,$a]=$st;[$new,$name,$old]=$a;
   if(!isset($GLOBALS['options'][$name])
     ||maybe_serialize($GLOBALS['options'][$name])!==$old)return 0;
   $GLOBALS['options'][$name]=unserialize($new);
   $GLOBALS['writes'][]=['CAS',$name];return 1;
 }
}
$GLOBALS['wpdb']=new TestDB();
function maybe_serialize($s){return is_array($s)?serialize($s):(string)$s;}
function get_option($key,$fallback=false){return $GLOBALS['options'][$key]??$fallback;}
function add_option($key,$data,$deprecated='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['options']))return false;
 $GLOBALS['options'][$key]=$data;$GLOBALS['writes'][]=['add',$key];return true;
}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_is_uuid($s){return is_string($s)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$s);}
function wp_cache_delete(...$args){return true;}
class MAD4B_Central_VPS_Broker_V1 {
 const JOB='mad4b_central_broker_job_v1_';
 const CLAIM='mad4b_central_broker_claim_v1_';
 const PUBKEY='mad4b_central_broker_signing_public_v1';
}
class MAD4B_Central_SSH_Command_Intake_V1 {
 const REQUEST_PREFIX='mad4b_central_ssh_request_v1_';
 const PREFIX='mad4b_central_ssh_grant_v1_';
 const TRANSPORT_PREFIX='mad4b_central_ssh_transport_v1_';
}
class MAD4B_Original_Remote_Hostinger_328_V1 {
 public static function active($transport,$grant){
  return ($transport['state']??null)==='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'
    &&($grant['state']??null)==='CENTRAL_OPERATION_SCOPE_GRANTED'
    &&($GLOBALS['live328']??false)===true;
 }
}
class MAD4B_Company_Native_WPCLI_Enrollment_V1 {
 const SLOT='mad4b_company_native_wpcli_enrollment_v1_';
}
class MAD4B_Central_Dual_Lane_Registry_V1 {
 public static function central(){return true;}
}
class MAD4B_Company_Environment_Registry_V2 {
 public static function target($company,$env){
  return ['company_id'=>$company,'environment'=>$env,'site_uuid'=>SITE,
          'canonical_origin'=>ORIGIN,
          'identity_evidence'=>['profile_digest'=>str_repeat('a',64)]];
 }
}
const SITE='49c562d1-8f2f-456f-b454-26816c6ba4cb';
const ORIGIN='https://staging.allroyalegypt.com';
const COMPANY='all-royal-egypt';
const ENVIRONMENT='staging';
const ISSUE='company.host-authority.anchor.issue';
const REVOKE='company.host-authority.anchor.revoke';
const TRANSPORT='44444444444444444444444444444444';
const PARENT='33333333333333333333333333333333';
$broker=sodium_crypto_sign_keypair();
$independent=sodium_crypto_sign_keypair();
$brokerPrivate=sodium_crypto_sign_secretkey($broker);
$independentPrivate=sodium_crypto_sign_secretkey($independent);
$brokerPub=sodium_crypto_sign_publickey($broker);
$independentPub=sodium_crypto_sign_publickey($independent);
$GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode($brokerPub);
$GLOBALS['live328']=true;
$source=file_get_contents('/source/wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php');
if(substr_count($source,"4jwcLgOUf2d/j2uDYbVysCnkSMJW64cXnhMPaeix11Y=")!==1)throw new RuntimeException('Independent public-key pin changed unexpectedly');
$source=str_replace('4jwcLgOUf2d/j2uDYbVysCnkSMJW64cXnhMPaeix11Y=',base64_encode($independentPub),$source);
eval(substr($source,5));
$cls=MAD4B_Native_Host_Exact_Grant_V1::class;
$checks=0;
function check($ok,$label){global $checks;$checks++;if(!$ok)throw new RuntimeException("CHECK_".$checks.":".$label);}
function seed_job(string $op,string $jid,string $grantid,bool $bootstrap):void {
 $claimedAt=time();
 $job=[
  'job_id'=>$jid,'operation_id'=>$op,'operation'=>$op,
  'broker_host_id'=>'srv2030908','company'=>COMPANY,'environment'=>ENVIRONMENT,
  'site_uuid'=>SITE,'target_host_id'=>'hosting-2','target_ssh_principal'=>'u948953904',
  'job_type'=>'signed_remote_hostinger_wpcli_v2',
  'principal_type'=>'signed_host_principal_v2',
  'target_scope'=>'host','command_format'=>'host.anchor.ref.v2',
  'plan_sha256'=>str_repeat('b',64),'command_sha256'=>str_repeat('c',64),
  'grant_id'=>$grantid,'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
  'claimed_epoch'=>$claimedAt,'expires_epoch'=>time()+1200,'executed'=>false,
  'authority_mode'=>$bootstrap?'bootstrap':'operational',
 ];
 $GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::JOB.$jid]=$job;
 $GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::CLAIM.$jid]=[
  'job_id'=>$jid,'state'=>$job['state'],'host_id'=>'srv2030908',
  'claimed_epoch'=>$claimedAt,'production_mutation_performed'=>false,
 ];
 $GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$jid]=[
  'request_id'=>$jid,'operation'=>$op,'grant_id'=>$grantid,
  'company'=>COMPANY,'environment'=>ENVIRONMENT,'site_uuid'=>SITE,
  'target_host_id'=>'hosting-2','target_ssh_principal'=>'u948953904',
  'command_sha256'=>$job['command_sha256'],
  'target_scope'=>'host','command_format'=>'host.anchor.ref.v2',
  'status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
  'execution_performed'=>false,'central_principal_id'=>59,
  'host_v2_bootstrap_only'=>$bootstrap,
  'host_anchor_identity'=>['plan_sha256'=>$job['plan_sha256']]
 ];
 $GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$grantid]=[
  'grant_id'=>$grantid,'operation'=>$op,
  'company'=>COMPANY,'environment'=>ENVIRONMENT,'site_uuid'=>SITE,
  'principal_type'=>'signed_host_principal_v2',
  'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED',
  'central_principal_id'=>59,'transport_id'=>TRANSPORT,
  'host_v2_bootstrap_only'=>$bootstrap,
  'original328_parent_grant_id'=>PARENT,
 ];
 $GLOBALS['options'][MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.COMPANY.'_'.ENVIRONMENT]=[
   'grant_id'=>PARENT,'transport_id'=>TRANSPORT,
 ];
 $GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.PARENT]=[
  'grant_id'=>PARENT,'operation'=>'company.remote.wpcli.full',
  'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED',
  'central_principal_id'=>59,'company'=>COMPANY,'environment'=>ENVIRONMENT,
  'site_uuid'=>SITE
 ];
 $GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.TRANSPORT]=[
  'transport_id'=>TRANSPORT,'company'=>COMPANY,'environment'=>ENVIRONMENT,
  'site_uuid'=>SITE,'host_id'=>'hosting-2',
  'state'=>'REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED',
 ];
}
function signed_doc(string $op,string $jid,string $grantid,int $revision,
                    int $previous,string $nonce,bool $revoke):array {
 global $brokerPrivate,$independentPrivate;
 $c=MAD4B_Native_Host_Exact_Grant_V1::class;
 $a=[
  'contract'=>$c::DYNAMIC_ANCHOR_CONTRACT,
  'company'=>COMPANY,'environment'=>ENVIRONMENT,'site_uuid'=>SITE,
  'canonical_origin'=>ORIGIN,'profile_digest'=>str_repeat('a',64),
  'host_id'=>'srv2030908','ssh_target'=>'hosting-2','ssh_principal'=>'u948953904',
  'ssh_root_sha256'=>str_repeat('d',64),
  'host_key_sha256'=>str_repeat('e',64),
  'registry_sha256'=>str_repeat('f',64),
  'policy_sha256'=>str_repeat('1',64),
  'revision'=>$revision,'issued'=>time(),'expires'=>time()+600,
  'nonce'=>$nonce,
  'state'=>$revoke?'REVOKED_INDEPENDENT_HOST_AUTHORITY':'ACTIVE_INDEPENDENT_HOST_AUTHORITY',
  'revoked'=>$revoke,'source'=>'original_approved_host_authority_reconcile'
 ];
 $msg=implode("\n",array_map(static fn($key)=>(string)$a[$key],$c::DYNAMIC_ANCHOR_SIGNED_FIELDS));
 $a['signature']=base64_encode(sodium_crypto_sign_detached($msg,$brokerPrivate));
 $approval=[
   'contract'=>$c::V95_APPROVAL_CONTRACT,'job_id'=>$jid,'operation_id'=>$op,
   'company'=>COMPANY,'environment'=>ENVIRONMENT,'site_uuid'=>SITE,
   'plan_sha256'=>str_repeat('b',64),'command_sha256'=>str_repeat('c',64),
   'grant_id'=>$grantid,
   'anchor_revision_before'=>$previous,'anchor_revision_after'=>$revision,
   'anchor_nonce'=>$nonce,'anchor_message_sha256'=>hash('sha256',$msg),
   'issued_epoch'=>time(),'expires_epoch'=>time()+100
 ];
 $canon=$approval;ksort($canon,SORT_STRING);
 $message=$c::V95_APPROVAL_SIGN_DOMAIN.wp_json_encode($canon,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 $approval['signature']=base64_encode(sodium_crypto_sign_detached($message,$independentPrivate));
 return [
   'contract'=>'mad4b.original318.anchor-reconcile-signed.v1',
   'job_id'=>$jid,'operation_id'=>$op,'anchor'=>$a,'approval'=>$approval
 ];
}
function failure(array $result):bool{return ($result['state']??null)==='DENIED'
    && ($result['anchor_issued']??true)===false
    && ($result['original313_certified']??true)===false;}

$firstJid=str_repeat('2',32);$firstGrant=str_repeat('1',32);
seed_job(ISSUE,$firstJid,$firstGrant,true);
$doc=signed_doc(ISSUE,$firstJid,$firstGrant,1,0,str_repeat('7',32),false);
$fake=$doc;$fake['approval']['signature']=base64_encode(random_bytes(64));
check(failure($cls::reconcile_signed_anchor_v95($fake)),'fake Original313 signature denied');
check(count($GLOBALS['writes'])===0,'no write before independent proof');
$fake=$doc;$fake['anchor']['signature']=base64_encode(random_bytes(64));
check(failure($cls::reconcile_signed_anchor_v95($fake)),'fake Broker signature denied');
$fake=$doc;$fake['approval']['anchor_revision_before']=1;
check(failure($cls::reconcile_signed_anchor_v95($fake)),'nonmonotonic revision rejected');
$GLOBALS['live328']=false;
check(failure($cls::reconcile_signed_anchor_v95($doc)),'inactive 328 denied');
$GLOBALS['live328']=true;
$issue=$cls::reconcile_signed_anchor_v95($doc);
check($issue['anchor_issued']===true&&$issue['anchor_revoked']===false,'signed issue WP postwrite');
check($issue['original313_certified']===false,'WordPress never claims independent final receipt');
check($issue['write_authorized']===false,'signed Anchor is not an automatic write grant');
check($issue['fresh_wordpress_readback']['state']==='ACTIVE','fresh Original318 active');
check(count($GLOBALS['writes'])===1,'single first issue write');
check(failure($cls::reconcile_signed_anchor_v95($doc)),'issue replay denied');
check(count($GLOBALS['writes'])===1,'issue replay had no further writes');
$otherJid=str_repeat('8',32);$otherGrant=str_repeat('9',32);
seed_job(REVOKE,$otherJid,$otherGrant,false);
$revoke=signed_doc(REVOKE,$otherJid,$otherGrant,2,1,str_repeat('a',32),true);
$bad=$revoke;$bad['approval']['grant_id']=str_repeat('b',32);
check(failure($cls::reconcile_signed_anchor_v95($bad)),'revoke altered grant binding denied');
$answer=$cls::reconcile_signed_anchor_v95($revoke);
check($answer['anchor_revoked']===true&&$answer['anchor_issued']===false,'signed revoke WP CAS');
check($answer['original313_certified']===false,'revoke still root313 unverified');
check($answer['fresh_wordpress_readback']['state']==='REVOKED','revoked readback cryptographically valid');
check(count($GLOBALS['writes'])===2,'one CAS revocation write');
check(failure($cls::reconcile_signed_anchor_v95($revoke)),'revoke replay rejected');
check(count($GLOBALS['writes'])===2,'revoke replay no writes');
echo json_encode(['state'=>'PASS','checks'=>$checks,'synthetic_issue'=>true,
 'synthetic_revoke'=>true,'root313_final_certified'=>false,
 'production_changed'=>false,'real_signer_used'=>false]).PHP_EOL;
