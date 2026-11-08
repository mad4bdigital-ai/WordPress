<?php
define('ABSPATH','/');
$root=sys_get_temp_dir().'/mad4b-intent-test-'.bin2hex(random_bytes(5));
mkdir($root.'/mu-plugins/.mad4b-host-telemetry',0700,true);
define('WP_CONTENT_DIR',$root);
define('WP_CLI',true);
$_SERVER['argv']=['wp','eval-file','-','--private-token=NEVER_RECORD_THIS'];
$registered=[];
function add_action($hook,$cb,$priority=10,$accepted=1){global $registered;$registered[$hook]=$cb;}
require $argv[1];
$file=$root.'/mu-plugins/.mad4b-host-telemetry/redis-flush-events-v2.jsonl';
file_put_contents($file,'');
if(!isset($registered['litespeed_purged_all_object']))throw new RuntimeException('PURGE_INTENT_HOOK_NOT_REGISTERED');
$registered['litespeed_purged_all_object']();
$rows=file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(count($rows)!==1)throw new RuntimeException('EXPECTED_ONE_INTENT');
$d=json_decode($rows[0],true);
if(($d['event_kind']??'')!=='purge_intent'||$d['successful']!==false)
 throw new RuntimeException('INTENT_CLAIMED_CACHE_FLUSH_SUCCESS');
if(str_contains($rows[0],'NEVER_RECORD_THIS'))throw new RuntimeException('SENSITIVE_CLI_ARG_LEAKED');
mad4b_redis_flush_origin_record_v2(true);
$r=file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$e=json_decode($r[1]??'null',true);
if(($e['event_kind']??'')!=='cache_flush_result'||($e['successful']??null)!==true)
 throw new RuntimeException('NORMAL_CACHE_FLUSH_SEMANTICS_CHANGED');
unlink($file);rmdir($root.'/mu-plugins/.mad4b-host-telemetry');rmdir($root.'/mu-plugins');rmdir($root);
echo "LS_PURGE_INTENT_NO_REDIS_WRITE_TEST_PASS\n";
