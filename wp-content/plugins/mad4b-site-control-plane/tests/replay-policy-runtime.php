<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
	private $code; private $data;
	public function __construct($code,$message='',$data=null){$this->code=(string)$code;$this->data=$data;}
	public function get_error_code(){return $this->code;}
	public function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}

final class MAD4B_SCP_Preparation_Receipt {
	public static $claims=array();
	public static function claims($token,$name){
		if(empty(self::$claims[$token]))return new WP_Error('fixture_receipt_unknown');
		$c=self::$claims[$token];
		return $c['ability_name']===$name?$c:new WP_Error('fixture_receipt_target_mismatch');
	}
}
final class MAD4B_SCP_Impact_Policy {
	public static function classify($ability,$provider,$input){
		$risk=false!==strpos($ability,'exceptional')?'exceptional':(false!==strpos($ability,'high')?'high':(false!==strpos($ability,'medium')?'medium':'low'));
		return array('risk_tier'=>$risk);
	}
}
final class MAD4B_SCP_Durable_Execution {
	public static $rows=array();
	public static function begin_idempotency($scope,$key,$request,$ttl){
		$id=$scope."\0".$key;
		if(!isset(self::$rows[$id])){
			self::$rows[$id]=array('request'=>$request,'status'=>'pending','claim_epoch'=>1,'result'=>null);
			return array('claimed'=>true,'replayed'=>false,'scope_key'=>$scope,'idempotency_key'=>$key,'request_sha256'=>$request,'claim_epoch'=>1);
		}
		$row=self::$rows[$id];
		if(!hash_equals($row['request'],$request))return new WP_Error('mad4b_idempotency_hash_conflict');
		if('completed'===$row['status'])return array('claimed'=>false,'replayed'=>true,'scope_key'=>$scope,'idempotency_key'=>$key,'request_sha256'=>$request,'claim_epoch'=>$row['claim_epoch'],'result'=>$row['result']);
		return new WP_Error('mad4b_idempotency_in_progress');
	}
	public static function complete_idempotency(array $claim,$result){
		$id=$claim['scope_key']."\0".$claim['idempotency_key'];
		if(empty(self::$rows[$id])||'pending'!==self::$rows[$id]['status'])return new WP_Error('fixture_complete_conflict');
		self::$rows[$id]['status']='completed'; self::$rows[$id]['result']=$result;
		return array('completed'=>true);
	}
}

require dirname(__DIR__).'/includes/class-mad4b-scp-replay-policy.php';
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL replay-policy-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$policy=MAD4B_SCP_Replay_Policy::policy_sha256();
$check(1===preg_match('/^[a-f0-9]{64}$/',$policy),'replay policy digest unavailable',$policy);
$claims=static function($ability,$nonce)use($policy){return array('ability_name'=>$ability,'nonce'=>$nonce,'authority_scope_sha256'=>str_repeat('a',64),'replay_policy_sha256'=>$policy);};

MAD4B_SCP_Preparation_Receipt::$claims['high-token']=$claims('fixture/high-write',str_repeat('1',32));
$high=MAD4B_SCP_Replay_Policy::begin('high-token','fixture/high-write','core',array('x'=>1),'same-key');
$check(is_array($high)&&'single_use'===$high['mode']&&!empty($high['consumed_before_execution']),'high risk was not single-use',$high);
$high_replay=MAD4B_SCP_Replay_Policy::begin('high-token','fixture/high-write','core',array('x'=>1),'same-key');
$check('mad4b_preparation_replay_denied'===$code($high_replay),'high-risk preparation replay was accepted',$high_replay);

MAD4B_SCP_Preparation_Receipt::$claims['medium-token']=$claims('fixture/medium-write',str_repeat('2',32));
$medium=MAD4B_SCP_Replay_Policy::begin('medium-token','fixture/medium-write','core',array('x'=>2),'idem-1');
$check(is_array($medium)&&'reusable_same_idempotency'===$medium['mode']&&!empty($medium['claimed']),'medium idempotent preparation did not claim durable replay state',$medium);
$dispatch=array('contract'=>'fixture.dispatch.v1','result'=>array('ok'=>true));
$complete=MAD4B_SCP_Replay_Policy::complete($medium,$dispatch);
$check(is_array($complete)&&!empty($complete['completed']),'reusable replay completion failed',$complete);
$medium_replay=MAD4B_SCP_Replay_Policy::begin('medium-token','fixture/medium-write','core',array('x'=>2),'idem-1');
$check(is_array($medium_replay)&&!empty($medium_replay['replayed'])&&$dispatch===$medium_replay['dispatch_result'],'same idempotency replay did not return the recorded result',$medium_replay);
$rebind=MAD4B_SCP_Replay_Policy::begin('medium-token','fixture/medium-write','core',array('x'=>2),'idem-2');
$check('mad4b_preparation_replay_binding_conflict'===$code($rebind),'receipt rebound to a different idempotency key',$rebind);
$payload_drift=MAD4B_SCP_Replay_Policy::begin('medium-token','fixture/medium-write','core',array('x'=>999),'idem-1');
$check('mad4b_preparation_replay_binding_conflict'===$code($payload_drift),'receipt/idempotency binding accepted a different request payload',$payload_drift);

MAD4B_SCP_Preparation_Receipt::$claims['low-no-idem']=$claims('fixture/low-write',str_repeat('3',32));
$low=MAD4B_SCP_Replay_Policy::begin('low-no-idem','fixture/low-write','core',array('x'=>3),'');
$check(is_array($low)&&'single_use'===$low['mode'],'missing idempotency key did not force single-use replay semantics',$low);
$check('mad4b_preparation_replay_denied'===$code(MAD4B_SCP_Replay_Policy::begin('low-no-idem','fixture/low-write','core',array('x'=>3),'')),'single-use fallback replay was accepted');

MAD4B_SCP_Preparation_Receipt::$claims['stale']=$claims('fixture/medium-write',str_repeat('4',32));
MAD4B_SCP_Preparation_Receipt::$claims['stale']['replay_policy_sha256']=str_repeat('f',64);
$check('mad4b_preparation_replay_policy_stale'===$code(MAD4B_SCP_Replay_Policy::begin('stale','fixture/medium-write','core',array(), 'idem-x')),'stale replay-policy receipt was accepted');

echo "mad4b.replay-policy.runtime.v1: PASS\n";
