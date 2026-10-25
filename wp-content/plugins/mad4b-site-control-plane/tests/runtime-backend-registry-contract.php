<?php
define('ABSPATH', __DIR__);
require dirname(__DIR__).'/includes/class-mad4b-scp-runtime-backend-registry.php';
$c=array(
 'docker1'=>array('type'=>'docker','operations'=>array('php.lint','runtime.status.read'),'discovered'=>true,'configured'=>true,'reachable'=>true,'certified'=>true,'authorized'=>true,'identity_verified'=>true,'isolation_verified'=>true,'latency_ms'=>15),
 'ssh1'=>array('type'=>'ssh','operations'=>array('php.lint'),'discovered'=>true,'configured'=>true,'reachable'=>true,'certified'=>false,'authorized'=>true,'identity_verified'=>true,'isolation_verified'=>true),
 'unknown'=>array('type'=>'custom','operations'=>array('php.lint'),'discovered'=>true,'configured'=>true,'reachable'=>true,'certified'=>true,'authorized'=>false,'identity_verified'=>true,'isolation_verified'=>true),
);
$s=MAD4B_SCP_Runtime_Backend_Registry::select($c,'php.lint','vps',1000);
if($s['selected']!=='docker1'||$s['eligible_count']!==1||$s['authorization_granted'])throw new RuntimeException('unsafe selection');
$c['docker1']['backoff_until']=2000;
if(MAD4B_SCP_Runtime_Backend_Registry::select($c,'php.lint','vps',1000)['selected']!==null)throw new RuntimeException('backoff bypass');
if(MAD4B_SCP_Runtime_Backend_Registry::fallback_allowed('content.publish','confirmed_not_started'))throw new RuntimeException('write fallback');
if(MAD4B_SCP_Runtime_Backend_Registry::fallback_allowed('php.lint','outcome_unknown'))throw new RuntimeException('uncertain replay');
if(!MAD4B_SCP_Runtime_Backend_Registry::fallback_allowed('php.lint','confirmed_not_started'))throw new RuntimeException('safe fallback');
$p=MAD4B_SCP_Runtime_Backend_Registry::snapshot($c,'invalid');
if($p['preset']!=='shared_hosting'||$p['authorization_granted']||isset($p['backends']['docker1']['host']))throw new RuntimeException('snapshot leak');
echo "PASS: RUNTIME_BACKEND_REGISTRY_FAIL_CLOSED\n";
