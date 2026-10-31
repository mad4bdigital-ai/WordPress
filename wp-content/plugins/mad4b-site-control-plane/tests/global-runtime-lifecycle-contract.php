<?php
/* PHP-only behavioral contract, no WordPress or external providers required. */
define('ABSPATH', __DIR__);
class WP_Error {
    private $code;
    public function __construct($code, $message) {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-global-runtime-lifecycle.php';
function check($ok,$reason){if(!$ok){fwrite(STDERR,"FAIL: $reason\n");exit(1);}}
$class='MAD4B_SCP_Global_Runtime_Lifecycle';
$graph=array(
 array('id'=>'context','kind'=>'read','depends_on'=>array(),'ability_registered'=>true),
 array('id'=>'propose','kind'=>'read','depends_on'=>array('context'),'ability_registered'=>true),
 array('id'=>'write','kind'=>'write','depends_on'=>array('propose'),'ability_registered'=>true),
 array('id'=>'verify','kind'=>'verify','depends_on'=>array('write'),'ability_registered'=>true),
);
$r=$class::evaluate($graph);
check($r['steps'][0]['state']==='READY','first independent stage ready');
check($r['steps'][1]['state']==='WAITING_DEPENDENCY','routine predecessor is not blocked');
check($r['steps'][2]['state']==='WAITING_DEPENDENCY','write waits, not false authority denial');
check(!$r['blocked_steps'] && !$r['native_write_authority_granted'],'no invented permission');
$proof=array('context'=>array('status'=>'verified','native_source_confirmed'=>true,
 'readback_confirmed'=>true,'exact_target_bound'=>true,'receipt_digest'=>str_repeat('a',64)));
$r=$class::evaluate($graph,$proof);
check($r['steps'][1]['state']==='READY','source proof advances successor');
$proof['propose']=$proof['context'];
$r=$class::evaluate($graph,$proof);
check($r['steps'][2]['state']==='WAITING_PREFLIGHT','write never auto-ready without native admission');
$proof['write']=array('status'=>'denied','native_source_confirmed'=>true);
$r=$class::evaluate($graph,$proof);
check($r['steps'][2]['state']==='BLOCKED_AUTHORITY' && $r['state']==='PARTIAL',
 'exact denial isolated while independent work remains');
$proof['write']=array('status'=>'commit_uncertain','native_source_confirmed'=>true);
$r=$class::evaluate($graph,$proof);
check($r['steps'][2]['state']==='NEEDS_RECONCILIATION','unknown write never retried');
$proof['write']=$proof['context'];
$r=$class::evaluate($graph,$proof);
check($r['steps'][3]['state']==='READY','verified native write unblocks readback');
$proof['verify']=$proof['context'];
$r=$class::evaluate($graph,$proof);
check($r['state']==='VERIFIED','only source-bound final proof verifies');
$graph[1]['ability_registered']=false;
$r=$class::evaluate($graph);
check($r['steps'][1]['state']==='BLOCKED_DEPENDENCY','real missing ability is blocker');
$graph[1]['ability_registered']=true;
$proof=array('context'=>array('status'=>'not_applicable','native_source_confirmed'=>true,
 'exact_target_bound'=>true,'not_applicable_proven'=>true));
$r=$class::evaluate($graph,$proof);
check($r['steps'][0]['state']==='SKIPPED_NOT_APPLICABLE','scoped unnecessary stage skipped');
check($class::classify_error('STAGING_WPCLI_BUSY')['state']==='RECOVERING','busy recovers');
check(!$class::classify_error('commit_uncertain')['retry_write'],'no blind retry');
check($class::evaluate(array(array('id'=>'a','depends_on'=>array('b')))) instanceof WP_Error,
 'cyclic or unknown dependency fails closed');
$planner=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-capability-dependency-planner.php');
check(str_contains($planner,'MAD4B_SCP_Global_Runtime_Lifecycle::evaluate('),
 'canonical Operation Registry projects with shared contract');
check(str_contains($planner,"'routine_predecessor_wait_is_not_blocking'"),
 'canonical planner exposes ordinary waiting separately');
echo "PASS global runtime lifecycle nonblocking contract (16 assertions)\\n";
