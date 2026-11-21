<?php
/** Pure selected-HEAD target/manifest equivalence; no update or WordPress runtime. */
define('ABSPATH', __DIR__.'/');
function absint($v) {return abs((int)$v);}
require dirname(__DIR__).'/includes/class-mad4b-scp-self-update.php';
function assert_candidate($ok,$label) {
 if (!$ok){fwrite(STDERR,"FAIL: ".$label.PHP_EOL);exit(2);}
 echo 'PASS '.$label.PHP_EOL;
}
$method=new ReflectionMethod('MAD4B_SCP_Self_Update','trusted_master_release_candidate');
if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$base=array(
 'version'=>'0.4.0-rc.129',
 'display_version'=>'0.4.0-rc.129',
 'source_commit_sha'=>str_repeat('a',40),
 'archive_sha256'=>str_repeat('b',64),
 'build_fingerprint'=>str_repeat('c',64),
 'package_manifest_digest'=>str_repeat('d',64),
 'size_bytes'=>15360,
);
$root=$base+array(
 'release_verdict_success'=>true,
 'release_root_trust_verified'=>true,
 'published_from_master'=>true,
 'release_verdict_run_id'=>12345,
);
$trust=function($identity,$manifest)use($method){return $method->invoke(null,$identity,$manifest);};
$positive=$trust($base,$root);
assert_candidate(is_array($positive)&&$positive['release_verdict_success']===true
 && $positive['published_from_master']===true
 && $positive['release_root_trust_verified']===true
 && $positive['release_verdict_run_id']===12345
 && $positive['source_commit_sha']===$base['source_commit_sha'],
 'preexisting_root_trusted_exact_manifest_only');
foreach(array(
 array('release_verdict_success',false),
 array('release_root_trust_verified',false),
 array('published_from_master',false),
 array('release_verdict_run_id',0),
 array('version','0.4.0-rc.130'),
 array('source_commit_sha',str_repeat('e',40)),
 array('archive_sha256',str_repeat('e',64)),
 array('build_fingerprint',str_repeat('e',64)),
 array('package_manifest_digest',str_repeat('e',64)),
 array('size_bytes',15361)
) as $tamper){
 $changed=$root;$changed[$tamper[0]]=$tamper[1];
 assert_candidate($trust($base,$changed)===array(),'deny_untrusted_or_foreign_'.$tamper[0]);
}
assert_candidate($trust($base,array())===array(),'unapproved_candidate_does_not_acquire_release_flags');
$src=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-self-update.php');
$apply=explode('public static function upload_apply( $input ) {',$src,2);
assert_candidate(count($apply)===2,'native_apply_method_exists');
$apply=explode('public static function native_source_relationship(',$apply[1],2)[0];
foreach(array(
 '\'wordpress_native_candidate_upload\' === $plan[\'channel\']',
 'self::fetch_manifest( true )',
 'self::trusted_master_release_candidate(',
 'if ( $trusted_fields ) $apply_target = array_merge( $apply_target, $trusted_fields );',
 'self::apply_verified_archive('
) as $marker) assert_candidate(strpos($apply,$marker)!==false,'selective_native_trust_'.$marker);
assert_candidate(strpos($apply,'self::verify_archive(')<strpos($apply,'self::fetch_manifest( true )')
 && strpos($apply,'self::fetch_manifest( true )')<strpos($apply,'self::apply_verified_archive('),
 'archive_verified_before_root_trust_recheck_before_apply');
assert_candidate(!function_exists('update_option')&&!function_exists('wp_mail'),
 'no_mutating_wordpress_hooks_available');
echo 'SELECTED_HEAD_NATIVE_TRUST_HERMETIC: PASS (no release approved)'.PHP_EOL;
