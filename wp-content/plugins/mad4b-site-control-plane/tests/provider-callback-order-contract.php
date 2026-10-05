<?php
define('ABSPATH',__DIR__.'/');
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;public function __construct($c,$m='',$d=null){$this->c=$c;}public function get_error_code(){return $this->c;}}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-callback-order.php';

$fail=static function($m){fwrite(STDERR,"FAIL provider-callback-order-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$binding=str_repeat('a',64);
$state=MAD4B_SCP_Provider_Callback_Order::initial_state($binding);
$check(is_array($state)&&0===$state['last_sequence'],'initial state invalid');

$e1=array('sequence'=>1,'event_id'=>'evt-1','payload_sha256'=>str_repeat('1',64),'provider_binding_sha256'=>$binding);
$r1=MAD4B_SCP_Provider_Callback_Order::reduce($state,$e1);
$check('READY_IN_ORDER'===$r1['verdict']&&1===$r1['state']['last_sequence'],'sequence 1 not ready');
$state=$r1['state'];

$e3=array('sequence'=>3,'event_id'=>'evt-3','payload_sha256'=>str_repeat('3',64),'provider_binding_sha256'=>$binding);
$r3=MAD4B_SCP_Provider_Callback_Order::reduce($state,$e3);
$check('GAP_BUFFERED_RECONCILIATION_REQUIRED'===$r3['verdict'],'out-of-order callback did not require reconciliation');
$check(true===$r3['reconciliation_required']&&1===$r3['state']['last_sequence'],'gap advanced authoritative cursor');
$state=$r3['state'];

$e2=array('sequence'=>2,'event_id'=>'evt-2','payload_sha256'=>str_repeat('2',64),'provider_binding_sha256'=>$binding);
$r2=MAD4B_SCP_Provider_Callback_Order::reduce($state,$e2);
$check('GAP_RECONCILED_READY_IN_ORDER'===$r2['verdict'],'missing callback did not reconcile buffered sequence');
$check(3===$r2['state']['last_sequence'],'reconciled cursor not advanced through contiguous pending callbacks');
$check(array(2,3)===array_map(static function($e){return $e['sequence'];},$r2['ready_events']),'ready events not returned in exact order');
$state=$r2['state'];

$dup=MAD4B_SCP_Provider_Callback_Order::reduce($state,$e3);
$check('DUPLICATE_IGNORED'===$dup['verdict'],'same callback replay was not idempotent');

$conflict=$e3;$conflict['payload_sha256']=str_repeat('4',64);
$conf=MAD4B_SCP_Provider_Callback_Order::reduce($state,$conflict);
$check(is_wp_error($conf)&&'mad4b_provider_callback_stale_or_conflicting'===$conf->get_error_code(),'conflicting duplicate was accepted');

$drift=$e2;$drift['sequence']=4;$drift['event_id']='evt-4';$drift['payload_sha256']=str_repeat('4',64);$drift['provider_binding_sha256']=str_repeat('b',64);
$bad=MAD4B_SCP_Provider_Callback_Order::reduce($state,$drift);
$check(is_wp_error($bad)&&'mad4b_provider_callback_binding_drift'===$bad->get_error_code(),'provider binding drift was accepted');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-provider-callback-order.php');
foreach(array('update_option(','wp_remote_get(','wp_remote_post(','shell_exec(','proc_open(') as $forbidden){
 $check(false===strpos($source,$forbidden),'callback reducer contains persistence/execution primitive: '.$forbidden);
}
echo "mad4b.provider-callback-order.v1: PASS\n";
