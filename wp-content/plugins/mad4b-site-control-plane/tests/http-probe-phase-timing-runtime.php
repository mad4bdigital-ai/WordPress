<?php
/** Passive WordPress hook lifecycle, numeric-only projection and cleanup. */
define('ABSPATH',__DIR__);
$mock_curl=!function_exists('curl_getinfo');
if($mock_curl){
 foreach(array('CURLINFO_NAMELOOKUP_TIME','CURLINFO_CONNECT_TIME','CURLINFO_APPCONNECT_TIME','CURLINFO_STARTTRANSFER_TIME','CURLINFO_TOTAL_TIME') as $i=>$name)define($name,$i+1);
 function curl_getinfo($handle,$key){if(!empty($GLOBALS['timing_fault']))throw new RuntimeException('PRIVATE');return $handle->values[$key]??0;}
}
$GLOBALS['actions']=array();
function add_action($name,$fn,$priority=10,$argc=1){$GLOBALS['actions'][$name][]=$fn;}
function remove_action($name,$fn,$priority=10){$GLOBALS['actions'][$name]=array_values(array_filter($GLOBALS['actions'][$name]??array(),static function($other)use($fn){return $other!==$fn;}));}
function action($name,...$args){foreach($GLOBALS['actions'][$name]??array() as $fn)$fn(...$args);}
function wp_safe_remote_get($url,$args){
 $GLOBALS['observed_args']=$args;
 $handle=(object)array('values'=>array(1=>.01,2=>.03,3=>.05,4=>0,5=>6));
 action('http_api_curl',$handle,$args,$url);
 // Nested request finishes first; its unrelated timings must not leak.
 action('http_api_curl',(object)array('values'=>array(4=>99)),array(),'https://foreign.test/PRIVATE');
 action('requests-curl.after_send');
 if(!empty($GLOBALS['request_fault']))throw new RuntimeException('PRIVATE request');
 action('requests-curl.after_send');
 return $GLOBALS['fixture_response'];
}
require __DIR__.'/../includes/class-mad4b-scp-http-probe-timing.php';
function phase_check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$c='MAD4B_SCP_HTTP_Probe_Timing';$args=array('timeout'=>6,'sslverify'=>true,'redirection'=>0,'cookies'=>array());
$GLOBALS['fixture_response']=new stdClass();
$r=$c::request('https://staging.test/',$args);
phase_check($r['response']===$GLOBALS['fixture_response']&&$GLOBALS['observed_args']===$args,'Response or HTTP security options changed');
if($mock_curl)phase_check($r['network_timing']['dns_complete_ms']===10.0&&$r['network_timing']['tls_complete_ms']===50.0&&$r['network_timing']['first_byte_ms']===null&&$r['network_timing']['total_ms']===6000.0,'Timeout phase evidence lost or nested request leaked');
phase_check(!array_filter($GLOBALS['actions']),'Scoped hooks leaked');
$GLOBALS['timing_fault']=true;
$r=$c::request('https://staging.test/',$args);
phase_check(!$r['network_timing']['available']&&$r['response']===$GLOBALS['fixture_response'],'Optional measurement failure changed response');
$GLOBALS['request_fault']=true;
try{$c::request('https://staging.test/',$args);throw new LogicException('Expected request exception');}
catch(RuntimeException $e){phase_check($e->getMessage()==='PRIVATE request','Original request failure replaced');}
phase_check(!array_filter($GLOBALS['actions']),'Exceptional request leaked hooks');
$clean=$c::project(array('dns_complete_ms'=>INF,'tcp_complete_ms'=>-1,'tls_complete_ms'=>'PRIVATE','first_byte_ms'=>0,'total_ms'=>60001,'url'=>'PRIVATE'));
phase_check(!$clean['available']&&strpos(json_encode($clean),'PRIVATE')===false,'Invalid/secret timing escaped');
phase_check($clean===$c::project($clean),'Projection not idempotent');
echo "HTTP_PROBE_PHASE_TIMING_PASS\n";
