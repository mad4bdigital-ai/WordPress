<?php
/** Real canonical inspector and descriptor, no WordPress, network, or site write. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=array()){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_has_ability($name){return $name===($GLOBALS['fixture_ability_name']??'mad4b/operator-grants-converge');}
function wp_get_ability($name){return $name==='mad4b/operator-grants-converge' && isset($GLOBALS['operator_ability']) ? $GLOBALS['operator_ability'] : new Demo_Ability();}
function apply_filters($filter,$value,...$args){return $value;}
class Demo_Ability {
 function get_meta(){
  $mcp=array('surface'=>$GLOBALS['lane'],
    'mad4b_execution_boundary'=>'test-only-governed');
  if(!empty($GLOBALS['descriptor_read_marker']))
    $mcp['mad4b_descriptor_read_lane']='read';
  return array('annotations'=>array('readonly'=>$GLOBALS['read_only']),'mcp'=>$mcp);
 }
 function execute(){}
 function get_input_schema(){return array('type'=>'object','additionalProperties'=>false);}
 function get_output_schema(){return array('type'=>'object');}
 function get_category(){return $GLOBALS['fixture_category']??'mad4b-developer-governance';}
 function get_label(){return 'Fixture';}
 function get_description(){return 'Original WordPress Ability typed projection fixture';}
}
class MAD4B_SCP_Servers {
 static function provider_for_capability_descriptor($server,$name){
  return $GLOBALS['provider_present'] ? 'registered-original-provider' : null;
 }
 static function core_tools($server){return array();}
}
class MAD4B_SCP_Authorization {
 static function execution_boundary_verified($ability){return $GLOBALS['boundary_verified'];}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-ability-contract-inspector.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-capability-descriptor-registry.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-registration-evidence.php';
function verified($ok,$why){if(!$ok){fwrite(STDERR,"FAIL: ".$why.PHP_EOL);exit(1);}}
$GLOBALS['read_only']=false;
$GLOBALS['boundary_verified']=true;
$GLOBALS['provider_present']=true;
$checks=0;
foreach(array('enrollment','admin','content','developer','write','read') as $lane){
 $GLOBALS['lane']=$lane;$GLOBALS['read_only']=$lane==='read';
 $result=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
  'mad4b-chatgpt',array('mad4b/operator-grants-converge'));
 verified($result['ready']===true && $result['authorizing']===false
  && count($result['bindings'])===1,'Verified actual '.$lane.' descriptor missing');
 $binding=$result['bindings']['mad4b/operator-grants-converge'];
 verified($binding['execution_lane']===$lane && $binding['authorizing']===false
  && $binding['authority_revalidation_required']===true
  && $binding['authority_snapshot_is_grant']===false,
  'Real Descriptor cannot imply executed permission: '.$lane);
 if($lane!=='read'){
  $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
    'mad4b-read',array('mad4b/operator-grants-converge'));
  verified(!$r['ready'] && $r['bindings']===array(),'Mutation leaked into read server: '.$lane);
 }
 ++$checks;
}
foreach(array('internal','breakglass','developer-breakglass','unknown') as $lane){
 $GLOBALS['lane']=$lane;$GLOBALS['read_only']=false;
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
  'mad4b-chatgpt',array('mad4b/operator-grants-converge'));
 verified(!$r['ready'] && empty($r['bindings']),'Unknown or breakglass lane projected: '.$lane);
 ++$checks;
}
$GLOBALS['lane']='enrollment';
foreach(array('boundary_verified','provider_present') as $property){
 $GLOBALS[$property]=false;
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
  'mad4b-chatgpt',array('mad4b/operator-grants-converge'));
 verified(!$r['ready'] && !$r['bindings'],'Missing original trust admitted: '.$property);
 $GLOBALS[$property]=true;
 ++$checks;
}
// Exact original source-marked read-only dispatchers may be projected on
// ChatGPT, but no other name/category/annotation/surface may borrow the marker.
foreach(array(
 array('mad4b/developer-discover','developer-dispatch'),
 array('mad4b/developer-info','developer-dispatch'),
 array('mad4b/enrollment-discover','enrollment'),
 array('mad4b/enrollment-info','enrollment')
) as $case){
 $GLOBALS['fixture_ability_name']=$case[0];
 $GLOBALS['fixture_category']='mad4b-read';
 $GLOBALS['lane']=$case[1];
 $GLOBALS['read_only']=true;
 $GLOBALS['descriptor_read_marker']=true;
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
   'mad4b-chatgpt',array($case[0]));
 verified($r['ready']===true && isset($r['bindings'][$case[0]])
   && $r['bindings'][$case[0]]['execution_lane']==='read'
   && $r['bindings'][$case[0]]['authority_revalidation_required']===true
   && $r['bindings'][$case[0]]['authorizing']===false,
   'Original read-only dispatcher descriptor was lost: '.$case[0]);
 ++$checks;
 $GLOBALS['descriptor_read_marker']=false;
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
   'mad4b-chatgpt',array($case[0]));
 verified(!$r['ready'] && $r['bindings']===array(),
   'Missing original descriptor declaration was admitted: '.$case[0]);
 ++$checks;
 $GLOBALS['descriptor_read_marker']=true;
 $GLOBALS['fixture_category']='mad4b-admin';
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
   'mad4b-chatgpt',array($case[0]));
 verified(!$r['ready'] && $r['bindings']===array(),
   'Foreign category acquired read classification: '.$case[0]);
 ++$checks;
 $GLOBALS['fixture_category']='mad4b-read';
 $GLOBALS['read_only']=false;
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
   'mad4b-chatgpt',array($case[0]));
 verified(!$r['ready'] && $r['bindings']===array(),
   'Mutation annotation acquired read classification: '.$case[0]);
 ++$checks;
}
// Direct profile's original registered reads are discoverable without
// treating its direct transport as a blanket write-enrollment authority.
foreach(array('mad4b/direct-connection-status','mad4b/direct-list-posts','mad4b/direct-get-post') as $name){
 $GLOBALS['fixture_ability_name']=$name;
 $GLOBALS['fixture_category']='mad4b-read';
 $GLOBALS['lane']='direct';
 $GLOBALS['read_only']=true;
 $GLOBALS['descriptor_read_marker']=true;
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
   'mad4b-chatgpt',array($name));
 verified($r['ready']===true && $r['bindings'][$name]['execution_lane']==='read'
   && $r['bindings'][$name]['authorizing']===false
   && $r['bindings'][$name]['authority_revalidation_required']===true,
   'Source-declared Direct readonly descriptor missing: '.$name);
 ++$checks;
 $GLOBALS['descriptor_read_marker']=false;
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
   'mad4b-chatgpt',array($name));
 verified(!$r['ready'] && empty($r['bindings']),
   'Missing Direct read declaration admitted: '.$name);
 ++$checks;
 $GLOBALS['descriptor_read_marker']=true;
 $GLOBALS['read_only']=false;
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
   'mad4b-chatgpt',array($name));
 verified(!$r['ready'] && empty($r['bindings']),
   'Direct tool with mutating annotation admitted as read: '.$name);
 ++$checks;
}
foreach(array('mad4b/direct-create-post','mad4b/direct-update-post') as $name){
 $GLOBALS['fixture_ability_name']=$name;
 $GLOBALS['fixture_category']='mad4b-content';
 $GLOBALS['lane']='direct';
 $GLOBALS['read_only']=false;
 $GLOBALS['descriptor_read_marker']=false;
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
   'mad4b-chatgpt',array($name));
 verified(!$r['ready'] && empty($r['bindings']),
   'Direct write tool bypassed its unverified original lane: '.$name);
 ++$checks;
}
foreach(array('mad4b/developer-execute','mad4b/enrollment-execute','demo/foreign') as $foreign){
 $GLOBALS['fixture_ability_name']=$foreign;
 $GLOBALS['fixture_category']='mad4b-read';
 $GLOBALS['read_only']=true;
 $GLOBALS['descriptor_read_marker']=true;
 $GLOBALS['lane']='developer-dispatch';
 $r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
   'mad4b-chatgpt',array($foreign));
 verified(!$r['ready'] && $r['bindings']===array(),
   'Untrusted dispatcher acquired read classification: '.$foreign);
 ++$checks;
}
// Real first-party original Operator grant callback ancestry. No callback
// is invoked and no grant or WordPress site exists in this hermetic fixture.
function add_action($hook,$callback,$priority=10){}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-grant-convergence.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-governed-ability-census.php';
class WP_Ability {
 private $execute_callback;
 private $permission_callback;
 private $meta;
 function __construct($execute,$permission,$meta){
  $this->execute_callback=$execute; $this->permission_callback=$permission; $this->meta=$meta;
 }
 function get_name(){return 'mad4b/operator-grants-converge';}
 function get_meta(){return $this->meta;}
 function get_input_schema(){return array('type'=>'object','properties'=>array('action'=>array('type'=>'string')));}
 function get_output_schema(){return array('type'=>'object');}
 function get_category(){return 'mad4b-developer-governance';}
 function get_label(){return 'Operator Grants';}
 function get_description(){return 'First party Operator Grants registered operation';}
 function execute(){}
}
class WP_Abilities_Registry {
 static function get_instance(){return new self();}
 function get_all_registered(){return array('mad4b/operator-grants-converge'=>$GLOBALS['operator_ability']);}
}
class MAD4B_SCP_Operation_Registry {
 static function registered_write_policy($name){return $name==='mad4b/operator-grants-converge'&&
  !empty($GLOBALS['operator_policy_present']) ? array('contract'=>'first-party-test') :
  new WP_Error('unregistered');}
}
$GLOBALS['operator_policy_present']=true;
$GLOBALS['operator_ability']=new WP_Ability(
 array('MAD4B_SCP_Operator_Grant_Convergence','execute'),
 array('MAD4B_SCP_Operator_Grant_Convergence','can_access'),
 array('mcp'=>array('surface'=>'enrollment','chatgpt_direct_step_up'=>true,
  'exact_chatgpt_client_required'=>true,'generic_remote_admin'=>false,
  'governed_enrollment_broker'=>true,'broker_dispatch_policy'=>'registered_operation_policy_v1',
  'owner_intent_only'=>true,'production_mutation_allowed'=>'policy_governed',
  'production_allowed'=>'policy_governed','governed_operation_policy'=>'registered_write_policy_v1'),
  'annotations'=>array('readonly'=>false)));
verified(MAD4B_SCP_Governed_Ability_Census::operator_grant_original_boundary_verified(
 $GLOBALS['operator_ability']), 'Exact original Operator callback proof missing');
++$checks;
$GLOBALS['operator_ability']=new WP_Ability(
 array('MAD4B_SCP_Operator_Grant_Convergence','can_access'),
 array('MAD4B_SCP_Operator_Grant_Convergence','execute'),
 $GLOBALS['operator_ability']->get_meta());
verified(!MAD4B_SCP_Governed_Ability_Census::operator_grant_original_boundary_verified(
 $GLOBALS['operator_ability']), 'Swapped Operator callbacks were accepted');
++$checks;
$GLOBALS['operator_ability']=new WP_Ability(
 array('MAD4B_SCP_Operator_Grant_Convergence','execute'),
 array('MAD4B_SCP_Operator_Grant_Convergence','can_access'),
 array('mcp'=>array('surface'=>'enrollment','chatgpt_direct_step_up'=>true,
  'exact_chatgpt_client_required'=>true,'generic_remote_admin'=>false,
  'governed_enrollment_broker'=>true,'broker_dispatch_policy'=>'registered_operation_policy_v1',
  'owner_intent_only'=>true,'production_mutation_allowed'=>'policy_governed',
  'production_allowed'=>'policy_governed','governed_operation_policy'=>'registered_write_policy_v1'),
  'annotations'=>array('readonly'=>false)));
$GLOBALS['operator_policy_present']=false;
verified(!MAD4B_SCP_Governed_Ability_Census::operator_grant_original_boundary_verified(
 $GLOBALS['operator_ability']), 'Unregistered Operator policy admitted');
++$checks;
unset($GLOBALS['operator_ability'],$GLOBALS['operator_policy_present']);
unset($GLOBALS['fixture_ability_name'],$GLOBALS['fixture_category'],
      $GLOBALS['descriptor_read_marker']);
echo "PASS: CANONICAL_MULTILANE_DESCRIPTOR ".$checks." real registry + inspector cases; non-authorizing".PHP_EOL;
