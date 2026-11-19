<?php
declare(strict_types=1);
require dirname(__DIR__).'/host-v106/test_owner_console_render.php';
$actor=7; $caps=['manage_options','activate_plugins']; $unified=false;
$wpdb=new class extends wpdb {
  public function get_col($query){return array_keys($GLOBALS['options']);}
};
$grantId=str_repeat('c',32); $requestId=str_repeat('d',32);
$meta=['company'=>'all-royal-egypt','environment'=>'staging',
  'central_principal_id'=>7,'transport_id'=>$host,
  'operation'=>'company.host-authority.anchor.issue',
  'host_v2_bootstrap_only'=>true];
$options[MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$grantId]
  =array_merge($meta,['grant_id'=>$grantId,'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED']);
$stage=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($stage['phase']!=='BOOTSTRAP_GRANT_STAGED')throw new RuntimeException('GRANT_STAGE');
ob_start();MAD4B_Host_Authority_Owner_Console_V106::screen();$html=ob_get_clean();
if(!str_contains($html,'id="mad4b-ha-grant-panel" hidden')
  || !str_contains($html,'id="mad4b-ha-command-panel">')
  || !str_contains($html,'id="mad4b-ha-command-confirm"')
  || !str_contains($html,'id="mad4b-ha-tracker" hidden'))
 throw new RuntimeException('SEPARATE_OWNER_CONSENT_REQUIRED');
$options[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$requestId]
  =array_merge($meta,['request_id'=>$requestId,
   'status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY']);
$state=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($state['phase']!=='ORIGINAL305_REQUEST_STAGED'
 || $state['request_id']!==$requestId)throw new RuntimeException('SAVED_OWNER_REQUEST');
ob_start();MAD4B_Host_Authority_Owner_Console_V106::screen();$html=ob_get_clean();
if(!str_contains($html,'id="mad4b-ha-tracker">')
 || !str_contains($html,'id="mad4b-ha-command-panel" hidden')
 || !str_contains($html,'id="mad4b-ha-grant-panel" hidden')
 || !str_contains($html,'Check latest status')
 || !str_contains($html,$requestId)
 || !str_contains($html,'"requestStatus":"ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY"'))
 throw new RuntimeException('PENDING_REQUEST_TRACKER_NOT_PRIMARY');
echo json_encode(['state'=>'PASS','checks'=>12,'read_only'=>true,
 'pending_primary'=>true,'no_duplicate_owner_consent'=>true]).PHP_EOL;
