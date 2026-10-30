<?php
// php tests/runtime-layers/readonly-layer-gates.php
// Standalone deterministic matrix; must run without booting WordPress or SEO.
require_once dirname(__DIR__, 2).'/includes/Runtime/RuntimeLayers.php';
use DynamicFilterSEOBridge\Runtime\RuntimeLayers;

$source=array(
    'valid'=>true, 'installed_content_valid'=>true,
    'provenance_complete'=>true, 'exact_build_reason'=>'ok'
);
$held=array('faulted'=>false,'build_matches'=>true,'effective_hold'=>true);
$cases=array(
    'held_but_verified'=>array($source,$held,true,true),
    'invalid_manifest'=>array(array_merge($source,array('installed_content_valid'=>false)),$held,false,true),
    'bad_provenance'=>array(array_merge($source,array('provenance_complete'=>false)),$held,false,true),
    'bad_build_reason'=>array(array_merge($source,array('exact_build_reason'=>'mismatch')),$held,false,true),
    'faulted'=>array($source,array_merge($held,array('faulted'=>true)),false,true),
    'build_mismatch'=>array($source,array_merge($held,array('build_matches'=>false)),false,true),
    'full_boot_unheld'=>array($source,array_merge($held,array('effective_hold'=>false)),true,false),
);
foreach($cases as $name=>$case){
    $result=RuntimeLayers::decide($case[0],$case[1]);
    if($result['presentation_read']!==$case[2] ||
       $result['filter_context_read']!==$case[2] ||
       $result['seo_full_boot_held']!==$case[3] ||
       $result['authorizing']!==false ||
       $result['read_only']!==true){
        fwrite(STDERR,"FAIL ".$name.PHP_EOL);
        exit(1);
    }
    echo "PASS ".$name.PHP_EOL;
}
