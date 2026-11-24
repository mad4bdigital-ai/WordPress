<?php
require $argv[1];
$root=$argv[2];$bundle=$argv[3];$runtime=$argv[4];
$body=file_get_contents($bundle);
preg_match_all('/\n\/\* (elementor-post-([0-9]+)) \*\/\n/',$body,$m,PREG_SET_ORDER);
$rows=[];
foreach($m as $x){$rows[]=['handle'=>$x[1],'body'=>file_get_contents($root.'/post-'.$x[2].'.css'),'media'=>'all','native_eligible'=>true];}
$c='MAD4B_Navigation_CSS_Dependency_V1';$id=$c::identity($rows,hash_file('sha256',$runtime));
if(!$id||!$c::segment_matches($id,$body))exit(1);
echo json_encode(['contract'=>'mad4b.navigation-css-readback.v1','source_identity'=>$id,'source_identity_sha256'=>$c::digest($id),'bundle_sha256'=>hash('sha256',$body),'exact_native_segment'=>true,'native_eligible_from_observed_bundle'=>true,'live_replay_verified'=>false,'authority_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
