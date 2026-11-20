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
function wp_has_ability($name){return ($name==='mad4b/operator-grants-converge'&&isset($GLOBALS['operator_ability'])) || ($name==='mad4b/staging-write-owner-attest'&&isset($GLOBALS['owner_ability'])) || ($name==='mad4b/chatgpt-tool-projection-apply'&&isset($GLOBALS['projection_ability'])) || $name===($GLOBALS['fixture_ability_name']??'mad4b/operator-grants-converge');}
function wp_get_ability($name){if($name==='mad4b/operator-grants-converge'&&isset($GLOBALS['operator_ability']))return $GLOBALS['operator_ability'];if($name==='mad4b/staging-write-owner-attest'&&isset($GLOBALS['owner_ability']))return $GLOBALS['owner_ability'];if($name==='mad4b/chatgpt-tool-projection-apply'&&isset($GLOBALS['projection_ability']))return $GLOBALS['projection_ability'];return new Demo_Ability();}
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
function add_filter($hook,$callback,$priority=10,$args=1){}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-grant-convergence.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-owner-intent.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-chatgpt-tool-projection.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-governed-ability-census.php';
class WP_Ability {
 private $execute_callback;
 private $permission_callback;
 private $meta;
 private $name;
 function __construct($execute,$permission,$meta,$name='mad4b/operator-grants-converge'){
  $this->execute_callback=$execute; $this->permission_callback=$permission; $this->meta=$meta; $this->name=$name;
 }
 function get_name(){return $this->name;}
 function get_meta(){return $this->meta;}
 function get_input_schema(){return array('type'=>'object','properties'=>array('action'=>array('type'=>'string')));}
 function get_output_schema(){return array('type'=>'object');}
 function get_category(){return $this->name==='mad4b/staging-write-owner-attest'?'mad4b-staging-write-authority':($this->name==='mad4b/chatgpt-tool-projection-apply'?'mad4b-governance':'mad4b-developer-governance');}
 function get_label(){return 'Original first party guard';}
 function get_description(){return 'First party Operator Grants registered operation';}
 function execute(){}
}
class WP_Abilities_Registry {
 static function get_instance(){return new self();}
 function get_all_registered(){ $a=array();if(isset($GLOBALS['operator_ability']))$a['mad4b/operator-grants-converge']=$GLOBALS['operator_ability'];if(isset($GLOBALS['owner_ability']))$a['mad4b/staging-write-owner-attest']=$GLOBALS['owner_ability'];if(isset($GLOBALS['projection_ability']))$a['mad4b/chatgpt-tool-projection-apply']=$GLOBALS['projection_ability'];return $a;}
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
$GLOBALS['boundary_verified']=false;
verified(MAD4B_SCP_Governed_Ability_Census::operator_grant_original_boundary_verified(
 $GLOBALS['operator_ability']), 'Exact original Operator callback proof missing');
$bound=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
 'mad4b-chatgpt',array('mad4b/operator-grants-converge'));
verified($bound['ready']===true
  && $bound['bindings']['mad4b/operator-grants-converge']['execution_lane']==='enrollment'
  && $bound['bindings']['mad4b/operator-grants-converge']['authorizing']===false,
  'Exact registered Operator descriptor did not bind');
++$checks;
$GLOBALS['operator_ability']=new WP_Ability(
 array('MAD4B_SCP_Operator_Grant_Convergence','can_access'),
 array('MAD4B_SCP_Operator_Grant_Convergence','execute'),
 $GLOBALS['operator_ability']->get_meta());
verified(!MAD4B_SCP_Governed_Ability_Census::operator_grant_original_boundary_verified(
 $GLOBALS['operator_ability']), 'Swapped Operator callbacks were accepted');
$bad=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
 'mad4b-chatgpt',array('mad4b/operator-grants-converge'));
verified(!$bad['ready'] && empty($bad['bindings']),
 'Swapped Operator callbacks became visible through MCP');
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
$bad=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
 'mad4b-chatgpt',array('mad4b/operator-grants-converge'));
verified(!$bad['ready'] && empty($bad['bindings']),
 'Unregistered Operator policy became visible through MCP');
++$checks;
unset($GLOBALS['operator_ability'],$GLOBALS['operator_policy_present']);

/** The original Staging Owner intent is *not* a grant or execution claim.
 * This fixture exercises both the original callback identity and canonical
 * descriptor with real source methods but no WordPress mutations.
 */
$owner_meta=array(
 'mcp'=>array('surface'=>'enrollment','chatgpt_direct_step_up'=>true,
  'exact_chatgpt_client_required'=>true,'generic_remote_admin'=>false,
  'production_mutation_allowed'=>false,'breakglass_allowed'=>false,
  'owner_intent_only'=>true,'governed_enrollment_broker'=>true,
  'broker_dispatch_policy'=>'owner_attestation_v1'),
 'annotations'=>array('readonly'=>false));
$owner='mad4b/staging-write-owner-attest';
$GLOBALS['owner_ability']=new WP_Ability(
 array('MAD4B_SCP_Staging_Write_Owner_Intent','attest'),
 array('MAD4B_SCP_Staging_Write_Owner_Intent','can_remote_attest'),
 $owner_meta,$owner);
verified(MAD4B_SCP_Governed_Ability_Census::staging_owner_intent_original_boundary_verified(
 $GLOBALS['owner_ability']),'Exact original Owner-intent callback proof missing');
$owner_binding=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
 'mad4b-chatgpt',array($owner));
verified($owner_binding['ready']===true && isset($owner_binding['bindings'][$owner])
  && $owner_binding['bindings'][$owner]['execution_lane']==='enrollment'
  && $owner_binding['bindings'][$owner]['authorizing']===false
  && $owner_binding['bindings'][$owner]['authority_revalidation_required']===true,
 'Staging Owner-intent descriptor was not source-bound');
++$checks;
$GLOBALS['owner_ability']=new WP_Ability(
 array('MAD4B_SCP_Staging_Write_Owner_Intent','can_remote_attest'),
 array('MAD4B_SCP_Staging_Write_Owner_Intent','attest'),$owner_meta,$owner);
$owner_binding=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
 'mad4b-chatgpt',array($owner));
verified(!$owner_binding['ready'] && empty($owner_binding['bindings']),
 'Swapped Owner-intent execution and permission callbacks became MCP tools');
++$checks;
$unsafe=$owner_meta;$unsafe['mcp']['broker_dispatch_policy']='unknown';
$GLOBALS['owner_ability']=new WP_Ability(
 array('MAD4B_SCP_Staging_Write_Owner_Intent','attest'),
 array('MAD4B_SCP_Staging_Write_Owner_Intent','can_remote_attest'),$unsafe,$owner);
$owner_binding=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
 'mad4b-chatgpt',array($owner));
verified(!$owner_binding['ready'] && empty($owner_binding['bindings']),
 'Wrong Owner-intent broker policy became an MCP descriptor');
++$checks;
unset($GLOBALS['owner_ability']);
// Original Staging-only ChatGPT tool projection: visibility never
// grants target execution. Require original execute/permission callbacks.
$projection='mad4b/chatgpt-tool-projection-apply';
$projection_meta=array(
 'mcp'=>array('surface'=>'enrollment',
  'chatgpt_tool_projection_contract'=>MAD4B_SCP_ChatGPT_Tool_Projection::CONTRACT,
  'chatgpt_direct_step_up'=>true,'exact_chatgpt_client_required'=>true,
  'projection_changes_authority'=>false,
  'production_mutation_allowed'=>false,'generic_remote_admin'=>false),
 'annotations'=>array('readonly'=>false,'destructive'=>false));
$GLOBALS['projection_ability']=new WP_Ability(
 array('MAD4B_SCP_ChatGPT_Tool_Projection','apply'),
 array('MAD4B_SCP_ChatGPT_Tool_Projection','can_apply'),
 $projection_meta,$projection);
verified(MAD4B_SCP_Governed_Ability_Census::chatgpt_projection_original_boundary_verified(
 $GLOBALS['projection_ability']), 'Exact nonauthorizing projection original callback proof missing');
$r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
 'mad4b-chatgpt',array($projection));
verified($r['ready']===true && $r['bindings'][$projection]['execution_lane']==='enrollment'
  && $r['bindings'][$projection]['authorizing']===false
  && $r['bindings'][$projection]['authority_revalidation_required']===true,
 'Original Staging projection descriptor not correctly source-bound');
++$checks;
$GLOBALS['projection_ability']=new WP_Ability(
 array('MAD4B_SCP_ChatGPT_Tool_Projection','can_apply'),
 array('MAD4B_SCP_ChatGPT_Tool_Projection','apply'),
 $projection_meta,$projection);
$r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
 'mad4b-chatgpt',array($projection));
verified(!$r['ready'] && empty($r['bindings']),
 'Swapped Staging projection callback pair gained MCP binding');
++$checks;
$bad_meta=$projection_meta;
$bad_meta['mcp']['projection_changes_authority']=true;
$GLOBALS['projection_ability']=new WP_Ability(
 array('MAD4B_SCP_ChatGPT_Tool_Projection','apply'),
 array('MAD4B_SCP_ChatGPT_Tool_Projection','can_apply'),
 $bad_meta,$projection);
$r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
 'mad4b-chatgpt',array($projection));
verified(!$r['ready'] && empty($r['bindings']),
 'Authority-changing projection metadata gained MCP binding');
++$checks;
unset($GLOBALS['projection_ability']);
unset($GLOBALS['fixture_ability_name'],$GLOBALS['fixture_category'],
      $GLOBALS['descriptor_read_marker']);
echo "PASS: CANONICAL_MULTILANE_DESCRIPTOR ".$checks." real registry + inspector cases; non-authorizing".PHP_EOL;
