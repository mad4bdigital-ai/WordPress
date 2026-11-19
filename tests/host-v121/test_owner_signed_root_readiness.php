<?php
declare(strict_types=1);
// Tests Original328 owner binding and root signed readiness using synthetic
// sodium test keys. No real Broker key, Owner session or WordPress mutation.
require dirname(__DIR__).'/host-v116/test_owner_state_ajax.php';
$caps=['manage_options','activate_plugins'];
$unified=false;
$actor=7;
if(!function_exists('sodium_crypto_sign_keypair'))
 throw new RuntimeException('SODIUM_TEST_RUNTIME_REQUIRED');
$kp=sodium_crypto_sign_keypair();
$pub=sodium_crypto_sign_publickey($kp);
$secret=sodium_crypto_sign_secretkey($kp);
$options[MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode($pub);
$options[MAD4B_Central_VPS_Broker_V1::JOB.$q]=[
 'job_id'=>$q,'plan_sha256'=>$plan,'expires_epoch'=>time()-80,
 'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
];
$options[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$q]['expires_epoch']=time()-80;
$fields=[
 'contract'=>'mad4b.host-authority-scoped-root-readiness.v121',
 'company'=>'all-royal-egypt','environment'=>'staging',
 'host'=>'hosting-2',
 'runner_sha256'=>MAD4B_Host_Authority_Owner_Console_V106::ROOT_V121_RUNNER_SHA,
 'worker_sha256'=>MAD4B_Host_Authority_Owner_Console_V106::ROOT_V121_WORKER_SHA,
 'issuer'=>'first-party-root-only',
 'mode'=>'signed-first-anchor-only',
 'issued_epoch'=>time(),
 'expires_epoch'=>time()+3600,
 'nonce'=>str_repeat('d',32)
];
$names=MAD4B_Host_Authority_Owner_Console_V106::ROOT_V121_FIELDS;
if(array_keys($fields)!==$names)
 throw new RuntimeException('ROOT_READINESS_FIELDS_ORDER_CHANGED');
$values=array_map(static fn($v)=>(string)$v,array_values($fields));
$message="MAD4B_HOST_AUTHORITY_V121_SCOPED_READINESS\n"
    .implode("\n",$values);
$fields['signature']=base64_encode(
    sodium_crypto_sign_detached($message,$secret));
$options[MAD4B_Host_Authority_Owner_Console_V106::ROOT_V121_READINESS]=$fields;

$s=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($s['owner_verified']!==true||$s['issuer_ready']!==true
 ||$s['phase']!=='OWNER_RENEWAL_READY'
 ||$s['signature_window_expired']!==true
 ||$s['anchor_revision']!==0
 ||$s['request_id']!==$q
 ||$s['plan_sha256']!==$plan)
 throw new RuntimeException('SIGNED_ROOT_SCOPED_RENEWAL_NOT_READY');
$r=MAD4B_Host_Authority_Owner_Console_V106::read_state(new WP_REST_Request());
if($r->status!==200||($r->data['issuer_ready']??null)!==true
 ||($r->data['phase']??null)!=='OWNER_RENEWAL_READY'
 ||isset($r->data['signature'],$r->data['host_principal']))
 throw new RuntimeException('SIGNED_ROOT_READINESS_AJAX_READ_FAILED');
ob_start();
MAD4B_Host_Authority_Owner_Console_V106::screen();
$page=ob_get_clean();
if(!str_contains($page,'First Anchor issuer ready')
 ||!str_contains($page,'id="mad4b-ha-command-panel"')
 ||str_contains($page,'id="mad4b-ha-command-panel" hidden')
 ||!str_contains($page,'Renew and dispatch Owner Request')
 ||!str_contains($page,'"issuerReady":true')
 ||!str_contains($page,'"planSha256":"'.$plan.'"'))
 throw new RuntimeException('SIGNED_ROOT_OWNER_RENEWAL_UI_NOT_READY');
foreach([
 'runner_sha256'=>str_repeat('f',64),
 'worker_sha256'=>str_repeat('a',64),
 'host'=>'hosting-3',
 'company'=>'wayfatours',
 'expires_epoch'=>time()-1
] as $field=>$change) {
 $changed=$fields;
 $changed[$field]=$change; // Signature MUST fail on any changed field.
 $options[MAD4B_Host_Authority_Owner_Console_V106::ROOT_V121_READINESS]=$changed;
 $held=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
 if($held['issuer_ready']!==false
  ||$held['phase']!=='INDEPENDENT_SIGNATURE_WINDOW_EXPIRED')
  throw new RuntimeException('ROOT_READINESS_UNAUTHENTICATED_'.$field);
}
$options[MAD4B_Host_Authority_Owner_Console_V106::ROOT_V121_READINESS]=$fields;
$caps=['mad4b_manage_company_operations'];
$ownerDenied=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($ownerDenied['owner_verified']!==false
 ||$ownerDenied['issuer_ready']!==false
 ||$ownerDenied['phase']!=='OWNER_SESSION_REQUIRED')
 throw new RuntimeException('ROOT_READINESS_PROMOTED_MASTER_ADMIN');
echo json_encode(['state'=>'PASS','checks'=>19,
 'signed_root_readiness_verified'=>true,
 'expired_claim_not_reused'=>true,
 'fresh_owner_consent_still_required'=>true,
 'root_and_broker_keys_synthetic'=>true],JSON_THROW_ON_ERROR),PHP_EOL;
