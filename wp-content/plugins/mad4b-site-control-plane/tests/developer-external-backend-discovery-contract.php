<?php
define('ABSPATH',__DIR__);
require dirname(__DIR__).'/includes/class-mad4b-scp-developer-host-capabilities.php';
$found=MAD4B_SCP_Developer_Host_Capabilities::external_backends();
foreach(['isolated_vps_runner','ssh_host_runner'] as $key) {
 if(!isset($found[$key]) || $found[$key]['execution_allowed']!==false || $found[$key]['execution_certified']!==false) throw new RuntimeException('Fail-closed candidate contract');
}
define('MAD4B_MCP_DEVELOPER_VPS_RUNNER_ID','certified-candidate:host-001');
$after=MAD4B_SCP_Developer_Host_Capabilities::external_backends();
if(!$after['isolated_vps_runner']['configured'] || $after['isolated_vps_runner']['execution_allowed'] || $after['ssh_host_runner']['configured']) throw new RuntimeException('Backend discovery mismatch');
$snapshot=MAD4B_SCP_Developer_Host_Capabilities::snapshot();
if($snapshot['external_execution_certified']!==false || !in_array('isolated_vps_runner',$snapshot['available_execution_backends'],true)) throw new RuntimeException('Snapshot mismatch');
if(strpos(json_encode($snapshot),'certified-candidate:host-001')!==false) throw new RuntimeException('Runner identity exposed');
echo "PASS: DEVELOPER_EXTERNAL_BACKEND_DISCOVERY_FAIL_CLOSED\n";
