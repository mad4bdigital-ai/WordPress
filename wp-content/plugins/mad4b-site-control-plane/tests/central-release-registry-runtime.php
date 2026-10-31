<?php
/** Hermetic signed central release readback test, without remote writes. */
declare(strict_types=1);
if ( ! defined('ABSPATH') ) define('ABSPATH', __DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code,$message=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function ck($value,$message){if(!$value){fwrite(STDERR,"CENTRAL_RELEASE_FAIL: ".$message.PHP_EOL);exit(1);}}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function wp_safe_remote_get($url,$opts){
    $GLOBALS['last_remote_url']=$url;
    $GLOBALS['last_remote_opts']=$opts;
    return $GLOBALS['response']??new WP_Error('offline');
}
function sorted($value){
    if(!is_array($value))return $value;
    if($value && array_keys($value)!==range(0,count($value)-1))ksort($value,SORT_STRING);
    foreach($value as &$v)$v=sorted($v);
    unset($v);
    return $value;
}
require dirname(__DIR__).'/includes/class-mad4b-scp-central-release-registry.php';
$cls='MAD4B_SCP_Central_Release_Registry';
$cfg=$cls::configuration();
ck($cfg && $cfg['contract']==='mad4b.central-staging-source.v1','trusted source config missing');
ck($cls::central_origin()===$cfg['origin'],'central origin config mismatch');
$priv=openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA));
ck((bool)$priv,'RSA key fixture unavailable');
$pub=openssl_pkey_get_details($priv)['key'];
define('MAD4B_CENTRAL_RELEASE_PUBLIC_KEY_PEM',$pub);
$sha=str_repeat('a',64);
$receipt=array(
  'contract'=>'mad4b.central-staging-release-readback.v1',
  'staging_origin'=>$cfg['origin'],
  'environment'=>'staging',
  'artifact_sha256'=>$sha,
  'file_manifest_sha256'=>str_repeat('b',64),
  'source_type'=>'local_git',
  'source_commit'=>str_repeat('c',40),
  'staging_pass'=>true,
  'checks'=>array('install'=>true,'smoke'=>true,'rollback'=>true),
  'tested_at'=>time(),
  'requirements'=>array('target_plugin_file'=>'sample/sample.php','target_version'=>'1.1.0',
    'php_min'=>'8.1.0','wordpress_min'=>'6.5.0','required_plugins'=>array()),
);
$canonical=json_encode(sorted($receipt),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
ck(openssl_sign($canonical,$signature,$priv,OPENSSL_ALGO_SHA256),'sign test readback');
$envelope=array(
 'contract'=>'mad4b.central-release-evidence-envelope.v1',
 'receipt'=>$receipt,'signature_b64'=>base64_encode($signature)
);
$verified=$cls::verify_envelope($envelope,$sha,$cfg['origin'],$pub);
ck(!is_wp_error($verified) && $verified['artifact_sha256']===$sha,'valid signed central readback');

$wrong=$envelope;$wrong['receipt']['staging_pass']=false;
ck(is_wp_error($cls::verify_envelope($wrong,$sha,$cfg['origin'],$pub)),'changed PASS must invalidate signature');
$wrong=$envelope;$wrong['receipt']['checks']['rollback']=false;
ck(is_wp_error($cls::verify_envelope($wrong,$sha,$cfg['origin'],$pub)),'changed rollback must invalidate signature');
$wrong=$envelope;$wrong['receipt']['staging_origin']='https://forged.example';
ck(is_wp_error($cls::verify_envelope($wrong,$sha,$cfg['origin'],$pub)),'untrusted central origin denied');
$wrong=$envelope;$wrong['receipt']['artifact_sha256']=str_repeat('d',64);
ck(is_wp_error($cls::verify_envelope($wrong,$sha,$cfg['origin'],$pub)),'different artifact denied');
$wrong=$envelope;$wrong['signature_b64']='bad';
ck(is_wp_error($cls::verify_envelope($wrong,$sha,$cfg['origin'],$pub)),'bad signature denied');
$wrong=$envelope;$wrong['receipt']['registry_readback_verified']=true;
ck(is_wp_error($cls::verify_envelope($wrong,$sha,$cfg['origin'],$pub)),'caller cannot claim registry verification');

$GLOBALS['response']=array('code'=>200,'body'=>json_encode($envelope));
$r=$cls::verified_readback($sha);
ck($r && $r['registry_readback_verified']===true,'trusted remote readback accepted');
ck($GLOBALS['last_remote_url']===$cfg['origin'].$cfg['readback_route'].$sha,'only pinned central endpoint queried');
ck($GLOBALS['last_remote_opts']['redirection']===0 && $GLOBALS['last_remote_opts']['limit_response_size']===65536,'bounded safe fetch');
$GLOBALS['response']=array('code'=>200,'body'=>json_encode($wrong));
ck(!$cls::verified_readback($sha),'untrusted remote payload not accepted');
ck(!$cls::verified_readback('latest'),'mutable release identifier denied');
echo "CENTRAL_RELEASE_REGISTRY_RUNTIME: PASS".PHP_EOL;
