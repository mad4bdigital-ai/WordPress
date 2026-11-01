<?php
// Standalone no-WordPress regression test for *precise scope*, bounds and expiry.
define('ABSPATH', '/tmp/');
function add_action($hook, $callback, $priority=10) {}
require __DIR__.'/company-performance-fragment-gc.php';
function check($test, $msg) {
    if (!$test) throw new \RuntimeException($msg);
}
$root = sys_get_temp_dir().'/mad4b-company-card-fragments-'.hash('sha256','synthetic-fixture-'.getmypid());
mkdir($root, 0700);
$outside = sys_get_temp_dir().'/outside-fragment-test-'.getmypid().'.json';
file_put_contents($outside,'{"expires":1,"html":"outside"}');
$expired = $root.'/'.hash('sha256','expired').'.json';
$active = $root.'/'.hash('sha256','active').'.json';
$bad = $root.'/'.hash('sha256','corrupt').'.json';
$unrelated = $root.'/unrelated.json';
$link = $root.'/'.hash('sha256','link').'.json';
file_put_contents($expired,json_encode(['expires'=>time()-60,'html'=>'valid expired']));
file_put_contents($active,json_encode(['expires'=>time()+3600,'html'=>'valid active']));
file_put_contents($bad,'not-json');
file_put_contents($unrelated,json_encode(['expires'=>1,'html'=>'unrelated']));
symlink($outside,$link);
try {
    $a=mad4b_company_fragment_gc_scan($root,time(),1,120,true);
    check($a['scanned']===1,'scan bound exceeded');
    $b=mad4b_company_fragment_gc_scan($root,time(),250,120,true);
    check(!file_exists($expired),'expired cache not removed');
    check(file_exists($active),'live cache unexpectedly removed');
    check(file_exists($bad),'unparseable cache incorrectly removed');
    check(file_exists($unrelated),'unrelated file incorrectly removed');
    check(is_link($link),'symlink unexpectedly removed');
    check(file_exists($outside),'outside target touched');
    $c=mad4b_company_fragment_gc_scan($root,time(),250,120,false);
    check($c['scanned']===0,'interval lock failed');
    echo "GC TEST PASS: expired deleted; active, invalid, unrelated, symlink, external preserved; bounds and interval enforced\n";
} finally {
    foreach (scandir($root) as $p) if ($p!=='.' && $p!=='..') unlink($root.'/'.$p);
    rmdir($root);
    unlink($outside);
}
