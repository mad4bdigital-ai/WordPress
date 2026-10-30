<?php
/** Independent in-memory regression of exact Staging MU sync bridge. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'etg_commerce_staging' );
$GLOBALS['qa31_env'] = 'production';
$GLOBALS['qa31_host'] = 'tours.dev.mad4b.com';
$GLOBALS['qa31_options'] = array();
$GLOBALS['qa31_started'] = 0;
$GLOBALS['qa31_owner_email'] = false;
function add_action( $name, $callback, $priority = 10 ) { return true; }
function wp_get_environment_type() { return $GLOBALS['qa31_env']; }
function home_url( $path = '' ) { return 'https://' . $GLOBALS['qa31_host'] . '/' . ltrim( $path, '/' ); }
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
function sanitize_key( $str ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $str ) ); }
function wp_is_uuid( $str ) { return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $str); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['qa31_options'] ) ? $GLOBALS['qa31_options'][$key] : $default; }
function update_option( $key, $val, $autoload = null ) { $GLOBALS['qa31_options'][$key] = $val; return true; }
function get_current_user_id() { return 15; }
function wp_generate_uuid4() { return '8ac9775e-d334-4fa0-b266-dc3f77d10501'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message = '', $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; }
    public function get_error_code() { return $this->code; }
}
class WP_REST_Response {
    private $payload; private $status;
    public function __construct( $payload, $status = 200 ) { $this->payload=$payload; $this->status=$status; }
    public function header( $name, $value ) {}
    public function get_data() { return $this->payload; }
    public function get_status() { return $this->status; }
}
class WP_REST_Request {
    private $params;
    public function __construct( $method='GET', $path='/', $params=array() ) { $this->params=$params; }
    public function get_param( $name ) { return $this->params[$name] ?? null; }
    public function set_param( $name, $value ) { $this->params[$name] = $value; }
    public function get_params() { return $this->params; }
    public function get_json_params() { return $this->params; }
    public function set_header( $name, $value ) {}
    public function set_body( $body ) { $value=json_decode($body,true);if(is_array($value))$this->params=$value; }
}
class MAD4B_Company_Readiness_Governance { public static function guard( $request ) { return true; } }
class MAD4B_Company_Bootstrap_Contract {
    public static function record( $code ) { return array( 'owner_email_set' => $GLOBALS['qa31_owner_email'] ); }
}
class MAD4B_SCP_Schema {}
class MAD4B_SCP_Operation_Journal {
    public static function begin( $context, $state, $payload ) {
        ++$GLOBALS['qa31_started'];
        return array( 'operation_id' => $context['operation_id'], 'state' => $state );
    }
    public static function status( $id ) { return array('operation_id'=>$id,'state'=>'planned'); }
}
class QA31_DB {
    public function prepare( $sql, $arg ) { return $sql; }
    public function get_var( $sql ) { return stripos($sql, 'GET_LOCK') !== false ? 1 : 1; }
}
$wpdb=new QA31_DB();
require dirname( __DIR__, 3 ) . '/mu-plugins/250-mad4b-company-plugin-sync-operation.php';
function check_sync( $ok, $msg ) { if ( ! $ok ) { fwrite(STDERR,"FAIL COMPANY_SYNC: $msg\n");exit(1); } }
function code_is( $value, $code ) { return is_wp_error($value) && $value->get_error_code() === $code; }
$uuid='0e794c81-f1a3-46b3-a525-529f4d4c53aa';
$hash=str_repeat('a',64);
$GLOBALS['qa31_options']['mad4b_tourism_company_site_registry_v1'] = array('wayfatours'=>array('company_site_uuid'=>$uuid,'host_binding_verified'=>true));
$GLOBALS['qa31_options']['mad4b_company_plugin_sync_plan_v1'] = array('wayfatours'=>array(
    'contract'=>'mad4b.company-plugin-sync-plan.v1','company'=>'wayfatours',
    'site_uuid'=>$uuid,'plan_hash'=>$hash,'generated_at'=>gmdate('c'),
    'source_plugin_count'=>117,'copy_missing'=>25,'activation_candidates'=>12,
));
$req=new WP_REST_Request('POST','/',array('company'=>'wayfatours','plan_hash'=>$hash));
check_sync(code_is(MAD4B_Company_Plugin_Sync_Operation::request_job($req),'mad4b_company_sync_wrong_host'),'non-Staging admitted');
$GLOBALS['qa31_env']='staging';$GLOBALS['qa31_host']='other.example';
check_sync(code_is(MAD4B_Company_Plugin_Sync_Operation::request_job($req),'mad4b_company_sync_wrong_host'),'wrong host admitted');
$GLOBALS['qa31_host']='tours.dev.mad4b.com';
check_sync(code_is(MAD4B_Company_Plugin_Sync_Operation::request_job($req),'mad4b_company_owner_email_required'),'missing company owner email admitted');
check_sync($GLOBALS['qa31_started']===0,'journal started without owner email');
$GLOBALS['qa31_owner_email']=true;
$wrong=new WP_REST_Request('POST','/',array('company'=>'wayfatours','plan_hash'=>str_repeat('b',64)));
check_sync(code_is(MAD4B_Company_Plugin_Sync_Operation::request_job($wrong),'mad4b_company_plan_hash_mismatch'),'stale plan hash admitted');
check_sync($GLOBALS['qa31_started']===0,'journal started on stale plan');
$result=MAD4B_Company_Plugin_Sync_Operation::request_job($req);
check_sync($result instanceof WP_REST_Response && $result->get_status()===201,'scoped queue not journaled');
$data=$result->get_data();
check_sync(($data['state']??'')==='PLANNED_NOT_EXECUTED' && $data['production_mutation_authorized']===false && $GLOBALS['qa31_started']===1,'queue authorized tenant mutation');
$duplicate=MAD4B_Company_Plugin_Sync_Operation::request_job($req);
check_sync(($duplicate->get_data()['state']??'')==='ALREADY_QUEUED' && $GLOBALS['qa31_started']===1,'duplicate journal entry');
$jobReq=new WP_REST_Request('GET','/',array('company'=>'wayfatours','operation_id'=>'8ac9775e-d334-4fa0-b266-dc3f77d10501'));
$journal=MAD4B_Company_Plugin_Sync_Operation::job($jobReq);
check_sync($journal instanceof WP_REST_Response && $journal->get_data()['production_mutation_authorized']===false,'readback elevated mutation');
echo "COMPANY_PLUGIN_SYNC_MU_RUNTIME: PASS (environment, owner email, hash, idempotent journal, readback)\n";
