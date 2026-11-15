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
function is_wp_error($value){return $value instanceof WP_Error;}
class WP_Error {
  private $code;
  public function __construct($code,$message=''){$this->code=$code;}
  public function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Policy {
  public static function can_mutate(){return false;}
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
  'content'=>['fixture-provider/content-write'],
  'admin'=>['fixture-provider/admin-write'],
  'write'=>['fixture-provider/native-write']];}
 public function register_abilities(){}
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
echo "ADAPTER_BASE_DEFAULT_WRITE: PASS fail-closed availability across all mutations, subclass overrides intact\n";
