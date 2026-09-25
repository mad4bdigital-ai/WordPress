<?php

define('ABSPATH',__DIR__.'/');
function add_action($h,$c,$p=10){}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
require dirname(__DIR__).'/includes/class-mad4b-scp-decommission-portability.php';
$fail=static function($m){fwrite(STDERR,"FAIL decommission-portability-contract: $m\n");exit(1);};
$check=static function($c,$m) use($fail){if(!$c)$fail($m);};

$blocked=MAD4B_SCP_Decommission_Portability::preflight(array(
 'scope'=>'workflow_provider',
 'inventory'=>array(
  'active_jobs'=>2,'unresolved_writes'=>1,'active_credentials'=>1,'active_webhooks'=>1,
  'scheduled_work'=>1,'runner_leases'=>1,'pending_dlq'=>1,
  'evidence_retained_or_exportable'=>false,'external_refs_missing'=>true,
 ),
));
$check('BLOCKED'===$blocked['decision'],'unsafe decommission was not blocked');
foreach(array('active_jobs_present','active_credentials_present','scheduled_work_present','required_evidence_not_retained') as $reason){
 $check(in_array($reason,$blocked['blockers'],true),'missing blocker '.$reason);
}
$check(false===$blocked['export_manifest']['secrets_included'],'secrets included in export manifest');
$check(false===$blocked['export_manifest']['private_keys_included'],'private keys included in export manifest');
$check(false===$blocked['export_manifest']['grants_recreated_on_import'],'grants would be recreated');
$check(false===$blocked['production_authorized'],'preflight created Production authority');

$ready=MAD4B_SCP_Decommission_Portability::preflight(array(
 'scope'=>'site',
 'inventory'=>array(
  'active_jobs'=>0,'unresolved_writes'=>0,'active_credentials'=>0,'active_webhooks'=>0,
  'scheduled_work'=>0,'runner_leases'=>0,'pending_dlq'=>0,
  'evidence_retained_or_exportable'=>true,'external_refs_missing'=>false,
 ),
 'policy'=>array('published_content_treatment'=>'retain','audit_tombstone_required'=>true),
));
$check('READY_FOR_GOVERNED_QUIESCE'===$ready['decision'],'clean inventory did not reach governed quiesce readiness');
$check(empty($ready['blockers']),'clean preflight has blockers');
$check(1===preg_match('/^[a-f0-9]{64}$/',$ready['preflight_fingerprint']),'preflight fingerprint invalid');
$check(false===$ready['mutation_performed'],'preflight mutated state');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-decommission-portability.php');
foreach(array('delete_option(','wp_delete_post(','wp_clear_scheduled_hook(','$wpdb->delete','shell_exec(','exec(','proc_open(') as $forbidden){
 $check(false===strpos($source,$forbidden),'mutation primitive present: '.$forbidden);
}
echo "mad4b.decommission-portability-preflight.v1: PASS\n";
