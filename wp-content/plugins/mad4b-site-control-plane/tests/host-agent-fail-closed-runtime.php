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
require dirname(__DIR__) . '/includes/class-mad4b-scp-context-authority.php';
if (MAD4B_SCP_Host_Agent_Bridge::public_key() !== '') {
    throw new RuntimeException('Unexpected Host signer in source package');
}
$payload = array('contract'=>'mad4b.host-agent-execution.v1','payload'=>array());
$result = MAD4B_SCP_Host_Agent_Bridge::execute($payload);
if (!$result instanceof WP_Error || $result->get_error_code() !== 'mad4b_host_cli_transport_required') {
    throw new RuntimeException('Unenrolled Host should fail closed before dispatch');
}
if (MAD4B_SCP_Host_Agent_Bridge::active() !== false
    || MAD4B_SCP_Host_Agent_Bridge::binding_ready() !== false
    || MAD4B_SCP_Host_Agent_Bridge::active_for('mad4b/context-ai-review') !== false) {
    throw new RuntimeException('Host binding or exact grant silently became active');
}
$policy = MAD4B_SCP_Host_Agent_Bridge::change_review_policy(
    array('mode'=>'ai_only','confirmation'=>'CONFIRM HOST AI REVIEW MODE'));
if (!$policy instanceof WP_Error ||
    $policy->get_error_code() !== 'mad4b_host_policy_not_attested') {
    throw new RuntimeException('Unverified Host must never change review policy');
}
$review = MAD4B_SCP_Host_Agent_Bridge::review(array('asset_id'=>'fixture'));
if (!$review instanceof WP_Error ||
    $review->get_error_code() !== 'mad4b_host_review_not_attested') {
    throw new RuntimeException('Unverified Host must never review Context');
}
$entry = MAD4B_SCP_Context_Authority::review_asset_by_host_agent(
    array('asset_id'=>'fixture'),
    array('id'=>12,'public_id'=>'fixture-agent'), 'fixture-subject');
if (!$entry instanceof WP_Error ||
    $entry->get_error_code() !== 'mad4b_host_review_proof_required') {
    throw new RuntimeException('Context Authority must require fresh Host CLI proof');
}
echo "PASS signed Host Agent refuses non-CLI unauthenticated execution\n";
