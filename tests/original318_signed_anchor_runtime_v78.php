<?php
/** Ephemeral cryptographic fixture: no production key, site DB, or WP command. */
error_reporting(E_ALL);
define('ABSPATH','/test');define('WP_CLI',true);
class WP_CLI { public static function add_command(...$x){} }
class MAD4B_Central_Dual_Lane_Registry_V1 { public static function central(){return true;} }
class MAD4B_Central_VPS_Broker_V1 {const PUBKEY='test_ephemeral_host_public';}
class MAD4B_Company_Environment_Registry_V2 {
 public static function target($company,$env){
  if($company!=='example-tour-company'||$env!=='production')return null;
  return ['company_id'=>$company,'environment'=>$env,'site_uuid'=>'11111111-1111-4111-8111-111111111111',
   'canonical_origin'=>'https://example-tour-company.com',
   'identity_evidence'=>['profile_digest'=>str_repeat('a',64)],'write_authorized'=>true];
 }
}
function wp_is_uuid($x){return is_string($x)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$x)===1;}
function get_option($k,$default=false){return $GLOBALS['test_options'][$k]??$default;}
function add_option($k,$v){if(array_key_exists($k,$GLOBALS['test_options']))return false;$GLOBALS['test_options'][$k]=$v;return true;}
function update_option($k,$v){$changed=($GLOBALS['test_options'][$k]??null)!==$v;$GLOBALS['test_options'][$k]=$v;return $changed;}
$source=file_get_contents(__DIR__.'/../deploy/host-authority/v68/318-mad4b-native-host-exact-grant.php');
$source=str_replace('MAD4B_Native_Host_Exact_Grant_V1','MAD4B_Native_Host_Exact_Grant_V55_Hermetic',$source);
$source=preg_replace('/MAD4B_Native_Host_Exact_Grant_V55_Hermetic::boot\(\);\s*$/','',$source);
eval(substr($source,5));
$c='MAD4B_Native_Host_Exact_Grant_V55_Hermetic';
$keypair=sodium_crypto_sign_keypair();$private=sodium_crypto_sign_secretkey($keypair);
$GLOBALS['test_options']=[MAD4B_Central_VPS_Broker_V1::PUBKEY=>base64_encode(sodium_crypto_sign_publickey($keypair))];
$issued=time();$p=['contract'=>$c::DYNAMIC_IDENTITY_CONTRACT,'company'=>'example-tour-company',
 'environment'=>'production','site_uuid'=>'11111111-1111-4111-8111-111111111111',
 'canonical_origin'=>'https://example-tour-company.com','profile_digest'=>str_repeat('a',64),
 'host_id'=>$c::HOST,'ssh_target'=>'hosting-2','ssh_principal'=>'u123456789',
 'ssh_root_sha256'=>str_repeat('b',64),'host_key_sha256'=>str_repeat('c',64),
 'registry_sha256'=>str_repeat('d',64),'policy_sha256'=>str_repeat('e',64),
 'issued'=>$issued,'expires'=>$issued+40,'nonce'=>bin2hex(random_bytes(16))];
function signed_doc($p,$private,$c){$text=implode("\n",array_map(static fn($key)=>(string)$p[$key],$c::DYNAMIC_IDENTITY_FIELDS));$p['signature']=base64_encode(sodium_crypto_sign_detached($text,$private));return $p;}
$anchorKey=$c::DYNAMIC_ANCHOR_PREFIX.'example-tour-company_production';
$anchor=['contract'=>$c::DYNAMIC_ANCHOR_CONTRACT,'company'=>$p['company'],
 'environment'=>$p['environment'],'site_uuid'=>$p['site_uuid'],
 'canonical_origin'=>$p['canonical_origin'],'profile_digest'=>$p['profile_digest'],
 'host_id'=>$p['host_id'],'ssh_target'=>$p['ssh_target'],
 'ssh_principal'=>$p['ssh_principal'],'ssh_root_sha256'=>$p['ssh_root_sha256'],
 'host_key_sha256'=>$p['host_key_sha256'],'registry_sha256'=>$p['registry_sha256'],
 'policy_sha256'=>$p['policy_sha256'],'revision'=>1,'issued'=>time(),
 'expires'=>time()+1800,'nonce'=>bin2hex(random_bytes(16)),
 'state'=>'ACTIVE_INDEPENDENT_HOST_AUTHORITY','revoked'=>false,
 'source'=>'original_approved_host_authority_reconcile'];
function signed_anchor($anchor,$private,$c){
 $text=implode("\n",array_map(static fn($k)=>(string)$anchor[$k],$c::DYNAMIC_ANCHOR_SIGNED_FIELDS));
 $anchor['signature']=base64_encode(sodium_crypto_sign_detached($text,$private));return $anchor;
}
$anchor=signed_anchor($anchor,$private,$c);
$without=$c::verify_dynamic_identity(signed_doc($p,$private,$c));
assert(($without['reason']??'')==='DYNAMIC_HOST_IDENTITY_INDEPENDENT_ANCHOR_MISSING_OR_DRIFT');
$unsigned=$anchor;$unsigned['signature']=base64_encode(str_repeat('X',64));
$GLOBALS['test_options'][$anchorKey]=$unsigned;
assert(($c::verify_dynamic_identity(signed_doc($p,$private,$c))['eligible']??true)===false);
$GLOBALS['test_options'][$anchorKey]=$anchor;
$p=signed_doc($p,$private,$c);
$ok=$c::verify_dynamic_identity($p);assert(($ok['eligible']??false)===true);
assert(($ok['remote_write_authorized']??true)===false);
$bad=$p;$bad['environment']='staging';assert(($c::verify_dynamic_identity($bad)['eligible']??true)===false);
$bad=$p;$bad['site_uuid']='22222222-2222-4222-8222-222222222222';assert(($c::verify_dynamic_identity($bad)['eligible']??true)===false);
$bad=$p;$bad['profile_digest']=str_repeat('f',64);assert(($c::verify_dynamic_identity($bad)['eligible']??true)===false);
$bad=$p;$bad['signature']=base64_encode(str_repeat('X',64));assert(($c::verify_dynamic_identity($bad)['eligible']??true)===false);
$bad=$p;$bad['issued']=time()-121;$bad['expires']=time()-80;assert(($c::verify_dynamic_identity($bad)['eligible']??true)===false);
$first=$c::attest_dynamic_identity($p);assert(($first['identity_readback_verified']??false)===true);
$second=$c::attest_dynamic_identity($p);assert(($second['reason']??'')==='DYNAMIC_HOST_IDENTITY_NONCE_REPLAY');
$stored=$GLOBALS['test_options'][$c::DYNAMIC_IDENTITY_PREFIX.'example-tour-company_production'];
$after=$c::dynamic_identity_status('example-tour-company','production');assert(($after['remote_write_authorized']??true)===false);
$wrong=$c::dynamic_identity_status('example-tour-company','staging');assert(($wrong['identity_readback_verified']??true)===false);
$changed=$GLOBALS['test_options'][$anchorKey];$changed['host_key_sha256']=str_repeat('f',64);
$GLOBALS['test_options'][$anchorKey]=$changed;
$drifted=$c::dynamic_identity_status('example-tour-company','production');
assert(($drifted['identity_readback_verified']??true)===false);
assert(($c::verify_dynamic_identity($p)['eligible']??true)===false);
$GLOBALS['test_options'][$anchorKey]=$anchor;
$revoked=$anchor;$revoked['revoked']=true;$GLOBALS['test_options'][$anchorKey]=$revoked;
assert(($c::dynamic_identity_status('example-tour-company','production')['identity_readback_verified']??true)===false);
$GLOBALS['test_options'][$anchorKey]=$anchor;
$tampered=$GLOBALS['test_options'][$c::DYNAMIC_IDENTITY_PREFIX.'example-tour-company_production'];
$tampered['signature']=base64_encode(str_repeat('X',64));
$GLOBALS['test_options'][$c::DYNAMIC_IDENTITY_PREFIX.'example-tour-company_production']=$tampered;
assert(($c::dynamic_identity_status('example-tour-company','production')['identity_readback_verified']??true)===false);
$GLOBALS['test_options'][$c::DYNAMIC_IDENTITY_PREFIX.'example-tour-company_production']=$stored;
$newrev=$anchor;$newrev['revision']=2;$GLOBALS['test_options'][$anchorKey]=$newrev;
assert(($c::dynamic_identity_status('example-tour-company','production')['identity_readback_verified']??true)===false);
$GLOBALS['test_options'][$anchorKey]=$anchor;
echo json_encode(['contract'=>'v78.ephemeral-signed-host-anchor-runtime.hermetic',
 'tests'=>16,'signed_anchor_verified'=>true,'unsigned_anchor_rejected'=>true,'positive_signed'=>true,'independent_anchor_required'=>true,'host_key_drift_invalidated'=>true,'revocation_invalidated'=>true,'revision_change_invalidated'=>true,'stored_signature_tamper_invalidated'=>true,'wrong_environment_denied'=>true,
 'wrong_site_uuid_denied'=>true,'wrong_profile_digest_denied'=>true,
 'invalid_signature_denied'=>true,'expired_denied'=>true,'nonce_replay_denied'=>true,
 'wrong_status_denied'=>true,'write_granted'=>false,'production_site_mutated'=>false])."\n";
