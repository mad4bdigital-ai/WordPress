<?php
define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code=(string)$code; $this->message=(string)$message; $this->data=$data; }
	public function get_error_code(){return $this->code;}
	public function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}

class MAD4B_SCP_Identifiers {
	public static function receipt_id_from_sha256($sha){return preg_match('/^[a-f0-9]{64}$/',(string)$sha)?'receipt:'.substr((string)$sha,0,32):'';}
}
class MAD4B_SCP_Approval_Impact_Binding {}
class MAD4B_SCP_Authorization_Decision_Graph {}
class MAD4B_SCP_Execution_Receipt {
	public static function build(array $claim,$result,array $terminal){
		return array('receipt_sha256'=>hash('sha256','fatal-fixture|'.$terminal['receipt_sha256']),'signature_state'=>'verified');
	}
}
class MAD4B_SCP_Audit {
	public static $path='';
	private static function path(){return self::$path!==''?self::$path:(string)getenv('MAD4B_FATAL_EVIDENCE_FILE');}
	public static function record($ability,array $summary,$status){
		$entry=array(
			'event_id'=>sprintf('22222222-2222-4222-8222-%012d',self::count()+1),
			'entry_hash'=>hash('sha256',wp_json_encode(array($ability,$summary,$status,self::count()+1))),
			'ability'=>(string)$ability,'status'=>(string)$status,'summary'=>$summary
		);
		if(false===file_put_contents(self::path(),wp_json_encode($entry)."\n",FILE_APPEND|LOCK_EX)) return new WP_Error('fixture_audit_write_failed','fixture');
		return $entry;
	}
	private static function rows(){
		$path=self::path(); if(!is_file($path))return array();
		$out=array(); foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:array() as $line){$row=json_decode($line,true);if(is_array($row))$out[]=$row;} return $out;
	}
	private static function count(){return count(self::rows());}
	public static function execution_checkpoint_events($attempt,$limit=20){
		$events=array_values(array_filter(self::rows(),static function($entry)use($attempt){
			$summary=$entry['summary']??array();
			return isset($summary['execution_attempt_sha256'])
				&& hash_equals(strtolower((string)$attempt),strtolower((string)$summary['execution_attempt_sha256']))
				&& in_array(sanitize_key((string)($summary['reason_code']??'')),array('execution_checkpoint','unified_execution_receipt_committed'),true);
		}));
		if(count($events)>$limit)$events=array_slice($events,-$limit);
		return array('events'=>$events,'chain_valid'=>true,'head_consistent'=>true);
	}
}

require dirname(__DIR__).'/includes/class-mad4b-scp-execution-evidence-policy.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-authorization.php';

$claim=array(
	'ability'=>'fixture/fatal-mutation','provider'=>'core','server_id'=>'mad4b-write',
	'request_id'=>'fatal-process-fixture','approval_required'=>false,
	'target_fingerprint'=>str_repeat('a',64),'resource_set_sha256'=>str_repeat('b',64),
	'context_receipt_sha256'=>str_repeat('c',64),
	'commit_guard_receipt'=>array('material_sha256'=>str_repeat('d',64)),
);
$stage=(string)getenv('MAD4B_FATAL_STAGE');
if(''!==$stage){
	if('approval_claimed'===$stage){
		$checkpoint=MAD4B_SCP_Authorization::execution_checkpoint($claim,'approval_claimed');
	}elseif('provider_entry'===$stage){
		MAD4B_SCP_Authorization::execution_checkpoint($claim,'approval_claimed');
		$checkpoint=MAD4B_SCP_Authorization::execution_checkpoint($claim,'provider_entry_possible');
	}elseif('provider_returned'===$stage){
		MAD4B_SCP_Authorization::execution_checkpoint($claim,'approval_claimed');
		MAD4B_SCP_Authorization::execution_checkpoint($claim,'provider_entry_possible');
		$checkpoint=MAD4B_SCP_Authorization::execution_checkpoint($claim,'provider_returned');
	}elseif('durable_receipt'===$stage){
		MAD4B_SCP_Authorization::execution_checkpoint($claim,'approval_claimed');
		MAD4B_SCP_Authorization::execution_checkpoint($claim,'provider_entry_possible');
		MAD4B_SCP_Authorization::execution_checkpoint($claim,'provider_returned');
		$ok=MAD4B_SCP_Authorization::finalize_execution_claim($claim,array('updated'=>true));
		if(true!==$ok){fwrite(STDERR,'finalize failed'.PHP_EOL);exit(3);}
		$checkpoint=array('execution_attempt_sha256'=>'');
	}else{fwrite(STDERR,'unknown stage'.PHP_EOL);exit(4);}
	if(isset($checkpoint)&&is_wp_error($checkpoint)){fwrite(STDERR,$checkpoint->get_error_code().PHP_EOL);exit(5);}
	echo "READY\n"; flush();
	while(true){usleep(100000);}
}

$fail=static function($message,$value=null){fwrite(STDERR,'FAIL execution-fatal-interruption-runtime: '.$message.(null===$value?'':' '.json_encode($value)).PHP_EOL);exit(1);};
$check=static function($condition,$message,$value=null)use($fail){if(!$condition)$fail($message,$value);};
$expected=array(
	'approval_claimed'=>'PREPARED',
	'provider_entry'=>'RECONCILING',
	'provider_returned'=>'RECONCILING',
	'durable_receipt'=>'COMMITTED',
);
foreach($expected as $stageName=>$expectedState){
	$path=sys_get_temp_dir().'/mad4b-fatal-'.$stageName.'-'.getmypid().'.jsonl';
	@unlink($path);
	$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__);
	$env=array('MAD4B_FATAL_STAGE'=>$stageName,'MAD4B_FATAL_EVIDENCE_FILE'=>$path);
	$spec=array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w'));
	$pipes=array();$proc=proc_open($cmd,$spec,$pipes,null,$env);
	$check(is_resource($proc),'unable to start child',array('stage'=>$stageName));
	fclose($pipes[0]);
	$ready=fgets($pipes[1]);
	$check("READY\n"===$ready,'child did not reach crash point',array('stage'=>$stageName,'stdout'=>$ready,'stderr'=>stream_get_contents($pipes[2])));
	$killed=proc_terminate($proc,9);
	if(!$killed)$killed=proc_terminate($proc);
	fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);
	$check($killed,'unable to terminate child process',array('stage'=>$stageName));
	MAD4B_SCP_Audit::$path=$path;
	$rows=file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:array();
	$check(!empty($rows),'durable evidence missing after process termination',array('stage'=>$stageName));
	$attempt='';
	foreach($rows as $line){$row=json_decode($line,true);$candidate=$row['summary']['execution_attempt_sha256']??'';if(preg_match('/^[a-f0-9]{64}$/',(string)$candidate))$attempt=(string)$candidate;}
	$check(''!==$attempt,'execution attempt correlation missing',array('stage'=>$stageName));
	$view=MAD4B_SCP_Authorization::execution_attempt_state($attempt);
	$check(is_array($view)&&$expectedState===($view['state']??''),'restart recovery state mismatch',array('stage'=>$stageName,'view'=>$view));
	$check(empty($view['blind_retry_allowed']),'restart recovery allowed blind retry',array('stage'=>$stageName,'view'=>$view));
	if('COMMITTED'!==$expectedState)$check(empty($view['terminal']),'interrupted execution became terminal',array('stage'=>$stageName,'view'=>$view));
	else $check(!empty($view['terminal']),'durable receipt did not remain terminal after worker death',$view);
	@unlink($path);
}
MAD4B_SCP_Audit::$path='';
echo "mad4b.execution-fatal-interruption.runtime.v1: PASS\n";
