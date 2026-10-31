<?php
define('ABSPATH','/tmp/');
class WP_Error {public function __construct(public $code,public $message,public $data=null){}}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($x){return abs((int)$x);}
class MAD4B_SCP_Policy {public static $allowed=false;public static function can_mutate(){return self::$allowed;}}
class MAD4B_SCP_Unified_Gate_Funnel {public static $blocked=[];public static function plan(){return ['plan_sha256'=>str_repeat('b',64)];}public static function readiness(){return ['mandatory_blockers'=>self::$blocked];}}
class MAD4B_SCP_Content_Jobs {public static $state='QUEUED';public static function get_job($input){return ['job'=>['state'=>self::$state,'job_revision'=>3]];}}
class MAD4B_SCP_Durable_Execution {public static $calls=0;public static function acquire_lease(...$args){self::$calls++;return ['epoch'=>2];}public static function heartbeat(...$args){self::$calls++;return true;}public static function assert_fencing_token(...$args){self::$calls++;return 'valid-fence';}}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-background-agent-admission.php';
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-background-agent-dispatch.php';
function check($v,$why){if(!$v)throw new Exception($why);}
$r=['worker_id'=>'worker-123','job_id'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa','plan_sha256'=>str_repeat('b',64),'lease_epoch'=>2];
check(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check($r)),'must deny no mutation authority');
check(MAD4B_SCP_Durable_Execution::$calls===0,'no lease before authority');
MAD4B_SCP_Policy::$allowed=true;
$bad=$r;$bad['plan_sha256']=str_repeat('a',64);
check(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check($bad)),'must reject stale plan');
MAD4B_SCP_Unified_Gate_Funnel::$blocked=['inventory'];
check(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check($r)),'must deny blocked prerequisites');
MAD4B_SCP_Unified_Gate_Funnel::$blocked=[];MAD4B_SCP_Content_Jobs::$state='COMPLETED';
check(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check($r)),'must deny completed job');
MAD4B_SCP_Content_Jobs::$state='QUEUED';
$a=MAD4B_SCP_Background_Agent_Dispatch::claim($r);
check(!is_wp_error($a)&&$a['provider_execution_authorized']===false&&$a['publication_authorized']===false,'lease is not provider authority');
$h=MAD4B_SCP_Background_Agent_Dispatch::heartbeat($r);
$v=MAD4B_SCP_Background_Agent_Dispatch::verify_commit($r);
check($h===true&&$v['verified']&&$v['mutation_performed']===false,'fencing verify is nonmutation');
check(MAD4B_SCP_Durable_Execution::$calls===3,'unexpected lease operation');
echo "BACKGROUND_AGENT_RUNTIME PASS denial, plan, gates, state, lease, fencing\n";
