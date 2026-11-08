<?php
error_reporting(E_ALL);
define('ABSPATH','/');
function add_action($hook,$cb){return true;}
require $argv[1];
$time=1000000;
$a=['uid'=>24,'created'=>$time-50,'preview'=>[
    'selection'=>'post:60629','paths'=>['/','/ar/'],
    'fingerprint'=>str_repeat('a',64)]];
$valid=MAD4B_Cache_Control_V1::preview_access_state($a,24,$time);
if($valid!=='ok')throw new RuntimeException('correct session denied: '.$valid);
$bad=[
    ['data'=>false,'uid'=>24,'now'=>$time],
    ['data'=>$a,'uid'=>25,'now'=>$time],
    ['data'=>$a,'uid'=>0,'now'=>$time],
    ['data'=>array_replace($a,['created'=>$time+300]),'uid'=>24,'now'=>$time],
    ['data'=>array_replace($a,['created'=>$time-601]),'uid'=>24,'now'=>$time],
    ['data'=>array_replace($a,['created'=>'not integer']),'uid'=>24,'now'=>$time],
    ['data'=>array_replace($a,['preview'=>['selection'=>'post:60629']]),'uid'=>24,'now'=>$time],
];
foreach ($bad as $case) {
  $result=MAD4B_Cache_Control_V1::preview_access_state($case['data'],$case['uid'],$case['now']);
  if($result==='ok')throw new RuntimeException('rejected preview was accepted');
}
$f=$a['preview'];
if(!MAD4B_Cache_Control_V1::preview_fingerprint_matches($f,$f))
 throw new RuntimeException('matching preview was rejected');
$tests=[
  array_replace($f,['fingerprint'=>str_repeat('b',64)]),
  array_replace($f,['selection'=>'post:60630']),
  array_replace($f,['paths'=>['/','/fr/']]),
  array_replace($f,['error'=>'unknown_object']),
  array_replace($f,['fingerprint'=>'invalid']),
];
foreach ($tests as $case) {
 if(MAD4B_Cache_Control_V1::preview_fingerprint_matches($f,$case))
  throw new RuntimeException('changed policy/URL/selection was accepted');
}
echo "MAD4B_PREVIEW_CONFIRM_PURE_TEST_PASS allowed=1 rejected=".
 count($bad)." fingerprint_rejected=".count($tests)."\n";
