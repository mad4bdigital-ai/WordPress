<?php
/** Bounded, in-memory queue tests. No WordPress runtime, network or media edits. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['mock_options']=[];$GLOBALS['updates']=0;$GLOBALS['simulate_storage_failure']=false;
function get_option($k,$d=null){return $GLOBALS['mock_options'][$k]??$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['updates']++;if(!empty($GLOBALS['simulate_storage_failure']))return false;$GLOBALS['mock_options'][$k]=$v;return true;}
function add_action(...$args){}
function add_filter(...$args){}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function check($v,$message){global $passes;if(!$v)throw new RuntimeException($message);$passes++;}
require __DIR__.'/../ops/site-company-overlays/wayfatours/media-runtime/wp-content/mu-plugins/mad4b-universal-media-readiness.php';
$cl=MAD4B_Universal_Media_Readiness_V1::class;$method=new ReflectionMethod($cl,'persist_deferred');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$passes=0;
$items=[['identity'=>'term_destinations_2406','kind'=>'term','type'=>'destinations','object_id'=>2406,'priority'=>'P2','reason'=>'noncommercial_media_intake','rights_verified'=>false],
 ['identity'=>'offer_destinations_2357','kind'=>'term','type'=>'destinations','object_id'=>2357,'priority'=>'P3','reason'=>'no_eligible_commercial_offer','rights_verified'=>false]];
$method->invoke(null,$items,true);
$q=$cl::deferred();
check($q['complete']===true,'complete');
check(count($q['items'])===2,'all jobs retained');
check(($q['counts']['noncommercial_media_intake']??0)===1&&($q['counts']['no_eligible_commercial_offer']??0)===1,'correct reasons');
check(($q['publisher_mutations']??null)===false&&($q['rights_or_supplier_grants_created']??null)===false,'no rights or publication grant');
check($GLOBALS['updates']===1,'persist once');
$method->invoke(null,$items,true);
check($GLOBALS['updates']===1,'stable data does not rewrite option');
$method->invoke(null,[],false);
check($GLOBALS['updates']===1&&$cl::deferred()['complete']===true,'incomplete scan preserves last proven queue');
check(!empty($GLOBALS['mad4b_configuration_discovery_incomplete']),'global incomplete prevents fake resolution');
$items[1]['name']='Updated city name';
$method->invoke(null,$items,true);
check($GLOBALS['updates']===2,'input change reflected');
$GLOBALS['simulate_storage_failure']=true;$GLOBALS['mad4b_configuration_discovery_incomplete']=false;
$items[0]['name']='new';$method->invoke(null,$items,true);
check(!empty($GLOBALS['mad4b_configuration_discovery_incomplete']),'queue storage failure leaves incident reconciliation incomplete');
check(count($cl::deferred()['items'])===2,'failed queue update cannot discard known backlog');
echo json_encode(['contract'=>'mad4b.universal-media-deferred-queue-regression.v2','passed'=>$passes,'tests'=>11])."\n";
