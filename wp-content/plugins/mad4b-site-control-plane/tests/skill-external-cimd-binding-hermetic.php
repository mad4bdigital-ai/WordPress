<?php
/** Original OAuth policy, connected to both Skill external observation call sites. */
define('ABSPATH',__DIR__.'/');
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function check($condition,$label){
 if(!$condition){fwrite(STDERR,"FAIL: $label\n");exit(1);}
 echo 'PASS '.$label.PHP_EOL;
}
require dirname(__DIR__).'/includes/class-mad4b-scp-local-oauth-server.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-skill-abilities.php';
$server='MAD4B_SCP_Local_OAuth_Server';
$legacy=$server::CHATGPT_CIMD_CLIENT_ID;
$scoped='https://chatgpt.com/oauth/connector_example_123456/client.json';
$foreign='https://evil.example/oauth/connector_example_123456/client.json';
check($server::trusted_chatgpt_client_ids_match($scoped,$scoped),
 'same_legitimate_connector_observer_allowed');
check($server::trusted_chatgpt_client_ids_match($legacy,$legacy),
 'same_legacy_observer_allowed');
check(!$server::trusted_chatgpt_client_ids_match($scoped,$legacy),
 'distinct_trusted_clients_cannot_be_conflated');
check(!$server::trusted_chatgpt_client_ids_match($scoped,$foreign),
 'foreign_observer_client_denied');
check(!$server::trusted_chatgpt_client_ids_match($scoped,''),
 'missing_observer_client_denied');
check($server::trusted_chatgpt_client_id($scoped),
 'scoped_external_client_supported');
check(!$server::trusted_chatgpt_client_id($foreign),
 'foreign_external_client_denied');
check(!$server::trusted_chatgpt_client_id('https://chatgpt.com:443/oauth/connector_example_123456/client.json'),
 'noncanonical_port_denied');
check(!$server::trusted_chatgpt_client_id('https://chatgpt.com/oauth/connector_example_123456/client.json?debug=1'),
 'query_tamper_denied');
check(!$server::trusted_chatgpt_client_id('https://chatgpt.com/oauth/short/client.json'),
 'short_opaque_client_path_denied');
$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-skill-abilities.php');
$finalize=explode('public static function finalize_external_snapshot_observation() {',$source,2);
check(count($finalize)===2,'source_snapshot_finalizer_present');
$finalize=explode('public static function snapshot_attestation_from_tools(',$finalize[1],2)[0];
check(strpos($finalize,'MAD4B_SCP_Local_OAuth_Server::trusted_chatgpt_client_ids_match(')!==false,
 'finalizer_uses_independent_exact_two_observer_client_equality');
check(strpos($finalize,'$observer[\'client_id\']')!==false
 && strpos($finalize,'$handshake[\'client_id\']')!==false,
 'finalizer_compares_both_observer_identities');
check(strpos($finalize,'self::SERVER_ID !==')!==false,'finalizer_binds_server_id');
$status=explode('public static function dynamic_snapshot_status() {',$source,2);
check(count($status)===2,'source_snapshot_status_present');
$status=explode('public static function ',$status[1],2)[0];
check(strpos($status,'MAD4B_SCP_Local_OAuth_Server::trusted_chatgpt_client_id(')!==false,
 'status_uses_original_cimd_allowlist');
check(strpos($status,'self::SERVER_ID !==')!==false,'status_binds_server_id');
check(strpos($status,'external_client_server_binding_mismatch')!==false,
 'status_retains_original_failure_state');
check(!function_exists('update_option') && !function_exists('wp_mail'),
 'no_wordpress_mutation_or_mail_runtime_in_fixture');
echo 'SKILL_SCOPED_CIMD_CLIENT_BINDING: PASS (no grants, no writes)'.PHP_EOL;
