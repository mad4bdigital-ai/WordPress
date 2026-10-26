<?php
define('ABSPATH', __DIR__);
define('MAD4B_MCP_DEVELOPER_RUNNER_DISCOVERY_URL', 'https://runner.example.test/v1/discovery');
define('MAD4B_MCP_DEVELOPER_RUNNER_DISCOVERY_ORIGIN', 'https://runner.example.test');
$GLOBALS['cache'] = array(); $GLOBALS['calls'] = 0;
function wp_get_environment_type() { return 'staging'; }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; return true; }
function wp_safe_remote_get($url, $args) {
 $GLOBALS['calls']++;
 if ($url !== MAD4B_MCP_DEVELOPER_RUNNER_DISCOVERY_URL || $args['redirection'] !== 0 || $args['limit_response_size'] > 2048) throw new RuntimeException('unsafe discovery fetch');
 return array('status'=>200, 'body'=>json_encode(array('contract'=>'mad4b.runner-discovery.v1','backends'=>array('isolated_vps_runner'=>array('available'=>true),'isolated_docker_runner'=>array('available'=>true)))));
}
function is_wp_error($x) {return false;}
function wp_remote_retrieve_response_code($r) {return $r['status'];}
function wp_remote_retrieve_body($r) {return $r['body'];}
function wp_parse_url($url) {return parse_url($url);}
require dirname(__DIR__).'/includes/class-mad4b-scp-developer-host-capabilities.php';
if(!MAD4B_SCP_Developer_Host_Capabilities::lifecycle_refresh()) throw new RuntimeException('first fetch failed');
$s=MAD4B_SCP_Developer_Host_Capabilities::external_backends();
if(empty($s['isolated_docker_runner']['live_available_observed'])) throw new RuntimeException('docker discovery missing');
if(empty($s['isolated_vps_runner']['live_available_observed'])||!empty($s['isolated_vps_runner']['execution_allowed'])) throw new RuntimeException('discovery must never grant authority');
if(MAD4B_SCP_Developer_Host_Capabilities::lifecycle_refresh() || $GLOBALS['calls'] !== 1) throw new RuntimeException('cooldown bypass');
echo "PASS: DEVELOPER_RUNNER_LIFECYCLE_FETCH_FAIL_CLOSED\n";
