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

$bundle=MAD4B_SCP_Decommission_Portability::build_export_bundle(array(
 'scope'=>'site',
 'source_identity'=>array(
  'source_commit_sha'=>str_repeat('a',40),
  'build_fingerprint'=>str_repeat('b',64),
  'site_uuid'=>'11111111-2222-4333-8444-555555555555',
 ),
 'snapshot'=>array(
  'schema_contracts'=>array('mad4b.schema.v11'),
  'jobs_events'=>array(array('job_id'=>'j1','state'=>'DONE')),
  'artifact_metadata'=>array(array('artifact_id'=>'a1','type'=>'draft')),
  'lineage'=>array(array('from'=>'a1','to'=>'a2','relation'=>'uses')),
  'writer_profiles'=>array(array('id'=>'w1','version'=>'v1')),
  'credentials'=>array(),
  'grants'=>array(),
  'production_authority'=>array(),
 ),
));
$check('mad4b.export-bundle.v1'===$bundle['contract'],'export bundle contract mismatch');
$check(1===preg_match('/^[a-f0-9]{64}$/',$bundle['bundle_sha256']),'bundle digest invalid');
$check(false===$bundle['secrets_included'] && false===$bundle['grants_included'],'bundle included forbidden authority material');

$valid_import=MAD4B_SCP_Decommission_Portability::validate_import_bundle(array(
 'bundle'=>$bundle,
 'target'=>array('site_uuid'=>'11111111-2222-4333-8444-555555555555','collisions'=>array(),'missing_external_refs'=>array()),
));
$check(true===$valid_import['valid'],'clean export bundle failed import validation');
$check(false===$valid_import['import_performed'] && false===$valid_import['production_authorized'],'validator mutated/authorized import');

$tampered=$bundle;
$tampered['payload']['jobs_events'][0]['state']='ALTERED';
$bad_checksum=MAD4B_SCP_Decommission_Portability::validate_import_bundle(array('bundle'=>$tampered,'target'=>array()));
$check(false===$bad_checksum['valid'] && in_array('bundle_checksum_mismatch',$bad_checksum['blockers'],true),'tampered bundle checksum was accepted');

$remap=MAD4B_SCP_Decommission_Portability::validate_import_bundle(array(
 'bundle'=>$bundle,
 'target'=>array('site_uuid'=>'99999999-9999-4999-8999-999999999999','collisions'=>array(),'missing_external_refs'=>array()),
));
$check(false===$remap['valid'] && in_array('site_remap_required',$remap['blockers'],true),'cross-site import lacked remap blocker');

$collide=MAD4B_SCP_Decommission_Portability::validate_import_bundle(array(
 'bundle'=>$bundle,
 'target'=>array(
  'site_uuid'=>'11111111-2222-4333-8444-555555555555',
  'collisions'=>array('artifact:a1'),
  'missing_external_refs'=>array('blob:external-1'),
 ),
));
$check(false===$collide['valid'],'collision/missing-ref import incorrectly valid');
$check(in_array('target_collisions_present',$collide['blockers'],true),'collision blocker missing');
$check(in_array('missing_external_refs',$collide['blockers'],true),'missing external ref blocker missing');

$forbidden=MAD4B_SCP_Decommission_Portability::build_export_bundle(array(
 'scope'=>'site',
 'snapshot'=>array('credentials'=>array('secret-handle')),
));
$check(true===$forbidden['blocked'],'credential-bearing snapshot was exportable');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-decommission-portability.php');
foreach(array('delete_option(','wp_delete_post(','wp_clear_scheduled_hook(','$wpdb->delete','shell_exec(','exec(','proc_open(') as $forbidden){
 $check(false===strpos($source,$forbidden),'mutation primitive present: '.$forbidden);
}
echo "mad4b.decommission-portability-preflight.v1: PASS\n";
