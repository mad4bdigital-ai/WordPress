<?php
// Isolated synthetic-key compatibility tests. NEVER writes a WordPress option.
declare(strict_types=1);
define('ABSPATH','/synthetic-isolated-php-test/');
function wp_is_uuid(string $uuid):bool {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $uuid) === 1;
}
require_once dirname(__DIR__,2).'/wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php';
$cls = 'MAD4B_Native_Host_Exact_Grant_V1';
$reflection = new ReflectionClass($cls);
$method = $reflection->getMethod('expired_anchor_digest_v103');
$fields = $reflection->getConstant('DYNAMIC_ANCHOR_SIGNED_FIELDS');
$now = 1770000000;
$site = [
    'company_id'=>'all-royal-egypt',
    'environment'=>'staging',
    'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'canonical_origin'=>'https://staging.allroyalegypt.com',
    'identity_evidence'=>['profile_digest'=>str_repeat('a',64)]
];
$anchor = [
    'contract'=>'mad4b.company-host-authority-anchor.v2',
    'company'=>'all-royal-egypt','environment'=>'staging',
    'site_uuid'=>$site['site_uuid'],
    'canonical_origin'=>$site['canonical_origin'],
    'profile_digest'=>$site['identity_evidence']['profile_digest'],
    'host_id'=>'srv2030908','ssh_target'=>'hosting-2',
    'ssh_principal'=>'u948953904',
    'ssh_root_sha256'=>str_repeat('1',64),
    'host_key_sha256'=>str_repeat('2',64),
    'registry_sha256'=>str_repeat('3',64),
    'policy_sha256'=>str_repeat('4',64),
    'revision'=>1,
    'issued'=>$now-1200,
    'expires'=>$now-300,
    'nonce'=>str_repeat('5',32),
    'state'=>'ACTIVE_INDEPENDENT_HOST_AUTHORITY',
    'revoked'=>false,
    'source'=>'original_approved_host_authority_reconcile'
];
$keypair=sodium_crypto_sign_keypair();
$pub=sodium_crypto_sign_publickey($keypair);
$private=sodium_crypto_sign_secretkey($keypair);
$sign = static function(array $a) use ($fields,$private):array {
    $body=implode(chr(10),array_map(static fn($field)=>(string)$a[$field],$fields));
    $a['signature']=base64_encode(sodium_crypto_sign_detached($body,$private));
    return $a;
};
$valid=$sign($anchor);
$call=static fn(array $a,array $s,string $p,int $t) =>
    $method->invoke(null,$a,$s,$p,$t);
$tests=0;
$expected=hash('sha256',implode(chr(10),array_map(
    static fn($f)=>(string)$valid[$f],$fields)));
if($call($valid,$site,$pub,$now)!==$expected)throw new RuntimeException('EXPIRED_VALID_SIGNATURE_MISSING');
$tests++;
$negative=[
    'not_expired'=>array_replace($anchor,['expires'=>$now+30]),
    'too_long'=>array_replace($anchor,['expires'=>$now-1,'issued'=>$now-7200]),
    'wrong_rev'=>array_replace($anchor,['revision'=>2]),
    'revoked'=>array_replace($anchor,['revoked'=>true]),
    'different_tenant'=>array_replace($anchor,['company'=>'wayfatours']),
    'different_host'=>array_replace($anchor,['ssh_target'=>'hosting-3']),
    'different_key'=>array_replace($anchor,['profile_digest'=>str_repeat('b',64)]),
    'future_issue'=>array_replace($anchor,['issued'=>$now+20,'expires'=>$now+30]),
];
foreach($negative as $label=>$values){
    $signed=$sign($values);
    if($call($signed,$site,$pub,$now)!==null)throw new RuntimeException("UNSAFE_ACCEPTED_$label");
    $tests++;
}
$tampered=$valid;$tampered['nonce']=str_repeat('9',32);
if($call($tampered,$site,$pub,$now)!==null)throw new RuntimeException('TAMPERED_NONCE_ACCEPTED');
$tests++;
$other=sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
if($call($valid,$site,$other,$now)!==null)throw new RuntimeException('UNENROLLED_SIGNER_ACCEPTED');
$tests++;
echo json_encode(['state'=>'PASS','tests'=>$tests,
 'signature_originally_valid_but_expired'=>true,
 'authority_for_grant_created'=>false,
 'wordpress_option_written'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
