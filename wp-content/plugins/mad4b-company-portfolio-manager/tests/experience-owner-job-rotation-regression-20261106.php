<?php
/** Regression: expired previous job is historical, not a new-package blocker. */
define('ABSPATH', __DIR__);
class MAD4B_Central_VPS_Broker_V1 { const JOB='test_broker_job_'; }
function add_action(...$args):void {}
$GLOBALS['test_store']=[];
function get_option($key,$default=false) {return $GLOBALS['test_store'][$key]??$default;}
require __DIR__.'/../includes/class-mad4b-company-experience-install-owner-launch.php';
$class='MAD4B_Company_Experience_Install_Owner_V1';
$method=new ReflectionMethod($class,'prior_blocks');
// PHP 7.4 requires explicit access to private methods in reflection fixtures.
$method->setAccessible(true);
$jobId=str_repeat('a',32);
$old=str_repeat('b',64);
$new=str_repeat('c',64);
$prior=['job_id'=>$jobId,'command_sha256'=>$old,'state'=>'QUEUED_FOR_VPS_BROKER_CLAIM'];
$broker=[
 'job_id'=>$jobId,'command_sha256'=>$old,'company'=>'wayfatours',
 'environment'=>'production','site_uuid'=>$class::SITE,
 'expires_epoch'=>time()-100,'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
];
$tests=0;
$check=static function(bool $pass,string $label)use(&$tests):void {
 if(!$pass){fwrite(STDERR,"FAIL: $label\n");exit(2);} ++$tests;
};
$check($method->invoke(null,null,$new)===false,'no previous job');
$check($method->invoke(null,$prior,$old)===true,'never replay same package command');
$check($method->invoke(null,$prior,$new)===true,'missing old Broker record fails closed');
$GLOBALS['test_store'][MAD4B_Central_VPS_Broker_V1::JOB.$jobId]=$broker;
$check($method->invoke(null,$prior,$new)===false,'expired claimed old package can rotate');
$GLOBALS['test_store'][MAD4B_Central_VPS_Broker_V1::JOB.$jobId]['state']='QUEUED_FOR_VPS_BROKER_CLAIM';
$check($method->invoke(null,$prior,$new)===false,'expired queued old package can rotate');
$GLOBALS['test_store'][MAD4B_Central_VPS_Broker_V1::JOB.$jobId]['expires_epoch']=time()+100;
$check($method->invoke(null,$prior,$new)===true,'still-current old job cannot be superseded');
$GLOBALS['test_store'][MAD4B_Central_VPS_Broker_V1::JOB.$jobId]['expires_epoch']=time()-100;
$GLOBALS['test_store'][MAD4B_Central_VPS_Broker_V1::JOB.$jobId]['state']='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED';
$check($method->invoke(null,$prior,$new)===true,'verified old job must be reconciled');
$GLOBALS['test_store'][MAD4B_Central_VPS_Broker_V1::JOB.$jobId]['state']='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY';
$GLOBALS['test_store'][MAD4B_Central_VPS_Broker_V1::JOB.$jobId]['site_uuid']='wrong-tenant';
$check($method->invoke(null,$prior,$new)===true,'cross-tenant job fails closed');
$GLOBALS['test_store'][MAD4B_Central_VPS_Broker_V1::JOB.$jobId]['site_uuid']=$class::SITE;
$GLOBALS['test_store'][MAD4B_Central_VPS_Broker_V1::JOB.$jobId]['command_sha256']=str_repeat('d',64);
$check($method->invoke(null,$prior,$new)===true,'mismatched historic command fails closed');
$check($method->invoke(null,['job_id'=>'invalid','command_sha256'=>$old],$new)===true,'bad job ID fails closed');
$check($method->invoke(null,$prior,'invalid')===true,'bad current command fails closed');
$check($method->invoke(null,['job_id'=>$jobId,'command_sha256'=>'invalid'],$new)===true,'bad old command fails closed');
echo "EXPERIENCE_ORIGINAL_305_ROTATION_TESTS_PASS {$tests}/{$tests}\n";
