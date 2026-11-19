<?php
/**
 * Original Core Operation Registry: exact selected-source catalog and faulted
 * read/privileged operation mutations. Executes its real catalog validator.
 * Mutations target only a /tmp synthetic catalog; never any WordPress site.
 */
define('ABSPATH', __DIR__.'/');
$root=dirname(__DIR__);
$original=$root.'/wp-content/plugins/mad4b-site-control-plane/config/operation-registry.json';
$tmp=sys_get_temp_dir().'/mad4b-readiness-ops-'.getmypid();
if(!mkdir($tmp,0700) && !is_dir($tmp))throw new RuntimeException('No isolated test workspace');
if(!mkdir($tmp.'/config',0700))throw new RuntimeException('No isolated catalog folder');
define('MAD4B_SCP_DIR',$tmp.'/');
$catalogPath=$tmp.'/config/operation-registry.json';
if(!copy($original,$catalogPath))throw new RuntimeException('Original catalog fixture unavailable');
$baseline=json_decode(file_get_contents($original),true,512,JSON_THROW_ON_ERROR);
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
function add_action($name,$callback,$priority=10){}
class WP_Error {
 private $code;public function __construct($code,$description=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
class MAD4B_SCP_Capability_Descriptor_Registry {
 const CONTRACT='mad4b.capability-descriptor-registry.v1';
 const CONSUMER_BINDING_CONTRACT='mad4b.capability-descriptor-binding.v1';
 public static function binding($ability,$consumer){
  return array('consumer'=>$consumer,'name'=>$ability,
   'state'=>'bound_read_only_for_fixture','authorizing'=>false);
 }
}
function assert_op($cond,$why) {
 if(!$cond)throw new RuntimeException('FAIL ORIGINAL OPERATION REGISTRY: '.$why);
}
require $root.'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operation-registry.php';
$r='MAD4B_SCP_Operation_Registry';
$readId='company.code-reconciliation.plan';
$grantId='wordpress.developer.operator-grants.converge';
$get=function($id)use($r){
 $row=$r::operation($id);
 assert_op(is_array($row),'Registered operation must have valid source '.$id);
 return $row;
};
$read=$get($readId);
$grant=$get($grantId);
assert_op($read['effect']==='read_only' && $read['side_effect_class']==='none'
 && $read['production_policy']==='observe_propose_only'
 && $read['generic_mutation_dispatch']===false
 && $read['capability_descriptor_bindings']['planner']['authorizing']===false
 && $read['capability_descriptor_bindings']['executor']['authorizing']===false,
 'Read-only comparator must not acquire file or Host mutation rights');
assert_op($grant['risk']==='exceptional'
 && $grant['owner_confirmation_required']===true
 && $grant['write_admission']['owner_step_up_required']===true,
 'Operator Developer grant path must require exceptional owner approval');
$policy=$r::registered_write_policy('mad4b/operator-grants-converge');
assert_op(is_array($policy)
 && $policy['operation_id']===$grantId
 && $policy['policy']['production_mode']==='runtime_governance_policy'
 && $policy['policy']['policy_effect']==='preflight_only'
 && strlen($policy['operation_sha256'])===64,
 'Only registered owner-gated write policy can return preflight identity');
assert_op(is_wp_error($r::registered_write_policy('mad4b/unknown-write')),
 'Unrecognized operation cannot self-enroll');
assert_op(is_wp_error($r::registered_write_policy('mad4b/company-code-reconciliation-plan')),
 'Read-only comparator cannot be considered a registered write');
$stages=$r::pipeline_profile('governed_read_only_v1');
assert_op(count($stages)===4 && array_column($stages,'id')===
 array('discover','native_plan','exact_execute','readback'),
 'Nonmutating original comparator pipeline must require ordered checks');
assert_op($r::stage_binding('native_plan',$read)['ability']==='mad4b/company-code-reconciliation-plan'
 && $r::stage_binding('exact_execute',$read)['ability']==='mad4b/company-code-reconciliation-compare'
 && $r::stage_binding('readback',$read)['binding_type']==='executor_owned_verification',
 'Read-only stage resolves only source-bound abilities');
$projection=$r::read_projection('catalog');
assert_op(in_array('mad4b/company-code-reconciliation-plan',$projection,true)
 && in_array('mad4b/company-code-reconciliation-compare',$projection,true),
 'Original read-only registry projection must include both signed comparator abilities');
function persist_test_catalog($json) {
 file_put_contents(MAD4B_SCP_DIR.'config/operation-registry.json',
  json_encode($json,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 MAD4B_SCP_Operation_Registry::reset_request_cache();
}
function expect_registry_error($data,$description,$type='operation') {
 persist_test_catalog($data);
 $result=$type==='policy'
  ? MAD4B_SCP_Operation_Registry::registered_write_policy('mad4b/operator-grants-converge')
  : MAD4B_SCP_Operation_Registry::operation('company.code-reconciliation.plan');
 assert_op(is_wp_error($result),$description.' should be rejected by original Core');
}
function patch_operation($catalog,$id,$updates) {
 foreach($catalog['operations'] as &$r) if($r['id']===$id){
  foreach($updates as $key=>$value)$r[$key]=$value;
  break;
 }
 unset($r);return $catalog;
}
expect_registry_error(patch_operation($baseline,$readId,
 array('generic_mutation_dispatch'=>true)), 'Read-only operation with mutation dispatch');
expect_registry_error(patch_operation($baseline,$readId,
 array('effect'=>'write')), 'Read-only comparator with write effect');
expect_registry_error(patch_operation($baseline,$readId,
 array('pipeline_profile'=>'governed_mutation_v1')),'Read-only comparator using mutation pipeline');
expect_registry_error(patch_operation($baseline,$readId,
 array('signed_core_evidence_required'=>false)),'Unsigned comparator source inventory');
expect_registry_error(patch_operation($baseline,$readId,
 array('production_policy'=>'allow')),'Read-only comparator secretly enabled for Production');
$invalid=$baseline;
$invalid['stage_bindings']['readback']['binding_type']='ability';
$invalid['stage_bindings']['readback']['ability']='mad4b/arbitrary-write';
expect_registry_error($invalid,'Readback re-bound to mutation ability');
expect_registry_error(patch_operation($baseline,$grantId,
 array('owner_confirmation_required'=>false)),'Operator grant without owner step-up','policy');
expect_registry_error(patch_operation($baseline,$grantId,
 array('write_admission'=>array_replace($grant['write_admission'],
 array('owner_step_up_required'=>false)))),'Operator grant policy without Owner step-up','policy');
expect_registry_error(patch_operation($baseline,$grantId,
 array('write_admission'=>array_replace($grant['write_admission'],
 array('production_mode'=>'unrestricted')))),'Operator grant bypassing Company profile','policy');
$invalid=$baseline;$invalid['default_mutation_policy']='allow';
expect_registry_error($invalid,'Global unauthorized mutation default');
$invalid=$baseline;$invalid['operations'][]=$invalid['operations'][0];
expect_registry_error($invalid,'Duplicate first-party operation');
persist_test_catalog($baseline);
assert_op($r::registered_write_policy('mad4b/operator-grants-converge')['operation_id']===$grantId,
 'Restored immutable original catalog must read back');
unlink($catalogPath);
rmdir($tmp.'/config');rmdir($tmp);
echo "NATIVE_OPERATION_REGISTRY_BOUNDARY: PASS two operations, eleven fault cases\n";
echo "NATIVE_OPERATION_REGISTRY_BOUNDARY: PASS\n";
