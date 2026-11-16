<?php
/**
 * Exact original Search Provider Autoprovision Ability registration and no
 * commercial activation without independently verified rights/budget.
 * All dependencies are synthetic and no provider requests or writes occur.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['provider_regs']=array();
$GLOBALS['provider_routes']=array();
$GLOBALS['provider_admin']=false;
$GLOBALS['provider_can_configure']=true;
$GLOBALS['provider_mutate']=false;
$GLOBALS['provider_writes']=0;
$GLOBALS['provider_jobs']=0;
function add_action($name,$callback,$priority=10,$args=1){}
function wp_register_ability($name,$cfg){$GLOBALS['provider_regs'][$name]=$cfg;}
function wp_has_ability($name){return isset($GLOBALS['provider_regs'][$name]);}
function register_rest_route($ns,$route,$cfg){$GLOBALS['provider_routes'][$ns.'/'.ltrim($route,'/')]=$cfg;}
function current_user_can($cap){return $cap==='manage_options'&&$GLOBALS['provider_admin'];}
function is_wp_error($r){return $r instanceof WP_Error;}
class WP_Error{
 private $code;
 public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function native_check($ok,$why){if(!$ok)throw new RuntimeException('FAIL SEARCH AUTOPROVISION: '.$why);}
class MAD4B_SCP_Policy {
 public static function can_mutate(){return $GLOBALS['provider_mutate'];}
}
class MAD4B_SCP_Search_Runtime {
 public static function can_configure(){return $GLOBALS['provider_can_configure'];}
}
class MAD4B_SCP_Search_Contracts {
 public static function error($code){return new WP_Error('mad4b_search_'.$code);}
 public static function id($value){return is_string($value)&&preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$value);}
 public static function sha($digest){return (bool)preg_match('/^[a-f0-9]{64}$/D',(string)$digest);}
}
class MAD4B_SCP_Site_Profile{
 public static function site_uuid(){return '12345678-1234-4123-8123-123456789abc';}
}
class MAD4B_SCP_Search_SERP_Enrollment {
 public function descriptor(){return array(
  'provider_id'=>'demo-serp','account_id'=>'commercial-account',
  'shared_account'=>true,'capabilities'=>array('engines'=>array('google'),'devices'=>array('desktop')));}
}
class MAD4B_SCP_Search_Providers {
 public static function adapters(){return array('demo-serp'=>new MAD4B_SCP_Search_SERP_Enrollment());}
 public static function descriptor_guard($description,$now){return new WP_Error('no_verified_rights');}
}
class MAD4B_SCP_Search_Provider_Connections {
 public static function status($provider){return array('connection_state'=>'VERIFIED','revision'=>7,'configured'=>true);}
}
class MAD4B_SCP_Search_Context {
 public static function profile($id){return new WP_Error('no_current_company_profile');}
}
class MAD4B_SCP_Search_Budgets {
 public static function status($id){return array('remaining'=>0,'reset_at'=>0,
  'hard_global_enforcement'=>false,'local_allocation_certified'=>false);}
}
class MAD4B_SCP_Search_Surfaces {
 public static function languages(){return array('en'=>array('active'=>true,'conflicting'=>false));}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/search/class-mad4b-scp-search-provider-autoprovision.php';
$c='MAD4B_SCP_Search_Provider_Autoprovision';
$c::abilities();$c::routes();
$expected=array(
 'mad4b/search-provider-autoprovision-inventory'=>false,
 'mad4b/search-provider-autoprovision-reconcile'=>true,
 'mad4b/search-provider-autoprovision-reconcile-all'=>true
);
native_check(count($GLOBALS['provider_regs'])===3 && count($GLOBALS['provider_routes'])===1,
 'Original dynamic Ability expansion is 3 real names, 1 private REST route');
foreach($expected as $ability=>$write){
 $a=$GLOBALS['provider_regs'][$ability]??null;
 native_check(is_array($a)
  && $a['input_schema']['additionalProperties']===false
  && $a['meta']['mcp']['surface']===($write?'write':'read')
  && $a['meta']['annotations']['readonly']===!$write
  && is_callable($a['permission_callback']),
  'Synthetic phantom prefix is never an ability: '.$ability);
 native_check(call_user_func($a['permission_callback'])===false,
  'Unprivileged actor cannot enroll or mutate commercial Search provider');
}
$adminRoute=$GLOBALS['provider_routes']['mad4b-search/v1/provider-autoprovision'];
native_check($adminRoute['methods']==='GET'
 && call_user_func($adminRoute['permission_callback'])===false,
 'Search provider inventory REST must not be public');
$GLOBALS['provider_admin']=true;
$read=$c::inspect(array('provider_id'=>'demo-serp'));
native_check(is_array($read)
 && $read['account_connection_state']==='VERIFIED'
 && $read['native_certification_verified']===false
 && $read['budget_authority_verified']===false
 && $read['commercial_evidence_ready']===false
 && $read['capture_ready']===false
 && $read['profile_created']===false
 && $read['paid_search_invoked']===false
 && in_array('native_provider_certification_and_rights_evidence_required',$read['pending'],true)
 && in_array('verified_account_budget_authority_required',$read['pending'],true),
 'VERIFIED account alone cannot claim paid search rights, profile or budget');
$unknown=$c::inspect(array('provider_id'=>'foreign-adapter'));
native_check($unknown instanceof WP_Error
 && $unknown->get_error_code()==='mad4b_search_autoprovision_provider_not_registered',
 'Unregistered dynamic provider must not create profile');
$denied=$c::reconcile(array('provider_id'=>'demo-serp'));
native_check($denied instanceof WP_Error
 && $denied->get_error_code()==='mad4b_search_autoprovision_write_denied',
 'Provider enrollment cannot bypass WordPress policy mutation gate');
$bulk=$c::reconcile_all();
native_check($bulk instanceof WP_Error
 && $bulk->get_error_code()==='mad4b_search_autoprovision_write_denied',
 'Bulk provider reconciliation must not bypass policy');
$c::on_verified('demo-serp',7);
$c::tick();
native_check($GLOBALS['provider_writes']===0
 && $GLOBALS['provider_jobs']===0,
 'Verified events or Cron without write policy cannot launch paid provider work');
echo "SEARCH_AUTOPROVISION_NATIVE: PASS 3 real abilities, VERIFIED alone never commercial authorization\n";// Distinct audit marker only after all negative provider/grant assertions.
echo "SEARCH_AUTOPROVISION_NATIVE: PASS\n";
