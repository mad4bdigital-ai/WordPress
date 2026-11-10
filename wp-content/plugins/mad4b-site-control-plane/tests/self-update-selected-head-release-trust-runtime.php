<?php
/** Hermetic signed Release manifest trust regression; no network or mutation. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
final class WP_Error {
 private $code;
 public function __construct($code,$message='',$data=array()){unset($message,$data);$this->code=(string)$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($x){return abs((int)$x);}
function wp_parse_url($x){return parse_url($x);}
function check_release($pass,$label){if(!$pass){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-self-update.php';
$class='MAD4B_SCP_Self_Update';
$source=str_repeat('a',40);
$valid=[
 'contract'=>$class::MANIFEST_CONTRACT,
 'repository'=>'mad4bdigital-ai/WordPress',
 'release_tag'=>$class::RELEASE_TAG,
 'version'=>'0.4.0-rc.104',
 'source_commit_sha'=>$source,
 'archive_sha256'=>str_repeat('b',64),
 'build_fingerprint'=>str_repeat('c',64),
 'package_manifest_digest'=>str_repeat('d',64),
 'size_bytes'=>15808576,
 'package_url'=>'https://github.com/mad4bdigital-ai/WordPress/releases/download/'.$class::RELEASE_TAG.'/mad4b-site-control-plane-'.$source.'.zip',
 'release_verdict_run_id'=>40817413273,
 'release_verdict_success'=>true,
 'release_root_trust_verified'=>true,
 'published_from_master'=>true,
];
$ref=new ReflectionMethod($class,'validate_manifest');
$manifest=$valid;
$result=$ref->invokeArgs(null,[&$manifest]);
check_release(true===$result,'exact signed source manifest is accepted');
check_release(isset($manifest['display_version'])&&str_contains($manifest['display_version'],'+build.'),'derived display version');
$project=(new ReflectionMethod($class,'public_manifest'))->invoke(null,$manifest);
check_release(is_array($project)&&$project['source_commit_sha']===$source&&$project['release_verdict_run_id']===40817413273,'projection of verified release identity');
foreach(['release_verdict_success','published_from_master','release_root_trust_verified'] as $flag)
 check_release(true===$project[$flag],'projection preserves '.$flag);
$tests=[
 ['contract','bad.contract'],['repository','foreign/WordPress'],['release_tag','wrong-channel'],
 ['version',''],['source_commit_sha','invalid'],['archive_sha256',str_repeat('f',63)],
 ['build_fingerprint','not-sha'],['package_manifest_digest','not-sha'],
 ['size_bytes',0],['size_bytes',16777217],['release_verdict_run_id',0],
 ['release_verdict_success',false],['published_from_master',false],['release_root_trust_verified',false],
 ['package_url','http://github.com/untrusted.zip'],
 ['package_url','https://github.com/mad4bdigital-ai/WordPress/releases/download/'.$class::RELEASE_TAG.'/other.zip'],
];
foreach($tests as [$field,$bad]){
 $case=$valid;$case[$field]=$bad;$out=$ref->invokeArgs(null,[&$case]);
 check_release(is_wp_error($out),'tampered release manifest rejected: '.$field);
}
$code=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-self-update.php');
foreach(['self::fetch_pointer( $force )','self::immutable_manifest_url( $pointer )','self::validate_pointer_manifest_binding( $pointer, $manifest )','self::verify_archive( $tmp, $plan[\'target\'] )','rollback_on_failed_readback'] as $marker)
 check_release(str_contains($code,$marker),'pointer/archive safety contract: '.$marker);
echo 'SELF_UPDATE_SIGNED_RELEASE_TRUST: PASS 26 checks'.PHP_EOL;
