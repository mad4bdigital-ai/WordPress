<?php
/** Standalone activation matrix for the two production-proven rate adapters. */
error_reporting(E_ALL);
$base=__DIR__.'/../mu-plugins/shared-product-core/adapters/';
$cases=[
 'no-constants'=>[null,null,false,false],
 'both-off'=>[false,false,false,false],
 'commerce-only'=>[true,null,false,true],
 'commerce-and-presentation-off'=>[true,false,false,true],
 'presentation-only'=>[false,true,false,true],
 'presentation-when-commerce-undefined'=>[null,true,false,true],
 'both-on'=>[true,true,false,true],
 'function-previously-declared'=>[false,true,true,true],
];
if(($argv[1]??'')==='--child'){
 $name=$argv[2]??'';
 if(!isset($cases[$name]))exit(2);
 [$commerce,$presentation,$predefined]=$cases[$name];
 define('ABSPATH','/offline-not-wordpress/');
 if($commerce!==null)define('MAD4B_COMMERCE_RUNTIME_ENABLED',$commerce);
 if($presentation!==null)define('MAD4B_PRESENTATION_RUNTIME_ENABLED',$presentation);
 $GLOBALS['hooks']=[];
 function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][]=$name;return true;}
 if($predefined)eval("function shared_product_program_mode_supported(\$mode) {return \$mode==='cruise_tour';}");
 require $base.'tour-rate-eligible-query.php';
 require $base.'tour-rate-facets-from-query.php';
 echo json_encode([
  'hook_count'=>count($GLOBALS['hooks']),
  'eligible_query_hook'=>in_array('jet-engine/query-builder/types/posts-query/args',$GLOBALS['hooks'],true),
  'facets_hook'=>in_array('jet-smart-filters/filters/filter-options',$GLOBALS['hooks'],true),
  'cruise'=>function_exists('shared_product_program_mode_supported')
     ? shared_product_program_mode_supported('cruise_tour') : null,
  'other'=>function_exists('shared_product_program_mode_supported')
     ? shared_product_program_mode_supported('tour') : null,
 ],JSON_THROW_ON_ERROR);
 exit;
}
$passed=0;
foreach($cases as $name=>$definition){
 $enabled=$definition[3];
 $pipes=[];
 $command=[PHP_BINARY,__FILE__,'--child',$name];
 $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
 if(!is_resource($process))throw new RuntimeException("CASE_CANNOT_START ".$name);
 fclose($pipes[0]);
 $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
 if(proc_close($process)!==0)throw new RuntimeException("PHP_FATAL ".$name.": ".$err);
 $result=json_decode($out,true,512,JSON_THROW_ON_ERROR);
 // PHP can compile function names before a file's early return. Hooks are the actual runtime activation.
 if(($result['hook_count']===5)!==$enabled)throw new RuntimeException("ACTIVATION_MISMATCH ".$name." ".$out);
 if($enabled && (!$result['eligible_query_hook'] || !$result['facets_hook']
      || !$result['cruise'] || $result['other']))
  throw new RuntimeException("FUNCTIONAL_MISMATCH ".$name." ".$out);
 $passed++;
}
echo "SHARED_PRODUCT_CORE_RUNTIME_MATRIX_PASS ".$passed."/".count($cases)." php=".PHP_VERSION."\n";
