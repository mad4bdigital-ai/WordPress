<?php
error_reporting(E_ALL);
define('ABSPATH','/');
$GLOBALS['mock_options']=[];
$GLOBALS['socket_batches']=[];
$GLOBALS['mad4b_actions']=[];
function add_action(...$args){return true;}
function wp_parse_url($url){return parse_url($url);}
function get_option($name,$fallback=null){return $GLOBALS['mock_options'][$name]??$fallback;}
function update_option($name,$value,$autoload=null){$GLOBALS['mock_options'][$name]=$value;return true;}
function do_action($name,...$args){$GLOBALS['mad4b_actions'][]=$name;}
class MAD4B_Cache_Control_V1 {
    public static function is_safe_public_path($p): bool {
        return is_string($p) && strlen($p)>0 && strlen($p)<501 &&
            str_starts_with($p,'/') && !str_contains($p,'//') &&
            !str_contains($p,'..') && !preg_match('~/(?:checkout|cart|wp-admin)(?:/|$)~',$p);
    }
    public static function path_from_url($url): ?string {
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['host']??'')!=='site.test' ||
           isset($parts['query'])||isset($parts['fragment']) ||
           ($parts['scheme']??'')!=='https')return null;
        $p=(string)($parts['path']??'/');
        return self::is_safe_public_path($p)?$p:null;
    }
    public static function preview_target($selection): array {
        if ($selection==='post:123')return ['paths'=>['/tours/one/','/es/tours/one/']];
        return ['error'=>'not_published_or_private'];
    }
    public static function socket_purge($paths): array {
        $GLOBALS['socket_batches'][]=$paths;
        if (($GLOBALS['mock_fail_second']??false) && count($GLOBALS['socket_batches'])===2)
            return ['status'=>'socket_unavailable','versioned'=>0];
        return ['status'=>'ok','versioned'=>count($paths)];
    }
}
require $argv[1];
$b='MAD4B_LiteSpeed_FastCGI_Coherence_V1';
assert($b::url_path('https://site.test/tours/one/')==='/tours/one/');
assert($b::url_path('/es/tours/one/')==='/es/tours/one/');
foreach(['https://evil.test/','https://site.test/checkout/','/cart/','/wp-admin/',
         'https://site.test/tours/?x=1','/tour/?secret=1','//evil.test/', '/abc#fragment'] as $bad){
    if($b::url_path($bad)!==null)throw new RuntimeException('UNSAFE_URL_ACCEPTED_'.$bad);
}
if(($argv[2]??'')==='batch'){
 for($i=0;$i<62;$i++)$b::on_url('/published-route-'.$i.'/');
 $p=$b::pending();
 if($p['overflow']||count($p['paths'])!==62)throw new RuntimeException('BOUNDED_BATCH_QUEUE_MISSING');
 $b::flush();
 if(count($GLOBALS['socket_batches'])!==2 ||
 count($GLOBALS['socket_batches'][0])!==60 ||
 count($GLOBALS['socket_batches'][1])!==2)throw new RuntimeException('BATCH_SPLITTING_FAILED');
 $receipts=get_option($b::RECEIPTS,[]);
 if($receipts[0]['result']['status']!=='ok' ||
 $receipts[0]['result']['versioned']!==62 ||
 !empty($receipts[0]['invalidation_gap']))throw new RuntimeException('BATCH_RECEIPT_INVALID');
 echo "PURE_BOUND_BATCH_PASS paths=62 batches=2 no_global_purge\n";
 exit;
}
if(($argv[2]??'')==='partial'){
 $GLOBALS['mock_fail_second']=true;
 for($i=0;$i<62;$i++)$b::on_url('/published-route-'.$i.'/');
 $b::flush();
 $receipt=get_option($b::RECEIPTS,[])[0];
 $gap=get_option($b::GAPS,[]);
 if($receipt['result']['status']!=='partial_batch_failure' ||
 $receipt['result']['versioned']!==60 ||
 $receipt['invalidation_gap']!==true ||
 empty($gap['manual_review_required']) ||
 $receipt['global_invalidation_performed']!==false)
  throw new RuntimeException('PARTIAL_FAILURE_NOT_ESCALATED');
 echo "PURE_PARTIAL_FAILURE_PASS scoped_gap_persisted_no_global_purge\n";
 exit;
}
if(($argv[2]??'')==='overflow'){
 for($i=0;$i<302;$i++)$b::on_url('/published-route-'.$i.'/');
 $p=$b::pending();
 if(!$p['overflow']||count($p['paths'])!==0)throw new RuntimeException('OVERFLOW_NOT_FAIL_CLOSED');
 $b::flush();
 if(count($GLOBALS['socket_batches'])!==0)throw new RuntimeException('OVERFLOW_REACHED_SOCKET');
 $receipts=get_option($b::RECEIPTS,[]);
 if(count($receipts)!==1 || $receipts[0]['result']['status']!=='batch_overflow_skipped' ||
 empty(get_option($b::GAPS,[])['manual_review_required']))
    throw new RuntimeException('OVERFLOW_NOT_RECORDED');
 echo "PURE_OVERFLOW_PASS socket_batches=0 receipt=overflow_skip\n";
 exit;
}
$b::on_url('https://site.test/tours/one/');
$b::on_url('/tours/one/');
$b::on_url('/checkout/');
$b::on_post(123);
$b::on_post(999);
$b::on_frontpage();
$queued=$b::pending();
if($queued['overflow']||$queued['paths']!==['/tours/one/','/es/tours/one/'])
 throw new RuntimeException('DEDUP_OR_POST_PATHS_FAILED '.json_encode($queued));
$b::flush();$b::flush();
if(count($GLOBALS['socket_batches'])!==1 ||
 count($GLOBALS['socket_batches'][0])!==2)throw new RuntimeException('MULTI_SOCKET_CALL');
$receipts=get_option($b::RECEIPTS,[]);
if(count($receipts)!==1 ||
 $receipts[0]['result']['versioned']!==2 ||
 $receipts[0]['redis_flushed']!==false)throw new RuntimeException('RECEIPT_INCOMPLETE');
echo "PURE_LITESPEED_FASTCGI_PASS paths=2 socket_batches=1 redis_flush=false\n";

