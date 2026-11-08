<?php
/**
 * MAD4B Redis FLUSH provenance v2.
 *
 * Callback invoked only after wp_cache_flush() returns. No cache writes,
 * source arguments, cookies, customer details, SQL or WordPress state changes.
 */
defined('ABSPATH') || exit;
if (!defined('MAD4B_REDIS_FLUSH_SITE')) define('MAD4B_REDIS_FLUSH_SITE','wayfatours');
if (!function_exists('mad4b_redis_flush_origin_record_v2')) {
 function mad4b_redis_flush_origin_record_v2($result): void {
  try {
   $site = defined('MAD4B_REDIS_FLUSH_SITE') ? (string)MAD4B_REDIS_FLUSH_SITE : 'unenrolled';
   if (!in_array($site, ['tours-dev','wayfatours'], true)) return;
   $base = WP_CONTENT_DIR.'/mu-plugins/.mad4b-host-telemetry/redis-flush-events-v2.jsonl';
   if (is_link($base) || !is_file($base)) return;
   $execution = defined('WP_CLI') && WP_CLI ? 'wpcli'
      : ((defined('DOING_CRON') && DOING_CRON) ? 'cron' : 'web');
   $command='none';
   $script='unknown';
   if ($execution==='wpcli') {
    $args=$_SERVER['argv'] ?? [];
    if (is_array($args)) {
     foreach ($args as $i=>$a) {
      if (!is_string($a)) continue;
      if (in_array($a,['eval-file','eval','cache','cli'],true)) $command=$a;
      if ($a === 'eval-file' && isset($args[$i+1]) && is_string($args[$i+1])) {
       $arg=$args[$i+1];
       $basename=basename($arg);
       $script=$arg==='-' ? 'stdin' :
        (preg_match('/^[a-zA-Z0-9._-]{1,100}[.]php$/D',$basename) ? $basename : 'unrecognized');
      }
     }
    }
   }
   $frames=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,9);
   $trace=[];
   foreach($frames as $frame){
    $file=basename((string)($frame['file']??'unknown'));
    $file=preg_replace('/[^A-Za-z0-9_.-]/','_',$file);
    $func=preg_replace('/[^A-Za-z0-9_:.-]/','_', (string)($frame['function']??'unknown'));
    $trace[]=substr($file.':'.$func,0,105);
   }
   // The web and cron paths have a concrete PHP caller even without argv.
   $caller='unknown';
   foreach ($trace as $item) {
    $basename=explode(':',$item,2)[0];
    if ($basename!=='object.lib.php' &&
        $basename!=='000-mad4b-redis-flush-provenance.php' &&
        $basename!=='unknown' &&
        preg_match('/^[A-Za-z0-9._-]{1,100}[.]php$/D',$basename)) {
     $caller=$basename;
     break;
    }
   }
   $workflow=(string)(getenv('MAD4B_WORKFLOW_NAME')?:'');
   if (!preg_match('/^[A-Za-z0-9_.-]{1,70}$/D',$workflow)) $workflow='unassigned';
   $record=[
    'contract'=>'mad4b.redis-flush-provenance.v2',
    'event_id'=>bin2hex(random_bytes(12)),
    'timestamp_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
    'site'=>$site,
    'execution'=>$execution,
    'command'=>$command,
    'script_basename'=>$script,
    'caller_basename'=>$caller,
    'workflow'=>$workflow,
    'successful'=>($result===true),
    'frames'=>$trace
   ];
   $line=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
   if (!is_string($line) || strlen($line)>2000) return;
   $stream=@fopen($base,'c+');
   if (!$stream) return;
   try {
    if (@flock($stream,LOCK_EX)) {
     $stat=fstat($stream);
     if (is_array($stat) && (int)($stat['size']??0)<2097152) {
      fseek($stream,0,SEEK_END);
      @fwrite($stream,$line."\n");
      @fflush($stream);
     }
     @flock($stream,LOCK_UN);
    }
   } finally { @fclose($stream); }
  } catch (Throwable $ignore) {
   // Telemetry is strictly non-authoritative for WordPress cache validity.
  }
 }
}


// Administrative readback only; no Redis connections or cache mutations.
if (!function_exists('mad4b_redis_flush_admin_status_v2')) {
 function mad4b_redis_flush_admin_status_v2(): array {
  $path=WP_CONTENT_DIR.'/mu-plugins/.mad4b-host-telemetry/redis-flush-status-v2.json';
  if (is_link($path) || !is_file($path) || filesize($path)>4096) return ['status'=>'NOT_YET_OBSERVED'];
  $data=json_decode((string)@file_get_contents($path),true);
  if (!is_array($data) || ($data['contract']??'')!=='mad4b.redis-flush-site-status.v2'
       || ($data['site']??'')!==MAD4B_REDIS_FLUSH_SITE)
    return ['status'=>'TELEMETRY_UNAVAILABLE'];
  return $data;
 }
}
if (function_exists('add_action')) {
 add_action('admin_menu', static function(): void {
  add_management_page('MAD4B Redis Flush Provenance','Redis Flush Provenance',
    'manage_options','mad4b-redis-flush-provenance-v2', static function(): void {
     if (!current_user_can('manage_options')) return;
     $r=mad4b_redis_flush_admin_status_v2();
     echo '<div class="wrap"><h1>MAD4B Redis Flush Provenance</h1>';
     echo '<p>Read-only verification of Redis full flushes and source attribution.</p>';
     echo '<table class="widefat striped"><tbody>';
     foreach (['site'=>'Company','status'=>'Status','observed_utc'=>'Last check',
       'flushdb_calls'=>'Redis FLUSHDB total','flushall_calls'=>'Redis FLUSHALL total',
       'count_delta'=>'New full flushes','last_caller'=>'Last identified caller'] as $key=>$label) {
       $value=$r[$key]??'Not yet recorded';
       if (!is_scalar($value))$value='unavailable';
       echo '<tr><th scope="row">'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>';
     }
     echo '</tbody></table>';
     echo '<p>Unknown flushes are alerted after one correlation window. '
      .'CLI stdin operations require a registered MAD4B_WORKFLOW_NAME to name their owner.</p></div>';
    });
 });
 add_action('admin_notices',static function():void {
  if (!current_user_can('manage_options'))return;
  $r=mad4b_redis_flush_admin_status_v2();
  if (($r['status']??'')!=='ALERT')return;
  echo '<div class="notice notice-error"><p><strong>MAD4B Redis Flush Alert:</strong> '
    .'A full Redis cache flush needs operator review. '
    .'<a href="'.esc_url(admin_url('tools.php?page=mad4b-redis-flush-provenance-v2')).'">'
    .'Open Redis Flush Provenance</a></p></div>';
 });
}
