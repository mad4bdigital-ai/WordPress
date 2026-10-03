<?php
define('ABSPATH',__DIR__);
define('MAD4B_SCP_TEST_RUNTIME',true);
define('MAD4B_SCP_DIR',dirname(__DIR__).'/');
class WP_Error{private $c,$m,$d;function __construct($c='',$m='',$d=null){$this->c=(string)$c;$this->m=(string)$m;$this->d=$d;}function get_error_code(){return$this->c;}function get_error_message(){return$this->m;}function get_error_data(){return$this->d;}}
function is_wp_error($v){return$v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
require dirname(__DIR__).'/includes/class-mad4b-scp-entropy.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-time-policy.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL deterministic-test-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};

$seed=(string)getenv('MAD4B_TEST_SEED');
$wall=(int)getenv('MAD4B_TEST_WALL_EPOCH');
$mono=(int)getenv('MAD4B_TEST_MONOTONIC_MS');
$check(''!==$seed&&$wall>0&&$mono>=0,'deterministic fixture environment missing');

$seeded=MAD4B_SCP_Entropy::set_test_seed($seed);
$clock=MAD4B_SCP_Time_Policy::set_test_clock($wall,$mono);
$check(is_array($seeded)&&is_array($clock),'deterministic test hooks unavailable',array($seeded,$clock));

$a1=MAD4B_SCP_Entropy::hex('preparation_receipt_nonce',16);
$a2=MAD4B_SCP_Entropy::hex('preparation_receipt_nonce',16);
$b1=MAD4B_SCP_Entropy::hex('provider_breaker_probe',16);
$check(is_string($a1)&&is_string($a2)&&is_string($b1)&&$a1!==$a2&&$a1!==$b1,'bounded purpose/counter entropy separation failed',array($a1,$a2,$b1));
$check('mad4b_entropy_request_invalid'===(MAD4B_SCP_Entropy::bytes('oversized',65))->get_error_code(),'entropy length bound failed');

$material=array(
 'contract'=>'mad4b.hermetic-critical-fixture.v1',
 'wall_epoch'=>MAD4B_SCP_Time_Policy::now_epoch(),
 'monotonic_ms'=>MAD4B_SCP_Time_Policy::monotonic_ms(),
 'preparation_nonce_1'=>$a1,
 'preparation_nonce_2'=>$a2,
 'breaker_probe_entropy'=>$b1,
 'time_policy_sha256'=>MAD4B_SCP_Time_Policy::policy_sha256()
);
$fingerprint=hash('sha256',json_encode($material,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$check(1===preg_match('/^[a-f0-9]{64}$/D',$fingerprint),'fixture fingerprint invalid',$material);
echo json_encode(array('contract'=>'mad4b.hermetic-critical-ci.v1','fixture_sha256'=>$fingerprint,'material'=>$material),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
