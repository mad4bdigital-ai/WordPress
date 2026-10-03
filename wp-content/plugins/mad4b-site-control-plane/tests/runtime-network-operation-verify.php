<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$operation_id=isset($args[0])?(string)$args[0]:'';
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL runtime-network-operation-verify: '.$m.(null===$v?'':' '.wp_json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$state=MAD4B_SCP_Network_Operation_Journal::reconstruct($operation_id);
$check(!is_wp_error($state)&&!empty($state['event_chain_valid']),'Fresh-process reconstruction failed.',$state);
$check(array(101)===$state['sets']['completed'],'Committed site set reconstructed incorrectly.',$state['sets']);
$check(array(202)===$state['sets']['reconciling'],'Reconciling site set reconstructed incorrectly.',$state['sets']);
$check(array(303)===$state['sets']['pending']&&array(303)===$state['resume_candidates'],'Pending/resume candidate set reconstructed incorrectly.',$state['sets']);
$check('reconciling'===$state['state']&&empty($state['replay_committed_allowed']),'Ambiguous fan-out state was promoted or committed replay enabled.',$state);

$before_revision=(int)$state['revision'];
$a=$state['targets']['101'];$b=$state['targets']['202'];
$contextA=array();foreach(array('target_site_uuid','origin_sha256','authority_scope_sha256','catalog_sha256','plan_sha256','preparation_sha256','approval_ticket_id','context_sha256','credential_binding_sha256','receipt_binding_sha256') as $field)$contextA[$field]=$a[$field];
$completed_claim=MAD4B_SCP_Network_Operation_Journal::claim_target($operation_id,101,'resume-worker',$contextA);
$check(!is_wp_error($completed_claim)&&!empty($completed_claim['already_completed']),'Resume attempted to replay committed target.',$completed_claim);
$after_completed=MAD4B_SCP_Network_Operation_Journal::reconstruct($operation_id);
$check($before_revision===(int)$after_completed['revision'],'Committed target replay changed durable journal revision.',$after_completed);

foreach(array('target_site_uuid','authority_scope_sha256','catalog_sha256','approval_ticket_id','context_sha256','credential_binding_sha256','receipt_binding_sha256') as $field){
 $cross=$contextA;$cross[$field]=$b[$field];
 $denied=MAD4B_SCP_Network_Operation_Journal::verify_target_context($operation_id,101,$cross);
 $check(is_wp_error($denied),'Cross-site '.$field.' was accepted for another target.',$denied);
}

$paused=MAD4B_SCP_Network_Operation_Journal::pause($operation_id);
$check(!is_wp_error($paused)&&'paused'===$paused['state']&&array(101)===$paused['sets']['completed'],'Pause rolled back or lost completed target.',$paused);
$resumed=MAD4B_SCP_Network_Operation_Journal::resume($operation_id);
$check(!is_wp_error($resumed)&&'reconciling'===$resumed['state']&&array(303)===$resumed['resume_candidates'],'Resume did not reconstruct conservative pending/reconciling sets.',$resumed);

echo "mad4b.network-operation.runtime.v1: PASS\n";
