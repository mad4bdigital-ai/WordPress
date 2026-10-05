<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$run_id = isset($args[0]) ? sanitize_key((string)$args[0]) : '';
if(''===$run_id){fwrite(STDERR,"FAIL runtime-catalog-redis-cache-verify: missing run id\n");exit(2);}
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL runtime-catalog-redis-cache-verify: '.$m.(null===$v?'':' '.wp_json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$check(wp_using_ext_object_cache()&&defined('MAD4B_CI_REDIS_OBJECT_CACHE'),'Fresh process did not load Redis object-cache drop-in.');
$found=false; $stale=wp_cache_get(MAD4B_SCP_Catalog_Backend_Controller::STATE_OPTION,'options',false,$found);
$check($found&&is_array($stale)&&'options'===$stale['authority_backend'],'Fresh process did not observe intentionally stale Redis state before guarded read.',$stale);
$directory_found=false; $poison=wp_cache_get(MAD4B_SCP_Catalog_Object_Store::DIRECTORY,'options',false,$directory_found);
$check($directory_found&&isset($poison['poisoned']),'Fresh process did not observe intentionally stale Redis catalog directory.',$poison);

$scope=MAD4B_SCP_Catalog_Backend_Controller::storage_scope();
$status=MAD4B_SCP_Catalog_Backend_Controller::status($scope);
$check('table'===$status['authority_backend']&&empty($status['fallback_on_table_failure']),'Stale Redis state resurrected options authority or enabled fallback.',$status);

$key='ci.catalog.redis.'.$run_id;
$expected=array('contract'=>'ci.catalog.redis-persistent.v1','run_id'=>$run_id,'sha256'=>hash('sha256',$run_id));
$store=new MAD4B_SCP_Catalog_Object_Store();
$readback=$store->get($key);
$check($expected===$readback,'Stale persistent options directory hid/replaced table-authoritative object.',$readback);

$rollback=MAD4B_SCP_Catalog_Backend_Controller::rollback($scope);
$check(!is_wp_error($rollback)&&'options'===$rollback['authority_backend'],'Redis fixture rollback failed after cache invalidation.',$rollback);
$cutover=$rollback['cutover'];
$retired=MAD4B_SCP_Catalog_Backend_Controller::retire_after_rollback($scope,$cutover['table_generation_id'],(int)$cutover['table_fencing_token'],true);
$check(!is_wp_error($retired)&&'retired'===$retired['retirement']['status'],'Redis fixture retirement failed.',$retired);
$check(0===(int)$retired['table']['orphan_bytes']&&0===(int)$retired['table']['generation_count'],'Redis fixture left table generations/orphan bytes after retirement.',$retired['table']);
echo "mad4b.catalog-redis-persistent.runtime.v1: PASS\n";
