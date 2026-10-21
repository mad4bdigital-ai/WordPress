<?php
/** Real registry + inspector fault paths: no raw error, no retry or authority. */
define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; private $data; public function __construct($code,$message='',$data=null){$this->code=$code;$this->data=$data;} public function get_error_code(){return $this->code;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_has_ability($n){return true;}
function wp_get_ability($n){if($GLOBALS['fault']==='inspection')throw new RuntimeException('PRIVATE inspection details');return new Audit_Ability();}
function apply_filters($hook,$value,...$args){if($GLOBALS['fault']==='extension')throw new RuntimeException('PRIVATE extension details');return $value;}
class MAD4B_SCP_Unified_Capability_Gateway { static function runtime_blog_matches(){if($GLOBALS['fault']==='blog')throw new RuntimeException('PRIVATE blog failure');return $GLOBALS['fault']!=='blog_denied';} }
class Audit_Ability { function get_meta(){return ['annotations'=>['readonly'=>true],'mcp'=>['surface'=>'read']];} function execute(){} function get_input_schema(){return ['type'=>'object'];} }
class MAD4B_Native_Incident_MU { static $events=[];static $throw=false; static function correlation_id(){return str_repeat('c',32);}static function record_throwable($e,$stage,$ability,$correlation){if(self::$throw)throw new RuntimeException('PRIVATE recorder');self::$events[]=[$stage,$ability,$correlation];} }
require __DIR__.'/../includes/class-mad4b-scp-ability-contract-inspector.php';
require __DIR__.'/../includes/class-mad4b-scp-capability-descriptor-registry.php';
require __DIR__.'/../includes/class-mad4b-scp-server-registration-evidence.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$GLOBALS['fault']='blog';
$r=MAD4B_SCP_Ability_Contract_Inspector::inspect('mad4b/site-info');
check(is_wp_error($r)&&$r->get_error_code()==='mad4b_chatgpt_projection_ability_inspection_failed','Blog guard exception escaped');
$GLOBALS['fault']='blog_denied';
$r=MAD4B_SCP_Ability_Contract_Inspector::inspect('mad4b/site-info');
check($r->get_error_code()==='mad4b_projection_blog_switch_denied','Blog isolation denial changed');
MAD4B_Native_Incident_MU::$events=[];
$GLOBALS['fault']='inspection';
$r=MAD4B_SCP_Capability_Descriptor_Registry::binding('mad4b/site-info','servers');
check(is_wp_error($r)&&$r->get_error_code()==='mad4b_chatgpt_projection_ability_inspection_failed','Inspector denial lost');
check(MAD4B_Native_Incident_MU::$events===[['ability_inspection','mad4b/site-info',str_repeat('c',32)]],'Inspector evidence missing or duplicated');
check($r->get_error_data()['correlation_id']===str_repeat('c',32),'Client correlation absent');
$GLOBALS['fault']='extension';
$r=MAD4B_SCP_Capability_Descriptor_Registry::binding('mad4b/site-info','servers');
check(is_wp_error($r)&&$r->get_error_code()==='mad4b_capability_descriptor_binding_failed','Extension exception escaped');
check(MAD4B_Native_Incident_MU::$events[1][0]==='descriptor_binding','Descriptor stage missing');
MAD4B_Native_Incident_MU::$throw=true;
$r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence('mad4b-chatgpt',['mad4b/site-info']);
check(!$r['ready']&&$r['bindings']===[]&&$r['blockers']===['mad4b/site-info:mad4b_capability_descriptor_binding_failed'],'Recorder failure changed fail-closed evidence');
check(strpos(json_encode($r),'PRIVATE')===false,'Private data leaked');
$GLOBALS['fault']='none';
$r=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence('mad4b-read',['mad4b/site-info']);
check($r['ready']&&count($r['bindings'])===1&&$r['authorizing']===false,'Independent healthy registration poisoned');
echo "DESCRIPTOR_FAULT_CONTAINMENT_PASS\n";
