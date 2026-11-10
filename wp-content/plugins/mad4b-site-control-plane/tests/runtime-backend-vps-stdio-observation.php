<?php
declare(strict_types=1);
define('ABSPATH', '/test/');
$GLOBALS['probe'] = null;
function get_transient($name) {
    return 'mad4b_vps_mcp_stdio_probe_v1' === $name ? $GLOBALS['probe'] : false;
}
function get_option($name,$default=null) { return $default; }
final class MAD4B_SCP_Site_Profile {
    public static function status() { return array(
        'contract' => 'mad4b.site-profile.v2',
        'site_uuid' => 'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
        'canonical_origin' => 'https://tours.dev.mad4b.com',
        'environment' => 'staging',
        'origin_match' => true,
        'environment_match' => true,
        'deployment_binding_match' => true
    ); }
}
final class MAD4B_SCP_Developer_Host_Capabilities {
    public static function snapshot() { return array(
        'normal_no_network_execution_ready' => true,
        'non_root_verified' => true,
        'external_backends' => array()
    ); }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-runtime-backend-registry.php';
function prove($value,$label) { if (!$value) throw new RuntimeException($label); }
$c='MAD4B_SCP_Runtime_Backend_Registry';
prove(in_array('vps_stdio',$c::TYPES,true),'stdio type must be valid');
$unverified = $c::builtin_candidates(array())['isolated_vps_runner'];
prove($unverified['type']==='vps_stdio','transport type is not HTTPS');
prove($unverified['reachable']===false,'missing proof cannot register reachability');
prove($unverified['authorized']===false,'missing proof cannot authorize write');
$GLOBALS['probe'] = array(
 'contract'=>'mad4b.vps-mcp-stdio-read-probe.v1',
 'site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
 'canonical_origin'=>'https://tours.dev.mad4b.com',
 'environment'=>'staging',
 'observed_at'=>time(),
 'tool_count'=>90,
 'read_tool_name'=>'mad4b-content-job-list',
 'readback_sha256'=>str_repeat('a',64),
 'read_tool_verified'=>true,
 'oauth_used'=>false,
 'mutation_performed'=>false,
);
prove($c::local_stdio_read_observation()===true,'recent exact read proof');
$candidate=$c::builtin_candidates(array())['isolated_vps_runner'];
prove($candidate['discovered']===true&&$candidate['configured']===true&&$candidate['reachable']===true,'observed live transport not registered');
prove($candidate['read_probe_observed']===true,'observed read proof not projected');
prove($candidate['certified']===false&&$candidate['authorized']===false,'read must not grant permission');
$view=$c::snapshot($c::builtin_candidates(array()),'vps');
prove($view['backends']['isolated_vps_runner']['read_probe_observed']===true,'snapshot proof missing');
prove($view['backends']['isolated_vps_runner']['ready']===false,'no automatic write/read authority');
prove($c::select($c::builtin_candidates(array()),'runtime.status.read','vps')['selected']===null,'unverified host actor became authorized');
$GLOBALS['probe']['site_uuid']='00000000-0000-4000-8000-000000000001';
prove($c::local_stdio_read_observation()===false,'cross-site observation accepted');
$GLOBALS['probe']['site_uuid']='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
$GLOBALS['probe']['observed_at']=time()-301;
prove($c::local_stdio_read_observation()===false,'expired read observation accepted');
$GLOBALS['probe']['observed_at']=time();
$GLOBALS['probe']['mutation_performed']=true;
prove($c::local_stdio_read_observation()===false,'mutating probe accepted');
echo "PASS: VPS_STDIO_BACKEND_DISCOVERED_NOT_AUTHORIZED\n";
