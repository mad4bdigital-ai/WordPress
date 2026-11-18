<?php
/** Unenrolled Host transport must reject synthetic write or review requests. */
define('ABSPATH', __DIR__);
class WP_Error {
    private $code;
    public function __construct($code, $message='') { $this->code=$code; }
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v) { return $v instanceof WP_Error; }
require dirname(__DIR__) . '/includes/class-mad4b-scp-host-agent-bridge.php';
if (MAD4B_SCP_Host_Agent_Bridge::public_key() !== '') {
    throw new RuntimeException('Unexpected Host signer in source package');
}
$payload = array('contract'=>'mad4b.host-agent-execution.v1','payload'=>array());
$result = MAD4B_SCP_Host_Agent_Bridge::execute($payload);
if (!$result instanceof WP_Error || $result->get_error_code() !== 'mad4b_host_cli_transport_required') {
    throw new RuntimeException('Unenrolled Host should fail closed before dispatch');
}
echo "PASS signed Host Agent refuses non-CLI unauthenticated execution\n";
