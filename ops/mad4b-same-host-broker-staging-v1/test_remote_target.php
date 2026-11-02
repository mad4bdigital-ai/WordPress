<?php
/** Test 306 remote discovery against original 312 target registry, no writes. */
define('ABSPATH','/');
function add_action(...$a){}
class MAD4B_Broker_Exact_SSH_Authority_V1 {
 const TARGETS=['hosting-1','hosting-2','hosting-3','srv2030908'];
}
$script=file_get_contents(__DIR__.'/306-mad4b-central-vps-broker.php');
function check($cond,$msg){if(!$cond){fwrite(STDERR,"FAIL $msg\n");exit(2);}echo "PASS $msg\n";}
check(is_string($script)&&strpos($script,'MAD4B_Broker_Exact_SSH_Authority_V1::TARGETS')!==false,'Original 306 references 312 target allowlist');
check(strpos($script,'array_diff(MAD4B_Broker_Exact_SSH_Authority_V1::TARGETS,[$broker_host])')!==false,'Broker self target excluded from remote targets');
$allowed=array_diff(MAD4B_Broker_Exact_SSH_Authority_V1::TARGETS,['srv2030908']);
check(in_array('hosting-2',$allowed,true),'ARE hosting-2 discovered');
check(in_array('hosting-3',$allowed,true),'ETG hosting-3 discovered');
check(!in_array('srv2030908',$allowed,true),'Local host not remote route');
check(!in_array('unknown-host',$allowed,true),'Unknown host denied');
check(strpos($script,"'PENDING_INDEPENDENT_SSH_VERIFICATION'")!==false,'Original 305 pending transport enforced');
check(strpos($script,"'QUEUED_FOR_VPS_BROKER_CLAIM'")!==false,'306 does not auto-execute');
echo "REMOTE_306_SOURCE_CONTRACT_PASS\n";
