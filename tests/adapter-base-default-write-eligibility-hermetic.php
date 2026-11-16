<?php
/**
 * Original Adapter Base and native Write Catalog handshake, PHP 7.4 / 8.5.
 * Exercise genuine base inheritance and opt-in overrides, not copied methods.
 * No site, provider, database, HTTP, credentials, mutation or approval.
 */
define('ABSPATH', __DIR__.'/');
function add_action(...$args){}
function add_filter(...$args){}
function apply_filters($name,$default){return $default;}
function sanitize_key($value){return strtolower((string)$value);}
$GLOBALS['native_abilities']=[];
$GLOBALS['fixture_auth_calls']=0;
function wp_register_ability($name,$config){$GLOBALS['native_abilities'][$name]=$config;}
class MAD4B_SCP_Authorization {
 public static function authorize_mutation($name,$server,$provider,$input){
  ++$GLOBALS['fixture_auth_calls'];
  return true; // only in isolated fixture; never a real authorization
 }
}
function is_wp_error($value){return $value instanceof WP_Error;}
class WP_Error {
  private $code;
  public function __construct($code,$message=''){$this->code=$code;}
  public function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Policy {
  public static function can_mutate(){return true;}
  public static function can_native_content_draft($input){return true;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-adapter-base.php';
function eligibility_assert($condition,$reason){
 if(!$condition)throw new RuntimeException('FAIL BASE ELIGIBILITY: '.$reason);
}
class Fixture_Provider_Adapter extends MAD4B_SCP_Adapter_Base {
 public $ready=false;
 public function id(){return 'fixture-provider';}
 public function label(){return 'Fixture Provider';}
 public function is_available(){return $this->ready;}
 public function ability_names(){return [
  'read'=>['fixture-provider/read'],
  'content'=>['fixture-provider/content-write','mad4b/content-apply-bundle'],
  'admin'=>['fixture-provider/admin-write'],
  'write'=>['fixture-provider/native-write']];}
 public function register_abilities(){}
 protected function mutation_requires_certification(){return false;}
 public function register_test_write(){
  $this->add_ability('fixture-provider/native-write','Fixture Write',
   static function($input){return ['simulated_only'=>true];},
   static function($input){return true;},null,'write',false);
 }
 public function register_test_read(){
  $this->add_ability('fixture-provider/read','Fixture Read',
   static function($input){return ['fixture_read_only'=>true];},
   static function($input){return true;},null,'read',true);
 }
 public function register_native_draft_permission(){
  $this->add_ability('mad4b/content-apply-bundle','Native Draft Admission Only',
   static function($input){throw new RuntimeException('Do not execute fixture write');},
   static function($input){return false;},null,'content',false);
 }
}
class Fixture_Custom_Gate_Adapter extends Fixture_Provider_Adapter {
 public function mutation_ability_runtime_eligibility($name){
  return new WP_Error('fixture_exact_external_rollback_missing');
 }
}
$a=new Fixture_Provider_Adapter();
eligibility_assert(method_exists($a,'mutation_ability_runtime_eligibility'),
 'Original Adapter_Base must expose a default native runtime write eligibility gate');
foreach(['fixture-provider/content-write','fixture-provider/admin-write',
 'fixture-provider/native-write'] as $name){
 $blocked=$a->mutation_ability_runtime_eligibility($name);
 eligibility_assert(is_wp_error($blocked)
  && $blocked->get_error_code()==='mad4b_adapter_unavailable',
  'Unavailable native adapter must be excluded from ALL original write surfaces: '.$name);
}
foreach(['fixture-provider/read','fixture-provider/unregistered-write','foreign-provider/native-write'] as $name){
 $blocked=$a->mutation_ability_runtime_eligibility($name);
 eligibility_assert(is_wp_error($blocked)
  && $blocked->get_error_code()==='mad4b_adapter_mutation_not_declared',
  'Read and foreign names must never inherit a provider mutation mount');
}
$a->ready=true;
foreach(['fixture-provider/content-write','fixture-provider/admin-write',
 'fixture-provider/native-write'] as $name){
 eligibility_assert($a->mutation_ability_runtime_eligibility($name)===true,
  'An available, declared write may be cataloged; this does not grant execution');
}
eligibility_assert(is_wp_error($a->mutation_ability_runtime_eligibility('fixture-provider/read')),
 'Available read identity does not become a write candidate');
$b=new Fixture_Custom_Gate_Adapter();
$b->ready=true;
$custom=$b->mutation_ability_runtime_eligibility('fixture-provider/native-write');
eligibility_assert(is_wp_error($custom)
 && $custom->get_error_code()==='fixture_exact_external_rollback_missing',
 'Subclass-specific strict provider/rollback safety overrides must retain precedence');
// Actual WordPress Ability permission callback must recheck runtime
// availability even after a cached Write mount has once been eligible.
$a->register_test_read();
$read_permission=$GLOBALS['native_abilities']['fixture-provider/read']['permission_callback']??null;
eligibility_assert(is_callable($read_permission) && $read_permission([])===true,
 'Read-only Ability must retain its original WordPress permission independent of a provider Write mount');
$a->register_test_write();
$permission=$GLOBALS['native_abilities']['fixture-provider/native-write']['permission_callback']??null;
eligibility_assert(is_callable($permission),'Original Adapter Base did not register guarded write permission');
$a->ready=false;
$attempt=$permission(['fixture'=>true]);
eligibility_assert(is_wp_error($attempt) && $attempt->get_error_code()==='mad4b_adapter_unavailable'
 && $GLOBALS['fixture_auth_calls']===0,
 'Stale mount cannot execute after provider disappears, even with simulated external grant');
$a->ready=true;
$authorized=$permission(['fixture'=>true]);
eligibility_assert($authorized===true && $GLOBALS['fixture_auth_calls']===1,
 'Healthy adapter still delegates normal granted execution to the original authority');
$a->ready=false;
$denied_again=$permission(['fixture'=>true]);
eligibility_assert(is_wp_error($denied_again)
 && $denied_again->get_error_code()==='mad4b_adapter_unavailable'
 && $GLOBALS['fixture_auth_calls']===1,
 'Runtime availability revocation must invalidate permission without reset_request_cache');
// Native WP-CLI draft has its own narrow policy bypass, but it cannot
// bypass a missing runtime provider. No actual draft is applied.
$a->register_native_draft_permission();
$native_permission=$GLOBALS['native_abilities']['mad4b/content-apply-bundle']['permission_callback'];
$a->ready=false;
$no_native=$native_permission(['fixture_draft'=>true]);
eligibility_assert(is_wp_error($no_native)
 && $no_native->get_error_code()==='mad4b_adapter_unavailable'
 && $GLOBALS['fixture_auth_calls']===1,
 'Even authorized local native draft must reject unavailable adapter');
$a->ready=true;
eligibility_assert($native_permission(['fixture_draft'=>true])===true
 && $GLOBALS['fixture_auth_calls']===1,
 'Existing native draft narrow bypass remains available when provider is ready');
$custom_write=new Fixture_Custom_Gate_Adapter();
$custom_write->ready=true;
$custom_write->register_test_write();
$custom_permission=$GLOBALS['native_abilities']['fixture-provider/native-write']['permission_callback'];
$custom_denied=$custom_permission(['fixture'=>true]);
eligibility_assert(is_wp_error($custom_denied)
 && $custom_denied->get_error_code()==='fixture_exact_external_rollback_missing'
 && $GLOBALS['fixture_auth_calls']===1,
 'Custom provider-specific runtime denial must also execute at permission time, ahead of any grant');
echo "ADAPTER_BASE_DEFAULT_WRITE: PASS fail-closed availability, exact permission revalidation, overrides\n";
