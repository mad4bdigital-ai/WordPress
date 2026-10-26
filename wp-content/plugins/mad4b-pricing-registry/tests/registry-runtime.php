<?php
/* Run: php tests/registry-runtime.php in a bootstrapped WordPress test installation. */
if (!defined('ABSPATH')) {
 $candidate=dirname(__DIR__,4).'/wp-load.php';
 if (!is_file($candidate)) { fwrite(STDERR,"WordPress bootstrap not found\n"); exit(2); }
 require $candidate;
}
$pass=[];
$base=MAD4B_Pricing_Source_Registry::snapshot();
$pass['native_sources']=$base['source_count']===6;
$pass['read_only']=$base['mutation_performed']===false && $base['quote_authorized']===false;
add_filter('mad4b/pricing_registry/definitions',static function($definitions){
 $definitions['qa_external']=['domain'=>'tour','source_type'=>'external','capabilities'=>['dated'],'dependency'=>'qa_provider_that_does_not_exist'];
 $definitions['invalid/key']=['domain'=>'tour','source_type'=>'external','capabilities'=>[],'dependency'=>'qa_missing'];
 return $definitions;
});
$all=MAD4B_Pricing_Source_Registry::snapshot();
$tour=MAD4B_Pricing_Source_Registry::snapshot('tour');
$pass['dynamic_provider']=isset($all['sources']['qa_external']);
$pass['unavailable_provider']=isset($all['sources']['qa_external']) && $all['sources']['qa_external']['available']===false;
$pass['invalid_key_rejected']=!isset($all['sources']['invalid/key']);
$pass['domain_isolated']=isset($tour['sources']['tour_rate']) && !isset($tour['sources']['accommodation_rate']);
foreach($pass as $name=>$ok) echo $name.'='.($ok?'PASS':'FAIL')."\n";
echo 'TOTAL='.count(array_filter($pass)).'/'.count($pass)."\n";
exit(in_array(false,$pass,true)?1:0);
