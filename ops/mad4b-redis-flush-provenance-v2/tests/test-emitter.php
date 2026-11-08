<?php
define('ABSPATH','/');
$root=sys_get_temp_dir().'/mad4b-flush-provenance-test-'.bin2hex(random_bytes(5));
mkdir($root.'/mu-plugins/.mad4b-host-telemetry',0700,true);
define('WP_CONTENT_DIR',$root);
define('WP_CLI',true);
$_SERVER['argv']=['wp','eval-file','/tmp/mad4b-property-type-fix.php','--token=SENSITIVE_DO_NOT_RECORD'];
require $argv[1];
$file=$root.'/mu-plugins/.mad4b-host-telemetry/redis-flush-events-v2.jsonl';
file_put_contents($file,'');
mad4b_redis_flush_origin_record_v2(true);
$rows=file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(count($rows)!==1)throw new RuntimeException('one_event_expected');
$r=json_decode($rows[0],true);
if(!is_array($r)||$r['successful']!==true||$r['script_basename']!=='mad4b-property-type-fix.php'||$r['command']!=='eval-file')
 throw new RuntimeException('SOURCE_ATTRIBUTION_NOT_CAPTURED');
if(str_contains($rows[0],'SENSITIVE_DO_NOT_RECORD')||str_contains($rows[0],'/tmp/mad4b-property-type-fix.php'))
 throw new RuntimeException('SENSITIVE_ARG_EXPOSED');
$_SERVER['argv']=['wp','eval-file','-'];
mad4b_redis_flush_origin_record_v2(false);
$rows=file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$r=json_decode($rows[1],true);
if(count($rows)!==2||$r['script_basename']!=='stdin'||$r['successful']!==false)
 throw new RuntimeException('STDIN_CALLER_NOT_HANDLED');
unlink($file);rmdir($root.'/mu-plugins/.mad4b-host-telemetry');rmdir($root.'/mu-plugins');rmdir($root);
echo "PROVENANCE_EMITTER_QA_PASS named_script=1 stdin=1 no_secrets=1\n";
