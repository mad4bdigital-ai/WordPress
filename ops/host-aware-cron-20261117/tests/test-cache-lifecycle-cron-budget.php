<?php
define('ABSPATH','/var/www/html/');
$source=$argv[1] ?? '';
if (!is_file($source)) throw new RuntimeException('SOURCE_FILE_MISSING');
$text=file_get_contents($source);
foreach(['$max_routes=3;','$per_request_timeout_s=3;','$max_wall_s=12;'] as $needle) {
 if(!str_contains($text,$needle))throw new RuntimeException('CRON_BUDGET_MISSING');
}
$start=strpos($text,' public static function drain()');
$end=strpos($text,' public static function menu()',$start);
$drain=substr($text,$start,$end-$start);
foreach(["do_action('litespeed_purge_url'",'_mad4b_cache_delivery','kick_purge_queue'] as $bad){
 if(str_contains($drain,$bad)) throw new RuntimeException('REPEAT_PURGE_IN_READ_ONLY_CRON');
}
require_once $source;
$class='MAD4B_Cache_Lifecycle_Control_V1';
$rows=['z'=>['state'=>'VERIFY_FAILED','retry_after'=>1,'checked'=>0,'updated'=>2],
 'a'=>['state'=>'PENDING_INVALIDATION','checked'=>0,'updated'=>1],
 'c'=>['state'=>'PENDING_INVALIDATION','checked'=>1,'updated'=>2],
 'f'=>['state'=>'VERIFY_FAILED','retry_after'=>2000000000,'checked'=>0,'updated'=>0],
 'v'=>['state'=>'VERIFIED','checked'=>0,'updated'=>0],
 'd'=>['state'=>'PENDING_INVALIDATION','checked'=>2,'updated'=>2]];
$out=$class::select_due($rows,1000,3);
if(array_keys($out)!==['a','z','c'])throw new RuntimeException('FAIR_DUE_SELECTION_OR_BACKOFF_FAILED');
if($class::classify_http(200,'abc','abc','public')!=='VERIFIED')throw new RuntimeException('VERIFY_PATH_BROKEN');
if($class::classify_http(0,'abc','','')!=='VERIFY_FAILED')throw new RuntimeException('TIMEOUT_WAS_CERTIFIED');
if($class::classify_http(200,'abc','','private, no-store')!=='OBSERVED_NOT_CACHEABLE')throw new RuntimeException('PRIVATE_CONTEXT_BROKEN');
if($class::classify_http(404,'abc','','',true)!=='VERIFIED')throw new RuntimeException('ABSENCE_VERIFICATION_BROKEN');
echo "PASS 11 bounded Cron/cache source invariants\n";
