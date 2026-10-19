<?php
/** Source runtime test: never accept unauthenticated public AJAX with missing backend. */
function wp_using_ext_object_cache(){return !empty($GLOBALS['etg_rl_cache']);}
function wp_cache_add($key,$value,$group,$ttl){if(array_key_exists($key,$GLOBALS['etg_rl_buckets'])){return false;}$GLOBALS['etg_rl_buckets'][$key]=$value;return true;}
function wp_cache_incr($key,$offset,$group){if(!isset($GLOBALS['etg_rl_buckets'][$key])){return false;}if(!empty($GLOBALS['etg_rl_fail_counter'])){return false;}return $GLOBALS['etg_rl_buckets'][$key]+=$offset;}
function wp_salt($scheme){return 'ci-fixture-salt';}
function apply_filters($hook,$limit,$window){return $limit;}
$GLOBALS['etg_rl_cache']=false;$GLOBALS['etg_rl_buckets']=array();$GLOBALS['etg_rl_fail_counter']=false;
require_once dirname(__DIR__).'/includes/Presentation/AjaxPresentationEndpoint.php';
$reflection=new ReflectionClass('ETG\\DynamicFilterSEOBridge\\Presentation\\AjaxPresentationEndpoint');
$endpoint=$reflection->newInstanceWithoutConstructor();
$mode=$reflection->getMethod('rateLimitMode');$mode->setAccessible(true);
$consume=$reflection->getMethod('consumeRateLimit');$consume->setAccessible(true);
function assert_rl($ok,$label){if(!$ok){throw new RuntimeException($label);}}
assert_rl('rate_limit_backend_unavailable'===$mode->invoke($endpoint),'unattested edge must be blocked');
$result=$consume->invoke($endpoint);
assert_rl(false===$result['allowed']&&'rate_limit_backend_unavailable'===$result['source'],'no persistent backend fails closed');
$GLOBALS['etg_rl_cache']=true;
assert_rl('persistent_object_cache'===$mode->invoke($endpoint),'persistent cache selected');
unset($_SERVER['REMOTE_ADDR']);
assert_rl(false===$consume->invoke($endpoint)['allowed'],'missing REMOTE_ADDR fails closed');
$_SERVER['REMOTE_ADDR']='127.0.0.1';
for($i=0;$i<60;$i++){assert_rl(true===$consume->invoke($endpoint)['allowed'],'inside limit must pass');}
assert_rl(false===$consume->invoke($endpoint)['allowed'],'request 61 must be denied');
$GLOBALS['etg_rl_fail_counter']=true;
assert_rl(false===$consume->invoke($endpoint)['allowed'],'counter failure must deny');
echo "PASS AJAX persistent cache budget, missing backend, missing IP and counter failure\n";
