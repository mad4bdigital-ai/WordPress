<?php
error_reporting(E_ALL);
define('ABSPATH','/');
$GLOBALS['checks']=0;
class WP_Error {private $code;function __construct($code,$msg=''){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_option($k,$d=null){return $d;}
function wp_get_environment_type(){return $GLOBALS['environment']??'local';}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function sanitize_text_field($s){return trim(strip_tags($s));}
function wp_json_encode($s,$opts=0){return json_encode($s,$opts);}
function add_action($hook,$callback,$priority=10){$GLOBALS['actions'][$hook][]=$callback;}
class MAD4B_SCP_Site_Profile {static function current_environment(){return $GLOBALS['environment']??'local';}static function site_uuid(){return '00000000-0000-4000-8000-000000000001';}}
class MAD4B_SCP_Context_Authority{static function review_policy(){return ['mode'=>$GLOBALS['mode']??'ai_only'];}}
function check($label,$value){$GLOBALS['checks']++;if(!$value){echo "FAIL $label\n";exit(1);}echo "PASS $label\n";}
$base=dirname(__DIR__).'/includes/';
require $base.'class-mad4b-scp-unified-policy.php';
require $base.'class-mad4b-scp-unified-approval-extensions.php';
require $base.'class-mad4b-scp-unified-approval-queue-bridge.php';
require $base.'class-mad4b-scp-unified-approval-admin.php';
require $base.'class-mad4b-scp-background-authorization.php';
check('core_classes_namespaced',class_exists('MAD4B_SCP_Unified_Policy')&&class_exists('MAD4B_SCP_Unified_Approval_Admin'));
check('old_standalone_class_not_defined',!class_exists('MAD4B_Unified_Policy'));
check('original_qa_mode_preserved',MAD4B_SCP_Unified_Policy::resolve(['module'=>'product_catalog','operation'=>'content_review'])['mode']==='ai_only');
check('commercial_content_not_auto_granted',MAD4B_SCP_Unified_Policy::resolve(['module'=>'pricing','operation'=>'price_change'])['decision_rule']==='ai_then_human');
check('production_breakglass_floor',MAD4B_SCP_Unified_Policy::resolve(['module'=>'breakglass','operation'=>'apply'])['mode']==='human_only');
$in=MAD4B_SCP_Unified_Approval_Extensions::inventory();
check('extensions_never_authorize_credentials',$in['unverified_api_key_and_mtls_disabled']===true && !$in['mutation_authorized']);
check('no_unpinned_extension',is_wp_error(MAD4B_SCP_Unified_Approval_Extensions::register('service_identity','sample',[])));
check('queue_gate_rejects_arbitrary_id',is_wp_error(MAD4B_SCP_Unified_Approval_Queue_Bridge::gate('guess')));
check('browser_broker_returns_waiting_auth',MAD4B_SCP_Browser_Auth_Broker::plan(['provider'=>'providerone','operation'=>'read_profile','origin'=>'https://example.com'])['state']==='WAITING_AUTH');
check('browser_mfa_not_bypassed',is_wp_error(MAD4B_SCP_Browser_Auth_Broker::issue_session_from_model([])));
$GLOBALS['environment']='staging';$GLOBALS['actions']=[];
MAD4B_SCP_Unified_Approval_Admin::boot();
check('staging_boot_rejected',!isset($GLOBALS['actions']['rest_api_init']));
$GLOBALS['environment']='local';MAD4B_SCP_Unified_Approval_Admin::boot();
check('local_boot_registers_rest',isset($GLOBALS['actions']['rest_api_init']));
echo "SUMMARY checks={$GLOBALS['checks']} failures=0\n";
