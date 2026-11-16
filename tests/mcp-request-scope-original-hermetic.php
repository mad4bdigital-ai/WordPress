<?php
/** Original scoped Endpoint Diagnostic classification only; no MCP bootstrap. */
define('ABSPATH', __DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$msg='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function is_admin(){return true;}
function wp_doing_ajax(){return true;}
function current_user_can($cap){return $GLOBALS['operator_permission'];}
class MAD4B_SCP_Servers {
 static function expected_server_ids(){return ['mad4b-read','mad4b-write'];}
}
class MAD4B_SCP_Provider_Diagnostic_Policy {
 static $allowed=false;
 static function explicit_rest_materialization_allowed(){return self::$allowed;}
}
function req_scope_check($valid,$reason){
 if(!$valid){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
$_POST['action']='mad4b_connection_endpoint_diagnostic';
$GLOBALS['operator_permission']=false;
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mcp-request-scope.php';
$c='MAD4B_SCP_MCP_Request_Scope';
$mu=['contract'=>'mad4b.mcp-adapter-mu-bootstrap.v6',
 'diagnostic_mu_proof_valid'=>true,'diagnostic_server_id'=>'mad4b-read'];
$GLOBALS['mad4b_scp_mcp_mu_bootstrap']=$mu;
req_scope_check($c::current_request_is_endpoint_diagnostic_job()===true,
 'Exact native MCP endpoint diagnostic AJAX route classification remains passive');
req_scope_check($c::endpoint_diagnostic_routing_server_id()==='mad4b-read',
 'Verified original MU bootstrap may return a nonauthorizing routing hint');
foreach([new WP_Error('bootstrap_proof_unknown'),1,'true',null,false,[]] as $bad){
 $GLOBALS['mad4b_scp_mcp_mu_bootstrap']=$mu;
 $GLOBALS['mad4b_scp_mcp_mu_bootstrap']['diagnostic_mu_proof_valid']=$bad;
 req_scope_check($c::endpoint_diagnostic_routing_server_id()==='',
  'Unverified MU bootstrap diagnostic source cannot route to any MCP server');
}
$GLOBALS['mad4b_scp_mcp_mu_bootstrap']=$mu;
foreach([new WP_Error('policy_unverified'),1,'true',null,false] as $bad){
 MAD4B_SCP_Provider_Diagnostic_Policy::$allowed=$bad;
 $GLOBALS['operator_permission']=false;
 req_scope_check(is_wp_error($c::begin_endpoint_diagnostic('mad4b-read')),
  'Unverified native diagnostic-policy permission cannot arm the MCP runtime');
}
MAD4B_SCP_Provider_Diagnostic_Policy::$allowed=true;
foreach([new WP_Error('operator_unverified'),1,'true',null,false] as $bad){
 $GLOBALS['operator_permission']=$bad;
 req_scope_check(is_wp_error($c::begin_endpoint_diagnostic('mad4b-read')),
  'Unverified WordPress operator capability cannot arm MCP runtime');
}
$GLOBALS['operator_permission']=false;
req_scope_check(is_wp_error($c::begin_endpoint_diagnostic('mad4b-read')),
 'No diagnostic route becomes execution authority without positive original operator proof');
echo 'PASS: MCP_REQUEST_SCOPE_ORIGINAL '.($GLOBALS['checks']??0).' original MU proof, operator and endpoint admission cases'.PHP_EOL;
