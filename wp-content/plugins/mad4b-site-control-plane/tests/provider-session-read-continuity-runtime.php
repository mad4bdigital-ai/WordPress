<?php
define('ABSPATH', __DIR__ . '/');
function sanitize_key($v) {return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
require dirname(__DIR__) . '/includes/class-mad4b-scp-truth-projection.php';
function check_fact($c,$msg) {if(!$c){fwrite(STDERR,'FAILED '.$msg.PHP_EOL);exit(1);}}
$fact=array('profile_configured'=>true,'profile_environment_match'=>true,'profile_origin_match'=>true,
 'adapter_available'=>true,'adapter_identity_ok'=>false,'adapter_read_compatible'=>true,
 'chatgpt_actual_registered'=>true);
$a=MAD4B_SCP_Truth_Projection::session_connection_identity($fact);
check_fact($a['ready'] && empty($a['blockers']),'read compatible operational');
$bad=$fact;$bad['adapter_read_compatible']=false;
$a=MAD4B_SCP_Truth_Projection::session_connection_identity($bad);
check_fact(!$a['ready'] && in_array('mcp_adapter_read_runtime_not_verified',$a['blockers'],true),'read unverified blocked');
$bad=$fact;$bad['chatgpt_actual_registered']=false;
$a=MAD4B_SCP_Truth_Projection::session_connection_identity($bad);
check_fact(!$a['ready'] && in_array('mcp_chatgpt_not_registered',$a['blockers'],true),'actual registration mandatory');
$bad=$fact;$bad['profile_origin_match']=false;
$a=MAD4B_SCP_Truth_Projection::session_connection_identity($bad);
check_fact(!$a['ready'] && in_array('site_profile_origin_drift',$a['blockers'],true),'site identity mandatory');
$bad=$fact;$bad['adapter_available']=false;
$a=MAD4B_SCP_Truth_Projection::session_connection_identity($bad);
check_fact(!$a['ready'] && in_array('mcp_adapter_unavailable',$a['blockers'],true),'adapter unavailable');
echo 'PROVIDER_SESSION_READ_CONTINUITY_PASS'.PHP_EOL;
