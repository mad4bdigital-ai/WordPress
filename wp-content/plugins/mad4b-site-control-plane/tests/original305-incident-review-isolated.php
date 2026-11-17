<?php
/** Isolated PHP test runner: source inclusion is replaced by the host test harness. */
error_reporting(E_ALL);
define('ABSPATH','/test/');
define('LOGGED_IN_COOKIE','wordpress_logged_in_test');
class WP_Error {
    public $code; public $message;
    public function __construct($c,$m,$data=array()){ $this->code=$c; $this->message=$m; }
    public function get_error_code(){ return $this->code; }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_option($key,$fallback=false){return $GLOBALS['o305_test_snapshot']??$fallback;}
function admin_url($path=''){return 'https://example.test/wp-admin/'.$path;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
class MAD4B_Original_Native_Operation_Providers_V1 {
    static function legacy_admitted($operation,$target,$scope){return !empty($GLOBALS['o305_test_signed']);}
}
function wp_has_ability($name){return $name==='mad4b/original305-incident-review';}
function wp_register_ability($name,$args){return array('registered'=>true);}
function get_current_user_id(){return $GLOBALS['o305_test_user_id'] ?? 17;}
function current_user_can($ability){return get_current_user_id()>0 && in_array($ability,array('manage_options','activate_plugins'),true);}
class O305AjaxReply extends RuntimeException {
    public $data;public $code;public $success;
    function __construct($success,$data,$code){parent::__construct('ajax response');$this->success=$success;$this->data=$data;$this->code=$code;}
}
function wp_send_json_success($data,$code=200){throw new O305AjaxReply(true,$data,$code);}
function wp_send_json_error($data,$code=500){throw new O305AjaxReply(false,$data,$code);}
function check_ajax_referer($action,$field,$die=true){return $action==='mad4b_original305_readiness_status' && $field==='_ajax_nonce' && ($GLOBALS['o305_test_ajax_nonce']??false);}
function nocache_headers(){}
function wp_validate_auth_cookie($cookie,$scheme){return $cookie==='cookie-good'?17:false;}
function wp_get_session_token(){return 'wp-session-good';}
function wp_verify_nonce($nonce,$action){return $nonce==='nonce-good' && $action==='wp_rest';}
function add_action($a,$b,$p=10){}
function register_rest_route($a,$b,$c){}
function add_management_page($a,$b,$c,$d,$e){}
function wp_json_encode_unchanged($v){return json_encode($v);}
class WP_REST_Server {const READABLE='GET';const CREATABLE='POST';}
class MAD4B_SCP_Policy {static function can_plan_mutations(){return true;}static function can_approve_mutations(){return true;}}
class MAD4B_SCP_Agent_Registry {
    static function get_agent_by_public_id($id){return $id==='agent-public-test' ? array('id'=>55,'status'=>'enabled','public_id'=>$id):null;}
    static function get_agent_by_id($id){return $id===55 ? array('id'=>55,'status'=>'enabled','public_id'=>'agent-public-test'):null;}
}
class MAD4B_SCP_Approval_Impact_Binding {}
class MAD4B_SCP_Approval_Tickets {
    static $ticket; static $created=0;static $decided=0;
    static function create_pending($agent,$server,$ability,$provider,$target,$input,$kind,$reason,$ttl){
        self::$created++;
        self::$ticket=array('ticket_id'=>'o305-ticket-test-01','status'=>'pending','payload_sha256'=>hash('sha256','payload-fixture'),
            'ability_name'=>$ability,'provider'=>$provider,'server_id'=>$server,'ticket_class'=>$kind,
            'agent_id'=>55,'target_fingerprint'=>$target,'expires_at'=>'2026-11-17 00:00:00','approved_by'=>0);
        return self::$ticket;
    }
    static function bind_ticket_to_current_candidate($id){return array('binding_ready'=>true);}
    static function get($id){return $id==='o305-ticket-test-01'?self::$ticket:null;}
    static function canonical_payload_hash($a,$s,$b,$p,$t,$i,$c){return self::$ticket['payload_sha256'];}
    static function decide_pending($id,$decision,$sha,$context){
        self::$decided++;
        self::$ticket['status']=$decision==='approve'?'approved':'revoked';
        self::$ticket['approved_by']=17;
        return self::$ticket;
    }
}
/*__ORIGINAL305_REVIEW_CLASS_SOURCE__*/
final class O305TestRequest implements ArrayAccess {
    private $params;private $ticket;
    function __construct($p,$id='o305-ticket-test-01'){$this->params=$p;$this->ticket=$id;}
    function get_json_params(){return $this->params;}
    function offsetExists($o):bool{return $o==='ticket_id';}
    function offsetGet($o):mixed{return $o==='ticket_id'?$this->ticket:null;}
    function offsetSet($o,$v):void{}
    function offsetUnset($o):void{}
}
function assert_it($ok,$message){if(!$ok){fwrite(STDERR,'FAIL '.$message."\n");exit(1);}echo 'PASS '.$message."\n";}
function request_fixture(){
    $p=array('contract'=>'mad4b.original305-incident-closure-review-request.v2',
    'action'=>'review_legacy_ambiguous_signed_write','approval_ability'=>'mad4b/original305-incident-review',
    'operation_id'=>'host.source.release','host_release_authorized'=>false,
    'legacy_hold_sha256'=>str_repeat('a',64),'registry_sha256'=>str_repeat('b',64),
    'supervisor_sha256'=>str_repeat('c',64),'service_unit_sha256'=>str_repeat('d',64),
    'state'=>'REQUIRES_FIRSTPARTY_SIGNED_MASTER_REVIEW');
    $stable=$p;ksort($stable,SORT_STRING);$p['request_sha256']=hash('sha256',wp_json_encode($stable,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return $p;
}
$cls='MAD4B_SCP_Original305_Incident_Review';$p=request_fixture();
assert_it($cls::exact_review_request($p)===$p,'valid exact V2 hash');
assert_it(is_wp_error($cls::deny_direct_execution()),'ability cannot directly execute review or mutate host');
$status=$cls::status();
assert_it($status['observation']['fresh']===false && $status['host_release_authorized']===false,'no fabricated automatic Host observation');
$GLOBALS['o305_test_snapshot']=array('contract'=>'mad4b.original305-diagnostic-snapshot.v1',
    'observation_epoch'=>time()-5,'expires_epoch'=>time()+895,
    'state'=>'LEGACY_AMBIGUOUS_WRITE_HOLD_NEEDS_GOVERNED_RECONCILIATION',
    'legacy_hold_present'=>true,'timer_enabled'=>false,
    'original_v8_registry_exact'=>true,'signed_readiness_admitted'=>false,
    'hold_evidence_consistency_verified'=>true,
    'wordpress_readback_available'=>true,
    'wordpress_readback_state'=>'SIGNED_LEASE_EXPIRED_OR_UNADMITTED');
$status=$cls::status();
assert_it($status['observation']['fresh']===true&&$status['observation']['legacy_hold_present']===true,
    'automatic Host observation is visible but does not close HOLD');
assert_it($status['incident_closed']===false && !$status['host_release_authorized'],
    'read-only automatic dashboard never authorizes publishing');
$GLOBALS['o305_test_user_id']=0;
try {$cls::ajax_readiness_status(); assert_it(false,'anonymous AJAX access must be denied');}
catch(O305AjaxReply $r){assert_it(!$r->success && $r->code===403,
    'anonymous admin-ajax status cannot disclose Original305 telemetry');}
$GLOBALS['o305_test_user_id']=17;
$GLOBALS['o305_test_ajax_nonce']=false;
try {$cls::ajax_readiness_status(); assert_it(false,'invalid nonce must be denied');}
catch(O305AjaxReply $r){assert_it(!$r->success && $r->code===403 &&
    $r->data['code']==='mad4b_original305_nonce_invalid',
    'admin-ajax status rejects missing or invalid nonce');}
$GLOBALS['o305_test_ajax_nonce']=true;
try {$cls::ajax_readiness_status(); assert_it(false,'successful AJAX should return a response');}
catch(O305AjaxReply $r){assert_it($r->success && $r->code===200 &&
    $r->data['observation']['legacy_hold_present']===true &&
    $r->data['host_release_authorized']===false,
    'authorized admin-ajax returns read-only telemetry with no release authority');}
unset($GLOBALS['o305_test_ajax_nonce'],$GLOBALS['o305_test_user_id']);
$health=$cls::operational_health();
assert_it($health['status']==='critical'&&$health['code']==='ORIGINAL305_HISTORICAL_HOLD',
    'historical HOLD is a native Site Health critical error');
$tests=$cls::site_health_tests(array('direct'=>array()));
assert_it(isset($tests['direct']['mad4b_original305_readiness']),
    'Original305 uses the existing WordPress Site Health test registry');
$report=$cls::site_health_result();
assert_it($report['status']==='critical'&&strpos($report['actions'],'original305')!==false,
    'critical Site Health report navigates to automatic diagnostics');
$GLOBALS['o305_test_snapshot']['legacy_hold_present']=false;
$GLOBALS['o305_test_snapshot']['timer_enabled']=true;
$GLOBALS['o305_test_snapshot']['signed_readiness_admitted']=true;
$GLOBALS['o305_test_snapshot']['state']='SIGNED_HOST_READINESS_ACCEPTED';
$GLOBALS['o305_test_snapshot']['wordpress_readback_state']='SIGNED_LEASE_CURRENT';
$GLOBALS['o305_test_signed']=true;
assert_it($cls::operational_health()['status']==='good',
    'healthy requires admitted signed readiness and a running renewal timer');
$GLOBALS['o305_test_signed']=false;
assert_it($cls::operational_health()['status']==='critical',
    'a missing WordPress signature blocks a false green status');
unset($GLOBALS['o305_test_signed']);
$GLOBALS['o305_test_snapshot']['wordpress_readback_state']='WPCLI_READ_FAILED';
$GLOBALS['o305_test_snapshot']['wordpress_readback_available']=false;
$GLOBALS['o305_test_snapshot']['signed_readiness_admitted']=false;
assert_it($cls::operational_health()['code']==='ORIGINAL305_WORDPRESS_READBACK_UNAVAILABLE',
    'a failed independent WordPress readback is a distinct Site Health error');
$GLOBALS['o305_test_snapshot']['wordpress_readback_available']=true;
assert_it($cls::status()['observation']['fresh']===false,
    'contradictory WordPress-readback evidence is rejected');
$GLOBALS['o305_test_snapshot']['hold_evidence_consistency_verified']=false;
assert_it($cls::status()['observation']['fresh']===false,
    'missing HOLD proof is not accepted as fresh telemetry');
$GLOBALS['o305_test_snapshot']['expires_epoch']=time()-1;
assert_it($cls::status()['observation']['fresh']===false,'stale Host diagnostics rejected');
assert_it($cls::operational_health()['code']==='ORIGINAL305_OBSERVATION_STALE',
    'stale observer is critical, not silently healthy');
unset($GLOBALS['o305_test_snapshot']);
$bad=$p;$bad['request_sha256']=str_repeat('f',64);
assert_it(is_wp_error($cls::exact_review_request($bad)),'tampered request hash rejected');
$bad=$p;$bad['host_release_authorized']=true;
assert_it(is_wp_error($cls::exact_review_request($bad)),'release authority bit rejected');
$bad=$p;$bad['untrusted_override']='approve';
assert_it(is_wp_error($cls::exact_review_request($bad)),'extra untrusted scope rejected');
assert_it(is_wp_error($cls::can_create()),'headless CLI cannot impersonate browser admin');
$_COOKIE[LOGGED_IN_COOKIE]='cookie-good';$_SERVER['HTTP_X_WP_NONCE']='nonce-good';
assert_it($cls::can_create()===true && $cls::can_decide()===true,'native admin session + nonce accepted for UI actions');
$req=new O305TestRequest(array('incident_request'=>$p,'agent_public_id'=>'agent-public-test','ttl'=>600));
$r=$cls::create_pending($req);
assert_it(!is_wp_error($r)&&$r['state']==='ORIGINAL305_PENDING_REVIEW_NOT_APPROVED','native pending ticket only');
assert_it(MAD4B_SCP_Approval_Tickets::$created===1&&!$r['host_release_authorized']&& !$r['incident_closed'],'pending ticket never releases host');
$deny=new O305TestRequest(array('incident_request'=>$p,'decision'=>'approve','expected_payload_sha256'=>str_repeat('f',64)));
assert_it(is_wp_error($cls::decide($deny)),'invalid ticket digest denied');
assert_it(MAD4B_SCP_Approval_Tickets::$decided===0,'no ticket decision performed on bad hash');
$yes=new O305TestRequest(array('incident_request'=>$p,'decision'=>'approve','expected_payload_sha256'=>MAD4B_SCP_Approval_Tickets::$ticket['payload_sha256']));
$r=$cls::decide($yes);
assert_it(!is_wp_error($r)&&$r['ticket']['status']==='approved','existing WP ticket decision used');
assert_it($r['signed_master_envelope_issued']===false&&$r['incident_closed']===false&&$r['host_release_authorized']===false,'approval is not signed Master envelope or HOLD closure');
echo "ALL_ORIGINAL305_ISOLATED_TESTS_PASSED\n";
