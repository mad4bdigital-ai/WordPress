<?php
/** Offline QA of the release integrity gate. Never flips Safe Boot. */
if ($argc < 2) {fwrite(STDERR,"Usage: php verify_candidate.php <extracted-plugin-root>\n");exit(2);}
$root=rtrim($argv[1],DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
define('DFSB_DIR',$root);
$id=json_decode((string)file_get_contents($root.'build-identity.json'),true);
if(!is_array($id)||empty($id['plugin_version']))throw new RuntimeException('identity missing');
define('DFSB_VERSION',(string)$id['plugin_version']);
require $root.'includes/Diagnostics/BuildIdentity.php';
require $root.'includes/Runtime/BootGuard.php';
$identity=\DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect();
$release=\DynamicFilterSEOBridge\Runtime\BootGuard::releaseReadiness();
// This is an offline installed-content verification, NOT a release certification
// or permission to deploy. A valid unsigned candidate may be runtime-eligible
// by default; the normal manual Safe Boot hold is not touched here.
if(empty($identity['valid']) || empty($identity['installed_content_valid']) ||
  empty($identity['provenance_complete']) || $identity['exact_build_reason']!=='ok' ||
  empty($release['ready']) || $release['reason']!=='ok' ||
  !empty($release['certification_required'])){
    throw new RuntimeException('installed content integrity failure: '.json_encode([$identity,$release]));
}
echo json_encode(['status'=>'PASS','source_sha'=>$identity['git_sha'],
    'files'=>$identity['installed_content_file_count'],
    'content_valid'=>true,'runtime_integrity_ready'=>true,
    'release_certified'=>!empty($identity['package_provenance_valid'])]),"\n";
