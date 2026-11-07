<?php
/** Extract live source function under test; fake WPML filter avoids any WordPress DB. */
$root=dirname(__DIR__);
$src=file_get_contents($root.'/wp-content/plugins/meta-catalog-feed-mapper-pro/meta-catalog-feed-mapper-pro.php');
$needle='function mcfm_default_wpml_term_id( $term ) {';
$start=strpos($src,$needle);
if($start===false)throw new RuntimeException('WPML_MAPPER_FUNCTION_MISSING');
$open=strpos($src,'{',$start);$depth=0;$end=null;
for($i=$open;$i<strlen($src);$i++){
 if($src[$i]==='{')$depth++;elseif($src[$i]==='}'&&--$depth===0){$end=$i;break;}
}
if($end===null)throw new RuntimeException('UNBALANCED_FUNCTION');
$GLOBALS['wpml_calls']=0;
function mcfm_is_wpml_active(){return true;}
function mcfm_get_default_language(){return 'en';}
function apply_filters($name,$value,...$args){
 if($name==='wpml_object_id'){$GLOBALS['wpml_calls']++;return 777;}
 return $value;
}
eval(substr($src,$start,$end-$start+1));
$term=(object)['term_id'=>51,'taxonomy'=>'destinations'];$checks=0;
function ok($yes,$label){global $checks;$checks++;if(!$yes)throw new RuntimeException('FAILED_'.$label);}
ok(mcfm_default_wpml_term_id($term)===777,'FIRST_LANGUAGE_MAPPING');
for($i=0;$i<30;$i++)ok(mcfm_default_wpml_term_id($term)===777,'CACHED_MAPPING');
ok($GLOBALS['wpml_calls']===1,'ONE_WPML_CALL_PER_TERM');
$other=(object)['term_id'=>52,'taxonomy'=>'destinations'];
ok(mcfm_default_wpml_term_id($other)===777,'SEPARATE_TERM');
ok($GLOBALS['wpml_calls']===2,'SECOND_TERM_GETS_SEPARATE_LOOKUP');
$tax=(object)['term_id'=>51,'taxonomy'=>'other'];
ok(mcfm_default_wpml_term_id($tax)===777,'TAXONOMY_SCOPED');
ok($GLOBALS['wpml_calls']===3,'TAXONOMY_GETS_SEPARATE_LOOKUP');
echo json_encode(['suite'=>'mcfm.wpml-term-memo','assertions'=>$checks,'lookup_calls'=>$GLOBALS['wpml_calls'],
 'reads_only'=>true,'persistent_cache'=>false]).PHP_EOL;
