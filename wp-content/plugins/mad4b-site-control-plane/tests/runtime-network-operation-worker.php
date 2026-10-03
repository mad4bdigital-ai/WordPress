<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$operation_id=isset($args[0])?(string)$args[0]:'';
$blog_id=isset($args[1])?absint($args[1]):0;
$worker_id=isset($args[2])?sanitize_key((string)$args[2]):'';
$outcome=isset($args[3])?sanitize_key((string)$args[3]):'';
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL runtime-network-operation-worker: '.$m.(null===$v?'':' '.wp_json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$state=MAD4B_SCP_Network_Operation_Journal::reconstruct($operation_id);
$check(!is_wp_error($state)&&isset($state['targets'][(string)$blog_id]),'Worker target is unavailable.',$state);
$row=$state['targets'][(string)$blog_id];
$observed=array();
foreach(array('target_site_uuid','origin_sha256','authority_scope_sha256','catalog_sha256','plan_sha256','preparation_sha256','approval_ticket_id','context_sha256','credential_binding_sha256','receipt_binding_sha256') as $field)$observed[$field]=$row[$field];
$claim=MAD4B_SCP_Network_Operation_Journal::claim_target($operation_id,$blog_id,$worker_id,$observed);
$check(!is_wp_error($claim)&&'claimed'===$claim['state'],'Worker failed to claim site target.',$claim);
$epoch=(int)$claim['claim_epoch'];
if('crash'===$outcome){
 global $wpdb; $tables=MAD4B_SCP_Schema::tables();
 $aged=$wpdb->query($wpdb->prepare("UPDATE {$tables['network_operation_targets']} SET claim_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE BINARY network_operation_id=BINARY %s AND target_blog_id=%d AND claim_epoch=%d AND BINARY worker_id=BINARY %s",$operation_id,$blog_id,$epoch,$worker_id));
 $check(1===(int)$aged,'Crash fixture could not age the claimed lease.');
 echo wp_json_encode(array('contract'=>'mad4b.network-operation-worker.v1','operation_id'=>$operation_id,'blog_id'=>$blog_id,'worker_id'=>$worker_id,'outcome'=>'crash_after_claim','claim_epoch'=>$epoch)).PHP_EOL;
 return;
}
if($epoch>0){
 $stale=MAD4B_SCP_Network_Operation_Journal::record_target_outcome($operation_id,$blog_id,$epoch-1,$worker_id,$outcome,'evidence:stale:'.$worker_id,'',$row['receipt_binding_sha256']);
 $data=is_wp_error($stale)?$stale->get_error_data():array();
 $check(is_wp_error($stale)&&isset($data['reason_code'])&&'network_target_claim_epoch_stale'===$data['reason_code'],'Reordered/stale dispatch epoch was not denied.',$stale);
}
$receipt='committed'===$outcome?hash('sha256','receipt|'.$operation_id.'|'.$blog_id.'|'.$epoch):'';
$evidence='evidence:network:'.$worker_id.':'.$epoch;
$foreign=MAD4B_SCP_Network_Operation_Journal::record_target_outcome($operation_id,$blog_id,$epoch,'foreign-worker',$outcome,'evidence:foreign',$receipt,$row['receipt_binding_sha256']);
$data=is_wp_error($foreign)?$foreign->get_error_data():array();
$check(is_wp_error($foreign)&&isset($data['reason_code'])&&'network_target_worker_mismatch'===$data['reason_code'],'Foreign worker closed another worker claim.',$foreign);
$result=MAD4B_SCP_Network_Operation_Journal::record_target_outcome($operation_id,$blog_id,$epoch,$worker_id,$outcome,$evidence,$receipt,$row['receipt_binding_sha256']);
$check(!is_wp_error($result)&&$outcome===$result['state'],'Worker outcome persistence failed.',$result);
if('committed'===$outcome){
 $again=MAD4B_SCP_Network_Operation_Journal::record_target_outcome($operation_id,$blog_id,$epoch,$worker_id,$outcome,$evidence,$receipt,$row['receipt_binding_sha256']);
 $check(!is_wp_error($again)&&!empty($again['outcome_idempotent']),'Exact duplicate committed callback was not idempotent.',$again);
 $altered=MAD4B_SCP_Network_Operation_Journal::record_target_outcome($operation_id,$blog_id,$epoch,$worker_id,$outcome,$evidence,hash('sha256',$receipt.'altered'),$row['receipt_binding_sha256']);
 $data=is_wp_error($altered)?$altered->get_error_data():array();
 $check(is_wp_error($altered)&&isset($data['reason_code'])&&'network_target_terminal_immutable'===$data['reason_code'],'Committed site was mutable after terminal receipt.',$altered);
}
echo wp_json_encode(array('contract'=>'mad4b.network-operation-worker.v1','operation_id'=>$operation_id,'blog_id'=>$blog_id,'worker_id'=>$worker_id,'outcome'=>$outcome,'claim_epoch'=>$epoch)).PHP_EOL;
