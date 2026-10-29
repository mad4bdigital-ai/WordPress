<?php
declare(strict_types=1);
require __DIR__.'/IndependentAuthority.php';
final class TestFail extends RuntimeException {}
function testit(string $name,bool $condition):void{
    if(!$condition)throw new TestFail($name);
    echo "PASS ".$name."\n";
}
$cls=MAD4B_Independent_Authority::class;
$now=time();$site='22222222-2222-4222-8222-222222222222';
$origin='https://staging-authority.example';
$keys=[];
foreach(['idp','grant','qa','ca'] as $name){
    $kp=sodium_crypto_sign_keypair();
    $keys[$name]=['secret'=>sodium_crypto_sign_secretkey($kp),
                  'public'=>bin2hex(sodium_crypto_sign_publickey($kp)),
                  'kid'=>'qa-local-'.$name];
}
$sign=static function(array $data,string $name)use(&$keys,$cls):array{
    return ['payload_json'=>$cls::json($data),
      'signature_hex'=>bin2hex(sodium_crypto_sign_detached($cls::json($data),$keys[$name]['secret'])),
      'kid'=>$keys[$name]['kid']];
};
$trust=static function(string $name)use(&$keys):array{
    return [$keys[$name]['kid']=>$keys[$name]['public']];
};
$intent=[
 'contract'=>$cls::INTENT,'operation_id'=>'qa-owner-'.bin2hex(random_bytes(8)),
 'action'=>'apply_company_release','site_uuid'=>$site,
 'environment'=>'staging','origin'=>$origin,
 'artifact_sha256'=>str_repeat('a',64),'plan_sha256'=>str_repeat('b',64),
 'rollback_sha256'=>str_repeat('c',64),'rights_sha256'=>str_repeat('d',64),
 'policy_sha256'=>$cls::singleOwnerPolicyDigest(),
 'created_at'=>$now-5,'expires_at'=>$now+180,'nonce'=>bin2hex(random_bytes(16))
];
$hash=$cls::digest($intent);
$idp=$sign(['contract'=>$cls::IDENTITY,'actor_id'=>'qa-real-single-owner',
 'role'=>'platform_owner','site_uuid'=>$site,'environment'=>'staging','intent_sha256'=>$hash,
 'session_sha256'=>hash('sha256','qa-session-'.bin2hex(random_bytes(16))),
 'authentication_method'=>'webauthn','authenticated_at'=>$now-4,'expires_at'=>$now+120],'idp');
$roles=$cls::APPROVERS['apply_company_release'];
$grant=$sign([
 'contract'=>$cls::SINGLE_OWNER_ENROLLMENT,'actor_id'=>'qa-real-single-owner',
 'site_uuid'=>$site,'environment'=>'staging','origin'=>$origin,
 'covered_roles'=>$roles,'allowed_actions'=>['apply_company_release'],
 'policy_sha256'=>$intent['policy_sha256'],'issued_at'=>$now-12,'expires_at'=>$now+180
],'grant');
$qa=$sign(['contract'=>$cls::EVIDENCE,'runner_id'=>'qa-independent-runner',
 'site_uuid'=>$site,'environment'=>'staging','intent_sha256'=>$hash,
 'artifact_sha256'=>$intent['artifact_sha256'],'plan_sha256'=>$intent['plan_sha256'],
 'observed_at'=>$now-4,'expires_at'=>$now+100,
 'results'=>array_fill_keys($cls::REQUIRED_BASE,true),
 'evidence_sha256'=>str_repeat('e',64)],'qa');
$liveGrant=static fn(string $receipt,string $actor,string $uuid,string $action):bool=>
    $uuid==='22222222-2222-4222-8222-222222222222'&&$action==='apply_company_release';
$canSign=static fn(array $p):array=>$sign($p,'ca');
$issue=static function(array $identity,array $enrollment,array $qaProof,array $idpTrust,
 array $grantTrust,callable $grantLive)use($cls,$intent,$trust,$canSign,$now):array{
    return $cls::issueSingleOwner($intent,$identity,$enrollment,$qaProof,
      $idpTrust,$grantTrust,$trust('qa'),$trust('ca'),$canSign,$grantLive,$now);
};
testit('legacy policy digest remains distinct', $cls::singleOwnerPolicyDigest()!==$cls::activePolicyDigest());
testit('single-owner intent matches dedicated policy',
    $cls::checkIntent(array_replace($intent,['policy_sha256'=>$cls::activePolicyDigest()]),$now));
$result=$issue($idp,$grant,$qa,$trust('idp'),$trust('grant'),$liveGrant);
if(($result['valid']??false)!==true) echo 'DENIAL_CODE '.($result['code']??'no_code')."\n";
testit('one owner verified, one signed permit',($result['valid']??false)===true);
$permit=$result['permit_payload']??[];
testit('only one identity receipt',count($permit['identity_receipt_hashes']??[])===1);
testit('three real roles covered',count($permit['covered_roles']??[])===3);
testit('approval mode explicit',($permit['approval_mode']??null)==='single_owner');
testit('not auto-deployed',($result['executed']??null)===false);
$envelope=$result['permit_envelope'];
$verify=static fn(?callable $active)=>$cls::verifyForExecution(
    $envelope,$trust('ca'),$intent,static fn()=>true,$now,$active);
testit('permit requires rechecking enrolled owner',($verify(null)['valid']??null)===false);
testit('valid owner-grant verification passes',($verify($liveGrant)['valid']??false)===true);
testit('revoked grant blocks execution',($verify(static fn()=>false)['valid']??null)===false);
testit('nonce replay guard required',($cls::verifyForExecution(
    $envelope,$trust('ca'),$intent,static fn()=>false,$now,$liveGrant)['valid']??null)===false);
testit('missing enrollment refuses issuance',($issue($idp,[],$qa,$trust('idp'),$trust('grant'),$liveGrant)['valid']??null)===false);
testit('untrusted enrollment refuses issuance',($issue($idp,$grant,$qa,$trust('idp'),[],$liveGrant)['valid']??null)===false);
testit('revoked enrollment refuses issuance',($issue($idp,$grant,$qa,$trust('idp'),$trust('grant'),static fn()=>false)['valid']??null)===false);
testit('same IdP and enrollment key refuses issuance',
    ($issue($idp,array_replace($grant,['kid'=>$keys['idp']['kid']]),$qa,$trust('idp'),$trust('grant'),$liveGrant)['valid']??null)===false);
$forged=$grant;$forged['signature_hex']=str_repeat('0',128);
testit('forged grant signature refuses issuance',($issue($idp,$forged,$qa,$trust('idp'),$trust('grant'),$liveGrant)['valid']??null)===false);
$claims=json_decode($grant['payload_json'],true);
$claims['covered_roles']=['platform_owner','company_owner'];
$shortRoles=$sign($claims,'grant');
testit('incomplete role coverage rejected',($issue($idp,$shortRoles,$qa,$trust('idp'),$trust('grant'),$liveGrant)['valid']??null)===false);
$claims=json_decode($grant['payload_json'],true);
$claims['site_uuid']='33333333-3333-4333-8333-333333333333';
$foreignSite=$sign($claims,'grant');
testit('cross-site owner grant rejected',($issue($idp,$foreignSite,$qa,$trust('idp'),$trust('grant'),$liveGrant)['valid']??null)===false);
$claims=json_decode($grant['payload_json'],true);
$claims['allowed_actions']=['publish_company_content'];
$wrongAction=$sign($claims,'grant');
testit('unapproved action denied',($issue($idp,$wrongAction,$qa,$trust('idp'),$trust('grant'),$liveGrant)['valid']??null)===false);
$claims=json_decode($idp['payload_json'],true);
$claims['role']='company_owner';
$wrongRole=$sign($claims,'idp');
testit('unverified platform owner role denied',($issue($wrongRole,$grant,$qa,$trust('idp'),$trust('grant'),$liveGrant)['valid']??null)===false);
$claims=json_decode($qa['payload_json'],true);
$claims['artifact_sha256']=str_repeat('f',64);
$badQa=$sign($claims,'qa');
testit('QA artifact drift denies issuance',($issue($idp,$grant,$badQa,$trust('idp'),$trust('grant'),$liveGrant)['valid']??null)===false);
$oldTest=$intent;$oldTest['policy_sha256']=$cls::activePolicyDigest();
testit('legacy issuer rejects owner-only idp count',($cls::issue(
    $oldTest,[$idp],$qa,$trust('idp'),$trust('qa'),$trust('ca'),$canSign,$now)['valid']??null)===false);
$legacyIntent=$intent;
$legacyIntent['policy_sha256']=$cls::activePolicyDigest();
$legacyIntent['operation_id']='qa-legacy-'.bin2hex(random_bytes(8));
$legacyIntent['nonce']=bin2hex(random_bytes(16));
$oldHash=$cls::digest($legacyIntent);
$people=[];
foreach(['platform_owner','company_owner','security_reviewer'] as $i=>$role) {
    $actor='legacy-actor-'.($i+1);
    $people[]=$sign([
      'contract'=>$cls::IDENTITY,'actor_id'=>$actor,
      'role'=>$role,'site_uuid'=>$site,'environment'=>'staging',
      'intent_sha256'=>$oldHash,
      'session_sha256'=>hash('sha256',$actor),
      'authentication_method'=>'mfa_step_up',
      'authenticated_at'=>$now-5,'expires_at'=>$now+100
    ],'idp');
}
$oldQa=$sign(['contract'=>$cls::EVIDENCE,'runner_id'=>'qa-independent-runner',
 'site_uuid'=>$site,'environment'=>'staging','intent_sha256'=>$oldHash,
 'artifact_sha256'=>$legacyIntent['artifact_sha256'],
 'plan_sha256'=>$legacyIntent['plan_sha256'],
 'observed_at'=>$now-4,'expires_at'=>$now+100,
 'results'=>array_fill_keys($cls::REQUIRED_BASE,true),
 'evidence_sha256'=>str_repeat('e',64)],'qa');
$legacy=$cls::issue($legacyIntent,$people,$oldQa,$trust('idp'),
    $trust('qa'),$trust('ca'),$canSign,$now);
testit('three-person legacy issue unchanged',($legacy['valid']??false)===true);
testit('legacy permit has three receipts and no single-owner mode',
    count($legacy['permit_payload']['identity_receipt_hashes']??[])===3 &&
    !array_key_exists('approval_mode',$legacy['permit_payload']));
$oldVerified=$cls::verifyForExecution($legacy['permit_envelope'],$trust('ca'),
    $legacyIntent,static fn()=>true,$now);
testit('three-person legacy verification unchanged',($oldVerified['valid']??false)===true);
foreach($keys as &$key){sodium_memzero($key['secret']);}unset($key);
echo "RESULT SINGLE_OWNER_POLICY_TESTS_PASS; production_issuance=false; live_signer_enrolled=false\n";
