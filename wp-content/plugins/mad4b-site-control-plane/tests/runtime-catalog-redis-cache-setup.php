<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$run_id = isset($args[0]) ? sanitize_key((string)$args[0]) : '';
if(''===$run_id){fwrite(STDERR,"FAIL runtime-catalog-redis-cache-setup: missing run id\n");exit(2);}
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL runtime-catalog-redis-cache-setup: '.$m.(null===$v?'':' '.wp_json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$check(wp_using_ext_object_cache() && defined('MAD4B_CI_REDIS_OBJECT_CACHE'),'Redis object-cache drop-in is not active.');
$scope=MAD4B_SCP_Catalog_Backend_Controller::storage_scope();
$status=MAD4B_SCP_Catalog_Backend_Controller::status($scope);
$check('options'===$status['authority_backend'],'Redis fixture must begin with options authority.',$status);
$key='ci.catalog.redis.'.$run_id;
$value=array('contract'=>'ci.catalog.redis-persistent.v1','run_id'=>$run_id,'sha256'=>hash('sha256',$run_id));
$store=new MAD4B_SCP_Catalog_Object_Store();
$store->put($key,$value,1200); $store->flush();
$status=MAD4B_SCP_Catalog_Backend_Controller::status($scope);
$shadow=$status['shadow'];
$check(!empty($shadow['parity']),'Redis setup shadow parity failed.',$status);
$cutover=MAD4B_SCP_Catalog_Backend_Controller::cutover($scope,$shadow['options_logical_sha256'],$shadow['table_directory_sha256'],(int)$shadow['table_fencing_token']);
$check(!is_wp_error($cutover)&&'table'===$cutover['authority_backend'],'Redis setup cutover failed.',$cutover);
$stale=array('contract'=>MAD4B_SCP_Catalog_Backend_Controller::CONTRACT,'authority_backend'=>'options','storage_scope_sha256'=>$scope,'shadow'=>array(),'cutover'=>array(),'rollback'=>array(),'retirement'=>array());
wp_cache_set(MAD4B_SCP_Catalog_Backend_Controller::STATE_OPTION,$stale,'options',600);
wp_cache_set(MAD4B_SCP_Catalog_Object_Store::DIRECTORY,array('poisoned'=>array('option'=>'missing','expires'=>time()+9999,'bytes'=>1)),'options',600);
$found=false; $cached=wp_cache_get(MAD4B_SCP_Catalog_Backend_Controller::STATE_OPTION,'options',false,$found);
$check($found&&is_array($cached)&&'options'===$cached['authority_backend'],'Failed to persist stale Redis authority fixture.',$cached);
echo wp_json_encode(array('contract'=>'mad4b.catalog-redis-setup.v1','run_id'=>$run_id,'key'=>$key,'scope'=>$scope,'table_generation_id'=>$cutover['cutover']['table_generation_id'],'table_fencing_token'=>$cutover['cutover']['table_fencing_token'])).PHP_EOL;
