<?php
/** Hermetic: no WordPress, database, Host or network writes permitted. */
define('ABSPATH', __DIR__);
class WP_Error {
    private $code;
    public function __construct($code, $message='', $data=array()) { $this->code=$code; }
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_current_user_id(){return 0;}
function current_user_can($cap){return false;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
require dirname(__DIR__).'/includes/class-mad4b-scp-native-host-source-release-review.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-original305-incident-review.php';
function check_denial($result,$expected,$context) {
    if (!$result instanceof WP_Error || $result->get_error_code()!==$expected) {
        throw new RuntimeException("Denied expected: ".$context." ".$expected);
    }
}
$release=['ability_name'=>'mad4b/host-source-release','git_commit'=>str_repeat('a',40),
    'host_scope'=>'host:demo-company', 'operation_id'=>'host.source.release',
    'owner_catalog_sha256'=>str_repeat('b',64),'owner_id'=>'demo-company',
    'plan_sha256'=>str_repeat('c',64)];
if (MAD4B_SCP_Native_Host_Source_Release_Review::request_input($release)!==$release)
    throw new RuntimeException('valid exact request changed');
$extra=$release;$extra['wp_user_id']='1';
check_denial(MAD4B_SCP_Native_Host_Source_Release_Review::request_input($extra),
    'mad4b_host_source_review_shape','unexpected WP identity');
check_denial(MAD4B_SCP_Native_Host_Source_Release_Review::can_execute($release),
    'mad4b_host_source_native_issuer_not_enrolled','native Host issuer');
check_denial(MAD4B_SCP_Native_Host_Source_Release_Review::execute($release),
    'mad4b_host_source_native_issuer_not_enrolled','direct Host operation');
check_denial(MAD4B_SCP_Original305_Incident_Review::deny_direct_execution(),
    'mad4b_original305_direct_execution_denied','direct ticket');
check_denial(MAD4B_SCP_Original305_Incident_Review::can_create(),
    'mad4b_original305_admin_required','unauthenticated ticket creation');
check_denial(MAD4B_SCP_Original305_Incident_Review::can_decide(),
    'mad4b_original305_admin_required','unauthenticated ticket decision');
$incident=['action'=>'review_legacy_ambiguous_signed_write',
    'approval_ability'=>'mad4b/original305-incident-review',
    'contract'=>'mad4b.original305-incident-closure-review-request.v2',
    'host_release_authorized'=>false,
    'legacy_hold_sha256'=>str_repeat('0',64),
    'operation_id'=>'host.source.release',
    'registry_sha256'=>str_repeat('1',64),
    'service_unit_sha256'=>str_repeat('2',64),
    'state'=>'REQUIRES_FIRSTPARTY_SIGNED_MASTER_REVIEW',
    'supervisor_sha256'=>str_repeat('3',64)];
ksort($incident,SORT_STRING);
$incident['request_sha256']=hash('sha256',wp_json_encode($incident,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
if (MAD4B_SCP_Original305_Incident_Review::exact_review_request($incident)!==$incident)
    throw new RuntimeException('exact incident scope wrongly rejected');
$incident['host_release_authorized']=true;
check_denial(MAD4B_SCP_Original305_Incident_Review::exact_review_request($incident),
    'mad4b_original305_review_scope_invalid','host escalation forbidden');
echo "PASS Original305 and Host source review bounded input, no direct host authority, no unauthenticated ticket\n";
