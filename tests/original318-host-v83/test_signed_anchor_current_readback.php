<?php
declare(strict_types=1);
define('ABSPATH', __DIR__.'/');
$GLOBALS['opts']=[];
final class MAD4B_Central_VPS_Broker_V1 { const PUBKEY='broker-key'; }
final class MAD4B_Central_Dual_Lane_Registry_V1 { public static function central():bool{return true;} }
final class MAD4B_Company_Environment_Registry_V2 {
  public static function target(string $c,string $e):array {
    return ['company_id'=>$c,'environment'=>$e,
     'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
     'canonical_origin'=>'https://staging.allroyalegypt.com',
     'identity_evidence'=>['profile_digest'=>str_repeat('a',64)]];
  }
}
function get_option(string $k,$default=false){return $GLOBALS['opts'][$k]??$default;}
function wp_is_uuid(string $s):bool {return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$s);}
function wp_json_encode($v,int $flags=0):string{return json_encode($v,$flags|JSON_THROW_ON_ERROR);}
require dirname(__DIR__,2).'/wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php';
$c='all-royal-egypt';$env='staging';$key='mad4b_company_native_host_identity_anchor_v1_'.$c.'_'.$env;
$class='MAD4B_Native_Host_Exact_Grant_V1';
$now=time();$checks=0;
$check=function(bool $v)use(&$checks):void{++$checks;if(!$v)throw new RuntimeException('ASSERTION_'.$checks);};
$missing=$class::current_anchor_readback_v2($c,$env);
$check(($missing['readback_verified']??true)===false);
$check(($missing['current_anchor_eligible']??true)===false);
$check(($missing['original313_certified']??true)===false);
$check(($missing['fresh_central_highwater_verified']??true)===false);
$check(($missing['write_authorized']??true)===false);
$private=sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat(chr(0x43),32)));
$public=sodium_crypto_sign_publickey_from_secretkey($private);
$GLOBALS['opts'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode($public);
$fields=$class::DYNAMIC_ANCHOR_SIGNED_FIELDS;
$row=[
 'contract'=>$class::DYNAMIC_ANCHOR_CONTRACT,'company'=>$c,'environment'=>$env,
 'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
 'canonical_origin'=>'https://staging.allroyalegypt.com','profile_digest'=>str_repeat('a',64),
 'host_id'=>'srv2030908','ssh_target'=>'hosting-2','ssh_principal'=>'u948953904',
 'ssh_root_sha256'=>str_repeat('b',64),'host_key_sha256'=>str_repeat('c',64),
 'registry_sha256'=>str_repeat('d',64),'policy_sha256'=>str_repeat('e',64),
 'revision'=>1,'issued'=>$now,'expires'=>$now+1000,
 'nonce'=>str_repeat('f',32),'state'=>'ACTIVE_INDEPENDENT_HOST_AUTHORITY',
 'revoked'=>false,'source'=>'original_approved_host_authority_reconcile'
];
$sign=function(array $r)use($private,$fields):array {
 $r['signature']=base64_encode(sodium_crypto_sign_detached(implode(chr(10),array_map(static fn($k)=>(string)$r[$k],$fields)),$private));
 return $r;
};
$GLOBALS['opts'][$key]=$sign($row);
$live=$class::current_anchor_readback_v2($c,$env);
$check(($live['readback_verified']??false)===true);
$check(($live['state']??'')==='ACTIVE');
$check(($live['revision']??0)===1);
$check(($live['current_anchor_signature_verified']??false)===true);
$check(($live['current_anchor_eligible']??true)===false);
$check(($live['signed_anchor_active_in_option']??false)===true);
$check(($live['fresh_central_highwater_verified']??true)===false);
$check(($live['original313_certified']??true)===false);
$check(($live['grant_created']??true)===false);
$GLOBALS['opts'][$key]['policy_sha256']=str_repeat('0',64);
$check($class::current_anchor_readback_v2($c,$env)['readback_verified']===false);
$GLOBALS['opts'][$key]=$sign($row);
$GLOBALS['opts'][$key]['state']='REVOKED_INDEPENDENT_HOST_AUTHORITY';
$check($class::current_anchor_readback_v2($c,$env)['readback_verified']===false);
$revoked=$row;$revoked['state']='REVOKED_INDEPENDENT_HOST_AUTHORITY';$revoked['revoked']=true;$revoked['revision']=2;
$GLOBALS['opts'][$key]=$sign($revoked);
$v=$class::current_anchor_readback_v2($c,$env);
$check($v['readback_verified']===true);
$check($v['state']==='REVOKED');
$check($v['current_anchor_eligible']===false);
$check($v['prior_grants_ineligible']===false);
$check($v['original313_certified']===false);
$check($class::current_anchor_readback_v2('all-royal-egypt','production')['readback_verified']===false);
$check($class::current_anchor_readback_v2('invalid!','staging')['readback_verified']===false);
echo json_encode(['state'=>'PASS','checks'=>$checks,'operation'=>'read_only',
                  'grant_created'=>false,'original313_certified'=>false]).chr(10);
