<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$mode=(string)getenv('MAD4B_CI_GENERATION_WORKER_MODE');
$ready=(string)getenv('MAD4B_CI_GENERATION_READY_FILE');
$release=(string)getenv('MAD4B_CI_GENERATION_RELEASE_FILE');
$fail=static function($m){fwrite(STDERR,"FAIL runtime-generation-rolling-worker: {$m}\n");exit(1);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};

if(!class_exists('MAD4B_SCP_Runtime_Generation_Fence'))$fail('runtime generation fence unavailable');

if('new'===$mode){
	$current=MAD4B_SCP_Runtime_Generation_Fence::assert_current();
	if(!is_array($current))$fail('new worker did not bind current on-disk generation: '.$code($current));
	echo "mad4b.runtime-generation.new-worker.v1: PASS\n";
	return;
}

if('old'!==$mode
	||0!==strpos($ready,'/tmp/mad4b-generation-roll-')
	||0!==strpos($release,'/tmp/mad4b-generation-roll-'))$fail('invalid rolling-worker sync contract');

$captured=MAD4B_SCP_Runtime_Generation_Fence::capture();
if(!is_array($captured))$fail('old worker baseline generation capture failed');
if(false===@file_put_contents($ready,(string)$captured['generation_sha256'],LOCK_EX))$fail('old worker could not publish ready marker');

$deadline=microtime(true)+45.0;
while(!is_file($release)){
	if(microtime(true)>=$deadline)$fail('old worker timed out waiting for generation replacement');
	usleep(10000);
}

$stale=MAD4B_SCP_Runtime_Generation_Fence::assert_current($captured);
if('mad4b_runtime_worker_recycle_required'!==$code($stale)
	&&'mad4b_runtime_generation_changed'!==$code($stale))$fail('old loaded worker was not fenced after on-disk generation replacement');
echo "mad4b.runtime-generation.old-worker-recycle.v1: PASS\n";
