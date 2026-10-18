<?php
/** Exact-head update must never run outside one-time governed WP Ability claim. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code; public function __construct($code,$msg='') {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
class MAD4B_SCP_Authorization {
    public static $claimed=false;
    public static function execution_callback_started($a){
        return self::$claimed && $a===MAD4B_SCP_Selected_Head_Update::APPLY_ABILITY;
    }
    public static function permission_result_from_authorization($d){
        if(is_wp_error($d)||is_bool($d))return $d;
        return is_array($d)&&true===($d['allowed']??false)
            &&'preflight_allowed'===($d['reason_code']??'');
    }
}
class MAD4B_SCP_Execution_Fence {
    public static function has_active_frame(){return MAD4B_SCP_Authorization::$claimed;}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-selected-head-update.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-selected-head-admin.php';
function test_assert($ok,$why){if(!$ok)throw new RuntimeException($why);}
$blocked=MAD4B_SCP_Selected_Head_Update::apply(array());
test_assert(is_wp_error($blocked)&&$blocked->get_error_code()==='mad4b_selected_head_governed_execution_required',
    'Direct PHP execution must fail before any I/O or package write');
test_assert(MAD4B_SCP_Authorization::permission_result_from_authorization(
    array('allowed'=>true,'reason_code'=>'preflight_allowed')),'Structured central preflight should be recognized');
test_assert(!MAD4B_SCP_Authorization::permission_result_from_authorization(
    array('allowed'=>false,'reason_code'=>'preflight_denied')),'Denied decision must not be coerced to success');
class MockApprovedAbility {
    public $received=null;
    public function execute($input){$this->received=$input;return array('result'=>'permission_wrapper_invoked');}
}
$GLOBALS['test_ability']=new MockApprovedAbility();
function wp_get_ability($id){
    if($id!==MAD4B_SCP_Selected_Head_Update::APPLY_ABILITY)return null;
    return $GLOBALS['test_ability'];
}
$input=array('candidate_source'=>array('repository'=>'mad4bdigital-ai/WordPress'),
    '_mad4b_approval_ticket_id'=>'89d9ad8f-64e4-4d0e-8e60-269450b62e5c');
$result=MAD4B_SCP_Staging_Selected_Head_Admin::execute_approved_candidate($input);
test_assert($result['result']==='permission_wrapper_invoked'
    && $GLOBALS['test_ability']->received===$input,
    'Admin bridge must call exact registered WP Ability and forward ticket intact');
echo "SELECTED_HEAD_GOVERNED_EXECUTION: PASS\n";
