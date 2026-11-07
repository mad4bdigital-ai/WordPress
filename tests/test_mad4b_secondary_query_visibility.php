<?php
define('ABSPATH','/');
$GLOBALS['documents']=[
 1001=>[ ['id'=>'parent','settings'=>[], 'elements'=>[
  ['id'=>'visible','settings'=>['jedv_enabled'=>'yes','jedv_conditions'=>[
   ['jedv_condition'=>'query-has-items','query_id'=>409]
  ]],'elements'=>[]]
 ]]],
 1002=>[['id'=>'plain','settings'=>['jedv_enabled'=>'no'],'elements'=>[]]]
];
$GLOBALS['meta_reads']=0;
function absint($x){return abs((int)$x);}
function add_filter($hook,$cb,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook][]=$cb;}
function get_post_meta($id,$key,$single){$GLOBALS['meta_reads']++;return $GLOBALS['documents'][$id]??false;}
function ok($name,$yes){if(!$yes)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$src=getenv('MAD4B_SECONDARY_LAZY_SOURCE');
if(!is_file($src))throw new RuntimeException('missing source');
require $src;
ok('nested Query Has Items dependency detected',mad4b_secondary_grid_query_visibility_dependency(409,1001)===true);
$reads=$GLOBALS['meta_reads'];
ok('dependency result memoized',mad4b_secondary_grid_query_visibility_dependency(409,1001)===true && $reads===$GLOBALS['meta_reads']);
ok('unrelated query not blocked',mad4b_secondary_grid_query_visibility_dependency(410,1001)===false);
ok('ordinary document can be deferred',mad4b_secondary_grid_query_visibility_dependency(409,1002)===false);
ok('missing document fails open for rendering',mad4b_secondary_grid_query_visibility_dependency(409,9000)===true);
ok('no source document fails open for rendering',mad4b_secondary_grid_query_visibility_dependency(409,0)===true);
ok('unresolved query ID safe',mad4b_secondary_grid_query_visibility_dependency(0,1001)===true);
echo "PASS_SEMANTIC_QUERY_VISIBILITY_LAZY_CONTRACT\n";
