<?php
/** Standalone test of authoritative broker diagnostics classifications. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
$GLOBALS['options']=array();
function add_action(...$args) {}
function get_option($name, $default=false) {return $GLOBALS['options'][$name]??$default;}
function is_wp_error($x) {return $x instanceof WP_Error;}
class WP_Error {
  public function __construct(private string $code, $message='', $data=array()){}
  public function get_error_code() {return $this->code;}
}
class MAD4B_Central_VPS_Broker_V1 {
  const JOB='mad4b_central_broker_job_v1_';
  const CLAIM='mad4b_central_broker_claim_v1_';
  const HEARTBEAT='mad4b_central_broker_heartbeat_v1';
  const HOST_ID='mad4b_central_broker_host_id_v1';
}
require __DIR__.'/../staging-mu/308-mad4b-vps-broker-diagnostics.php';
function check($x,$why){if(!$x)throw new RuntimeException($why);}
$id='cdc38dc8843a8f78089be52c5087aa16';
$job=array(
    'job_id'=>$id,'company'=>'wayfatours',
    'state'=>'QUEUED_FOR_VPS_BROKER_CLAIM',
    'target_host_id'=>'srv2030908','environment'=>'production',
    'site_uuid'=>'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b',
    'created_epoch'=>time()-100,'expires_epoch'=>time()+300,
    'command_sha256'=>str_repeat('a',64),'operation'=>'maintenance.shell');
$GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::JOB.$id]=$job;
$read=MAD4B_VPS_Broker_Diagnostics_V1::detail($id);
check($read['status']==='QUEUED','queued state');
check($read['completion_verified']===false,'queued not verified');
$claim=array('job_id'=>$id,'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
    'host_id'=>'srv2030908','claimed_epoch'=>time()-10);
$GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::CLAIM.$id]=$claim;
$queued=MAD4B_VPS_Broker_Diagnostics_V1::detail($id);
check($queued['status']==='INCONSISTENT','queued with claim is inconsistent');
$job['state']='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY';
$GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::JOB.$id]=$job;
$claimed=MAD4B_VPS_Broker_Diagnostics_V1::detail($id);
check($claimed['status']==='CLAIMED','signed broker claim recorded');
check($claimed['completion_verified']===false && !$claimed['shell_execution_verified'],
    'claimed never equals executed');
$wrong=$claim;$wrong['host_id']='other-host';
$GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::CLAIM.$id]=$wrong;
$bad=MAD4B_VPS_Broker_Diagnostics_V1::detail($id);
check($bad['status']==='INCONSISTENT','host mismatch');
$GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::CLAIM.$id]=$claim;
$job['state']='FAILED_EXECUTION';
$GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::JOB.$id]=$job;
$failed=MAD4B_VPS_Broker_Diagnostics_V1::detail($id);
check($failed['status']==='FAILED','native failed state');
$job['state']='QUEUED_FOR_VPS_BROKER_CLAIM';
$job['expires_epoch']=time()-20;
$GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::JOB.$id]=$job;
unset($GLOBALS['options'][MAD4B_Central_VPS_Broker_V1::CLAIM.$id]);
$expired=MAD4B_VPS_Broker_Diagnostics_V1::detail($id);
check($expired['status']==='EXPIRED','expired queued job');
check(is_wp_error(MAD4B_VPS_Broker_Diagnostics_V1::detail(str_repeat('a',31))),'invalid ID denied');
check(is_wp_error(MAD4B_VPS_Broker_Diagnostics_V1::detail(str_repeat('b',32))),'missing job denied');
check($expired['completion_verified']===false,'never invent completed execution');
echo "PASS: queued, claimed, expired, failed, inconsistent, scope validation and no false execution verification\n";
