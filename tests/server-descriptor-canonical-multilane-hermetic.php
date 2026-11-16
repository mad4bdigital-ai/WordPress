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
function wp_has_ability($name){return $name==='mad4b/operator-grants-converge';}
function wp_get_ability($name){return new Demo_Ability();}
function apply_filters($filter,$value,...$args){return $value;}
class Demo_Ability {
 function get_meta(){
  return array('annotations'=>array('readonly'=>$GLOBALS['read_only']),
   'mcp'=>array('surface'=>$GLOBALS['lane'],
    'mad4b_execution_boundary'=>'test-only-governed'));
 }
 function execute(){}
 function get_input_schema(){return array('type'=>'object','additionalProperties'=>false);}
 function get_output_schema(){return array('type'=>'object');}
 function get_category(){return 'mad4b-developer-governance';}
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
echo "PASS: CANONICAL_MULTILANE_DESCRIPTOR ".$checks." real registry + inspector cases; non-authorizing".PHP_EOL;
