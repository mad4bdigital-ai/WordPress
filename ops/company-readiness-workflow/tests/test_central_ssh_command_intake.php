<?php
/** Standalone boundary tests: one-time central grant != SSH host privilege. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
define('DB_NAME', 'etg_commerce_staging');
$GLOBALS['store'] = array();
$GLOBALS['actor'] = true;
$GLOBALS['delegation'] = true;
$GLOBALS['nonce'] = true;
function add_action(...$args) {}
function register_rest_route(...$args) {}
function wp_get_environment_type() { return 'staging'; }
function home_url($url = '/') { return 'https://tours.dev.mad4b.com' . $url; }
function untrailingslashit($value) { return rtrim($value, '/'); }
function is_user_logged_in() { return $GLOBALS['actor']; }
function get_current_user_id() { return $GLOBALS['actor'] ? 1 : 0; }
function current_user_can($cap) { return $GLOBALS['actor'] && $cap === 'manage_options'; }
function wp_verify_nonce($nonce, $action) { return $GLOBALS['nonce'] && $nonce === 'valid'; }
function sanitize_text_field($value) { return trim($value); }
function get_option($key, $default = false) { return $GLOBALS['store'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['store'][$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($key, $GLOBALS['store'])) return false;
    $GLOBALS['store'][$key] = $value; return true;
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function rest_ensure_response($value) { return new WP_REST_Response($value); }
class WP_Error {
    private string $code;
    public function __construct($code, $message, $data = array()) {$this->code = $code;}
    public function get_error_code() {return $this->code;}
}
class WP_REST_Response {
    private $data; public function __construct($data, $status = 200) {$this->data=$data;}
    public function get_data() {return $this->data;}
}
class WP_REST_Request implements ArrayAccess {
    private array $params; private array $json;
    public function __construct(array $params = array(), array $json = array(), private string $nonce = 'valid') {
        $this->params = $params; $this->json = $json;
    }
    public function offsetGet($offset): mixed {return $this->params[$offset] ?? null;}
    public function offsetExists($offset): bool {return isset($this->params[$offset]);}
    public function offsetSet($offset, $value): void {$this->params[$offset] = $value;}
    public function offsetUnset($offset): void {unset($this->params[$offset]);}
    public function get_json_params() {return $this->json;}
    public function get_header($header) {return $header === 'X-WP-Nonce' ? $this->nonce : '';}
    public function get_param($field) {return $this->params[$field] ?? null;}
}
class MAD4B_SCP_Site_Profile {
    public static function origin_enrolled() {return true;}
    public static function site_urls_match_enrollment() {return true;}
    public static function user_is_enrolled($id) {return $id === 1;}
}
class MAD4B_Company_Operation_Admission_V1 {
    public static function assess($company, $intent) {
        return array('policy_delegation_admitted'=>$GLOBALS['delegation'],
          'company_site_uuid'=>'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b');
    }
    public static function manager_id() {return 'manager-id';}
}
class MAD4B_Manager_Auto_Write_V1 {
    public static function company_target($company) {
        return array('state'=>'REGISTERED','site_uuid'=>'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b',
          'canonical_origin'=>'https://wayfatours.mad4b.com','environment'=>'production');
    }
    public static function entitlement($manager, $company, $operation, $user) {
        return array('assignment_authorized'=>$GLOBALS['delegation'],'assignment_revision'=>3);
    }
}
require __DIR__.'/../staging-mu/304-mad4b-central-dual-lane-registry.php';
require __DIR__.'/../staging-mu/305-mad4b-central-ssh-command-intake.php';
function check($condition, $why) {if (!$condition) throw new RuntimeException($why);}
$valid = new WP_REST_Request(array('company'=>'wayfatours'),array(
    'operation'=>'maintenance.shell','host_id'=>'srv2030908','host_principal'=>'root',
    'ttl_seconds'=>300,'reason'=>'Repair company configuration during recovery'));
$issued = MAD4B_Central_SSH_Command_Intake_V1::issue($valid);
check($issued instanceof WP_REST_Response, 'valid owner grant');
$grant = $issued->get_data();
check($grant['state'] === 'CENTRAL_OPERATION_SCOPE_GRANTED', 'grant scope issued');
check($grant['execution_authorized'] === false && $grant['host_ssh_authority_verified'] === false, 'no invented host grant');
check($grant['site_uuid'] === 'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b', 'exact company scope');
$command = new WP_REST_Request(array('company'=>'wayfatours'),array(
    'grant_id'=>$grant['grant_id'],'command'=>'printf test-ssh-intake',
    'summary'=>'Read-only probe of SSH integration',
    'correlation_id'=>'mad4b-test-123456','target_scope'=>'wordpress'));
$accepted = MAD4B_Central_SSH_Command_Intake_V1::accept_command($command);
check($accepted instanceof WP_REST_Response, 'command accepted');
$row = $accepted->get_data();
check($row['status'] === 'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY', 'pending host authority');
check($row['command_sha256'] === hash('sha256','printf test-ssh-intake'), 'exact bytes hash');
check($row['execution_performed'] === false && $row['command_bytes_persisted'] === false, 'no false execution');
check(!str_contains(json_encode($GLOBALS['store']), 'printf test-ssh-intake'), 'never persist shell bytes');
$replay = MAD4B_Central_SSH_Command_Intake_V1::accept_command($command);
check(is_wp_error($replay) && $replay->get_error_code() === 'mad4b_ssh_intake_one_time_grant_already_claimed', 'one-time claim');
$wrong = new WP_REST_Request(array('company'=>'other-company'),$command->get_json_params());
check(is_wp_error(MAD4B_Central_SSH_Command_Intake_V1::accept_command($wrong)), 'cross-company denied');
$GLOBALS['nonce'] = false;
check(is_wp_error(MAD4B_Central_SSH_Command_Intake_V1::issue($valid)), 'missing nonce rejected');
$GLOBALS['nonce'] = true;
$GLOBALS['delegation'] = false;
check(is_wp_error(MAD4B_Central_SSH_Command_Intake_V1::issue($valid)), 'revoked delegation denied');
$GLOBALS['delegation'] = true;
$GLOBALS['actor'] = false;
check(is_wp_error(MAD4B_Central_SSH_Command_Intake_V1::issue($valid)), 'guest denied');
$GLOBALS['actor'] = true;
$transport = new WP_REST_Request(array('company'=>'wayfatours'),array(
    'host_id'=>'srv2030908','host_principal'=>'root',
    'host_key_fingerprint'=>'SHA256:'.str_repeat('A',43),
    'credential_ref'=>'vault:mad4b/wayfatours-ssh',
    'reason'=>'Enroll centrally managed persistent SSH connection'));
$registered = MAD4B_Central_SSH_Command_Intake_V1::register_transport($transport);
check($registered instanceof WP_REST_Response, 'persistent SSH transport registered');
$transportRow = $registered->get_data();
check($transportRow['state'] === 'PENDING_INDEPENDENT_SSH_VERIFICATION', 'transport pending verification');
check($transportRow['ssh_session_active'] === false && $transportRow['host_authority_verified'] === false,
    'registration never claims live host SSH privilege');
check($transportRow['ssh_secret_stored_in_wordpress'] === false, 'secret not stored');
$persistent = new WP_REST_Request(array('company'=>'wayfatours'),array(
    'operation'=>'maintenance.shell','host_id'=>'srv2030908','host_principal'=>'root',
    'grant_mode'=>'persistent','transport_id'=>$transportRow['transport_id'],
    'reason'=>'Long-lived scoped WordPress company maintenance authority'));
$p = MAD4B_Central_SSH_Command_Intake_V1::issue($persistent);
check($p instanceof WP_REST_Response, 'persistent grant accepted');
$pData = $p->get_data();
check($pData['grant_mode'] === 'persistent' && $pData['expires_epoch'] === null,
    'persistent grant has no TTL');
for ($i=1; $i<=2; $i++) {
    $req = new WP_REST_Request(array('company'=>'wayfatours'),array(
        'grant_id'=>$pData['grant_id'],'command'=>'printf safe-command-'.$i,
        'summary'=>'Host diagnostic command intake number '.$i,
        'correlation_id'=>'mad4b-persistent-correlation-'.$i,
        'target_scope'=>'wordpress'));
    $ok = MAD4B_Central_SSH_Command_Intake_V1::accept_command($req);
    check($ok instanceof WP_REST_Response, 'persistent grant reused for distinct correlation '.$i);
    $info = $ok->get_data();
    check($info['grant_mode'] === 'persistent' && $info['execution_performed'] === false,
        'persistent command not executed by registry');
    check(is_wp_error(MAD4B_Central_SSH_Command_Intake_V1::accept_command($req)),
        'duplicate correlation denied');
}
$revokeGrant = new WP_REST_Request(array('company'=>'wayfatours',
    'kind'=>'grant','object_id'=>$pData['grant_id']));
$revokeResult = MAD4B_Central_SSH_Command_Intake_V1::revoke($revokeGrant);
check($revokeResult instanceof WP_REST_Response
    && $revokeResult->get_data()['state'] === 'REVOKED', 'persistent grant revoked');
$afterGrantRevoke = new WP_REST_Request(array('company'=>'wayfatours'),array(
    'grant_id'=>$pData['grant_id'],'command'=>'printf should-not-claim',
    'summary'=>'Attempt after grant revocation must fail',
    'correlation_id'=>'mad4b-correlation-revoked-1',
    'target_scope'=>'wordpress'));
check(is_wp_error(MAD4B_Central_SSH_Command_Intake_V1::accept_command($afterGrantRevoke)),
    'revoked persistent grant denied');
$revokeTransport = new WP_REST_Request(array('company'=>'wayfatours',
    'kind'=>'transport','object_id'=>$transportRow['transport_id']));
check(MAD4B_Central_SSH_Command_Intake_V1::revoke($revokeTransport) instanceof WP_REST_Response,
    'persistent SSH transport revocable');
check(is_wp_error(MAD4B_Central_SSH_Command_Intake_V1::issue($persistent)),
    'no grant issued against revoked transport');
check(!str_contains(json_encode($GLOBALS['store']), 'printf safe-command-'),
    'persistent commands never stored as raw bytes');
echo "PASS: one-time + persistent grants, SSH configuration, reuse across correlations, revocation, tenant/auth/binding guards; no host privilege invented\n";
