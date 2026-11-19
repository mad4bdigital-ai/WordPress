<?php
/** Native workflow observations are projections, never retry or grant instructions. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;public function __construct($code,$msg=''){$this->code=$code;}public function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function ok($yes,$reason){if(!$yes){fwrite(STDERR,'FAIL: '.$reason."\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-global-runtime-lifecycle.php';
$c='MAD4B_SCP_Global_Runtime_Lifecycle';
$x=$c::classify_error('commit_uncertain');ok($x['state']==='NEEDS_RECONCILIATION'&&$x['retry_write']===false,'Uncertain mutation denies replay');
$x=$c::classify_error('permission_denied');ok($x['state']==='BLOCKED_AUTHORITY'&&$x['retry_write']===false,'Authority failure denies write');
$x=$c::classify_error('timeout');ok($x['state']==='RECOVERING'&&$x['retry_write']===false,'Timeout never authorizes blind replay');
$nodes=array(array('id'=>'gather','kind'=>'read'),array('id'=>'commit','kind'=>'write','depends_on'=>array('gather')));
$x=$c::evaluate($nodes,array('gather'=>array('status'=>'verified','readback_confirmed'=>true,'exact_target_bound'=>true,'receipt_digest'=>'fake')));
ok($x['steps'][0]['state']!=='VERIFIED','Untrusted caller proof cannot certify original provider');
ok($x['steps'][1]['state']==='WAITING_DEPENDENCY'&&$x['native_write_authority_granted']===false,'Write still waits for native predecessor');
$receipt=array('status'=>'verified','readback_confirmed'=>true,'exact_target_bound'=>true,'receipt_digest'=>str_repeat('a',64),'native_source_confirmed'=>true);
$invalidReceipt=$receipt;$invalidReceipt['receipt_digest']='not_a_sha256';
$invalidGraph=$c::evaluate($nodes,array('gather'=>$invalidReceipt));
ok($invalidGraph['steps'][0]['state']!=='VERIFIED'
    &&$invalidGraph['steps'][1]['state']==='WAITING_DEPENDENCY',
    'Native evidence with malformed digest cannot certify predecessor');
$x=$c::evaluate($nodes,array('gather'=>$receipt));ok($x['steps'][0]['state']==='VERIFIED'&&$x['steps'][1]['state']==='WAITING_PREFLIGHT','Verified read does not grant write');
ok($x['automatic_provider_write_performed']===false&&$x['journal_or_cas_modified']===false,'No provider dispatch and no CAS mutation');
$invalid=$c::evaluate(array(array('id'=>'a','depends_on'=>array('b'))));ok(is_wp_error($invalid),'Forward and cyclic refs denied');
$lane=$c::observe_native_lane('scheduler_admission',array('decision'=>'ADMIT'));
ok($lane['state']==='WAITING_PREFLIGHT'&&$lane['mutation_performed']===false&&$lane['blind_write_retry_allowed']===false,'Scheduler admission is not execution');
$lane=$c::observe_native_lane('adaptive_dispatcher',array('write_uncertain'=>true));
ok($lane['state']==='NEEDS_RECONCILIATION'&&$lane['alternate_mutation_authorized']===false,'Uncertain dispatch requires native readback');
$lane=$c::observe_native_lane('unknown_owner',array());ok(is_wp_error($lane),'Unknown native owner rejected');
echo "PASS: global runtime lifecycle native observation, exact readback and no automatic writes (12 checks)\n";
