<?php
$root=sys_get_temp_dir().'/mad4b-js-retention-test-'.getmypid();
mkdir($root.'/litespeed/js',0700,true);
define('ABSPATH','/fake/');define('WP_CONTENT_DIR',$root);
$GLOBALS['run_clock']=time();$GLOBALS['jobs']=[];
function wp_get_environment_type(){return 'staging';}
function home_url($x='/'){return 'https://staging.egypttourgates.com'.$x;}
function wp_parse_url($u,$type){return parse_url($u,$type);}
function add_action($hook,$fn,$prio=10){$GLOBALS['actions'][$hook]=$fn;}
function wp_next_scheduled($hook){return $GLOBALS['jobs'][$hook]??false;}
function wp_schedule_event($when,$how,$hook){$GLOBALS['jobs'][$hook]=$when;return true;}
function update_option($key,$value,$autoload=false){$GLOBALS['record'][$key]=$value;return true;}
require $argv[1];
$class='MAD4B_Site_Local_Litespeed_JS_Retention_V1';$class::register();
assert(isset($GLOBALS['jobs'][$class::HOOK]));
$dir=$root.'/litespeed/js';
$old=$dir.'/'.str_repeat('a',32).'.js';$fresh=$dir.'/'.str_repeat('b',32).'.js';
$unsafe=$dir.'/'.str_repeat('c',32).'.js';$other=$dir.'/not-a-hashed-file.js';
file_put_contents($old,'generated');touch($old,time()-9*86400);
file_put_contents($fresh,'recent');touch($fresh,time()-2*86400);
file_put_contents($other,'old but not hashed');touch($other,time()-9*86400);
if(function_exists('symlink'))symlink($old,$unsafe);
$r=$class::prune();
if($r['state']!=='COMPLETE'||$r['deleted']!==1||$r['bytes_freed']!==9)throw new RuntimeException('AGED_CACHE_RETENTION_WRONG');
if(file_exists($old)||!file_exists($fresh)||(function_exists('symlink')&&!is_link($unsafe))||!file_exists($other))throw new RuntimeException('FILE_BOUNDARY_VIOLATION');
if($class::prune()['deleted']!==0)throw new RuntimeException('NOT_IDEMPOTENT');
if(count($GLOBALS['jobs'])!==1)throw new RuntimeException('DUPLICATE_SCHEDULE');
unlink($fresh);if(is_link($unsafe))unlink($unsafe);unlink($other);rmdir($dir);rmdir($root.'/litespeed');rmdir($root);
echo "PASS 9 site-local JS cache retention safety checks\n";
