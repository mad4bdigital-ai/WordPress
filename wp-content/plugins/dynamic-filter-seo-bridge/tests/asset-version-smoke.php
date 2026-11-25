<?php
$root=dirname(__DIR__);define('DFSB_ASSET_VERSION','test-release');
require $root.'/includes/Runtime/AssetVersion.php';
use DynamicFilterSEOBridge\Runtime\AssetVersion;
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$files=[];$consumers=0;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/includes',FilesystemIterator::SKIP_DOTS));
foreach($it as $f){if($f->getExtension()!=='php'||$f->getFilename()==='AssetVersion.php')continue;$s=file_get_contents($f->getPathname());preg_match_all("~AssetVersion::forPath\\('([^']+)'\\)~",$s,$m);if(!$m[1])continue;
 preg_match_all('~wp_enqueue_(?:script|style)\\(~',$s,$calls);check(count($calls[0])===count($m[1]),'unversioned enqueue '.$f->getFilename());
 foreach($m[1] as $path){check(is_file($root.'/'.$path),'missing '.$path);check(AssetVersion::forPath($path)===hash_file('sha256',$root.'/'.$path),'content hash '.$path);$files[$path]=true;$consumers++;}
 check(strpos($s,'filemtime(')===false,'mtime version remains');
}
check($consumers===19&&count($files)===19,'complete enqueue census');check(AssetVersion::forPath('../dynamic-filter-seo-bridge.php')==='test-release','invalid path fallback');check(AssetVersion::forPath('assets/js/absent.js')==='test-release','missing fallback');
check(AssetVersion::forPath('assets/js/ajax-filter-state.js')!==AssetVersion::forPath('assets/js/ajax-taxonomy-reconcile.js'),'independent file versions');
echo "PASS: $consumers enqueue paths, content hashes, independent versions and fallback\n";
