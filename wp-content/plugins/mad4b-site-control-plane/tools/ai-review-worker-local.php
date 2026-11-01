<?php
/** Local-only cron entrypoint; direct HTTP invocation is always denied. */
if(PHP_SAPI!=='cli' || getenv('MAD4B_AI_HOST_RUNNER')!=='1')exit(7);
$boot=dirname(__DIR__,4).'/wp-load.php';
if(!is_file($boot))exit(8);
$_SERVER['HTTP_HOST']='localhost:8091';
require $boot;
if(!function_exists('wp_get_environment_type') || wp_get_environment_type()!=='local'
   || !class_exists('MAD4B_SCP_AI_Review_Worker'))exit(9);
$result=MAD4B_SCP_AI_Review_Worker::run_once();
if(is_wp_error($result)) {
 echo wp_json_encode(array('status'=>'error','error_code'=>$result->get_error_code()))."\n";
 exit(10);
}
echo wp_json_encode(array('status'=>$result['status']??'unknown',
 'processed'=>(int)($result['processed']??0),
 'errors'=>(array)($result['errors']??$result['blockers']??array()),
 'environment'=>'local'))."\n";
