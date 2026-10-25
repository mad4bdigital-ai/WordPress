<?php
/* Hermetic: safe first-party error origin code survives generic HTTP 503, foreign data does not. */
define('ABSPATH', __DIR__);
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)$v)); }
function wp_get_environment_type() { return 'staging'; }
class WP_REST_Response {
    private $data; private $status; private $headers = array();
    function __construct($data,$status) { $this->data=$data; $this->status=$status; }
    function get_status() { return $this->status; }
    function get_data() { return $this->data; }
    function header($k,$v) { $this->headers[$k]=$v; }
}
class Fake_Request {
    function get_route() { return '/mcp/mad4b-chatgpt'; }
    function get_method() { return 'POST'; }
}
class MAD4B_SCP_Servers {
    static function expected_server_ids() { return array('mad4b-chatgpt'); }
}
class MAD4B_Native_Incident_MU {
    static function active() { return true; }
    static function correlation_id() { return str_repeat('a', 32); }
}
function get_option($key,$fallback=array()) { return $fallback; }
$GLOBALS['captured_event']=null;
function update_option($key,$value,$autoload=false) { $GLOBALS['captured_event']=$value['events'][0] ?? null; return true; }
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-mcp-transport-admission.php';
$req=new Fake_Request();
$r=new WP_REST_Response(array('code'=>'mad4b_oauth_evaluation_exception','message'=>'PRIVATE SECRET'),503);
MAD4B_SCP_MCP_Transport_Admission::annotate_response($r,null,$req);
$event=$GLOBALS['captured_event'];
if (!is_array($event) || $event['code']!=='mad4b_oauth_evaluation_exception' || strpos(json_encode($event),'PRIVATE')!==false)
  throw new RuntimeException('First-party origin not recorded safely');
echo "PASS: MCP_ORIGIN_ERROR_PRESERVATION_RUNTIME\n";
