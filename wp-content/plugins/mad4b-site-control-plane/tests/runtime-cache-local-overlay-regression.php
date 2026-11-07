<?php
declare(strict_types=1);
define('ABSPATH', __DIR__);
$options = array();
function get_option($name, $default = null) { global $options; return $options[$name] ?? $default; }
function update_option($name, $value, $autoload = false) { global $options; $options[$name] = $value; return true; }
require dirname(__DIR__) . '/bootstrap/mad4b-runtime-cache-convergence-mu.php';
$class = MAD4B_SCP_Runtime_Cache_Convergence_MU::class;
$root = '/home/u123456789/domains/mad4b-overlay-test.invalid/public_html/staging/wp-content/plugins/mad4b-site-control-plane';
$private = '/home/u123456789/.mad4b-recovery';
@mkdir($root . '/includes', 0700, true);
@mkdir($private, 0700, true);
$file = $root . '/includes/test.php';
file_put_contents($file, '<?php echo "base";');
$old = file_get_contents($file);
$hash = hash('sha256',$old);
$entry = ['path'=>'includes/test.php','bytes'=>strlen($old),'sha256'=>$hash];
$canonical = "includes/test.php\0".strlen($old)."\0".$hash."\n";
$manifest = [
 'contract'=>'mad4b.build-provenance.v1',
 'source_commit_sha'=>str_repeat('a',40),
 'package_manifest_digest'=>hash('sha256',$canonical),
 'manifest_file_count'=>1,
 'package_files'=>[$entry],
];
file_put_contents($root.'/MAD4B-BUILD-PROVENANCE.json',json_encode($manifest));
$overlayPath=$class::operator_overlay_path($root,'staging');
if ($overlayPath !== $private.'/mcp-runtime-source-overlay.json') {
 throw new RuntimeException('STAGING_PRIVATE_OVERLAY_PATH_FAILED');
}
if ($class::operator_overlay_path($root,'production') !== $class::LOCAL_OVERLAY) {
 throw new RuntimeException('PRODUCTION_ROOT_LOCAL_OVERLAY_PATH_FAILED');
}
$invalidated=0;
$hook=static function($p) use (&$invalidated): bool {$invalidated++;return true;};
$uuid='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
$a=$class::converge($root,'staging',$uuid,$hook);
if ($a['state']!=='revalidated' || $a['files_checked']!==1 || $invalidated!==1) {
 throw new RuntimeException('CLEAN_BASE_CONVERGENCE_FAILED '.json_encode($a));
}
$options=[];
file_put_contents($file,'<?php echo "reviewed hotfix";');
$new=file_get_contents($file);
$binding=hash('sha256',$uuid."\n".'staging'."\n".realpath($root));
$overlay=[
 'contract'=>'mad4b.staging-local-source-overlay.v1',
 'base_manifest_sha256'=>hash_file('sha256',$root.'/MAD4B-BUILD-PROVENANCE.json'),
 'site_binding_sha256'=>$binding,
 'base_source_commit_sha'=>$manifest['source_commit_sha'],
 'base_package_manifest_digest'=>$manifest['package_manifest_digest'],
 'changed_files'=>[
   ['path'=>'includes/test.php','base_sha256'=>$hash,'sha256'=>hash('sha256',$new),
   'base_bytes'=>strlen($old),'bytes'=>strlen($new)]
 ],
];
file_put_contents($overlayPath,json_encode($overlay));
chmod($overlayPath,0600);
$b=$class::converge($root,'staging',$uuid,$hook);
if ($b['state']!=='revalidated' || $b['files_checked']!==1 || $invalidated!==2) {
 throw new RuntimeException('REVIEWED_HOTFIX_CONVERGENCE_FAILED '.json_encode($b));
}
$c=$class::converge($root,'staging',$uuid,$hook);
if ($c['state']!=='current') throw new RuntimeException('EXISTING_RECEIPT_NOT_REUSED');
$options=[];
$overlay['changed_files'][0]['sha256']=str_repeat('f',64);
file_put_contents($overlayPath,json_encode($overlay));
$d=$class::converge($root,'staging',$uuid,$hook);
if ($d['state']!=='blocked') throw new RuntimeException('WRONG_OVERLAY_SHA_ACCEPTED');
echo "MAD4B_RUNTIME_CACHE_OVERLAY=4/4 PASS\n";
