<?php
/** Isolated regression: run through WP CLI/PHP inside a disposable local lab only. */
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-chatgpt';
$_SERVER['REQUEST_METHOD'] = 'POST';
require_once '/var/www/html/wp-load.php';
rest_get_server();
if (wp_get_environment_type() !== 'local') { fwrite(STDERR, "SKIP: local only\n"); exit(2); }
$pass = 0; $fail = 0;
$check = static function($name, $valid) use (&$pass, &$fail) { echo $name . ': ' . ($valid ? 'PASS' : 'FAIL') . "\n"; $valid ? ++$pass : ++$fail; };
$policy = MAD4B_SCP_Context_Authority::review_policy();
$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id((string)($policy['ai_agent_public_id'] ?? ''));
$login = 'mad4b_connection_' . substr(hash('sha256', MAD4B_SCP_Site_Profile::site_uuid()), 0, 12);
$user = get_user_by('login', $login);
if (!is_array($agent) || !$user || !in_array('administrator', (array)$user->roles, true)) { fwrite(STDERR,"SKIP: requires existing integration administrator and configured agent\n"); exit(2); }
$grant = MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'], 'mad4b-write', 'mad4b/context-ai-review', 'core');
if (!is_array($grant) || ($grant['effect'] ?? '') !== 'allow') { fwrite(STDERR,"SKIP: exact grant unavailable\n"); exit(2); }
$token = bin2hex(random_bytes(32));
$key = 'mad4b_pwless_mcp_' . hash('sha256', $token);
$fingerprint = hash('sha256', 'mad4b_pwless_mcp' . chr(0) . MAD4B_SCP_Site_Profile::site_uuid() . chr(0) . $agent['public_id']);
$request = new WP_REST_Request('POST', '/mcp/mad4b-chatgpt');
$request->set_header('Authorization','Bearer ' . $token);
$make = static function(array $overrides = []) use ($agent,$fingerprint,$user) {
 return array_replace(['site'=>MAD4B_SCP_Site_Profile::site_uuid(),'agent'=>$agent['public_id'],'fingerprint'=>$fingerprint,'wp_user_id'=>(int)$user->ID],$overrides);
};
try {
 $check('unknown_token_denied', MAD4B_SCP_Passwordless_Connection::verify_mcp($request) === false);
 $binding = MAD4B_SCP_Agent_Registry::bind_subject($agent['public_id'], 'mad4b_pwless_mcp', $fingerprint, 'Local test-bound identity');
 if ($binding !== true) { echo "bind_subject: FAIL\n"; exit(1); }
 set_transient($key, $make(), 120);
 $check('valid_token_accepted', MAD4B_SCP_Passwordless_Connection::verify_mcp($request) === true);
 $check('admin_capability', current_user_can('manage_options'));
 set_transient($key, $make(), 120);
 $session_id = '';
 foreach (['initialize', 'tools/list'] as $method) {
   $rpc = new WP_REST_Request('POST', '/mcp/mad4b-chatgpt');
   $rpc->set_header('Authorization', 'Bearer ' . $token);
   $rpc->set_header('Content-Type', 'application/json');
   if ($session_id !== '') { $rpc->set_header('Mcp-Session-Id', $session_id); $rpc->set_header('MCP-Protocol-Version', '2025-11-25'); }
   $params = $method === 'initialize' ? ['protocolVersion'=>'2025-03-26','capabilities'=>new stdClass(),'clientInfo'=>['name'=>'mad4b-local-test','version'=>'1']] : new stdClass();
   $rpc->set_body(wp_json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]));
   $result = rest_do_request($rpc);
   $status = (int)$result->get_status();
   if ($method === 'initialize') $session_id = (string) ($result->get_headers()['Mcp-Session-Id'] ?? $result->get_headers()['mcp-session-id'] ?? '');
   $check('rest_' . str_replace('/','_',$method) . '_not_auth_denied', $status >= 200 && $status < 300);
   $error_data = (array) $result->get_data();
   echo 'rest_' . str_replace('/','_',$method) . '_status=' . $status . ' details=' . substr(wp_json_encode($error_data),0,350) . "\n";
 }

 $context = apply_filters('mad4b_scp_authenticated_subject_context', ['authenticated'=>false]);
 $check('agent_identity_context', ($context['subject_type'] ?? '') === 'mad4b_pwless_mcp' && ($context['wp_user_id'] ?? null) === (int)$user->ID);
 set_transient($key, $make(['site'=> 'wrong-site-uuid']), 120);
 $check('foreign_site_denied', MAD4B_SCP_Passwordless_Connection::verify_mcp($request) === false);
 set_transient($key, $make(['wp_user_id'=>0]), 120);
 $check('missing_admin_denied', MAD4B_SCP_Passwordless_Connection::verify_mcp($request) === false);
 delete_transient($key);
 $check('revoked_token_denied', MAD4B_SCP_Passwordless_Connection::verify_mcp($request) === false);
 $other = new WP_REST_Request('POST','/mcp/mad4b-developer');
 $other->set_header('Authorization', 'Bearer ' . $token);
 $check('developer_route_denied', MAD4B_SCP_Passwordless_Connection::verify_mcp($other) === false);
} finally { delete_transient($key); wp_set_current_user(0); }
echo 'SUMMARY pass=' . $pass . ' fail=' . $fail . "\n";
exit($fail ? 1 : 0);
